<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;

final class SnapshotApplicationService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
        private readonly ComposePreviewService $composePreview,
    ) {
    }

    public function snapshot(int $runId): ?array
    {
        return $this->database->fetchOne(
            "SELECT br.*, bj.name AS job_name, bj.uuid AS job_uuid, bj.id AS job_id, r.name AS repository_name, " .
            "r.id AS repository_id, r.path AS repository_path, r.password_file, r.status AS repository_status, " .
            "(SELECT COUNT(*) FROM backup_applications ba WHERE ba.backup_job_id = bj.id) AS configured_app_count " .
            "FROM backup_runs br " .
            "JOIN backup_jobs bj ON bj.id = br.backup_job_id " .
            "JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE br.id = :id AND br.status IN ('success', 'warning') AND br.snapshot_id IS NOT NULL",
            ['id' => $runId]
        );
    }

    public function scan(int $runId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM snapshot_application_scans WHERE backup_run_id = :run_id',
            ['run_id' => $runId]
        );
    }

    public function applications(int $runId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM snapshot_applications WHERE backup_run_id = :run_id ORDER BY name COLLATE NOCASE ASC',
            ['run_id' => $runId]
        );
        foreach ($rows as &$row) {
            $warnings = json_decode((string) $row['warnings_json'], true);
            $manifest = json_decode((string) $row['manifest_preview_json'], true);
            $row['warnings'] = is_array($warnings) ? $warnings : [];
            $row['manifest_preview'] = is_array($manifest) ? $manifest : [];
        }
        unset($row);
        return $rows;
    }

    public function enqueueInspection(int $runId, bool $force = false): bool
    {
        $snapshot = $this->snapshot($runId);
        if ($snapshot === null) {
            throw new RuntimeException('Snapshot not found or unavailable for application inspection.');
        }

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $scan = $this->scan($runId);
            if (!$force && $scan !== null && in_array($scan['status'], ['queued', 'scanning', 'ready'], true)) {
                $pdo->commit();
                return false;
            }
            if ($scan !== null && in_array($scan['status'], ['queued', 'scanning'], true)) {
                $pdo->commit();
                return false;
            }

            $now = date('c');
            if ($scan === null) {
                $this->database->execute(
                    "INSERT INTO snapshot_application_scans(backup_run_id, status, application_count, created_at) VALUES (:run_id, 'queued', 0, :created_at)",
                    ['run_id' => $runId, 'created_at' => $now]
                );
            } else {
                $this->database->execute(
                    "UPDATE snapshot_application_scans SET status = 'queued', application_count = 0, error = NULL, started_at = NULL, finished_at = NULL WHERE backup_run_id = :run_id",
                    ['run_id' => $runId]
                );
            }

            $operationId = $this->queue->enqueue('snapshot.apps.inspect', ['backup_run_id' => $runId]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => $snapshot['repository_id'], 'id' => $operationId]
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

    public function inspect(int $runId): int
    {
        $snapshot = $this->snapshot($runId);
        if ($snapshot === null) {
            throw new RuntimeException('Snapshot not found.');
        }
        if ($snapshot['repository_status'] !== 'ready') {
            throw new RuntimeException('Repository is not ready.');
        }

        $repositoryPath = $this->paths->toContainerPath((string) $snapshot['repository_path']);
        $passwordFile = (string) $snapshot['password_file'];
        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key is missing or unreadable.');
        }

        $this->database->execute(
            "UPDATE snapshot_application_scans SET status = 'scanning', started_at = :started_at, finished_at = NULL, error = NULL WHERE backup_run_id = :run_id",
            ['started_at' => date('c'), 'run_id' => $runId]
        );

        try {
            $nodes = $this->restic->findSnapshotFiles(
                $repositoryPath,
                $passwordFile,
                (string) $snapshot['snapshot_id'],
                '/storage/manifests/',
                '.json'
            );

            $manifestPaths = array_values(array_map(
                static fn (array $node): string => (string) ($node['path'] ?? ''),
                $nodes
            ));

            $found = [];
            foreach (array_values(array_unique($manifestPaths)) as $manifestPath) {
                $raw = $this->restic->dumpSnapshotFile(
                    $repositoryPath,
                    $passwordFile,
                    (string) $snapshot['snapshot_id'],
                    $manifestPath
                );

                try {
                    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }
                if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'zimabackup.application-manifest.v1') {
                    continue;
                }

                $sanitized = $this->composePreview->sanitizeManifest($manifest);
                $preview = $this->composePreview->build($sanitized);
                $containers = is_array($manifest['containers'] ?? null) ? $manifest['containers'] : [];
                $firstContainer = is_array($containers[0] ?? null) ? $containers[0] : [];
                $appKey = trim((string) ($manifest['app_key'] ?? ''));
                if ($appKey === '') {
                    $appKey = 'manifest:' . sha1($manifestPath);
                }
                $found[$appKey] = [
                    'app_key' => $appKey,
                    'name' => (string) ($manifest['name'] ?? $manifest['project_name'] ?? $appKey),
                    'project_name' => $manifest['project_name'] ?? null,
                    'image' => (string) ($firstContainer['image'] ?? ''),
                    'manifest_path' => $manifestPath,
                    'container_count' => count($containers),
                    'compose_preview' => $preview['compose'],
                    'manifest_preview_json' => json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'warnings_json' => json_encode($preview['warnings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ];
            }

            $pdo = $this->database->pdo();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->database->execute('DELETE FROM snapshot_applications WHERE backup_run_id = :run_id', ['run_id' => $runId]);
                $now = date('c');
                foreach ($found as $application) {
                    $this->database->execute(
                        'INSERT INTO snapshot_applications(backup_run_id, app_key, name, project_name, image, manifest_path, container_count, compose_preview, manifest_preview_json, warnings_json, created_at) ' .
                        'VALUES (:backup_run_id, :app_key, :name, :project_name, :image, :manifest_path, :container_count, :compose_preview, :manifest_preview_json, :warnings_json, :created_at)',
                        [
                            'backup_run_id' => $runId,
                            ...$application,
                            'created_at' => $now,
                        ]
                    );
                }
                $this->database->execute(
                    "UPDATE snapshot_application_scans SET status = 'ready', application_count = :count, error = NULL, finished_at = :finished_at WHERE backup_run_id = :run_id",
                    ['count' => count($found), 'finished_at' => $now, 'run_id' => $runId]
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            return count($found);
        } catch (Throwable $exception) {
            $this->markFailed($runId, $exception->getMessage());
            throw $exception;
        }
    }

    public function markFailed(int $runId, string $error): void
    {
        $this->database->execute(
            "UPDATE snapshot_application_scans SET status = 'failed', error = :error, finished_at = :finished_at WHERE backup_run_id = :run_id",
            ['error' => substr($error, 0, 4000), 'finished_at' => date('c'), 'run_id' => $runId]
        );
    }
}
