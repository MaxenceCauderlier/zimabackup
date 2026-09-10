<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use ZimaBackup\Core\Database;

final class SnapshotController extends AbstractController
{
    public function index(): string
    {
        /** @var Database $database */
        $database = $this->app->service(Database::class);

        $snapshots = $database->fetchAll(
            "SELECT br.*, bj.uuid AS job_uuid, bj.name AS job_name, r.name AS repository_name, " .
            "(SELECT COUNT(*) FROM backup_applications ba WHERE ba.backup_job_id = bj.id) AS app_count, " .
            "(SELECT status FROM snapshot_application_scans sas WHERE sas.backup_run_id = br.id) AS app_scan_status " .
            "FROM backup_runs br " .
            "JOIN backup_jobs bj ON bj.id = br.backup_job_id " .
            "JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE br.status IN ('success', 'warning') AND br.snapshot_id IS NOT NULL " .
            "ORDER BY br.finished_at DESC LIMIT 100"
        );

        return $this->render('snapshots/index.twig', [
            'snapshots' => $snapshots,
        ]);
    }
}
