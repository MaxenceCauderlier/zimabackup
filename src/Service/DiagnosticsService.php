<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use DateTimeImmutable;
use Throwable;
use ZimaBackup\Core\Database;

final class DiagnosticsService
{
    public function __construct(
        private readonly Database $database,
        private readonly int $workerInterval = 10,
    ) {
    }

    public function summary(): array
    {
        $issues = [];
        $heartbeat = $this->database->fetchOne("SELECT updated_at FROM runtime_status WHERE key = 'worker.heartbeat'");
        $workerState = 'unknown';
        $workerLastSeen = $heartbeat['updated_at'] ?? null;

        if ($workerLastSeen !== null) {
            try {
                $age = time() - (new DateTimeImmutable((string) $workerLastSeen))->getTimestamp();
                $threshold = max(60, $this->workerInterval * 5);
                $workerState = $age <= $threshold ? 'online' : 'offline';
                if ($workerState === 'offline') {
                    $runningOperation = $this->database->fetchOne(
                        "SELECT started_at FROM operations WHERE status = 'running' ORDER BY started_at DESC LIMIT 1"
                    );
                    if ($runningOperation !== null) {
                        $workerState = 'busy';
                    } else {
                        $issues[] = [
                            'severity' => 'error',
                            'title' => 'Backup worker is not responding',
                            'detail' => 'Background backups and restores cannot run until the worker is available again.',
                            'route' => 'activity',
                        ];
                    }
                }
            } catch (Throwable) {
                $workerState = 'unknown';
            }
        } else {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Backup worker status is not available yet',
                'detail' => 'The worker has not reported a heartbeat since Activity diagnostics were enabled.',
                'route' => 'activity',
            ];
        }

        foreach ($this->database->fetchAll(
            "SELECT name, status FROM repositories WHERE archived_at IS NULL AND status IN ('missing','failed') ORDER BY name COLLATE NOCASE"
        ) as $row) {
            $issues[] = [
                'severity' => 'error',
                'title' => 'Backup storage needs attention',
                'detail' => (string) $row['name'] . ' · ' . (string) $row['status'],
                'route' => 'repositories',
            ];
        }

        $failedJobs = $this->database->fetchAll(
            "SELECT bj.name, br.error FROM backup_jobs bj " .
            "JOIN backup_runs br ON br.id = (SELECT br2.id FROM backup_runs br2 WHERE br2.backup_job_id = bj.id ORDER BY br2.id DESC LIMIT 1) " .
            "WHERE bj.deleted_at IS NULL AND bj.enabled = 1 AND br.status = 'failed' ORDER BY bj.name COLLATE NOCASE"
        );
        foreach ($failedJobs as $row) {
            $issues[] = [
                'severity' => 'error',
                'title' => 'Latest backup failed',
                'detail' => (string) $row['name'] . (($row['error'] ?? '') !== '' ? ' · ' . substr((string) $row['error'], 0, 140) : ''),
                'route' => 'backups',
            ];
        }

        $discovery = $this->database->fetchOne("SELECT value FROM settings WHERE key = 'apps.discovery.status'");
        if (($discovery['value'] ?? null) === 'failed') {
            $error = $this->database->scalar("SELECT value FROM settings WHERE key = 'apps.discovery.error'");
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Application discovery is failing',
                'detail' => is_string($error) && $error !== '' ? substr($error, 0, 160) : 'ZimaBackup cannot refresh the Docker application list.',
                'route' => 'applications',
            ];
        }

        $zimaOsApi = $this->zimaOsApiStatus();
        if (($zimaOsApi['configured'] ?? null) === true
            && ($zimaOsApi['reachable'] ?? null) === false
            && !($zimaOsApi['stale'] ?? true)) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'Native ZimaOS restore is not ready',
                'detail' => (string) (($zimaOsApi['error'] ?? null) ?: 'The worker cannot reach the local ZimaOS API.'),
                'route' => 'settings',
            ];
        }

        $staleCutoff = date('c', time() - 21600);
        $stale = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM operations WHERE status = 'running' AND started_at IS NOT NULL AND started_at < :cutoff",
            ['cutoff' => $staleCutoff]
        );
        if ($stale > 0) {
            $issues[] = [
                'severity' => 'warning',
                'title' => 'A background operation may be stuck',
                'detail' => 'A background operation has been running for more than 6 hours.',
                'route' => 'activity',
            ];
        }

        $errors = 0;
        $warnings = 0;
        foreach ($issues as $issue) {
            $errors += $issue['severity'] === 'error' ? 1 : 0;
            $warnings += $issue['severity'] === 'warning' ? 1 : 0;
        }

        return [
            'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'healthy'),
            'issues' => $issues,
            'error_count' => $errors,
            'warning_count' => $warnings,
            'worker_state' => $workerState,
            'worker_last_seen' => $workerLastSeen,
            'zimaos_api' => $zimaOsApi,
        ];
    }

    /**
     * Last worker-side check of the local ZimaOS API. The web process never
     * needs Docker or host-network privileges to expose this diagnostic.
     *
     * @return array<string,mixed>
     */
    public function zimaOsApiStatus(): array
    {
        $row = $this->database->fetchOne("SELECT value, updated_at FROM runtime_status WHERE key = 'zimaos.api'");
        if ($row === null) {
            return [
                'configured' => null,
                'reachable' => null,
                'base_url' => '',
                'host' => null,
                'port' => null,
                'resolved_ip' => null,
                'error' => null,
                'checked_at' => null,
                'stale' => true,
            ];
        }

        $decoded = json_decode((string) ($row['value'] ?? ''), true);
        $status = is_array($decoded) ? $decoded : [];
        $checkedAt = (string) ($row['updated_at'] ?? '');
        $stale = true;
        if ($checkedAt !== '') {
            try {
                $stale = (time() - (new DateTimeImmutable($checkedAt))->getTimestamp()) > 180;
            } catch (Throwable) {
                $stale = true;
            }
        }

        return [
            'configured' => array_key_exists('configured', $status) ? (bool) $status['configured'] : null,
            'reachable' => array_key_exists('reachable', $status) ? (bool) $status['reachable'] : null,
            'base_url' => (string) ($status['base_url'] ?? ''),
            'host' => isset($status['host']) ? (string) $status['host'] : null,
            'port' => isset($status['port']) ? (int) $status['port'] : null,
            'resolved_ip' => isset($status['resolved_ip']) ? (string) $status['resolved_ip'] : null,
            'error' => isset($status['error']) ? (string) $status['error'] : null,
            'checked_at' => $checkedAt !== '' ? $checkedAt : null,
            'stale' => $stale,
        ];
    }
}
