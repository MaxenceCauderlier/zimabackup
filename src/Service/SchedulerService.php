<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use ZimaBackup\Core\Database;

final class SchedulerService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Return jobs that are due. Execution is deliberately left for the next
     * milestone, where locking and BackupService will be introduced together.
     */
    public function dueJobs(): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM backup_jobs ' .
            'WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= :now ' .
            'ORDER BY next_run_at ASC',
            ['now' => date('c')]
        );
    }
}
