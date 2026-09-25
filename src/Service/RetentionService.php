<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class RetentionService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
    ) {
    }

    public function enqueueForJobUuid(string $uuid): int
    {
        $job = $this->database->fetchOne(
            'SELECT bj.*, r.id AS repository_id, r.status AS repository_status, r.archived_at AS repository_archived_at ' .
            'FROM backup_jobs bj JOIN repositories r ON r.id = bj.repository_id ' .
            'WHERE bj.uuid = :uuid AND bj.deleted_at IS NULL',
            ['uuid' => $uuid]
        );
        if ($job === null) {
            throw new InvalidArgumentException('Backup job not found.');
        }
        if ((int) $job['retention_enabled'] !== 1) {
            throw new InvalidArgumentException('Retention is disabled for this backup job.');
        }
        return $this->enqueue($job, null);
    }

    /** Queue finished backup runs whose policy has not yet been applied. */
    public function queuePending(int $limit = 5): int
    {
        $rows = $this->database->fetchAll(
            "SELECT br.id AS trigger_run_id, bj.* , r.id AS repository_id, r.status AS repository_status, r.archived_at AS repository_archived_at " .
            "FROM backup_runs br JOIN backup_jobs bj ON bj.id = br.backup_job_id JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE br.retention_state = 'pending' AND br.status IN ('success','warning') " .
            "AND bj.retention_enabled = 1 AND bj.deleted_at IS NULL ORDER BY br.id ASC LIMIT " . max(1, min(20, $limit))
        );

        $queued = 0;
        foreach ($rows as $row) {
            try {
                $this->enqueue($row, (int) $row['trigger_run_id']);
                $queued++;
            } catch (Throwable) {
                // Leave the run pending so a later worker loop can retry.
            }
        }
        return $queued;
    }

    /** Execute one retention run. Only snapshots belonging to this job are touched. */
    public function execute(int $retentionRunId): array
    {
        $run = $this->database->fetchOne(
            'SELECT rr.*, bj.uuid AS job_uuid, bj.name AS job_name, bj.repository_id, ' .
            'r.path AS repository_path, r.password_file, r.status AS repository_status ' .
            'FROM retention_runs rr JOIN backup_jobs bj ON bj.id = rr.backup_job_id ' .
            'JOIN repositories r ON r.id = bj.repository_id WHERE rr.id = :id',
            ['id' => $retentionRunId]
        );
        if ($run === null) {
            throw new RuntimeException('Retention run not found.');
        }
        if ($run['repository_status'] !== 'ready') {
            throw new RuntimeException('Repository is not ready for retention.');
        }

        $repositoryPath = $this->paths->toContainerPath((string) $run['repository_path']);
        if (!is_file(rtrim($repositoryPath, '/') . '/config')) {
            throw new RuntimeException('Repository storage is missing. Retention was not applied.');
        }
        if (!is_readable((string) $run['password_file'])) {
            throw new RuntimeException('Repository recovery key is missing or unreadable.');
        }

        $this->database->execute(
            "UPDATE retention_runs SET status = 'running', started_at = :started_at, error = NULL WHERE id = :id",
            ['started_at' => date('c'), 'id' => $retentionRunId]
        );

        $snapshots = $this->database->fetchAll(
            "SELECT id, snapshot_id, started_at, finished_at FROM backup_runs " .
            "WHERE backup_job_id = :job_id AND status IN ('success','warning') AND snapshot_id IS NOT NULL " .
            "AND COALESCE(snapshot_state, 'present') = 'present' ORDER BY started_at DESC, id DESC",
            ['job_id' => $run['backup_job_id']]
        );

        $remove = $this->snapshotsToRemove(
            $snapshots,
            max(1, (int) $run['keep_last']),
            max(0, (int) $run['keep_daily']),
            max(0, (int) $run['keep_weekly']),
            max(0, (int) $run['keep_monthly'])
        );

        $removed = 0;
        foreach ($remove as $snapshot) {
            $this->restic->forgetSnapshot($repositoryPath, (string) $run['password_file'], (string) $snapshot['snapshot_id']);
            $now = date('c');
            $this->database->execute(
                "UPDATE backup_runs SET snapshot_state = 'forgotten', forgotten_at = :forgotten_at, snapshot_forget_error = NULL WHERE id = :id",
                ['forgotten_at' => $now, 'id' => $snapshot['id']]
            );
            $removed++;
        }

        $now = date('c');
        $this->database->execute(
            "UPDATE retention_runs SET status = 'success', removed_count = :removed_count, finished_at = :finished_at, error = NULL WHERE id = :id",
            ['removed_count' => $removed, 'finished_at' => $now, 'id' => $retentionRunId]
        );
        if ($run['trigger_backup_run_id'] !== null) {
            $this->database->execute(
                "UPDATE backup_runs SET retention_state = 'success', retention_error = NULL WHERE id = :id",
                ['id' => $run['trigger_backup_run_id']]
            );
        }

        return ['removed_count' => $removed, 'kept_count' => count($snapshots) - $removed];
    }

    public function markFailed(int $retentionRunId, string $error): void
    {
        $run = $this->database->fetchOne('SELECT * FROM retention_runs WHERE id = :id', ['id' => $retentionRunId]);
        $message = substr($error, 0, 4000);
        $this->database->execute(
            "UPDATE retention_runs SET status = 'failed', finished_at = :finished_at, error = :error WHERE id = :id",
            ['finished_at' => date('c'), 'error' => $message, 'id' => $retentionRunId]
        );
        if ($run !== null && $run['trigger_backup_run_id'] !== null) {
            $this->database->execute(
                "UPDATE backup_runs SET retention_state = 'failed', retention_error = :error WHERE id = :id",
                ['error' => $message, 'id' => $run['trigger_backup_run_id']]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function snapshotsToRemove(array $snapshots, int $keepLast, int $keepDaily, int $keepWeekly, int $keepMonthly): array
    {
        if (count($snapshots) <= 1) {
            return [];
        }

        $keep = [];
        foreach (array_slice($snapshots, 0, $keepLast) as $snapshot) {
            $keep[(int) $snapshot['id']] = true;
        }

        $this->keepDistinctPeriods($snapshots, $keep, $keepDaily, static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'));
        $this->keepDistinctPeriods($snapshots, $keep, $keepWeekly, static fn (DateTimeImmutable $date): string => $date->format('o-W'));
        $this->keepDistinctPeriods($snapshots, $keep, $keepMonthly, static fn (DateTimeImmutable $date): string => $date->format('Y-m'));

        // Never allow an automated policy to remove the final snapshot.
        $keep[(int) $snapshots[0]['id']] = true;

        return array_values(array_filter(
            $snapshots,
            static fn (array $snapshot): bool => !isset($keep[(int) $snapshot['id']])
        ));
    }

    private function keepDistinctPeriods(array $snapshots, array &$keep, int $limit, callable $periodKey): void
    {
        if ($limit <= 0) {
            return;
        }
        $periods = [];
        foreach ($snapshots as $snapshot) {
            $date = new DateTimeImmutable((string) $snapshot['started_at']);
            $key = $periodKey($date);
            if (isset($periods[$key])) {
                continue;
            }
            $periods[$key] = true;
            $keep[(int) $snapshot['id']] = true;
            if (count($periods) >= $limit) {
                break;
            }
        }
    }

    private function enqueue(array $job, ?int $triggerRunId): int
    {
        if (($job['repository_archived_at'] ?? null) !== null || ($job['repository_status'] ?? '') !== 'ready') {
            throw new InvalidArgumentException('Repository is not ready for retention.');
        }

        $active = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM retention_runs WHERE backup_job_id = :job_id AND status IN ('pending','running')",
            ['job_id' => $job['id']]
        );
        if ($active > 0) {
            throw new InvalidArgumentException('Retention is already queued or running for this backup job.');
        }

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->database->execute(
                "INSERT INTO retention_runs(uuid, backup_job_id, trigger_backup_run_id, status, keep_last, keep_daily, keep_weekly, keep_monthly, created_at) " .
                "VALUES (:uuid, :job_id, :trigger_run_id, 'pending', :keep_last, :keep_daily, :keep_weekly, :keep_monthly, :created_at)",
                [
                    'uuid' => Uuid::v4(),
                    'job_id' => $job['id'],
                    'trigger_run_id' => $triggerRunId,
                    'keep_last' => max(1, (int) $job['keep_last']),
                    'keep_daily' => max(0, (int) $job['keep_daily']),
                    'keep_weekly' => max(0, (int) $job['keep_weekly']),
                    'keep_monthly' => max(0, (int) $job['keep_monthly']),
                    'created_at' => date('c'),
                ]
            );
            $retentionRunId = $this->database->lastInsertId();
            if ($triggerRunId !== null) {
                $updated = $this->database->execute(
                    "UPDATE backup_runs SET retention_state = 'queued', retention_error = NULL WHERE id = :id AND retention_state = 'pending'",
                    ['id' => $triggerRunId]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Backup retention state changed before it could be queued.');
                }
            }
            $operationId = $this->queue->enqueue('retention.apply', ['retention_run_id' => $retentionRunId]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => (int) $job['repository_id'], 'id' => $operationId]
            );
            $pdo->commit();
            return $retentionRunId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
