<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class ActivityService
{
    private const QUIET_OPERATION_TYPES = ['snapshot.browse'];

    public function __construct(private readonly Database $database)
    {
    }

    public function record(
        string $severity,
        string $category,
        string $title,
        ?string $summary = null,
        ?string $subject = null,
        ?string $technicalDetails = null,
        array $context = [],
        ?string $operationUuid = null,
    ): int {
        $severity = in_array($severity, ['info', 'success', 'warning', 'error'], true) ? $severity : 'info';
        $category = trim($category) !== '' ? trim($category) : 'system';

        try {
            $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $contextJson = '{}';
        }

        $this->database->execute(
            'INSERT INTO activity_events(uuid, severity, category, title, summary, subject, technical_details, context_json, operation_uuid, created_at) ' .
            'VALUES (:uuid, :severity, :category, :title, :summary, :subject, :technical_details, :context_json, :operation_uuid, :created_at)',
            [
                'uuid' => Uuid::v4(),
                'severity' => $severity,
                'category' => $category,
                'title' => $title,
                'summary' => $summary,
                'subject' => $subject,
                'technical_details' => $technicalDetails !== null ? substr($technicalDetails, 0, 12000) : null,
                'context_json' => $contextJson,
                'operation_uuid' => $operationUuid,
                'created_at' => date('c'),
            ]
        );

        return $this->database->lastInsertId();
    }

    public function recordOperation(array $operation, bool $success, ?string $error = null): void
    {
        $type = (string) ($operation['type'] ?? 'unknown');
        if (in_array($type, self::QUIET_OPERATION_TYPES, true)) {
            return;
        }

        $definition = $this->definitionFor($type, $operation['payload'] ?? []);
        if ($definition === null) {
            $definition = [
                'category' => 'system',
                'success_title' => 'Background operation completed',
                'failure_title' => 'Background operation failed',
                'success_summary' => 'The background operation completed successfully.',
                'failure_summary' => 'The background operation did not complete.',
                'subject' => $type,
            ];
        }

        $severity = $success ? ($definition['success_severity'] ?? 'success') : 'error';
        $title = $success ? $definition['success_title'] : $definition['failure_title'];
        $summary = $success ? $definition['success_summary'] : $definition['failure_summary'];

        // A Restic backup can succeed with warnings (exit code 3). Surface this
        // as a warning in Activity instead of pretending it was fully clean.
        if ($success && $type === 'backup.run') {
            $runId = (int) (($operation['payload']['run_id'] ?? 0));
            $run = $this->database->fetchOne('SELECT status, error FROM backup_runs WHERE id = :id', ['id' => $runId]);
            if (($run['status'] ?? null) === 'warning') {
                $severity = 'warning';
                $title = 'Backup completed with warnings';
                $summary = 'A recovery point was created, but the backup engine reported warnings.';
                $error = (string) ($run['error'] ?? '');
            }
        }

        $this->record(
            $severity,
            $definition['category'],
            $title,
            $summary,
            $definition['subject'],
            $success && ($error === null || $error === '') ? null : $error,
            ['operation_type' => $type],
            (string) ($operation['uuid'] ?? '') ?: null,
        );
    }

    public function recordSystem(string $severity, string $title, string $summary, ?string $technicalDetails = null): void
    {
        $this->record($severity, 'system', $title, $summary, null, $technicalDetails);
    }

    public function list(?string $severity = null, ?string $category = null, int $limit = 200): array
    {
        $where = [];
        $params = [];
        if ($severity !== null && in_array($severity, ['info', 'success', 'warning', 'error'], true)) {
            $where[] = 'severity = :severity';
            $params['severity'] = $severity;
        }
        if ($category !== null && in_array($category, $this->categories(), true)) {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM activity_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        return $this->database->fetchAll($sql, $params);
    }

    public function recent(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll('SELECT * FROM activity_events ORDER BY id DESC LIMIT ' . $limit);
    }

    public function counts(): array
    {
        $counts = ['all' => 0, 'info' => 0, 'success' => 0, 'warning' => 0, 'error' => 0];
        foreach ($this->database->fetchAll('SELECT severity, COUNT(*) AS total FROM activity_events GROUP BY severity') as $row) {
            $severity = (string) $row['severity'];
            if (array_key_exists($severity, $counts)) {
                $counts[$severity] = (int) $row['total'];
            }
            $counts['all'] += (int) $row['total'];
        }
        return $counts;
    }

    public function categories(): array
    {
        return ['backup', 'restore', 'storage', 'applications', 'maintenance', 'system'];
    }

    public function heartbeat(): void
    {
        $this->setRuntimeStatus('worker.heartbeat', 'alive');
    }

    public function setRuntimeStatus(string $key, string $value): void
    {
        $this->database->execute(
            'INSERT INTO runtime_status(key, value, updated_at) VALUES (:key, :value, :updated_at) ' .
            'ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['key' => $key, 'value' => $value, 'updated_at' => date('c')]
        );
    }

    public function cleanup(int $retentionDays = 30, int $maxEvents = 5000): void
    {
        $retentionDays = max(1, min(3650, $retentionDays));
        $maxEvents = max(100, min(100000, $maxEvents));
        $cutoff = date('c', time() - ($retentionDays * 86400));
        $this->database->execute('DELETE FROM activity_events WHERE created_at < :cutoff', ['cutoff' => $cutoff]);
        $this->database->execute(
            'DELETE FROM activity_events WHERE id NOT IN (SELECT id FROM activity_events ORDER BY id DESC LIMIT ' . $maxEvents . ')'
        );
    }

    private function definitionFor(string $type, array $payload): ?array
    {
        return match ($type) {
            'repository.init' => $this->repositoryDefinition($payload, 'Backup storage initialized', 'Backup storage initialization failed', 'The backup storage is ready to use.'),
            'repository.reinitialize' => $this->repositoryDefinition($payload, 'Backup storage reinitialized', 'Backup storage reinitialization failed', 'A new empty backup storage was created using the existing recovery key.', 'warning'),
            'repository.check' => $this->repositoryDefinition($payload, 'Backup check completed', 'Backup check failed', 'The backup storage passed its integrity check.'),
            'repository.prune' => $this->repositoryDefinition($payload, 'Unused space released', 'Unable to free unused space', 'Backup data no longer needed by any recovery point was removed.'),
            'snapshot.forget' => $this->backupRunDefinition($payload, 'Recovery point deleted', 'Recovery point deletion failed', 'The recovery point is no longer available for restore.', 'maintenance'),
            'backup.run' => $this->backupRunDefinition($payload, 'Backup completed', 'Backup failed', 'A new recovery point was created.', 'backup'),
            'retention.apply' => $this->retentionDefinition($payload),
            'restore.run' => $this->restoreDefinition($payload, 'File restore completed', 'File restore failed', 'Selected data was restored to the requested target.'),
            'restore.cleanup' => $this->restoreDefinition($payload, 'Restore files cleaned', 'Restore cleanup failed', 'The temporary restore target was removed.'),
            'apps.discover' => [
                'category' => 'applications', 'success_title' => 'Applications refreshed', 'failure_title' => 'Application discovery failed',
                'success_summary' => 'The list of Docker applications was refreshed.', 'failure_summary' => 'ZimaBackup could not refresh the application list.', 'subject' => null,
            ],
            'snapshot.apps.inspect' => $this->backupRunDefinition($payload, 'Applications found in recovery point', 'Application inspection failed', 'Application backup information was read from the recovery point.', 'applications'),
            'application.restore' => $this->applicationRestoreDefinition($payload, 'Application data restored', 'Application restore failed', 'The selected application data was restored.'),
            'application.restore.cleanup' => $this->applicationRestoreDefinition($payload, 'Application staging cleaned', 'Application staging cleanup failed', 'The temporary application restore workspace was removed.'),
            'application.install' => $this->applicationInstallDefinition($payload),
            default => null,
        };
    }

    private function repositoryDefinition(array $payload, string $successTitle, string $failureTitle, string $successSummary, string $successSeverity = 'success'): array
    {
        $id = (int) ($payload['repository_id'] ?? 0);
        $name = $id > 0 ? $this->database->scalar('SELECT name FROM repositories WHERE id = :id', ['id' => $id]) : null;
        return [
            'category' => 'storage', 'success_title' => $successTitle, 'failure_title' => $failureTitle,
            'success_summary' => $successSummary, 'failure_summary' => 'The backup storage operation did not complete.',
            'subject' => is_string($name) ? $name : null, 'success_severity' => $successSeverity,
        ];
    }

    private function backupRunDefinition(array $payload, string $successTitle, string $failureTitle, string $successSummary, string $category): array
    {
        $id = (int) ($payload['run_id'] ?? $payload['backup_run_id'] ?? 0);
        $name = $id > 0 ? $this->database->scalar(
            'SELECT bj.name FROM backup_runs br JOIN backup_jobs bj ON bj.id = br.backup_job_id WHERE br.id = :id',
            ['id' => $id]
        ) : null;
        return [
            'category' => $category, 'success_title' => $successTitle, 'failure_title' => $failureTitle,
            'success_summary' => $successSummary, 'failure_summary' => 'The operation did not complete.',
            'subject' => is_string($name) ? $name : null,
        ];
    }

    private function retentionDefinition(array $payload): array
    {
        $id = (int) ($payload['retention_run_id'] ?? 0);
        $name = $id > 0 ? $this->database->scalar(
            'SELECT bj.name FROM retention_runs rr JOIN backup_jobs bj ON bj.id = rr.backup_job_id WHERE rr.id = :id',
            ['id' => $id]
        ) : null;
        return [
            'category' => 'maintenance', 'success_title' => 'Recovery point retention applied', 'failure_title' => 'Recovery point retention failed',
            'success_summary' => 'Old recovery points were evaluated using this backup job’s retention rules.',
            'failure_summary' => 'Automatic recovery point cleanup did not complete.', 'subject' => is_string($name) ? $name : null,
        ];
    }

    private function restoreDefinition(array $payload, string $successTitle, string $failureTitle, string $successSummary): array
    {
        $id = (int) ($payload['restore_run_id'] ?? 0);
        $subject = $id > 0 ? $this->database->scalar('SELECT target_path FROM restore_runs WHERE id = :id', ['id' => $id]) : null;
        return [
            'category' => 'restore', 'success_title' => $successTitle, 'failure_title' => $failureTitle,
            'success_summary' => $successSummary, 'failure_summary' => 'The restore operation did not complete.',
            'subject' => is_string($subject) ? $subject : null,
        ];
    }

    private function applicationRestoreDefinition(array $payload, string $successTitle, string $failureTitle, string $successSummary): array
    {
        $id = (int) ($payload['application_restore_run_id'] ?? 0);
        $name = $id > 0 ? $this->database->scalar('SELECT app_name FROM application_restore_runs WHERE id = :id', ['id' => $id]) : null;
        return [
            'category' => 'restore', 'success_title' => $successTitle, 'failure_title' => $failureTitle,
            'success_summary' => $successSummary, 'failure_summary' => 'The application restore operation did not complete.',
            'subject' => is_string($name) ? $name : null,
        ];
    }

    private function applicationInstallDefinition(array $payload): array
    {
        $id = (int) ($payload['application_install_run_id'] ?? 0);
        $name = $id > 0 ? $this->database->scalar('SELECT app_name FROM application_install_runs WHERE id = :id', ['id' => $id]) : null;
        return [
            'category' => 'restore', 'success_title' => 'Application installed', 'failure_title' => 'Application installation failed',
            'success_summary' => 'Docker objects were recreated and the application started.',
            'failure_summary' => 'ZimaBackup could not recreate and start the application.', 'subject' => is_string($name) ? $name : null,
        ];
    }
}
