<?php

declare(strict_types=1);

use ZimaBackup\Core\Application;
use ZimaBackup\Service\ApplicationDiscoveryService;
use ZimaBackup\Service\ApplicationInstallService;
use ZimaBackup\Service\ApplicationRestoreService;
use ZimaBackup\Service\BackupService;
use ZimaBackup\Service\RepositoryService;
use ZimaBackup\Service\RetentionService;
use ZimaBackup\Service\RestoreService;
use ZimaBackup\Service\SchedulerService;
use ZimaBackup\Service\SnapshotApplicationService;
use ZimaBackup\Service\SnapshotService;
use ZimaBackup\Service\SettingsService;
use ZimaBackup\Service\TaskQueueService;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::create(dirname(__DIR__));

/** @var TaskQueueService $queue */
$queue = $app->service(TaskQueueService::class);
/** @var RepositoryService $repositories */
$repositories = $app->service(RepositoryService::class);
/** @var RetentionService $retention */
$retention = $app->service(RetentionService::class);
/** @var BackupService $backups */
$backups = $app->service(BackupService::class);
/** @var RestoreService $restores */
$restores = $app->service(RestoreService::class);
/** @var ApplicationDiscoveryService $applications */
$applications = $app->service(ApplicationDiscoveryService::class);
/** @var SchedulerService $scheduler */
$scheduler = $app->service(SchedulerService::class);
/** @var SnapshotApplicationService $snapshotApplications */
$snapshotApplications = $app->service(SnapshotApplicationService::class);
/** @var ApplicationRestoreService $applicationRestores */
$applicationRestores = $app->service(ApplicationRestoreService::class);
/** @var ApplicationInstallService $applicationInstalls */
$applicationInstalls = $app->service(ApplicationInstallService::class);
/** @var SnapshotService $snapshots */
$snapshots = $app->service(SnapshotService::class);
/** @var SettingsService $settings */
$settings = $app->service(SettingsService::class);

$interval = max(2, (int) (getenv('WORKER_INTERVAL') ?: 10));
$discoveryInterval = max(30, $settings->getInt('apps.discovery.interval', (int) (getenv('APP_DISCOVERY_INTERVAL') ?: 120)));
$lastDiscovery = 0;
$lastMaintenance = 0;

echo sprintf(
    "ZimaBackup worker started (interval: %d seconds, app discovery: %d seconds).\n",
    $interval,
    $discoveryInterval
);

while (true) {
    while (($operation = $queue->claimNext()) !== null) {
        echo sprintf("%s - Running %s (%s).\n", date('c'), $operation['type'], $operation['uuid']);

        try {
            switch ($operation['type']) {
                case 'repository.init':
                    $repositoryId = (int) ($operation['payload']['repository_id'] ?? 0);
                    if ($repositoryId <= 0) {
                        throw new RuntimeException('repository.init task has no valid repository_id.');
                    }

                    try {
                        $repositories->initialize($repositoryId);
                    } catch (Throwable $exception) {
                        $repositories->markFailed($repositoryId, $exception->getMessage());
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['repository_id' => $repositoryId]);
                    break;

                case 'repository.reinitialize':
                    $repositoryId = (int) ($operation['payload']['repository_id'] ?? 0);
                    if ($repositoryId <= 0) {
                        throw new RuntimeException('repository.reinitialize task has no valid repository_id.');
                    }
                    try {
                        $unavailableSnapshots = $repositories->reinitialize($repositoryId);
                    } catch (Throwable $exception) {
                        $repositories->markReinitializeFailed($repositoryId, $exception->getMessage());
                        throw $exception;
                    }
                    $queue->complete((int) $operation['id'], [
                        'repository_id' => $repositoryId,
                        'unavailable_snapshots' => $unavailableSnapshots,
                    ]);
                    break;

                case 'repository.check':
                    $repositoryId = (int) ($operation['payload']['repository_id'] ?? 0);
                    if ($repositoryId <= 0) {
                        throw new RuntimeException('repository.check task has no valid repository_id.');
                    }
                    try {
                        $repositories->check($repositoryId);
                    } catch (Throwable $exception) {
                        $repositories->markCheckFailed($repositoryId, $exception->getMessage());
                        throw $exception;
                    }
                    $queue->complete((int) $operation['id'], ['repository_id' => $repositoryId]);
                    break;

                case 'repository.prune':
                    $repositoryId = (int) ($operation['payload']['repository_id'] ?? 0);
                    if ($repositoryId <= 0) {
                        throw new RuntimeException('repository.prune task has no valid repository_id.');
                    }
                    try {
                        $repositories->prune($repositoryId);
                    } catch (Throwable $exception) {
                        $repositories->markPruneFailed($repositoryId, $exception->getMessage());
                        throw $exception;
                    }
                    $queue->complete((int) $operation['id'], ['repository_id' => $repositoryId]);
                    break;

                case 'snapshot.forget':
                    $backupRunId = (int) ($operation['payload']['backup_run_id'] ?? 0);
                    if ($backupRunId <= 0) {
                        throw new RuntimeException('snapshot.forget task has no valid backup_run_id.');
                    }
                    try {
                        $snapshots->executeForget($backupRunId);
                    } catch (Throwable $exception) {
                        $snapshots->markForgetFailed($backupRunId, $exception->getMessage());
                        throw $exception;
                    }
                    $queue->complete((int) $operation['id'], ['backup_run_id' => $backupRunId]);
                    break;

                case 'backup.run':
                    $runId = (int) ($operation['payload']['run_id'] ?? 0);
                    if ($runId <= 0) {
                        throw new RuntimeException('backup.run task has no valid run_id.');
                    }

                    try {
                        $backups->executeRun($runId);
                    } catch (Throwable $exception) {
                        $backups->markRunFailed($runId, $exception->getMessage());
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['run_id' => $runId]);
                    break;

                case 'retention.apply':
                    $retentionRunId = (int) ($operation['payload']['retention_run_id'] ?? 0);
                    if ($retentionRunId <= 0) {
                        throw new RuntimeException('retention.apply task has no valid retention_run_id.');
                    }
                    try {
                        $result = $retention->execute($retentionRunId);
                    } catch (Throwable $exception) {
                        $retention->markFailed($retentionRunId, $exception->getMessage());
                        throw $exception;
                    }
                    $queue->complete((int) $operation['id'], ['retention_run_id' => $retentionRunId] + $result);
                    break;

                case 'restore.run':
                    $restoreRunId = (int) ($operation['payload']['restore_run_id'] ?? 0);
                    if ($restoreRunId <= 0) {
                        throw new RuntimeException('restore.run task has no valid restore_run_id.');
                    }

                    try {
                        $restores->execute($restoreRunId);
                    } catch (Throwable $exception) {
                        $restores->markFailed($restoreRunId, $exception->getMessage());
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['restore_run_id' => $restoreRunId]);
                    break;

                case 'apps.discover':
                    $count = $applications->refresh();
                    $lastDiscovery = time();
                    $queue->complete((int) $operation['id'], ['application_count' => $count]);
                    break;

                case 'snapshot.apps.inspect':
                    $backupRunId = (int) ($operation['payload']['backup_run_id'] ?? 0);
                    if ($backupRunId <= 0) {
                        throw new RuntimeException('snapshot.apps.inspect task has no valid backup_run_id.');
                    }

                    try {
                        $count = $snapshotApplications->inspect($backupRunId);
                    } catch (Throwable $exception) {
                        $snapshotApplications->markFailed($backupRunId, $exception->getMessage());
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['backup_run_id' => $backupRunId, 'application_count' => $count]);
                    break;

                case 'application.restore':
                    $applicationRestoreRunId = (int) ($operation['payload']['application_restore_run_id'] ?? 0);
                    if ($applicationRestoreRunId <= 0) {
                        throw new RuntimeException('application.restore task has no valid application_restore_run_id.');
                    }

                    try {
                        $applicationRestores->execute($applicationRestoreRunId);
                    } catch (Throwable $exception) {
                        $applicationRestores->markFailed($applicationRestoreRunId, $exception->getMessage());
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['application_restore_run_id' => $applicationRestoreRunId]);
                    break;

                case 'application.install':
                    $applicationInstallRunId = (int) ($operation['payload']['application_install_run_id'] ?? 0);
                    if ($applicationInstallRunId <= 0) {
                        throw new RuntimeException('application.install task has no valid application_install_run_id.');
                    }

                    try {
                        $applicationInstalls->execute($applicationInstallRunId);
                    } catch (Throwable $exception) {
                        // execute() records the rollback-aware failure itself. This is
                        // still called for preflight errors that occur before its catch.
                        $latest = $applicationInstalls->findById($applicationInstallRunId);
                        if ($latest !== null && ($latest['status'] ?? null) !== 'failed') {
                            $applicationInstalls->markFailed($applicationInstallRunId, $exception->getMessage());
                        }
                        throw $exception;
                    }

                    $queue->complete((int) $operation['id'], ['application_install_run_id' => $applicationInstallRunId]);
                    break;

                default:
                    throw new RuntimeException(sprintf('Unsupported operation type: %s', $operation['type']));
            }

            echo sprintf("%s - Operation completed.\n", date('c'));
        } catch (Throwable $exception) {
            $queue->fail((int) $operation['id'], $exception->getMessage());
            fwrite(STDERR, sprintf("%s - Operation failed: %s\n", date('c'), $exception->getMessage()));
        }
    }

    $discoveryInterval = max(30, $settings->getInt('apps.discovery.interval', $discoveryInterval));
    if ((time() - $lastDiscovery) >= $discoveryInterval) {
        try {
            $count = $applications->refresh();
            echo sprintf("%s - Application discovery refreshed: %d app(s).\n", date('c'), $count);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("%s - Application discovery failed: %s\n", date('c'), $exception->getMessage()));
        }
        $lastDiscovery = time();
    }

    // Queue due scheduled backups. next_run_at is advanced atomically with
    // the queued backup so a worker restart cannot accidentally duplicate it.
    foreach ($scheduler->dueJobs() as $job) {
        try {
            $nextRunAt = $scheduler->nextForJob($job);
            $backups->enqueueScheduledRunByUuid((string) $job['uuid'], $nextRunAt);
            echo sprintf("%s - Scheduled backup queued: %s.\n", date('c'), $job['name']);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("%s - Scheduled backup queue failed for %s: %s\n", date('c'), $job['name'], $exception->getMessage()));
        }
    }

    // Successful runs mark retention as pending. Queue those policies separately
    // so snapshot cleanup never extends the backup operation itself.
    $retention->queuePending();

    // Repository maintenance is low-frequency and configuration-driven. At most
    // one automatic check/prune is queued per maintenance pass.
    if ((time() - $lastMaintenance) >= 60) {
        $autoCheck = $settings->getInt('maintenance.auto_check', 1) === 1;
        $checkDays = $autoCheck ? max(1, $settings->getInt('maintenance.check_interval_days', 7)) : 0;
        $autoPrune = $settings->getInt('maintenance.auto_prune', 0) === 1;
        $pruneDays = max(1, $settings->getInt('maintenance.prune_interval_days', 30));
        try {
            $repositories->enqueueDueMaintenance($checkDays, $autoPrune, $pruneDays);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("%s - Repository maintenance scheduling failed: %s\n", date('c'), $exception->getMessage()));
        }
        $lastMaintenance = time();
    }

    sleep($interval);
}
