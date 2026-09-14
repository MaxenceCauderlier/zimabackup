<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class ApplicationRestoreService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
        private readonly ComposePreviewService $composePreview,
        private readonly DockerEngineClient $docker,
    ) {
    }

    public function snapshotApplication(int $snapshotApplicationId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT sa.*, br.snapshot_id, br.id AS backup_run_id, br.finished_at AS snapshot_finished_at, ' .
            'bj.id AS backup_job_id, bj.uuid AS job_uuid, bj.name AS job_name, ' .
            'r.id AS repository_id, r.name AS repository_name, r.path AS repository_path, ' .
            'r.password_file, r.status AS repository_status, ba.selected_mounts_json ' .
            'FROM snapshot_applications sa ' .
            'JOIN backup_runs br ON br.id = sa.backup_run_id ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id ' .
            'LEFT JOIN backup_applications ba ON ba.backup_job_id = bj.id AND ba.app_key = sa.app_key ' .
            'WHERE sa.id = :id',
            ['id' => $snapshotApplicationId]
        );

        if ($row === null) {
            return null;
        }

        $mounts = json_decode((string) ($row['selected_mounts_json'] ?? '[]'), true);
        $warnings = json_decode((string) ($row['warnings_json'] ?? '[]'), true);
        $row['selected_mounts'] = is_array($mounts) ? array_values($mounts) : [];
        $row['warnings'] = is_array($warnings) ? array_values($warnings) : [];

        return $row;
    }

    public function all(): array
    {
        return $this->database->fetchAll(
            'SELECT ar.*, r.name AS repository_name ' .
            'FROM application_restore_runs ar ' .
            'JOIN repositories r ON r.id = ar.repository_id ' .
            'ORDER BY ar.id DESC LIMIT 100'
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT ar.*, r.name AS repository_name, r.path AS repository_path, sa.compose_preview ' .
            'FROM application_restore_runs ar ' .
            'JOIN repositories r ON r.id = ar.repository_id ' .
            'JOIN snapshot_applications sa ON sa.id = ar.snapshot_application_id ' .
            'WHERE ar.uuid = :uuid',
            ['uuid' => $uuid]
        );
        if ($row === null) {
            return null;
        }

        $selected = json_decode((string) $row['selected_mounts_json'], true);
        $applied = json_decode((string) $row['applied_paths_json'], true);
        $row['selected_mounts'] = is_array($selected) ? array_values($selected) : [];
        $row['applied_paths'] = is_array($applied) ? array_values($applied) : [];
        return $row;
    }

    public function defaultStagingPath(array $application): string
    {
        $slug = strtolower((string) ($application['name'] ?? 'application'));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: 'application';
        $slug = trim($slug, '-');
        $shortSnapshot = substr((string) ($application['snapshot_id'] ?? 'snapshot'), 0, 8);
        return sprintf('/DATA/ZimaBackup/ApplicationRestores/%s-%s', $slug ?: 'application', $shortSnapshot);
    }

    public function enqueue(int $snapshotApplicationId, string $mode, string $confirm = ''): array
    {
        $application = $this->snapshotApplication($snapshotApplicationId);
        if ($application === null) {
            throw new InvalidArgumentException('Application snapshot not found.');
        }
        if ($application['repository_status'] !== 'ready') {
            throw new InvalidArgumentException('The snapshot repository is not ready.');
        }

        $mode = trim($mode);
        if (!in_array($mode, ['staging', 'original'], true)) {
            throw new InvalidArgumentException('Choose a valid application restore mode.');
        }
        if ($mode === 'original' && strtoupper(trim($confirm)) !== 'RESTORE') {
            throw new InvalidArgumentException('Type RESTORE to confirm restoration to the original paths.');
        }

        $selectedMounts = [];
        foreach ($application['selected_mounts'] as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $source = trim((string) ($mount['source'] ?? ''));
            if ($source === '') {
                continue;
            }
            $source = $this->validateRestoreSource($source, (string) $application['repository_path']);
            $selectedMounts[] = [
                'source' => $source,
                'destination' => (string) ($mount['destination'] ?? ''),
                'service' => $mount['service'] ?? null,
            ];
        }

        if ($mode === 'original') {
            $present = (int) $this->database->scalar(
                'SELECT COUNT(*) FROM discovered_apps WHERE app_key = :app_key AND present = 1',
                ['app_key' => $application['app_key']]
            );
            if ($present > 0) {
                throw new InvalidArgumentException('This application is currently detected by Docker. Remove/stop the existing deployment and refresh Applications before restoring to original paths. Use staging mode to inspect safely.');
            }
        }

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $active = (int) $this->database->scalar(
                "SELECT COUNT(*) FROM application_restore_runs WHERE snapshot_application_id = :id AND status IN ('pending', 'running')",
                ['id' => $snapshotApplicationId]
            );
            if ($active > 0) {
                throw new InvalidArgumentException('A restore for this application is already queued or running.');
            }

            $uuid = Uuid::v4();
            $stagingBase = rtrim($this->defaultStagingPath($application), '/');
            $stagingPath = $stagingBase . '-' . substr($uuid, 0, 8);
            $this->validateStagingTarget($stagingPath, (string) $application['repository_path']);

            $now = date('c');
            $this->database->execute(
                'INSERT INTO application_restore_runs(' .
                'uuid, snapshot_application_id, backup_run_id, repository_id, app_key, app_name, mode, status, staging_path, selected_mounts_json, created_at' .
                ") VALUES (:uuid, :snapshot_application_id, :backup_run_id, :repository_id, :app_key, :app_name, :mode, 'pending', :staging_path, :selected_mounts_json, :created_at)",
                [
                    'uuid' => $uuid,
                    'snapshot_application_id' => $snapshotApplicationId,
                    'backup_run_id' => (int) $application['backup_run_id'],
                    'repository_id' => (int) $application['repository_id'],
                    'app_key' => (string) $application['app_key'],
                    'app_name' => (string) $application['name'],
                    'mode' => $mode,
                    'staging_path' => $stagingPath,
                    'selected_mounts_json' => json_encode($selectedMounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]
            );
            $restoreId = $this->database->lastInsertId();
            $operationId = $this->queue->enqueue('application.restore', ['application_restore_run_id' => $restoreId]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => (int) $application['repository_id'], 'id' => $operationId]
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->findByUuid($uuid) ?? [];
    }

    public function execute(int $restoreRunId): void
    {
        $run = $this->database->fetchOne(
            'SELECT ar.*, sa.manifest_path, br.snapshot_id, r.path AS repository_path, r.password_file, r.status AS repository_status ' .
            'FROM application_restore_runs ar ' .
            'JOIN snapshot_applications sa ON sa.id = ar.snapshot_application_id ' .
            'JOIN backup_runs br ON br.id = ar.backup_run_id ' .
            'JOIN repositories r ON r.id = ar.repository_id ' .
            'WHERE ar.id = :id',
            ['id' => $restoreRunId]
        );
        if ($run === null) {
            throw new RuntimeException('Application restore run not found.');
        }
        if ($run['repository_status'] !== 'ready') {
            throw new RuntimeException('Snapshot repository is not ready.');
        }

        $selectedMounts = json_decode((string) $run['selected_mounts_json'], true);
        $selectedMounts = is_array($selectedMounts) ? array_values($selectedMounts) : [];

        $repositoryLogicalPath = (string) $run['repository_path'];
        $repositoryPath = $this->paths->toContainerPath($repositoryLogicalPath);
        $stagingLogicalPath = $this->validateStagingTarget((string) $run['staging_path'], $repositoryLogicalPath);
        $stagingPath = $this->paths->toContainerPath($stagingLogicalPath);
        $passwordFile = (string) $run['password_file'];

        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key is missing or unreadable.');
        }
        $this->assertNoSymlinkComponents($stagingPath, $stagingLogicalPath);
        if (file_exists($stagingPath) && !is_dir($stagingPath)) {
            throw new RuntimeException('Application restore staging target exists and is not a directory.');
        }
        if (!is_dir($stagingPath) && !mkdir($stagingPath, 0700, true) && !is_dir($stagingPath)) {
            throw new RuntimeException('Unable to create the application restore staging directory.');
        }
        $entries = array_values(array_diff(scandir($stagingPath) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new RuntimeException('Application restore staging directory must be empty.');
        }

        $this->database->execute(
            "UPDATE application_restore_runs SET status = 'running', started_at = :started_at, finished_at = NULL, error = NULL, progress_percent = 0 WHERE id = :id",
            ['started_at' => date('c'), 'id' => $restoreRunId]
        );

        $includes = [];
        foreach ($selectedMounts as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $source = $this->validateRestoreSource((string) ($mount['source'] ?? ''), $repositoryLogicalPath);
            $includes[$source] = $source;
        }
        $manifestPath = trim((string) $run['manifest_path']);
        if ($manifestPath !== '') {
            $manifestPath = '/' . ltrim($manifestPath, '/');
            $includes[$manifestPath] = $manifestPath;
        }

        $lastUpdateAt = 0.0;
        $summary = $this->restic->restore(
            $repositoryPath,
            $passwordFile,
            (string) $run['snapshot_id'],
            $stagingPath,
            'never',
            function (array $message) use ($restoreRunId, &$lastUpdateAt): void {
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
                    'UPDATE application_restore_runs SET progress_percent = :progress, total_files = :total_files, files_restored = :files_restored, total_bytes = :total_bytes, bytes_restored = :bytes_restored, error_count = :error_count WHERE id = :id',
                    [
                        'progress' => $percent,
                        'total_files' => (int) ($message['total_files'] ?? 0),
                        'files_restored' => (int) ($message['files_restored'] ?? 0),
                        'total_bytes' => (int) ($message['total_bytes'] ?? 0),
                        'bytes_restored' => (int) ($message['bytes_restored'] ?? 0),
                        'error_count' => (int) ($message['error_count'] ?? 0),
                        'id' => $restoreRunId,
                    ]
                );
            },
            array_values($includes)
        );

        $rawManifest = $this->restic->dumpSnapshotFile(
            $repositoryPath,
            $passwordFile,
            (string) $run['snapshot_id'],
            (string) $run['manifest_path']
        );
        try {
            $manifest = json_decode($rawManifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The application manifest is invalid JSON.', 0, $exception);
        }
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'zimabackup.application-manifest.v1') {
            throw new RuntimeException('The application manifest has an unsupported schema.');
        }

        $compose = $this->composePreview->build($manifest)['compose'];
        $composePath = rtrim($stagingPath, '/') . '/docker-compose.yml';
        if (file_put_contents($composePath, $compose, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the reconstructed Docker Compose file.');
        }
        chmod($composePath, 0600);

        $appliedPaths = [];
        if ($run['mode'] === 'original') {
            if ($this->isApplicationPresentInDocker((string) $run['app_key'])) {
                throw new RuntimeException('The application is currently present in Docker. Original-path restore was stopped to avoid modifying a live deployment.');
            }

            $originalPlan = [];
            foreach ($selectedMounts as $mount) {
                if (!is_array($mount)) {
                    continue;
                }
                $logicalSource = $this->validateRestoreSource((string) ($mount['source'] ?? ''), $repositoryLogicalPath);
                $stagedSource = rtrim($stagingPath, '/') . $logicalSource;
                $target = $this->paths->toContainerPath($logicalSource);
                $this->assertOriginalTargetSafe($stagedSource, $target, $logicalSource);
                $originalPlan[] = [$stagedSource, $target, $logicalSource];
            }

            // Preflight every target before writing any original path. This avoids
            // restoring one mount and only then discovering that a later mount
            // already contains data.
            foreach ($originalPlan as [$stagedSource, $target, $logicalSource]) {
                $this->applyStagedPath($stagedSource, $target, $logicalSource);
                $appliedPaths[] = $logicalSource;
                $this->database->execute(
                    'UPDATE application_restore_runs SET applied_paths_json = :applied_paths_json WHERE id = :id',
                    [
                        'applied_paths_json' => json_encode($appliedPaths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'id' => $restoreRunId,
                    ]
                );
            }
        }

        $this->database->execute(
            "UPDATE application_restore_runs SET status = 'success', compose_path = :compose_path, applied_paths_json = :applied_paths_json, progress_percent = 100, total_files = :total_files, files_restored = :files_restored, total_bytes = :total_bytes, bytes_restored = :bytes_restored, finished_at = :finished_at, error = NULL WHERE id = :id",
            [
                'compose_path' => $stagingLogicalPath . '/docker-compose.yml',
                'applied_paths_json' => json_encode($appliedPaths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'total_files' => (int) ($summary['total_files'] ?? 0),
                'files_restored' => (int) ($summary['files_restored'] ?? 0),
                'total_bytes' => (int) ($summary['total_bytes'] ?? 0),
                'bytes_restored' => (int) ($summary['bytes_restored'] ?? 0),
                'finished_at' => date('c'),
                'id' => $restoreRunId,
            ]
        );
    }

    public function markFailed(int $restoreRunId, string $error): void
    {
        $this->database->execute(
            "UPDATE application_restore_runs SET status = 'failed', finished_at = :finished_at, error = :error WHERE id = :id",
            ['finished_at' => date('c'), 'error' => substr($error, 0, 4000), 'id' => $restoreRunId]
        );
    }

    private function validateRestoreSource(string $source, string $repositoryPath): string
    {
        $source = $this->paths->normalizeLogicalPath($source);
        $this->paths->toContainerPath($source);
        if (in_array($source, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException('Application restore refuses root-level /DATA or /media mounts.');
        }
        if ($this->paths->overlaps($source, $repositoryPath)) {
            throw new InvalidArgumentException('Application data path overlaps the repository and cannot be restored safely.');
        }
        return $source;
    }

    private function validateStagingTarget(string $path, string $repositoryPath): string
    {
        $path = $this->paths->normalizeLogicalPath($path);
        $this->paths->toContainerPath($path);
        if (!str_starts_with($path, '/DATA/ZimaBackup/ApplicationRestores/')) {
            throw new InvalidArgumentException('Application restore staging must stay under /DATA/ZimaBackup/ApplicationRestores.');
        }
        if ($this->paths->overlaps($path, $repositoryPath)) {
            throw new InvalidArgumentException('Application restore staging cannot overlap the repository.');
        }
        return $path;
    }

    private function assertOriginalTargetSafe(string $stagedSource, string $target, string $logicalTarget): void
    {
        if (!file_exists($stagedSource) && !is_link($stagedSource)) {
            throw new RuntimeException(sprintf('The staged application data is missing: %s', $logicalTarget));
        }
        if (is_link($stagedSource)) {
            throw new RuntimeException(sprintf('The staged application root is a symbolic link and cannot be applied automatically: %s', $logicalTarget));
        }

        $this->assertNoSymlinkComponents($target, $logicalTarget);
        if (is_link($target)) {
            throw new RuntimeException(sprintf('Original path is a symbolic link. Nothing was overwritten: %s', $logicalTarget));
        }
        if (!file_exists($target)) {
            return;
        }
        if (!is_dir($target)) {
            throw new RuntimeException(sprintf('Original path already exists and is not a directory: %s', $logicalTarget));
        }
        $entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new RuntimeException(sprintf('Original path is not empty. Nothing was overwritten: %s', $logicalTarget));
        }
    }

    private function applyStagedPath(string $stagedSource, string $target, string $logicalTarget): void
    {
        // Copy beside the final destination first, then rename into place. That
        // keeps each individual mount atomic on the destination filesystem.
        $parent = dirname($target);
        $this->assertNoSymlinkComponents($parent, $logicalTarget);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create parent directory for %s', $logicalTarget));
        }

        $temporary = $parent . '/.zimabackup-restore-' . bin2hex(random_bytes(6));
        try {
            $process = new Process(['cp', '-a', '--', $stagedSource, $temporary]);
            $process->setTimeout(null);
            $process->mustRun();

            if (file_exists($target)) {
                $entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
                if ($entries !== [] || !rmdir($target)) {
                    throw new RuntimeException(sprintf('Original path changed during restore and was not replaced: %s', $logicalTarget));
                }
            }

            if (!rename($temporary, $target)) {
                throw new RuntimeException(sprintf('Unable to atomically place restored application data at %s', $logicalTarget));
            }
        } finally {
            if (file_exists($temporary) || is_link($temporary)) {
                $cleanup = new Process(['rm', '-rf', '--', $temporary]);
                $cleanup->setTimeout(60);
                $cleanup->run();
            }
        }
    }

    private function assertNoSymlinkComponents(string $path, string $logicalLabel): void
    {
        $cursor = rtrim($path, '/');
        while ($cursor !== '' && $cursor !== '/') {
            if (is_link($cursor)) {
                throw new RuntimeException(sprintf('Restore path contains a symbolic link and was refused: %s', $logicalLabel));
            }
            if (in_array($cursor, ['/DATA', '/media'], true)) {
                break;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }

    private function isApplicationPresentInDocker(string $appKey): bool
    {
        foreach ($this->docker->containers(true) as $container) {
            if (!is_array($container)) {
                continue;
            }
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            $project = trim((string) ($labels['com.docker.compose.project'] ?? ''));
            $id = (string) ($container['Id'] ?? '');
            $candidate = $project !== '' ? 'compose:' . $project : ($id !== '' ? 'container:' . $id : '');
            if ($candidate !== '' && hash_equals($appKey, $candidate)) {
                return true;
            }
        }
        return false;
    }

}
