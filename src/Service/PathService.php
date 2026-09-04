<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;

final class PathService
{
    public function __construct(private readonly array $containerRoots)
    {
    }

    /**
     * Convert a host path shown in the UI to the matching container path.
     *
     * Example: /DATA/Documents -> /host/DATA/Documents
     */
    public function toContainerPath(string $logicalPath): string
    {
        $normalized = $this->normalizeLogicalPath($logicalPath);

        foreach ($this->containerRoots as $logicalRoot => $containerRoot) {
            if ($normalized === $logicalRoot || str_starts_with($normalized, $logicalRoot . '/')) {
                return rtrim($containerRoot, '/') . substr($normalized, strlen($logicalRoot));
            }
        }

        throw new InvalidArgumentException(sprintf('Path is outside allowed roots: %s', $logicalPath));
    }

    public function normalizeLogicalPath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('An absolute path is required.');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('The path contains an invalid null byte.');
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
