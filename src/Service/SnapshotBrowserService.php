<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;

final class SnapshotBrowserService
{
    private const MAX_ENTRIES_PER_DIRECTORY = 1000;

    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
    ) {
    }

    public function snapshot(int $runId): ?array
    {
        return $this->database->fetchOne(
            'SELECT br.*, bj.name AS job_name, bj.uuid AS job_uuid, r.id AS repository_id, r.name AS repository_name, ' .
            'r.path AS repository_path, r.password_file, r.status AS repository_status, ' .
            '(SELECT COUNT(*) FROM backup_applications ba WHERE ba.backup_job_id = bj.id) AS configured_app_count ' .
            'FROM backup_runs br ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id ' .
            "WHERE br.id = :id AND br.snapshot_id IS NOT NULL AND br.status IN ('success', 'warning') " .
            "AND COALESCE(br.snapshot_state, 'present') = 'present'",
            ['id' => $runId]
        );
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Invalid snapshot path.');
        }
        if ($path[0] !== '/') {
            throw new InvalidArgumentException('Snapshot paths must be absolute.');
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

        $normalized = '/' . implode('/', $segments);
        if (!$this->isVisiblePath($normalized)) {
            throw new InvalidArgumentException('Only /DATA and /media content is exposed in the recovery browser.');
        }

        return $normalized;
    }

    /** @return list<string> */
    public function normalizeSelection(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $candidate = $this->normalizePath($path);
            if ($candidate === '/') {
                throw new InvalidArgumentException('Select /DATA, /media, or a file/folder inside them instead of the snapshot root.');
            }
            $normalized[$candidate] = true;
            if (count($normalized) > 100) {
                throw new InvalidArgumentException('Select at most 100 files or folders in one restore.');
            }
        }
        return array_keys($normalized);
    }

    public function listing(int $runId, string $path): ?array
    {
        $path = $this->normalizePath($path);
        $row = $this->database->fetchOne(
            'SELECT * FROM snapshot_browser_cache WHERE backup_run_id = :run_id AND path = :path',
            ['run_id' => $runId, 'path' => $path]
        );
        if ($row === null) {
            return null;
        }
        $entries = json_decode((string) $row['entries_json'], true);
        $row['entries'] = is_array($entries) ? array_values($entries) : [];
        return $row;
    }

    public function enqueue(int $runId, string $path, bool $force = false): bool
    {
        $snapshot = $this->snapshot($runId);
        if ($snapshot === null) {
            throw new InvalidArgumentException('Snapshot not found or unavailable.');
        }
        if ($snapshot['repository_status'] !== 'ready') {
            throw new InvalidArgumentException('The snapshot repository is not ready.');
        }
        $path = $this->normalizePath($path);

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = $this->database->fetchOne(
                'SELECT * FROM snapshot_browser_cache WHERE backup_run_id = :run_id AND path = :path',
                ['run_id' => $runId, 'path' => $path]
            );
            if (!$force && $existing !== null && in_array($existing['status'], ['queued', 'loading', 'ready'], true)) {
                $pdo->commit();
                return false;
            }
            if ($existing !== null && in_array($existing['status'], ['queued', 'loading'], true)) {
                $pdo->commit();
                return false;
            }

            $now = date('c');
            if ($existing === null) {
                $this->database->execute(
                    "INSERT INTO snapshot_browser_cache(backup_run_id, path, status, entries_json, entry_count, truncated, created_at, updated_at) " .
                    "VALUES (:run_id, :path, 'queued', '[]', 0, 0, :created_at, :updated_at)",
                    ['run_id' => $runId, 'path' => $path, 'created_at' => $now, 'updated_at' => $now]
                );
            } else {
                $this->database->execute(
                    "UPDATE snapshot_browser_cache SET status = 'queued', error = NULL, updated_at = :updated_at WHERE backup_run_id = :run_id AND path = :path",
                    ['updated_at' => $now, 'run_id' => $runId, 'path' => $path]
                );
            }

            $operationId = $this->queue->enqueue('snapshot.browse', [
                'backup_run_id' => $runId,
                'path' => $path,
            ]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => (int) $snapshot['repository_id'], 'id' => $operationId]
            );
            $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function execute(int $runId, string $path): void
    {
        $snapshot = $this->snapshot($runId);
        if ($snapshot === null) {
            throw new RuntimeException('Snapshot not found.');
        }
        if ($snapshot['repository_status'] !== 'ready') {
            throw new RuntimeException('Repository is not ready.');
        }
        $path = $this->normalizePath($path);
        $passwordFile = (string) $snapshot['password_file'];
        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key is missing or unreadable.');
        }

        $this->database->execute(
            "UPDATE snapshot_browser_cache SET status = 'loading', error = NULL, updated_at = :updated_at WHERE backup_run_id = :run_id AND path = :path",
            ['updated_at' => date('c'), 'run_id' => $runId, 'path' => $path]
        );

        try {
            $result = $this->restic->listSnapshotDirectory(
                $this->paths->toContainerPath((string) $snapshot['repository_path']),
                $passwordFile,
                (string) $snapshot['snapshot_id'],
                $path,
                self::MAX_ENTRIES_PER_DIRECTORY
            );

            $this->database->execute(
                "UPDATE snapshot_browser_cache SET status = 'ready', entries_json = :entries, entry_count = :entry_count, " .
                "truncated = :truncated, error = NULL, updated_at = :updated_at WHERE backup_run_id = :run_id AND path = :path",
                [
                    'entries' => json_encode($result['entries'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'entry_count' => (int) $result['count'],
                    'truncated' => !empty($result['truncated']) ? 1 : 0,
                    'updated_at' => date('c'),
                    'run_id' => $runId,
                    'path' => $path,
                ]
            );
        } catch (Throwable $exception) {
            $this->markFailed($runId, $path, $exception->getMessage());
            throw $exception;
        }
    }

    public function markFailed(int $runId, string $path, string $error): void
    {
        $path = $this->normalizePath($path);
        $this->database->execute(
            "UPDATE snapshot_browser_cache SET status = 'failed', error = :error, updated_at = :updated_at WHERE backup_run_id = :run_id AND path = :path",
            [
                'error' => substr($error, 0, 4000),
                'updated_at' => date('c'),
                'run_id' => $runId,
                'path' => $path,
            ]
        );
    }

    /** @return list<array{label:string,path:string}> */
    public function breadcrumbs(string $path): array
    {
        $path = $this->normalizePath($path);
        $crumbs = [['label' => 'Snapshot', 'path' => '/']];
        if ($path === '/') {
            return $crumbs;
        }
        $cursor = '';
        foreach (array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')) as $segment) {
            $cursor .= '/' . $segment;
            $crumbs[] = ['label' => $segment, 'path' => $cursor];
        }
        return $crumbs;
    }

    private function isVisiblePath(string $path): bool
    {
        return $path === '/' || $path === '/DATA' || str_starts_with($path, '/DATA/') || $path === '/media' || str_starts_with($path, '/media/');
    }
}
