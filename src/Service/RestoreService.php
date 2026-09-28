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

final class RestoreService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
        private readonly SettingsService $settings,
    ) {
    }

    public function all(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT rr.*, bj.name AS job_name, r.name AS repository_name ' .
            'FROM restore_runs rr ' .
            'JOIN backup_runs br ON br.id = rr.backup_run_id ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = rr.repository_id ' .
            'ORDER BY rr.id DESC LIMIT 100'
        );
        foreach ($rows as &$row) {
            $row['selected_paths'] = $this->decodeSelectedPaths((string) ($row['selected_paths_json'] ?? '[]'));
        }
        unset($row);
        return $rows;
    }

    public function snapshotByBackupRunId(int $backupRunId): ?array
    {
        return $this->database->fetchOne(
            'SELECT br.*, bj.uuid AS job_uuid, bj.name AS job_name, bj.repository_id, ' .
            'r.name AS repository_name, r.path AS repository_path, r.status AS repository_status ' .
            'FROM backup_runs br ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id ' .
            "WHERE br.id = :id AND br.snapshot_id IS NOT NULL AND br.status IN ('success', 'warning') AND COALESCE(br.snapshot_state, 'present') = 'present'",
            ['id' => $backupRunId]
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT rr.*, bj.uuid AS job_uuid, bj.name AS job_name, r.name AS repository_name, r.path AS repository_path ' .
            'FROM restore_runs rr ' .
            'JOIN backup_runs br ON br.id = rr.backup_run_id ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = rr.repository_id ' .
            'WHERE rr.uuid = :uuid',
            ['uuid' => $uuid]
        );
        if ($row !== null) {
            $row['selected_paths'] = $this->decodeSelectedPaths((string) ($row['selected_paths_json'] ?? '[]'));
        }
        return $row;
    }

    public function defaultTarget(array $snapshot): string
    {
        $name = strtolower((string) ($snapshot['job_name'] ?? 'snapshot'));
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?: 'snapshot';
        $name = trim($name, '-');
        $shortSnapshot = substr((string) ($snapshot['snapshot_id'] ?? 'restore'), 0, 8);

        $root = rtrim((string) $this->settings->get('restore.default_root', '/DATA/ZimaBackup/Restores'), '/');
        return sprintf('%s/%s-%s', $root, $name ?: 'snapshot', $shortSnapshot);
    }

    /**
     * Queue a safe restore into a dedicated target directory.
     * If $selectedPaths is empty, the whole snapshot is restored. Otherwise only
     * the selected absolute paths inside /DATA or /media are included.
     */
    public function enqueue(int $backupRunId, string $targetPath, array $selectedPaths = []): array
    {
        $snapshot = $this->snapshotByBackupRunId($backupRunId);
        if ($snapshot === null) {
            throw new InvalidArgumentException('Snapshot not found or not restorable.');
        }
        if ($snapshot['repository_status'] !== 'ready') {
            throw new InvalidArgumentException('The snapshot repository is not ready.');
        }

        $targetPath = $this->validateTarget($targetPath, (string) $snapshot['repository_path']);
        $selectedPaths = $this->normalizeSelectedPaths($selectedPaths);
        $selectionMode = $selectedPaths === [] ? 'full' : 'selected';

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $active = (int) $this->database->scalar(
                "SELECT COUNT(*) FROM restore_runs WHERE target_path = :target_path AND status IN ('pending', 'running')",
                ['target_path' => $targetPath]
            );
            if ($active > 0) {
                throw new InvalidArgumentException('A restore is already queued or running for this target path.');
            }

            $uuid = Uuid::v4();
            $this->database->execute(
                'INSERT INTO restore_runs(uuid, backup_run_id, repository_id, snapshot_id, target_path, overwrite_mode, status, progress_percent, selection_mode, selected_paths_json, created_at) ' .
                "VALUES (:uuid, :backup_run_id, :repository_id, :snapshot_id, :target_path, 'never', 'pending', 0, :selection_mode, :selected_paths_json, :created_at)",
                [
                    'uuid' => $uuid,
                    'backup_run_id' => $backupRunId,
                    'repository_id' => (int) $snapshot['repository_id'],
                    'snapshot_id' => (string) $snapshot['snapshot_id'],
                    'target_path' => $targetPath,
                    'selection_mode' => $selectionMode,
                    'selected_paths_json' => json_encode($selectedPaths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => date('c'),
                ]
            );
            $restoreRunId = $this->database->lastInsertId();

            $operationId = $this->queue->enqueue('restore.run', [
                'restore_run_id' => $restoreRunId,
            ]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => (int) $snapshot['repository_id'], 'id' => $operationId]
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

    /** Execute one queued restore from the privileged worker only. */
    public function execute(int $restoreRunId): void
    {
        $run = $this->database->fetchOne(
            'SELECT rr.*, r.path AS repository_path, r.password_file, r.status AS repository_status ' .
            'FROM restore_runs rr JOIN repositories r ON r.id = rr.repository_id WHERE rr.id = :id',
            ['id' => $restoreRunId]
        );
        if ($run === null) {
            throw new RuntimeException('Restore run not found.');
        }
        if ($run['repository_status'] !== 'ready') {
            throw new RuntimeException('Snapshot repository is not ready.');
        }

        $repositoryLogicalPath = (string) $run['repository_path'];
        $repositoryPath = $this->paths->toContainerPath($repositoryLogicalPath);
        $targetLogicalPath = $this->validateTarget((string) $run['target_path'], $repositoryLogicalPath);
        $targetPath = $this->paths->toContainerPath($targetLogicalPath);
        $passwordFile = (string) $run['password_file'];
        $selectedPaths = $this->decodeSelectedPaths((string) ($run['selected_paths_json'] ?? '[]'));
        $selectedPaths = $this->normalizeSelectedPaths($selectedPaths);

        if (!is_file($passwordFile) || !is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key file is missing or unreadable.');
        }

        if (file_exists($targetPath) && !is_dir($targetPath)) {
            throw new RuntimeException('Restore target exists and is not a directory.');
        }

        $this->assertNoSymlinkComponents($targetPath, $targetLogicalPath);
        if (!is_dir($targetPath) && !mkdir($targetPath, 0770, true) && !is_dir($targetPath)) {
            throw new RuntimeException(sprintf('Unable to create restore target: %s', $targetLogicalPath));
        }

        $entries = array_values(array_diff(scandir($targetPath) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new RuntimeException('Safe restore requires an empty target directory. Choose a new folder.');
        }

        $this->database->execute(
            "UPDATE restore_runs SET status = 'running', started_at = :started_at, finished_at = NULL, error = NULL, progress_percent = 0 WHERE id = :id",
            ['started_at' => date('c'), 'id' => $restoreRunId]
        );

        $lastUpdateAt = 0.0;
        $summary = $this->restic->restore(
            $repositoryPath,
            $passwordFile,
            (string) $run['snapshot_id'],
            $targetPath,
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
                    'UPDATE restore_runs SET progress_percent = :progress, total_files = :total_files, files_restored = :files_restored, ' .
                    'files_skipped = :files_skipped, total_bytes = :total_bytes, bytes_restored = :bytes_restored, bytes_skipped = :bytes_skipped, ' .
                    'error_count = :error_count WHERE id = :id',
                    [
                        'progress' => $percent,
                        'total_files' => (int) ($message['total_files'] ?? 0),
                        'files_restored' => (int) ($message['files_restored'] ?? 0),
                        'files_skipped' => (int) ($message['files_skipped'] ?? 0),
                        'total_bytes' => (int) ($message['total_bytes'] ?? 0),
                        'bytes_restored' => (int) ($message['bytes_restored'] ?? 0),
                        'bytes_skipped' => (int) ($message['bytes_skipped'] ?? 0),
                        'error_count' => (int) ($message['error_count'] ?? 0),
                        'id' => $restoreRunId,
                    ]
                );
            },
            $selectedPaths
        );

        $this->database->execute(
            "UPDATE restore_runs SET status = 'success', progress_percent = 100, total_files = :total_files, files_restored = :files_restored, " .
            'files_skipped = :files_skipped, total_bytes = :total_bytes, bytes_restored = :bytes_restored, bytes_skipped = :bytes_skipped, ' .
            'finished_at = :finished_at, error = NULL WHERE id = :id',
            [
                'total_files' => (int) ($summary['total_files'] ?? 0),
                'files_restored' => (int) ($summary['files_restored'] ?? 0),
                'files_skipped' => (int) ($summary['files_skipped'] ?? 0),
                'total_bytes' => (int) ($summary['total_bytes'] ?? 0),
                'bytes_restored' => (int) ($summary['bytes_restored'] ?? 0),
                'bytes_skipped' => (int) ($summary['bytes_skipped'] ?? 0),
                'finished_at' => date('c'),
                'id' => $restoreRunId,
            ]
        );
    }

    public function enqueueCleanup(string $uuid): void
    {
        $run = $this->findByUuid($uuid);
        if ($run === null) {
            throw new InvalidArgumentException('Restore not found.');
        }
        if (!in_array($run['status'], ['success', 'failed'], true)) {
            throw new InvalidArgumentException('Wait for the restore to finish before cleaning its target.');
        }
        if (!empty($run['cleaned_at'])) {
            throw new InvalidArgumentException('This restore target has already been cleaned.');
        }
        if (in_array((string) ($run['cleanup_status'] ?? ''), ['queued', 'running'], true)) {
            throw new InvalidArgumentException('Restore cleanup is already queued or running.');
        }

        $this->database->execute("UPDATE restore_runs SET cleanup_status = 'queued', cleanup_error = NULL WHERE id = :id", ['id' => (int) $run['id']]);
        $operationId = $this->queue->enqueue('restore.cleanup', ['restore_run_id' => (int) $run['id']]);
        $this->database->execute(
            'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
            ['repository_id' => (int) $run['repository_id'], 'id' => $operationId]
        );
    }

    public function executeCleanup(int $restoreRunId): void
    {
        $run = $this->database->fetchOne(
            'SELECT rr.*, r.path AS repository_path FROM restore_runs rr JOIN repositories r ON r.id = rr.repository_id WHERE rr.id = :id',
            ['id' => $restoreRunId]
        );
        if ($run === null) {
            throw new RuntimeException('Restore run not found.');
        }
        if (!in_array($run['status'], ['success', 'failed'], true)) {
            throw new RuntimeException('Restore target cannot be cleaned while the restore is active.');
        }

        $this->database->execute("UPDATE restore_runs SET cleanup_status = 'running', cleanup_error = NULL WHERE id = :id", ['id' => $restoreRunId]);
        $logical = $this->validateTarget((string) $run['target_path'], (string) $run['repository_path']);
        $target = $this->paths->toContainerPath($logical);
        $this->assertNoSymlinkComponents($target, $logical);

        if (file_exists($target) || is_link($target)) {
            $process = new Process(['rm', '-rf', '--', $target]);
            $process->setTimeout(null);
            $process->mustRun();
        }

        $this->database->execute(
            "UPDATE restore_runs SET cleanup_status = 'success', cleaned_at = :cleaned_at, cleanup_error = NULL WHERE id = :id",
            ['cleaned_at' => date('c'), 'id' => $restoreRunId]
        );
    }

    public function markCleanupFailed(int $restoreRunId, string $error): void
    {
        $this->database->execute(
            "UPDATE restore_runs SET cleanup_status = 'failed', cleanup_error = :error WHERE id = :id",
            ['error' => substr($error, 0, 4000), 'id' => $restoreRunId]
        );
    }

    public function markFailed(int $restoreRunId, string $error): void
    {
        $this->database->execute(
            "UPDATE restore_runs SET status = 'failed', finished_at = :finished_at, error = :error WHERE id = :id",
            [
                'finished_at' => date('c'),
                'error' => substr($error, 0, 4000),
                'id' => $restoreRunId,
            ]
        );
    }

    /** @return list<string> */
    private function normalizeSelectedPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\')) {
                throw new InvalidArgumentException('Invalid selected snapshot path.');
            }
            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    throw new InvalidArgumentException('Snapshot path traversal is not allowed.');
                }
                $segments[] = $segment;
            }
            $candidate = '/' . implode('/', $segments);
            if (!($candidate === '/DATA' || str_starts_with($candidate, '/DATA/') || $candidate === '/media' || str_starts_with($candidate, '/media/'))) {
                throw new InvalidArgumentException('Selected restore paths must stay under /DATA or /media.');
            }
            $normalized[$candidate] = true;
            if (count($normalized) > 100) {
                throw new InvalidArgumentException('Select at most 100 files or folders in one restore.');
            }
        }
        return array_keys($normalized);
    }

    /** @return list<string> */
    private function decodeSelectedPaths(string $json): array
    {
        try {
            $paths = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }

    private function validateTarget(string $targetPath, string $repositoryPath): string
    {
        $targetPath = $this->paths->normalizeLogicalPath($targetPath);
        $this->paths->toContainerPath($targetPath);

        if (in_array($targetPath, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException('Choose a dedicated restore subdirectory, not the root of /DATA or /media.');
        }

        if ($this->paths->overlaps($targetPath, $repositoryPath)) {
            throw new InvalidArgumentException('Restore target cannot overlap the repository itself.');
        }

        return $targetPath;
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
}
