<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use ZimaBackup\Core\Database;

final class SchedulerService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Normalize form input into the compact value stored in backup_jobs.
     * Daily: HH:MM. Weekly: ISO_WEEKDAY@HH:MM (1 = Monday, 7 = Sunday).
     *
     * @return array{type:string,value:?string,next_run_at:?string}
     */
    public function normalize(string $type, string $time = '03:00', ?int $weekday = null): array
    {
        $type = strtolower(trim($type));
        if ($type === '' || $type === 'manual') {
            return ['type' => 'manual', 'value' => null, 'next_run_at' => null];
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($time))) {
            throw new InvalidArgumentException('Schedule time must use the HH:MM format.');
        }
        $time = trim($time);

        if ($type === 'daily') {
            $value = $time;
        } elseif ($type === 'weekly') {
            if ($weekday === null || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('Choose a weekday for weekly backups.');
            }
            $value = $weekday . '@' . $time;
        } else {
            throw new InvalidArgumentException('Schedule must be Manual, Daily or Weekly.');
        }

        return [
            'type' => $type,
            'value' => $value,
            'next_run_at' => $this->nextRun($type, $value),
        ];
    }

    public function nextRun(string $type, ?string $value, ?DateTimeImmutable $after = null): ?string
    {
        $type = strtolower(trim($type));
        if ($type === 'manual' || $value === null || trim($value) === '') {
            return null;
        }

        $after ??= new DateTimeImmutable('now');

        if ($type === 'daily') {
            [$hour, $minute] = $this->parseTime($value);
            $candidate = $after->setTime($hour, $minute, 0);
            if ($candidate <= $after) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate->format(DATE_ATOM);
        }

        if ($type === 'weekly') {
            if (!preg_match('/^([1-7])@(.+)$/', $value, $matches)) {
                throw new InvalidArgumentException('Stored weekly schedule is invalid.');
            }
            $weekday = (int) $matches[1];
            [$hour, $minute] = $this->parseTime($matches[2]);
            $currentWeekday = (int) $after->format('N');
            $days = ($weekday - $currentWeekday + 7) % 7;
            $candidate = $after->modify(sprintf('+%d days', $days))->setTime($hour, $minute, 0);
            if ($candidate <= $after) {
                $candidate = $candidate->modify('+7 days');
            }
            return $candidate->format(DATE_ATOM);
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function dueJobs(int $limit = 10): array
    {
        return $this->database->fetchAll(
            "SELECT bj.* FROM backup_jobs bj " .
            "JOIN repositories r ON r.id = bj.repository_id " .
            "WHERE bj.enabled = 1 AND bj.deleted_at IS NULL " .
            "AND bj.schedule_type IN ('daily', 'weekly') " .
            "AND bj.next_run_at IS NOT NULL AND bj.next_run_at <= :now " .
            "AND r.archived_at IS NULL AND r.status = 'ready' " .
            "AND NOT EXISTS (SELECT 1 FROM backup_runs br WHERE br.backup_job_id = bj.id AND br.status IN ('pending','running')) " .
            "ORDER BY bj.next_run_at ASC LIMIT " . max(1, min(50, $limit)),
            ['now' => date('c')]
        );
    }

    public function nextForJob(array $job): ?string
    {
        return $this->nextRun((string) ($job['schedule_type'] ?? 'manual'), $job['schedule_value'] ?? null);
    }

    /** @return array{time:string,weekday:int} */
    public function formValues(string $type, ?string $value): array
    {
        if ($type === 'weekly' && is_string($value) && preg_match('/^([1-7])@(.+)$/', $value, $matches)) {
            return ['time' => $matches[2], 'weekday' => (int) $matches[1]];
        }
        return ['time' => is_string($value) && $value !== '' ? $value : '03:00', 'weekday' => 1];
    }

    /** @return array{0:int,1:int} */
    private function parseTime(string $time): array
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Stored schedule time is invalid.');
        }
        [$hour, $minute] = array_map('intval', explode(':', $time, 2));
        return [$hour, $minute];
    }
}
