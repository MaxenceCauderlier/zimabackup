<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class Translator
{
    private const SUPPORTED = ['en', 'fr'];

    private array $messages = [];

    public function __construct(
        private readonly string $locale,
        private readonly string $translationsPath,
    ) {
        $file = rtrim($translationsPath, '/') . '/' . $this->locale() . '.php';
        if (is_file($file)) {
            $messages = require $file;
            if (is_array($messages)) {
                $this->messages = $messages;
            }
        }
    }

    public static function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));
        if (str_contains($locale, '-')) {
            $locale = explode('-', $locale, 2)[0];
        }
        if (str_contains($locale, '_')) {
            $locale = explode('_', $locale, 2)[0];
        }
        return in_array($locale, self::SUPPORTED, true) ? $locale : 'en';
    }

    public static function supportedLocales(): array
    {
        return [
            'en' => 'English',
            'fr' => 'Français',
        ];
    }

    public function locale(): string
    {
        return self::normalizeLocale($this->locale);
    }

    /**
     * English source strings are the canonical message IDs. This keeps the
     * translation layer small and makes missing translations fall back to a
     * readable English interface automatically.
     */
    public function translate(string $message, array $parameters = []): string
    {
        $translated = (string) ($this->messages[$message] ?? $message);
        foreach ($parameters as $name => $value) {
            $translated = str_replace('{' . $name . '}', (string) $value, $translated);
        }
        return $translated;
    }

    /** Translate application errors when a known message reaches the UI. */
    public function message(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        if (isset($this->messages[$message])) {
            return (string) $this->messages[$message];
        }

        $patterns = [
            '/^Source does not exist: (.+)$/' => 'Source does not exist: {value}',
            '/^Source is not readable by the backup worker: (.+)$/' => 'Source is not readable by the backup worker: {value}',
            '/^Source (.+) overlaps the destination repository\.$/' => 'Source {value} overlaps the destination repository.',
            '/^Application is no longer available in Docker: (.+)$/' => 'Application is no longer available in Docker: {value}',
            '/^Original path is not empty\. Nothing was overwritten: (.+)$/' => 'Original path is not empty. Nothing was overwritten: {value}',
            '/^Restored bind source does not exist: (.+)$/' => 'Restored bind source does not exist: {value}',
            '/^Automatic install refused host bind outside \/DATA and \/media: (.+)$/' => 'Automatic install refused host bind outside /DATA and /media: {value}',
            '/^Service (.+) has no image reference in the manifest\.$/' => 'Service {value} has no image reference in the manifest.',
            '/^Service (.+) contains Docker device requests \(for example GPU access\)\. Review them manually before installation\.$/' => 'Service {value} contains Docker device requests (for example GPU access). Review them manually before installation.',
            '/^Service (.+) has a runtime healthcheck\. It is recorded in the manifest but not emitted automatically in this preview yet\.$/' => 'Service {value} has a runtime healthcheck. It is recorded in the manifest but not emitted automatically in this preview yet.',
        ];

        if (preg_match('/^Service (.+) uses named Docker volume (.+)\. ZimaBackup does not currently back up named-volume contents automatically\.$/', $message, $matches) === 1) {
            return $this->translate('Service {service} uses named Docker volume {volume}. ZimaBackup does not currently back up named-volume contents automatically.', [
                'service' => $matches[1],
                'volume' => $matches[2],
            ]);
        }

        if (preg_match('/^Service (.+) used Docker network mode "(.+)"; the preview reconstructs named networks instead of reusing the runtime network name\.$/', $message, $matches) === 1) {
            return $this->translate('Service {service} used Docker network mode "{mode}"; the preview reconstructs named networks instead of reusing the runtime network name.', [
                'service' => $matches[1],
                'mode' => $matches[2],
            ]);
        }

        foreach ($patterns as $pattern => $key) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return $this->translate($key, ['value' => $matches[1]]);
            }
        }

        return $message;
    }

    public function status(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        $english = match ($status) {
            'ready' => 'Ready',
            'success' => 'Success',
            'failed' => 'Failed',
            'pending' => 'Pending',
            'running' => 'Running',
            'queued' => 'Queued',
            'scanning' => 'Scanning',
            'initializing' => 'Initializing',
            'reinitializing' => 'Reinitializing',
            'missing' => 'Missing',
            'disabled' => 'Disabled',
            'enabled' => 'Enabled',
            'present' => 'Present',
            'unavailable' => 'Unavailable',
            'forgotten' => 'Deleted',
            'forgetting' => 'Deleting',
            'not_applicable' => 'Not applicable',
            'completed' => 'Completed',
            'cleaning' => 'Cleaning',
            'staging' => 'Safe staging',
            'original' => 'Original paths',
            'manual' => 'Manual',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'warning' => 'Warning',
            'error' => 'Error',
            'loading' => 'Loading',
            'stopped' => 'Stopped',
            'unknown' => 'Unknown',
            'online' => 'Online',
            'offline' => 'Offline',
            'busy' => 'Busy',
            'backup' => 'Backups',
            'restore' => 'Restores',
            'storage' => 'Storage',
            'applications' => 'Applications',
            'maintenance' => 'Maintenance',
            'system' => 'System',
            default => ucfirst(str_replace('_', ' ', $status)),
        };

        return $this->translate($english);
    }

    public function dateTime(mixed $value, bool $withTime = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return (string) $value;
        }

        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

        if ($this->locale() === 'fr') {
            $months = [
                1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
                7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
            ];
            $result = $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
            return $withTime ? $result . ' ' . $this->translate('at') . ' ' . $date->format('H:i') : $result;
        }

        return $withTime ? $date->format('M j, Y H:i') : $date->format('M j, Y');
    }

    public function javascriptMessages(): array
    {
        return [
            'copied' => $this->translate('Copied ✓'),
            'confirm' => $this->translate('Are you sure?'),
            'removeSource' => $this->translate('Remove source'),
        ];
    }
}
