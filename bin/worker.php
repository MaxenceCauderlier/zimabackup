<?php

declare(strict_types=1);

use ZimaBackup\Core\Application;
use ZimaBackup\Service\RepositoryService;
use ZimaBackup\Service\SchedulerService;
use ZimaBackup\Service\TaskQueueService;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::create(dirname(__DIR__));

/** @var TaskQueueService $queue */
$queue = $app->service(TaskQueueService::class);
/** @var RepositoryService $repositories */
$repositories = $app->service(RepositoryService::class);
/** @var SchedulerService $scheduler */
$scheduler = $app->service(SchedulerService::class);

$interval = max(2, (int) (getenv('WORKER_INTERVAL') ?: 10));

echo sprintf("ZimaBackup worker started (interval: %d seconds).\n", $interval);

while (true) {
    // Drain privileged operations before checking scheduled backup jobs.
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

                default:
                    throw new RuntimeException(sprintf('Unsupported operation type: %s', $operation['type']));
            }

            echo sprintf("%s - Operation completed.\n", date('c'));
        } catch (Throwable $exception) {
            $queue->fail((int) $operation['id'], $exception->getMessage());
            fwrite(STDERR, sprintf("%s - Operation failed: %s\n", date('c'), $exception->getMessage()));
        }
    }

    $dueJobs = $scheduler->dueJobs();
    if ($dueJobs !== []) {
        echo sprintf("%s - %d backup job(s) due. Backup execution is the next milestone.\n", date('c'), count($dueJobs));
    }

    sleep($interval);
}
