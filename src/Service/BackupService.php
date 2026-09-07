<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class BackupService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
        private readonly ApplicationDiscoveryService $applications,
    ) {
    }

    public function all(): array
    {
        return $this->database->fetchAll(
            'SELECT bj.*, r.name AS repository_name, r.path AS repository_path, r.status AS repository_status, ' .
            '(SELECT COUNT(*) FROM backup_sources bs WHERE bs.backup_job_id = bj.id) AS source_count, ' .
            '(SELECT COUNT(*) FROM backup_applications ba WHERE ba.backup_job_id = bj.id) AS app_count, ' .
            '(SELECT br.status FROM backup_runs br WHERE br.backup_job_id = bj.id ORDER BY br.id DESC LIMIT 1) AS latest_status, ' .
            '(SELECT br.started_at FROM backup_runs br WHERE br.backup_job_id = bj.id ORDER BY br.id DESC LIMIT 1) AS latest_started_at, ' .
            '(SELECT br.finished_at FROM backup_runs br WHERE br.backup_job_id = bj.id ORDER BY br.id DESC LIMIT 1) AS latest_finished_at ' .
            'FROM backup_jobs bj JOIN repositories r ON r.id = bj.repository_id ' .
            'ORDER BY bj.created_at DESC'
        );
    }

    public function readyRepositories(): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM repositories WHERE status = 'ready' ORDER BY name COLLATE NOCASE ASC"
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        $job = $this->database->fetchOne(
            'SELECT bj.*, r.name AS repository_name, r.path AS repository_path, r.status AS repository_status ' .
            'FROM backup_jobs bj JOIN repositories r ON r.id = bj.repository_id WHERE bj.uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($job === null) {
            return null;
        }

        $job['sources'] = $this->database->fetchAll(
            'SELECT * FROM backup_sources WHERE backup_job_id = :job_id ORDER BY id ASC',
            ['job_id' => $job['id']]
        );
        $job['applications'] = $this->database->fetchAll(
            'SELECT * FROM backup_applications WHERE backup_job_id = :job_id ORDER BY id ASC',
            ['job_id' => $job['id']]
        );
        foreach ($job['applications'] as &$application) {
            $application['selected_mounts'] = $this->decodeJsonList((string) $application['selected_mounts_json']);
        }
        unset($application);

        $job['runs'] = $this->database->fetchAll(
            'SELECT * FROM backup_runs WHERE backup_job_id = :job_id ORDER BY id DESC LIMIT 20',
            ['job_id' => $job['id']]
        );

        return $job;
    }

    /**
     * Create a backup job from manual paths, discovered applications, or both.
     *
     * @param list<string> $sources
     * @param list<array{application_id:int,mount_ids:list<int>}> $applicationSelections
     */
    public function create(string $name, int $repositoryId, array $sources, array $applicationSelections = []): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Backup name must contain between 1 and 100 characters.');
        }

        $repository = $this->database->fetchOne(
            'SELECT * FROM repositories WHERE id = :id',
            ['id' => $repositoryId]
        );
        if ($repository === null || $repository['status'] !== 'ready') {
            throw new InvalidArgumentException('Choose a repository that is ready.');
        }

        $normalizedSources = [];
        foreach ($sources as $source) {
            if (!is_string($source) || trim($source) === '') {
                continue;
            }
            $source = $this->validateSource($source, (string) $repository['path']);
            $normalizedSources[$source] = $source;
        }

        $selectedApps = [];
        foreach ($applicationSelections as $selection) {
            $applicationId = (int) ($selection['application_id'] ?? 0);
            if ($applicationId <= 0) {
                continue;
            }

            $application = $this->applications->findById($applicationId);
            if ($application === null) {
                throw new InvalidArgumentException('One of the selected applications is no longer available. Refresh Applications and try again.');
            }

            $requestedMountIds = array_values(array_unique(array_map('intval', $selection['mount_ids'] ?? [])));
            $availableMounts = [];
            foreach ($application['mounts'] as $mount) {
                $availableMounts[(int) $mount['id']] = $mount;
            }

            $selectedMounts = [];
            foreach ($requestedMountIds as $mountId) {
                $mount = $availableMounts[$mountId] ?? null;
                if ($mount === null || (int) $mount['eligible'] !== 1) {
                    continue;
                }

                $source = $this->validateSource((string) $mount['source'], (string) $repository['path']);
                $normalizedSources[$source] = $source;
                $selectedMounts[] = [
                    'source' => $source,
                    'destination' => (string) $mount['destination'],
                    'service' => $mount['service_name'],
                ];
            }

            $selectedApps[$application['app_key']] = [
                'app_key' => (string) $application['app_key'],
                'app_name' => (string) $application['name'],
                'selected_mounts' => $selectedMounts,
            ];
        }

        $normalizedSources = array_values($normalizedSources);
        $selectedApps = array_values($selectedApps);
        if ($normalizedSources === [] && $selectedApps === []) {
            throw new InvalidArgumentException('Select at least one application or add one source folder.');
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $now = date('c');
            $uuid = Uuid::v4();

            $this->database->execute(
                'INSERT INTO backup_jobs(uuid, repository_id, name, enabled, schedule_type, keep_last, keep_daily, keep_weekly, keep_monthly, created_at, updated_at) ' .
                'VALUES (:uuid, :repository_id, :name, 1, :schedule_type, 3, 7, 4, 6, :created_at, :updated_at)',
                [
                    'uuid' => $uuid,
                    'repository_id' => $repositoryId,
                    'name' => $name,
                    'schedule_type' => 'manual',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $jobId = $this->database->lastInsertId();

            foreach ($normalizedSources as $source) {
                $this->database->execute(
                    'INSERT INTO backup_sources(backup_job_id, path, label, created_at) VALUES (:job_id, :path, :label, :created_at)',
                    [
                        'job_id' => $jobId,
                        'path' => $source,
                        'label' => basename($source),
                        'created_at' => $now,
                    ]
                );
            }

            foreach ($selectedApps as $application) {
                $this->database->execute(
                    'INSERT INTO backup_applications(backup_job_id, app_key, app_name, selected_mounts_json, created_at) ' .
                    'VALUES (:job_id, :app_key, :app_name, :selected_mounts_json, :created_at)',
                    [
                        'job_id' => $jobId,
                        'app_key' => $application['app_key'],
                        'app_name' => $application['app_name'],
                        'selected_mounts_json' => json_encode($application['selected_mounts'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                    ]
                );
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $job = $this->findByUuid($uuid);
        if ($job === null) {
            throw new RuntimeException('Backup job was created but could not be reloaded.');
        }

        return $job;
    }

    /** Queue one run and prevent the same job from being queued/running twice. */
    public function enqueueRunByUuid(string $uuid): array
    {
        $job = $this->database->fetchOne(
            'SELECT bj.*, r.status AS repository_status, r.id AS repo_id ' .
            'FROM backup_jobs bj JOIN repositories r ON r.id = bj.repository_id WHERE bj.uuid = :uuid',
            ['uuid' => $uuid]
        );
        if ($job === null) {
            throw new InvalidArgumentException('Backup job not found.');
        }
        if ($job['repository_status'] !== 'ready') {
            throw new InvalidArgumentException('The destination repository is not ready.');
        }

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $active = (int) $this->database->scalar(
                "SELECT COUNT(*) FROM backup_runs WHERE backup_job_id = :job_id AND status IN ('pending', 'running')",
                ['job_id' => $job['id']]
            );
            if ($active > 0) {
                throw new InvalidArgumentException('This backup is already queued or running.');
            }

            $now = date('c');
            $this->database->execute(
                "INSERT INTO backup_runs(backup_job_id, status, started_at, progress_percent) VALUES (:job_id, 'pending', :started_at, 0)",
                ['job_id' => $job['id'], 'started_at' => $now]
            );
            $runId = $this->database->lastInsertId();

            $operationId = $this->queue->enqueue('backup.run', [
                'job_id' => (int) $job['id'],
                'run_id' => $runId,
            ]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => $job['repo_id'], 'id' => $operationId]
            );

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->database->fetchOne('SELECT * FROM backup_runs WHERE id = :id', ['id' => $runId]) ?? [];
    }

    /** Execute a queued run. This method must only be called by the worker. */
    public function executeRun(int $runId): void
    {
        $run = $this->database->fetchOne(
            'SELECT br.*, bj.uuid AS job_uuid, bj.name AS job_name, bj.repository_id, ' .
            'r.path AS repository_path, r.password_file, r.status AS repository_status ' .
            'FROM backup_runs br ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id ' .
            'WHERE br.id = :run_id',
            ['run_id' => $runId]
        );
        if ($run === null) {
            throw new RuntimeException('Backup run not found.');
        }
        if ($run['repository_status'] !== 'ready') {
            throw new RuntimeException('Destination repository is not ready.');
        }

        $sourceRows = $this->database->fetchAll(
            'SELECT path FROM backup_sources WHERE backup_job_id = :job_id ORDER BY id ASC',
            ['job_id' => $run['backup_job_id']]
        );
        $applicationRows = $this->database->fetchAll(
            'SELECT * FROM backup_applications WHERE backup_job_id = :job_id ORDER BY id ASC',
            ['job_id' => $run['backup_job_id']]
        );
        if ($sourceRows === [] && $applicationRows === []) {
            throw new RuntimeException('Backup job has no sources or applications.');
        }

        $repositoryLogicalPath = (string) $run['repository_path'];
        $repositoryPath = $this->paths->toContainerPath($repositoryLogicalPath);
        $passwordFile = (string) $run['password_file'];

        if (!is_file($passwordFile) || !is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key file is missing or unreadable.');
        }

        $containerSources = [];
        $applicationTags = [];
        $lastUpdateAt = 0.0;

        try {
            foreach ($sourceRows as $sourceRow) {
                $logicalPath = (string) $sourceRow['path'];
                if ($this->paths->overlaps($logicalPath, $repositoryLogicalPath)) {
                    throw new RuntimeException(sprintf('Source %s overlaps the destination repository.', $logicalPath));
                }

                $containerPath = $this->paths->toContainerPath($logicalPath);
                if (!file_exists($containerPath)) {
                    throw new RuntimeException(sprintf('Source does not exist: %s', $logicalPath));
                }
                if (!is_readable($containerPath)) {
                    throw new RuntimeException(sprintf('Source is not readable by the backup worker: %s', $logicalPath));
                }

                $containerSources[$containerPath] = $containerPath;
            }

            $appKeys = array_values(array_map(
                static fn (array $application): string => (string) $application['app_key'],
                $applicationRows
            ));
            $manifestPaths = $this->applications->createBackupManifests(
                $appKeys,
                (string) $run['job_uuid'],
                $runId
            );
            foreach ($appKeys as $appKey) {
                $manifestPath = $manifestPaths[$appKey] ?? null;
                if ($manifestPath === null) {
                    throw new RuntimeException(sprintf('Application manifest was not generated: %s', $appKey));
                }
                $containerSources[$manifestPath] = $manifestPath;
                $applicationTags[] = 'zimabackup-app=' . $appKey;
            }
            $containerSources = array_values($containerSources);

            $this->database->execute(
                "UPDATE backup_runs SET status = 'running', started_at = :started_at, finished_at = NULL, error = NULL, progress_percent = 0 WHERE id = :id",
                ['started_at' => date('c'), 'id' => $runId]
            );

            $summary = $this->restic->backup(
                $repositoryPath,
                $passwordFile,
                $containerSources,
                (string) $run['job_uuid'],
                function (array $message) use ($runId, &$lastUpdateAt): void {
                    if (($message['message_type'] ?? null) !== 'status') {
                        return;
                    }

                    $now = microtime(true);
                    $percent = max(0.0, min(100.0, ((float) ($message['percent_done'] ?? 0)) * 100));
                    if ($percent < 100 && ($now - $lastUpdateAt) < 0.75) {
                        return;
                    }
                    $lastUpdateAt = $now;

                    $this->database->execute(
                        'UPDATE backup_runs SET progress_percent = :progress, total_files = :total_files, files_done = :files_done, ' .
                        'total_bytes = :total_bytes, bytes_done = :bytes_done, error_count = :error_count WHERE id = :id',
                        [
                            'progress' => $percent,
                            'total_files' => (int) ($message['total_files'] ?? 0),
                            'files_done' => (int) ($message['files_done'] ?? 0),
                            'total_bytes' => (int) ($message['total_bytes'] ?? 0),
                            'bytes_done' => (int) ($message['bytes_done'] ?? 0),
                            'error_count' => (int) ($message['error_count'] ?? 0),
                            'id' => $runId,
                        ]
                    );
                },
                $applicationTags
            );
        } finally {
            // Full manifests can contain application credentials. They must not
            // remain in ZimaBackup storage after Restic has consumed them, even
            // when the backup itself fails.
            $this->applications->cleanupBackupManifests((string) $run['job_uuid'], $runId);
        }

        $partial = (bool) ($summary['_partial'] ?? false);
        $this->database->execute(
            'UPDATE backup_runs SET status = :status, snapshot_id = :snapshot_id, finished_at = :finished_at, error = :error, ' .
            'progress_percent = 100, files_new = :files_new, files_changed = :files_changed, files_unmodified = :files_unmodified, ' .
            'total_files = :total_files, files_done = :files_done, total_bytes = :total_bytes, bytes_done = :bytes_done, bytes_processed = :bytes_processed, bytes_added = :bytes_added ' .
            'WHERE id = :id',
            [
                'status' => $partial ? 'warning' : 'success',
                'snapshot_id' => $summary['snapshot_id'] ?? null,
                'finished_at' => date('c'),
                'error' => $partial ? substr((string) ($summary['_error'] ?? 'Some source data could not be read.'), 0, 4000) : null,
                'files_new' => (int) ($summary['files_new'] ?? 0),
                'files_changed' => (int) ($summary['files_changed'] ?? 0),
                'files_unmodified' => (int) ($summary['files_unmodified'] ?? 0),
                'total_files' => (int) ($summary['total_files_processed'] ?? 0),
                'files_done' => (int) ($summary['total_files_processed'] ?? 0),
                'total_bytes' => (int) ($summary['total_bytes_processed'] ?? 0),
                'bytes_done' => (int) ($summary['total_bytes_processed'] ?? 0),
                'bytes_processed' => (int) ($summary['total_bytes_processed'] ?? 0),
                'bytes_added' => (int) ($summary['data_added_packed'] ?? $summary['data_added'] ?? 0),
                'id' => $runId,
            ]
        );
    }

    public function markRunFailed(int $runId, string $error): void
    {
        $this->database->execute(
            "UPDATE backup_runs SET status = 'failed', finished_at = :finished_at, error = :error WHERE id = :id",
            [
                'finished_at' => date('c'),
                'error' => substr($error, 0, 4000),
                'id' => $runId,
            ]
        );
    }

    private function validateSource(string $source, string $repositoryPath): string
    {
        $source = $this->paths->normalizeLogicalPath($source);
        $this->paths->toContainerPath($source);

        if (in_array($source, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Choose a more specific source than %s. This avoids accidentally backing up unrelated disks or the backup repository itself.',
                $source
            ));
        }

        if ($this->paths->overlaps($source, $repositoryPath)) {
            throw new InvalidArgumentException(sprintf(
                'Source %s overlaps the repository at %s. Choose separate paths to prevent recursive backups.',
                $source,
                $repositoryPath
            ));
        }

        return $source;
    }

    private function decodeJsonList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
