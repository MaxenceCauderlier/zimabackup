<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use ZimaBackup\Core\Database;
use ZimaBackup\Service\ResticService;

final class DashboardController extends AbstractController
{
    public function index(): string
    {
        /** @var Database $database */
        $database = $this->app->service(Database::class);

        /** @var ResticService $restic */
        $restic = $this->app->service(ResticService::class);

        $recentRuns = $database->fetchAll(
            'SELECT br.status, br.started_at, br.finished_at, bj.name AS job_name ' .
            'FROM backup_runs br ' .
            'JOIN backup_jobs bj ON bj.id = br.backup_job_id ' .
            'ORDER BY br.started_at DESC LIMIT 5'
        );

        return $this->render('dashboard/index.twig', [
            'stats' => [
                'jobs' => (int) $database->scalar('SELECT COUNT(*) FROM backup_jobs'),
                'repositories' => (int) $database->scalar('SELECT COUNT(*) FROM repositories'),
                'successful_runs' => (int) $database->scalar(
                    "SELECT COUNT(*) FROM backup_runs WHERE status = 'success'"
                ),
                'failed_runs' => (int) $database->scalar(
                    "SELECT COUNT(*) FROM backup_runs WHERE status = 'failed'"
                ),
            ],
            'recent_runs' => $recentRuns,
            'restic_version' => $restic->version(),
        ]);
    }
}
