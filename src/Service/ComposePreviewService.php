<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

final class ComposePreviewService
{
    /**
     * Build a human-reviewable Docker Compose preview from a normalized
     * ZimaBackup application manifest. Secret environment values must already
     * be sanitized before this method is called.
     *
     * @return array{compose: string, warnings: list<string>}
     */
    public function build(array $manifest): array
    {
        $warnings = [];
        $projectName = $this->safeName((string) ($manifest['project_name'] ?? $manifest['name'] ?? 'restored-app'));
        $services = [];
        $networks = [];

        $containers = is_array($manifest['containers'] ?? null) ? $manifest['containers'] : [];
        foreach ($containers as $index => $container) {
            if (!is_array($container)) {
                continue;
            }

            $serviceName = $this->safeName((string) ($container['service'] ?? $container['name'] ?? ('service-' . ($index + 1))));
            if (isset($services[$serviceName])) {
                $serviceName .= '-' . ($index + 1);
            }

            $service = [];
            $image = trim((string) ($container['image'] ?? ''));
            if ($image !== '') {
                $service['image'] = $image;
            } else {
                $warnings[] = sprintf('Service %s has no image reference in the manifest.', $serviceName);
            }

            $environment = is_array($container['environment'] ?? null) ? $container['environment'] : [];
            if ($environment !== []) {
                $service['environment'] = array_values(array_map('strval', $environment));
            }

            $ports = $this->ports($container['port_bindings'] ?? []);
            if ($ports !== []) {
                $service['ports'] = $ports;
            }

            $volumes = $this->volumes($container['mounts'] ?? [], $warnings, $serviceName);
            if ($volumes !== []) {
                $service['volumes'] = $volumes;
            }

            $restart = is_array($container['restart_policy'] ?? null) ? $container['restart_policy'] : [];
            $restartName = trim((string) ($restart['Name'] ?? ''));
            if ($restartName !== '' && $restartName !== 'no') {
                if ($restartName === 'on-failure' && (int) ($restart['MaximumRetryCount'] ?? 0) > 0) {
                    $restartName .= ':' . (int) $restart['MaximumRetryCount'];
                }
                $service['restart'] = $restartName;
            }

            $this->copyScalar($service, 'user', $container, 'user');
            $this->copyScalar($service, 'working_dir', $container, 'working_dir');
            $this->copyScalar($service, 'hostname', $container, 'hostname');
            $this->copyScalar($service, 'domainname', $container, 'domainname');

            $command = $container['command'] ?? null;
            if (is_array($command) && $command !== []) {
                $service['command'] = array_values(array_map('strval', $command));
            } elseif (is_string($command) && trim($command) !== '') {
                $service['command'] = $command;
            }

            $entrypoint = $container['entrypoint'] ?? null;
            if (is_array($entrypoint) && $entrypoint !== []) {
                $service['entrypoint'] = array_values(array_map('strval', $entrypoint));
            } elseif (is_string($entrypoint) && trim($entrypoint) !== '') {
                $service['entrypoint'] = $entrypoint;
            }

            if (($container['privileged'] ?? false) === true) {
                $service['privileged'] = true;
            }
            if (($container['read_only_rootfs'] ?? false) === true) {
                $service['read_only'] = true;
            }

            foreach (['cap_add', 'cap_drop', 'security_opt', 'extra_hosts', 'dns', 'dns_search', 'group_add'] as $field) {
                $value = $container[$field] ?? [];
                if (is_array($value) && $value !== []) {
                    $service[$field] = array_values(array_map('strval', $value));
                }
            }

            $sysctls = $container['sysctls'] ?? [];
            if (is_array($sysctls) && $sysctls !== []) {
                $service['sysctls'] = $sysctls;
            }

            $devices = $this->devices($container['devices'] ?? []);
            if ($devices !== []) {
                $service['devices'] = $devices;
            }

            $shmSize = (int) ($container['shm_size'] ?? 0);
            if ($shmSize > 0) {
                $service['shm_size'] = $shmSize;
            }

            $networkMode = trim((string) ($container['network_mode'] ?? ''));
            $containerNetworks = is_array($container['networks'] ?? null) ? $container['networks'] : [];
            if ($networkMode !== '' && !in_array($networkMode, ['default', 'bridge'], true)) {
                if ($networkMode === 'host' || $networkMode === 'none' || str_starts_with($networkMode, 'service:')) {
                    $service['network_mode'] = $networkMode;
                } elseif (!str_ends_with($networkMode, '_default')) {
                    $warnings[] = sprintf('Service %s used Docker network mode "%s"; the preview reconstructs named networks instead of reusing the runtime network name.', $serviceName, $networkMode);
                }
            }

            if (!isset($service['network_mode']) && $containerNetworks !== []) {
                $serviceNetworks = [];
                foreach ($containerNetworks as $networkName => $networkData) {
                    $networkName = (string) $networkName;
                    $composeNetworkName = $this->composeNetworkName($networkName, $projectName);
                    $serviceNetworks[] = $composeNetworkName;
                    if ($composeNetworkName !== 'default') {
                        $networks[$composeNetworkName] = ['external' => true, 'name' => $networkName];
                    }
                }
                if ($serviceNetworks !== [] && $serviceNetworks !== ['default']) {
                    $service['networks'] = array_values(array_unique($serviceNetworks));
                }
            }

            $deviceRequests = $container['device_requests'] ?? [];
            if (is_array($deviceRequests) && $deviceRequests !== []) {
                $warnings[] = sprintf('Service %s contains Docker device requests (for example GPU access). Review them manually before installation.', $serviceName);
            }

            $healthcheck = $container['healthcheck'] ?? null;
            if (is_array($healthcheck) && $healthcheck !== []) {
                $warnings[] = sprintf('Service %s has a runtime healthcheck. It is recorded in the manifest but not emitted automatically in this preview yet.', $serviceName);
            }

            $services[$serviceName] = $service;
        }

        if ($services === []) {
            $warnings[] = 'No container service could be reconstructed from this manifest.';
        }

        $warnings[] = 'ZimaOS x-casaos store metadata is not guaranteed to be recoverable from Docker Inspect alone. This preview focuses on runtime Docker Compose configuration.';

        $compose = [
            'name' => $projectName,
            'services' => $services,
        ];
        if ($networks !== []) {
            $compose['networks'] = $networks;
        }

        return [
            'compose' => $this->toYaml($compose),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** Return a copy safe enough to persist in SQLite and display in the UI. */
    public function sanitizeManifest(array $manifest): array
    {
        return $this->sanitizeValue($manifest, null);
    }

    private function sanitizeValue(mixed $value, ?string $key): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '***';
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $childKey => $childValue) {
            $childKeyString = is_string($childKey) ? $childKey : null;

            if ($childKeyString === 'environment' && is_array($childValue)) {
                $result[$childKey] = array_values(array_map(static function (mixed $entry): string {
                    $entry = (string) $entry;
                    if (!str_contains($entry, '=')) {
                        return $entry;
                    }
                    [$name] = explode('=', $entry, 2);
                    return $name . '=***';
                }, $childValue));
                continue;
            }

            $result[$childKey] = $this->sanitizeValue($childValue, $childKeyString);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(?:password|passwd|secret|token|credential|private[_-]?key|api[_-]?key|access[_-]?key|client[_-]?secret)/i', $key);
    }

    private function ports(mixed $bindings): array
    {
        if (!is_array($bindings)) {
            return [];
        }

        $ports = [];
        foreach ($bindings as $containerPort => $hostBindings) {
            $containerPort = (string) $containerPort;
            if (!is_array($hostBindings)) {
                continue;
            }
            foreach ($hostBindings as $binding) {
                if (!is_array($binding)) {
                    continue;
                }
                $hostPort = trim((string) ($binding['HostPort'] ?? ''));
                if ($hostPort === '') {
                    continue;
                }
                $hostIp = trim((string) ($binding['HostIp'] ?? ''));
                $parts = $hostIp !== '' && !in_array($hostIp, ['0.0.0.0', '::'], true)
                    ? [$hostIp, $hostPort, $containerPort]
                    : [$hostPort, $containerPort];
                $ports[] = implode(':', $parts);
            }
        }
        return array_values(array_unique($ports));
    }

    private function volumes(mixed $mounts, array &$warnings, string $serviceName): array
    {
        if (!is_array($mounts)) {
            return [];
        }

        $volumes = [];
        foreach ($mounts as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $type = (string) ($mount['Type'] ?? $mount['type'] ?? '');
            $destination = trim((string) ($mount['Destination'] ?? $mount['destination'] ?? ''));
            if ($destination === '') {
                continue;
            }

            if ($type === 'bind') {
                $source = trim((string) ($mount['Source'] ?? $mount['source'] ?? ''));
                if ($source === '') {
                    continue;
                }
                $entry = $source . ':' . $destination;
                if (($mount['RW'] ?? true) === false) {
                    $entry .= ':ro';
                }
                $volumes[] = $entry;
                continue;
            }

            if ($type === 'volume') {
                $name = trim((string) ($mount['Name'] ?? ''));
                if ($name !== '') {
                    $volumes[] = $name . ':' . $destination;
                    $warnings[] = sprintf('Service %s uses named Docker volume %s. ZimaBackup v0.5-v0.8 does not automatically back up named-volume contents.', $serviceName, $name);
                }
            }
        }

        return array_values(array_unique($volumes));
    }

    private function devices(mixed $devices): array
    {
        if (!is_array($devices)) {
            return [];
        }
        $result = [];
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $host = trim((string) ($device['PathOnHost'] ?? ''));
            $container = trim((string) ($device['PathInContainer'] ?? ''));
            if ($host === '' || $container === '') {
                continue;
            }
            $entry = $host . ':' . $container;
            $permissions = trim((string) ($device['CgroupPermissions'] ?? ''));
            if ($permissions !== '') {
                $entry .= ':' . $permissions;
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function copyScalar(array &$target, string $targetKey, array $source, string $sourceKey): void
    {
        $value = trim((string) ($source[$sourceKey] ?? ''));
        if ($value !== '') {
            $target[$targetKey] = $value;
        }
    }

    private function composeNetworkName(string $networkName, string $projectName): string
    {
        if ($networkName === $projectName . '_default' || $networkName === $projectName . '-default') {
            return 'default';
        }
        return $this->safeName($networkName);
    }

    private function safeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'restored-app';
        $value = trim($value, '-_.');
        return $value !== '' ? $value : 'restored-app';
    }

    private function toYaml(mixed $value, int $indent = 0): string
    {
        if (!is_array($value)) {
            return str_repeat(' ', $indent) . $this->yamlScalar($value) . "\n";
        }

        $lines = '';
        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            $padding = str_repeat(' ', $indent);
            if ($isList) {
                if (is_array($child)) {
                    $lines .= $padding . "-\n" . $this->toYaml($child, $indent + 2);
                } else {
                    $lines .= $padding . '- ' . $this->yamlScalar($child) . "\n";
                }
                continue;
            }

            $yamlKey = $this->yamlScalar((string) $key);
            if (is_array($child)) {
                if ($child === []) {
                    $lines .= $padding . $yamlKey . ": {}\n";
                } else {
                    $lines .= $padding . $yamlKey . ":\n" . $this->toYaml($child, $indent + 2);
                }
            } else {
                $lines .= $padding . $yamlKey . ': ' . $this->yamlScalar($child) . "\n";
            }
        }
        return $lines;
    }

    private function yamlScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        return json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
