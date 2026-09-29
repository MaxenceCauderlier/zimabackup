<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use ZimaBackup\Core\Database;
use ZimaBackup\Service\ActivityService;
use ZimaBackup\Service\DiagnosticsService;

final class DashboardController extends AbstractController
{
    public function index(): string
    {
        /** @var Database $database */
        $database = $this->app->service(Database::class);
        /** @var ActivityService $activity */
        $activity = $this->app->service(ActivityService::class);
        /** @var DiagnosticsService $diagnostics */
        $diagnostics = $this->app->service(DiagnosticsService::class);

        $lastSuccess = $database->fetchOne(
            "SELECT br.finished_at, bj.name AS job_name FROM backup_runs br " .
            "JOIN backup_jobs bj ON bj.id = br.backup_job_id " .
            "WHERE br.status IN ('success','warning') ORDER BY br.finished_at DESC LIMIT 1"
        );
        $nextJob = $database->fetchOne(
            "SELECT name, next_run_at FROM backup_jobs WHERE deleted_at IS NULL AND enabled = 1 " .
            "AND next_run_at IS NOT NULL ORDER BY next_run_at ASC LIMIT 1"
        );
        $jobs = $database->fetchAll(
            "SELECT bj.uuid, bj.name, bj.enabled, bj.schedule_type, bj.next_run_at, r.name AS repository_name, " .
            "(SELECT br.status FROM backup_runs br WHERE br.backup_job_id = bj.id ORDER BY br.id DESC LIMIT 1) AS latest_status " .
            "FROM backup_jobs bj JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE bj.deleted_at IS NULL AND r.archived_at IS NULL ORDER BY bj.name COLLATE NOCASE ASC LIMIT 8"
        );

        return $this->render('dashboard/index.twig', [
            'last_success' => $lastSuccess,
            'next_job' => $nextJob,
            'recent_activity' => $activity->recent(8),
            'jobs' => $jobs,
            'diagnostics' => $diagnostics->summary(),
            'repository_count' => (int) $database->scalar('SELECT COUNT(*) FROM repositories WHERE archived_at IS NULL'),
        ]);
    }
}
