<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use ZimaBackup\Core\Database;

final class SettingsService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
    ) {
    }

    public function defaults(): array
    {
        return [
            'restore.default_root' => '/DATA/ZimaBackup/Restores',
            'application_restore.default_root' => '/DATA/ZimaBackup/ApplicationRestores',
            'apps.discovery.interval' => (string) max(30, (int) (getenv('APP_DISCOVERY_INTERVAL') ?: 120)),
        ];
    }

    public function all(): array
    {
        $values = $this->defaults();
        foreach ($this->database->fetchAll('SELECT key, value FROM settings') as $row) {
            if (array_key_exists((string) $row['key'], $values)) {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }
        return $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->database->scalar('SELECT value FROM settings WHERE key = :key', ['key' => $key]);
        if ($value === false || $value === null) {
            return $this->defaults()[$key] ?? $default;
        }
        return (string) $value;
    }

    public function getInt(string $key, int $default): int
    {
        return (int) ($this->get($key, (string) $default) ?? $default);
    }

    public function update(array $input): array
    {
        $restoreRoot = $this->validateRoot((string) ($input['restore.default_root'] ?? ''));
        $applicationRestoreRoot = $this->validateRoot((string) ($input['application_restore.default_root'] ?? ''));
        $discoveryInterval = (int) ($input['apps.discovery.interval'] ?? 120);
        if ($discoveryInterval < 30 || $discoveryInterval > 3600) {
            throw new InvalidArgumentException('Application discovery interval must be between 30 and 3600 seconds.');
        }

        $values = [
            'restore.default_root' => $restoreRoot,
            'application_restore.default_root' => $applicationRestoreRoot,
            'apps.discovery.interval' => (string) $discoveryInterval,
        ];

        $now = date('c');
        foreach ($values as $key => $value) {
            $this->database->execute(
                'INSERT INTO settings(key, value, updated_at) VALUES (:key, :value, :updated_at) ' .
                'ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
                ['key' => $key, 'value' => $value, 'updated_at' => $now]
            );
        }

        return $values;
    }

    private function validateRoot(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Restore roots cannot be empty.');
        }
        $path = $this->paths->normalizeLogicalPath($path);
        $this->paths->toContainerPath($path);
        if (in_array($path, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException('Choose a dedicated restore subdirectory, not /DATA or /media directly.');
        }
        return $path;
    }
}
