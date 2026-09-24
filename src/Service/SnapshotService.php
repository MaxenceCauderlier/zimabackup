<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;

final class SnapshotService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
    ) {
    }

    public function all(): array
    {
        return $this->database->fetchAll(
            "SELECT br.*, bj.uuid AS job_uuid, bj.name AS job_name, bj.deleted_at AS job_deleted_at, r.name AS repository_name, " .
            "(SELECT COUNT(*) FROM backup_applications ba WHERE ba.backup_job_id = bj.id) AS app_count, " .
            "(SELECT status FROM snapshot_application_scans sas WHERE sas.backup_run_id = br.id) AS app_scan_status " .
            "FROM backup_runs br " .
            "JOIN backup_jobs bj ON bj.id = br.backup_job_id " .
            "JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE br.status IN ('success', 'warning') AND br.snapshot_id IS NOT NULL " .
            "AND COALESCE(br.snapshot_state, 'present') IN ('present', 'forgetting', 'unavailable') " .
            "ORDER BY br.finished_at DESC LIMIT 100"
        );
    }

    public function enqueueForget(int $runId): void
    {
        $snapshot = $this->find($runId);
        if ($snapshot === null) {
            throw new InvalidArgumentException('Snapshot not found.');
        }
        if (($snapshot['snapshot_state'] ?? 'present') !== 'present') {
            throw new InvalidArgumentException('This snapshot is already being removed or has been forgotten.');
        }

        $activeRestores = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM restore_runs WHERE backup_run_id = :id AND status IN ('pending', 'running')",
            ['id' => $runId]
        );
        $activeAppRestores = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM application_restore_runs WHERE backup_run_id = :id AND status IN ('pending', 'running')",
            ['id' => $runId]
        );
        if ($activeRestores + $activeAppRestores > 0) {
            throw new InvalidArgumentException('Wait for active restores from this snapshot to finish before forgetting it.');
        }

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->database->execute(
                "UPDATE backup_runs SET snapshot_state = 'forgetting', snapshot_forget_error = NULL WHERE id = :id",
                ['id' => $runId]
            );
            $operationId = $this->queue->enqueue('snapshot.forget', ['backup_run_id' => $runId]);
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
    }

    public function executeForget(int $runId): void
    {
        $snapshot = $this->find($runId);
        if ($snapshot === null) {
            throw new RuntimeException('Snapshot not found.');
        }

        $repositoryPath = $this->paths->toContainerPath((string) $snapshot['repository_path']);
        $passwordFile = (string) $snapshot['password_file'];
        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key is missing or unreadable.');
        }

        $this->restic->forgetSnapshot($repositoryPath, $passwordFile, (string) $snapshot['snapshot_id']);
        $this->database->execute(
            "UPDATE backup_runs SET snapshot_state = 'forgotten', forgotten_at = :forgotten_at, snapshot_forget_error = NULL WHERE id = :id",
            ['forgotten_at' => date('c'), 'id' => $runId]
        );
    }

    public function markForgetFailed(int $runId, string $error): void
    {
        $this->database->execute(
            "UPDATE backup_runs SET snapshot_state = 'present', snapshot_forget_error = :error WHERE id = :id",
            ['error' => substr($error, 0, 4000), 'id' => $runId]
        );
    }

    private function find(int $runId): ?array
    {
        return $this->database->fetchOne(
            'SELECT br.*, r.id AS repository_id, r.path AS repository_path, r.password_file ' .
            'FROM backup_runs br JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id WHERE br.id = :id AND br.snapshot_id IS NOT NULL',
            ['id' => $runId]
        );
    }
}
