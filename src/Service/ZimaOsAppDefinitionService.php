<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ZimaOsAppDefinitionService
{
    public function __construct(private readonly string $appsRoot = '/var/lib/casaos/apps')
    {
    }

    /** @return array{available: bool, root: string} */
    public function status(): array
    {
        return [
            'available' => is_dir($this->appsRoot) && is_readable($this->appsRoot),
            'root' => $this->appsRoot,
        ];
    }

    /**
     * Find the exact installed Compose definition for a ZimaOS-managed project.
     * Container labels are preferred because they point at the file Docker
     * Compose actually used. The conventional ZimaOS application directory is
     * used as a fallback.
     *
     * @param list<array<string, mixed>> $labelSets
     * @return array{path: string, yaml: string, sha256: string, metadata: array<string, mixed>}|null
     */
    public function find(string $projectName, array $labelSets = []): ?array
    {
        $projectName = trim($projectName);
        if ($projectName === '' || !is_dir($this->appsRoot)) {
            return null;
        }

        $candidates = [];
        foreach ($labelSets as $labels) {
            if (!is_array($labels)) {
                continue;
            }
            $configFiles = trim((string) ($labels['com.docker.compose.project.config_files'] ?? ''));
            if ($configFiles !== '') {
                foreach (preg_split('/\s*,\s*/', $configFiles) ?: [] as $file) {
                    $file = trim($file);
                    if ($file !== '') {
                        $candidates[] = $file;
                    }
                }
            }
            $workingDir = trim((string) ($labels['com.docker.compose.project.working_dir'] ?? ''));
            if ($workingDir !== '') {
                $candidates[] = rtrim($workingDir, '/') . '/docker-compose.yml';
                $candidates[] = rtrim($workingDir, '/') . '/docker-compose.yaml';
            }
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $projectName)) {
            $candidates[] = rtrim($this->appsRoot, '/') . '/' . $projectName . '/docker-compose.yml';
            $candidates[] = rtrim($this->appsRoot, '/') . '/' . $projectName . '/docker-compose.yaml';
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (!$this->isInsideAppsRoot($candidate) || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $size = @filesize($candidate);
            if ($size !== false && $size > 2 * 1024 * 1024) {
                continue;
            }
            $yaml = file_get_contents($candidate);
            if ($yaml === false || trim($yaml) === '') {
                continue;
            }

            try {
                $document = Yaml::parse($yaml);
            } catch (ParseException) {
                continue;
            }
            if (!is_array($document) || !is_array($document['services'] ?? null)) {
                continue;
            }

            return [
                'path' => $candidate,
                'yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
                'metadata' => $this->metadata($document, $projectName),
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function metadata(array $document, string $projectName): array
    {
        $x = is_array($document['x-casaos'] ?? null) ? $document['x-casaos'] : [];
        $title = $this->localizedValue($x['title'] ?? null);

        return [
            'id' => trim((string) ($x['id'] ?? $x['store_app_id'] ?? '')) ?: null,
            'title' => $title !== '' ? $title : $this->humanize($projectName),
            'category' => trim((string) ($x['category'] ?? '')) ?: null,
            'icon' => trim((string) ($x['icon'] ?? '')) ?: null,
            'version' => trim((string) ($x['version'] ?? '')) ?: null,
            'main' => trim((string) ($x['main'] ?? '')) ?: null,
            'author' => trim((string) ($x['author'] ?? '')) ?: null,
            'developer' => trim((string) ($x['developer'] ?? '')) ?: null,
        ];
    }

    private function localizedValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (!is_array($value)) {
            return '';
        }
        foreach (['en_US', 'en_us', 'en_GB', 'fr_FR'] as $locale) {
            if (isset($value[$locale]) && is_scalar($value[$locale])) {
                return trim((string) $value[$locale]);
            }
        }
        foreach ($value as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }
        return '';
    }

    private function isInsideAppsRoot(string $path): bool
    {
        $root = realpath($this->appsRoot);
        $candidate = realpath($path);
        if ($root === false || $candidate === false) {
            return false;
        }
        $root = rtrim($root, '/');
        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }

    private function humanize(string $value): string
    {
        $value = str_replace(['-', '_', '.'], ' ', $value);
        return ucwords(trim(preg_replace('/\s+/', ' ', $value) ?: $value));
    }
}
