<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;

final class ApplicationDiscoveryService
{
    public function __construct(
        private readonly Database $database,
        private readonly DockerEngineClient $docker,
        private readonly TaskQueueService $queue,
        private readonly string $manifestDirectory,
        private readonly string $ownComposeProject = 'zimabackup',
    ) {
    }

    public function all(): array
    {
        $apps = $this->database->fetchAll(
            'SELECT * FROM discovered_apps WHERE present = 1 ORDER BY name COLLATE NOCASE ASC'
        );

        foreach ($apps as &$app) {
            $app['mounts'] = $this->database->fetchAll(
                'SELECT * FROM discovered_app_mounts WHERE application_id = :id ORDER BY recommended DESC, source COLLATE NOCASE ASC, destination COLLATE NOCASE ASC',
                ['id' => $app['id']]
            );
        }
        unset($app);

        return $apps;
    }

    public function findById(int $id): ?array
    {
        $app = $this->database->fetchOne(
            'SELECT * FROM discovered_apps WHERE id = :id AND present = 1',
            ['id' => $id]
        );
        if ($app === null) {
            return null;
        }

        $app['mounts'] = $this->database->fetchAll(
            'SELECT * FROM discovered_app_mounts WHERE application_id = :id ORDER BY recommended DESC, id ASC',
            ['id' => $id]
        );

        return $app;
    }

    public function findByKey(string $appKey): ?array
    {
        $app = $this->database->fetchOne(
            'SELECT * FROM discovered_apps WHERE app_key = :app_key',
            ['app_key' => $appKey]
        );
        if ($app === null) {
            return null;
        }

        $app['mounts'] = $this->database->fetchAll(
            'SELECT * FROM discovered_app_mounts WHERE application_id = :id ORDER BY recommended DESC, id ASC',
            ['id' => $app['id']]
        );

        return $app;
    }

    public function status(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT key, value, updated_at FROM settings WHERE key IN ('apps.discovery.status', 'apps.discovery.error', 'apps.discovery.last_scan')"
        );
        $status = [
            'status' => 'unknown',
            'error' => null,
            'last_scan' => null,
        ];

        foreach ($rows as $row) {
            if ($row['key'] === 'apps.discovery.status') {
                $status['status'] = $row['value'];
            } elseif ($row['key'] === 'apps.discovery.error') {
                $status['error'] = $row['value'];
            } elseif ($row['key'] === 'apps.discovery.last_scan') {
                $status['last_scan'] = $row['value'];
            }
        }

        return $status;
    }

    public function enqueueRefresh(): bool
    {
        $active = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM operations WHERE type = 'apps.discover' AND status IN ('pending', 'running')"
        );
        if ($active > 0) {
            return false;
        }

        $this->queue->enqueue('apps.discover', []);
        $this->setSetting('apps.discovery.status', 'queued');
        return true;
    }

    /**
     * Discover Docker Compose projects without persisting container secrets.
     * Exact environment values are read only when a backup manifest is created.
     */
    public function refresh(): int
    {
        $this->setSetting('apps.discovery.status', 'scanning');
        $this->setSetting('apps.discovery.error', null);

        try {
            $groups = $this->discoverGroups(false);
            $now = date('c');
            $pdo = $this->database->pdo();
            $pdo->exec('BEGIN IMMEDIATE');

            try {
                $this->database->execute('UPDATE discovered_apps SET present = 0');

                foreach ($groups as $group) {
                    $existing = $this->database->fetchOne(
                        'SELECT id FROM discovered_apps WHERE app_key = :app_key',
                        ['app_key' => $group['app_key']]
                    );

                    $manifestJson = json_encode($group['manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    if ($existing === null) {
                        $this->database->execute(
                            'INSERT INTO discovered_apps(app_key, name, provider, project_name, status, image, container_count, manifest_json, present, discovered_at, updated_at) ' .
                            'VALUES (:app_key, :name, :provider, :project_name, :status, :image, :container_count, :manifest_json, 1, :discovered_at, :updated_at)',
                            [
                                'app_key' => $group['app_key'],
                                'name' => $group['name'],
                                'provider' => 'docker-engine',
                                'project_name' => $group['project_name'],
                                'status' => $group['status'],
                                'image' => $group['image'],
                                'container_count' => count($group['containers']),
                                'manifest_json' => $manifestJson,
                                'discovered_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                        $appId = $this->database->lastInsertId();
                    } else {
                        $appId = (int) $existing['id'];
                        $this->database->execute(
                            'UPDATE discovered_apps SET name = :name, provider = :provider, project_name = :project_name, status = :status, image = :image, ' .
                            'container_count = :container_count, manifest_json = :manifest_json, present = 1, updated_at = :updated_at WHERE id = :id',
                            [
                                'name' => $group['name'],
                                'provider' => 'docker-engine',
                                'project_name' => $group['project_name'],
                                'status' => $group['status'],
                                'image' => $group['image'],
                                'container_count' => count($group['containers']),
                                'manifest_json' => $manifestJson,
                                'updated_at' => $now,
                                'id' => $appId,
                            ]
                        );
                        $this->database->execute('DELETE FROM discovered_app_mounts WHERE application_id = :id', ['id' => $appId]);
                    }

                    foreach ($group['mounts'] as $mount) {
                        $this->database->execute(
                            'INSERT INTO discovered_app_mounts(application_id, container_name, service_name, source, destination, mount_type, read_write, eligible, recommended, reason, created_at) ' .
                            'VALUES (:application_id, :container_name, :service_name, :source, :destination, :mount_type, :read_write, :eligible, :recommended, :reason, :created_at)',
                            [
                                'application_id' => $appId,
                                'container_name' => $mount['container_name'],
                                'service_name' => $mount['service_name'],
                                'source' => $mount['source'],
                                'destination' => $mount['destination'],
                                'mount_type' => $mount['mount_type'],
                                'read_write' => $mount['read_write'] ? 1 : 0,
                                'eligible' => $mount['eligible'] ? 1 : 0,
                                'recommended' => $mount['recommended'] ? 1 : 0,
                                'reason' => $mount['reason'],
                                'created_at' => $now,
                            ]
                        );
                    }
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            $this->setSetting('apps.discovery.status', 'ready');
            $this->setSetting('apps.discovery.last_scan', $now);
            $this->setSetting('apps.discovery.error', null);
            return count($groups);
        } catch (Throwable $exception) {
            $this->setSetting('apps.discovery.status', 'failed');
            $this->setSetting('apps.discovery.error', substr($exception->getMessage(), 0, 2000));
            throw $exception;
        }
    }

    /**
     * Create a short-lived, complete restore manifest for one application.
     * Environment values can contain secrets, so this file is mode 0600 and is
     * deleted by BackupService immediately after Restic has snapshotted it.
     */
    public function createBackupManifest(string $appKey, string $jobUuid, int $runId): string
    {
        $paths = $this->createBackupManifests([$appKey], $jobUuid, $runId);
        return $paths[$appKey];
    }

    /**
     * Generate complete manifests for several selected applications with one
     * Docker scan. This avoids re-inspecting the entire daemon for every app in
     * the same backup run.
     *
     * @param list<string> $appKeys
     * @return array<string, string> app key => manifest path
     */
    public function createBackupManifests(array $appKeys, string $jobUuid, int $runId): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('strval', $appKeys))));
        if ($wanted === []) {
            return [];
        }

        $groups = $this->discoverGroups(true);
        $indexed = [];
        foreach ($groups as $group) {
            $indexed[(string) $group['app_key']] = $group;
        }

        $directory = rtrim($this->manifestDirectory, '/') . '/' . $jobUuid . '/' . $runId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the temporary application manifest directory.');
        }

        $paths = [];
        foreach ($wanted as $appKey) {
            $group = $indexed[$appKey] ?? null;
            if ($group === null) {
                throw new RuntimeException(sprintf('Application is no longer available in Docker: %s', $appKey));
            }

            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $appKey) ?: 'application';
            $path = $directory . '/' . $safeName . '.json';
            $payload = [
                'schema' => 'zimabackup.application-manifest.v1',
                'generated_at' => date('c'),
                'definition_kind' => 'docker-inspect',
                'restore_notice' => 'This is a normalized runtime definition. A future restore step will convert it to a ZimaOS/Docker Compose definition.',
                ...$group['manifest'],
            ];

            try {
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to serialize the application restore manifest.', 0, $exception);
            }

            if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the application restore manifest.');
            }
            chmod($path, 0600);
            $paths[$appKey] = $path;
        }

        return $paths;
    }

    public function cleanupBackupManifests(string $jobUuid, int $runId): void
    {
        $directory = rtrim($this->manifestDirectory, '/') . '/' . $jobUuid . '/' . $runId;
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($directory);
        @rmdir(dirname($directory));
    }

    /** @return list<array<string, mixed>> */
    private function discoverGroups(bool $includeSecrets): array
    {
        $containers = $this->docker->containers(true);
        $hostRoots = $this->discoverHostRoots($containers);
        $groups = [];

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ($id === '') {
                continue;
            }

            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            if (($labels['org.zimabackup.internal'] ?? null) === 'true') {
                continue;
            }

            $project = trim((string) ($labels['com.docker.compose.project'] ?? ''));
            if ($project !== '' && $project === $this->ownComposeProject) {
                continue;
            }

            $name = ltrim((string) (($container['Names'][0] ?? '') ?: substr($id, 0, 12)), '/');
            $appKey = $project !== '' ? 'compose:' . $project : 'container:' . $id;

            $inspect = $this->docker->inspectContainer($id);
            $config = is_array($inspect['Config'] ?? null) ? $inspect['Config'] : [];
            $inspectLabels = is_array($config['Labels'] ?? null) ? $config['Labels'] : $labels;
            $service = trim((string) ($inspectLabels['com.docker.compose.service'] ?? ''));
            $displayName = $project !== '' ? $this->humanizeName($project) : $this->humanizeName($name);

            if (!isset($groups[$appKey])) {
                $groups[$appKey] = [
                    'app_key' => $appKey,
                    'name' => $displayName,
                    'project_name' => $project !== '' ? $project : null,
                    'status' => 'stopped',
                    'image' => (string) ($config['Image'] ?? $container['Image'] ?? ''),
                    'containers' => [],
                    'mounts' => [],
                ];
            }

            $state = is_array($inspect['State'] ?? null) ? $inspect['State'] : [];
            if (($state['Running'] ?? false) === true) {
                $groups[$appKey]['status'] = 'running';
            }

            $containerManifest = $this->normalizeContainer($inspect, $includeSecrets, $hostRoots);
            $groups[$appKey]['containers'][] = $containerManifest;

            foreach (($inspect['Mounts'] ?? []) as $mount) {
                if (!is_array($mount)) {
                    continue;
                }
                $groups[$appKey]['mounts'][] = $this->normalizeMount($mount, $name, $service !== '' ? $service : null, $hostRoots);
            }
        }

        $result = [];
        foreach ($groups as $group) {
            $group['mounts'] = $this->deduplicateMounts($group['mounts']);
            $group['manifest'] = [
                'app_key' => $group['app_key'],
                'name' => $group['name'],
                'provider' => 'docker-engine',
                'project_name' => $group['project_name'],
                'status' => $group['status'],
                'containers' => $group['containers'],
            ];
            $result[] = $group;
        }

        usort($result, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        return $result;
    }

    private function normalizeContainer(array $inspect, bool $includeSecrets, array $hostRoots = []): array
    {
        $config = is_array($inspect['Config'] ?? null) ? $inspect['Config'] : [];
        $hostConfig = is_array($inspect['HostConfig'] ?? null) ? $inspect['HostConfig'] : [];
        $networkSettings = is_array($inspect['NetworkSettings'] ?? null) ? $inspect['NetworkSettings'] : [];
        $labels = is_array($config['Labels'] ?? null) ? $config['Labels'] : [];
        $environment = is_array($config['Env'] ?? null) ? $config['Env'] : [];

        if (!$includeSecrets) {
            $environment = array_values(array_map(static function (mixed $value): string {
                $value = (string) $value;
                $key = explode('=', $value, 2)[0];
                return $key . '=***';
            }, $environment));
        }

        $composeLabels = [];
        foreach ($labels as $key => $value) {
            if (str_starts_with((string) $key, 'com.docker.compose.') || str_contains(strtolower((string) $key), 'casaos')) {
                $composeLabels[$key] = $value;
            }
        }

        $networks = [];
        foreach (($networkSettings['Networks'] ?? []) as $networkName => $network) {
            if (!is_array($network)) {
                continue;
            }
            $networks[$networkName] = [
                'aliases' => $network['Aliases'] ?? [],
                'ip_address' => $network['IPAddress'] ?? null,
                'gateway' => $network['Gateway'] ?? null,
            ];
        }

        $portableMounts = [];
        foreach (($inspect['Mounts'] ?? []) as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $portable = $mount;
            $rawSource = (string) ($mount['Source'] ?? '');
            $logicalSource = $this->logicalSource($rawSource, $hostRoots);
            if ($logicalSource !== $rawSource) {
                $portable['HostSource'] = $rawSource;
                $portable['Source'] = $logicalSource;
            }
            $portableMounts[] = $portable;
        }

        return [
            'id' => (string) ($inspect['Id'] ?? ''),
            'name' => ltrim((string) ($inspect['Name'] ?? ''), '/'),
            'image' => (string) ($config['Image'] ?? ''),
            'image_id' => (string) ($inspect['Image'] ?? ''),
            'service' => $labels['com.docker.compose.service'] ?? null,
            'command' => $config['Cmd'] ?? [],
            'entrypoint' => $config['Entrypoint'] ?? null,
            'environment' => $environment,
            'working_dir' => $config['WorkingDir'] ?? '',
            'user' => $config['User'] ?? '',
            'hostname' => $config['Hostname'] ?? '',
            'domainname' => $config['Domainname'] ?? '',
            'exposed_ports' => $config['ExposedPorts'] ?? [],
            'declared_volumes' => $config['Volumes'] ?? [],
            'healthcheck' => $config['Healthcheck'] ?? null,
            'stop_signal' => $config['StopSignal'] ?? null,
            'restart_policy' => $hostConfig['RestartPolicy'] ?? [],
            'network_mode' => $hostConfig['NetworkMode'] ?? null,
            'port_bindings' => $hostConfig['PortBindings'] ?? [],
            'binds' => $hostConfig['Binds'] ?? [],
            'devices' => $hostConfig['Devices'] ?? [],
            'device_requests' => $hostConfig['DeviceRequests'] ?? [],
            'privileged' => (bool) ($hostConfig['Privileged'] ?? false),
            'cap_add' => $hostConfig['CapAdd'] ?? [],
            'cap_drop' => $hostConfig['CapDrop'] ?? [],
            'security_opt' => $hostConfig['SecurityOpt'] ?? [],
            'extra_hosts' => $hostConfig['ExtraHosts'] ?? [],
            'dns' => $hostConfig['Dns'] ?? [],
            'dns_search' => $hostConfig['DnsSearch'] ?? [],
            'sysctls' => $hostConfig['Sysctls'] ?? [],
            'shm_size' => $hostConfig['ShmSize'] ?? null,
            'ipc_mode' => $hostConfig['IpcMode'] ?? null,
            'pid_mode' => $hostConfig['PidMode'] ?? null,
            'group_add' => $hostConfig['GroupAdd'] ?? [],
            'read_only_rootfs' => (bool) ($hostConfig['ReadonlyRootfs'] ?? false),
            'runtime' => $hostConfig['Runtime'] ?? null,
            'ulimits' => $hostConfig['Ulimits'] ?? [],
            'resources' => [
                'memory' => $hostConfig['Memory'] ?? 0,
                'nano_cpus' => $hostConfig['NanoCpus'] ?? 0,
                'cpu_shares' => $hostConfig['CpuShares'] ?? 0,
            ],
            'mounts' => $portableMounts,
            'networks' => $networks,
            'compose_metadata' => $composeLabels,
        ];
    }

    private function normalizeMount(array $mount, string $containerName, ?string $service, array $hostRoots = []): array
    {
        $hostSource = (string) ($mount['Source'] ?? '');
        $source = $this->logicalSource($hostSource, $hostRoots);
        $destination = (string) ($mount['Destination'] ?? '');
        $type = (string) ($mount['Type'] ?? 'unknown');
        $eligible = $type === 'bind' && $this->isAllowedDataPath($source);
        $recommended = $eligible && $this->isRecommendedPath($source, $destination);

        $reason = null;
        if ($type !== 'bind') {
            $reason = 'Named Docker volumes are detected but are not backed up automatically in v0.5.';
        } elseif (!$eligible) {
            $reason = 'The host path is outside /DATA and /media.';
        } elseif ($recommended) {
            $reason = 'Persistent application configuration detected.';
        } else {
            $reason = 'Large/user data mount: available, but not selected by default.';
        }

        return [
            'container_name' => $containerName,
            'service_name' => $service,
            'source' => $source,
            'host_source' => $hostSource !== $source ? $hostSource : null,
            'destination' => $destination,
            'mount_type' => $type,
            'read_write' => (bool) ($mount['RW'] ?? false),
            'eligible' => $eligible,
            'recommended' => $recommended,
            'reason' => $reason,
        ];
    }

    /**
     * Infer how the host paths mounted into ZimaBackup map to logical ZimaOS
     * roots. This makes application discovery work both on real ZimaOS
     * (/DATA -> /DATA) and in development (./dev-data -> /DATA).
     *
     * @param list<array<string, mixed>> $containers
     * @return array<string, string> logical root => host source root
     */
    private function discoverHostRoots(array $containers): array
    {
        $roots = [
            '/DATA' => '/DATA',
            '/media' => '/media',
        ];

        foreach ($containers as $container) {
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            if (($labels['org.zimabackup.internal'] ?? null) !== 'true') {
                continue;
            }

            $id = (string) ($container['Id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $inspect = $this->docker->inspectContainer($id);
            } catch (Throwable) {
                continue;
            }

            foreach (($inspect['Mounts'] ?? []) as $mount) {
                if (!is_array($mount) || ($mount['Type'] ?? null) !== 'bind') {
                    continue;
                }

                $destination = rtrim((string) ($mount['Destination'] ?? ''), '/');
                $source = rtrim((string) ($mount['Source'] ?? ''), '/');
                if (in_array($destination, ['/DATA', '/media'], true) && $source !== '') {
                    $roots[$destination] = $source;
                }
            }
        }

        return $roots;
    }

    private function logicalSource(string $source, array $hostRoots): string
    {
        $source = rtrim($source, '/');
        if ($source === '') {
            return '';
        }

        $roots = $hostRoots + ['/DATA' => '/DATA', '/media' => '/media'];
        // Prefer the longest host root so nested custom mount roots resolve
        // deterministically if a user has unusual storage mappings.
        uasort($roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($roots as $logicalRoot => $hostRoot) {
            $hostRoot = rtrim((string) $hostRoot, '/');
            if ($hostRoot === '') {
                continue;
            }
            if ($source === $hostRoot || str_starts_with($source, $hostRoot . '/')) {
                return rtrim((string) $logicalRoot, '/') . substr($source, strlen($hostRoot));
            }
        }

        return $source;
    }

    private function isAllowedDataPath(string $source): bool
    {
        return $source === '/DATA'
            || str_starts_with($source, '/DATA/')
            || $source === '/media'
            || str_starts_with($source, '/media/');
    }

    private function isRecommendedPath(string $source, string $destination): bool
    {
        $haystack = strtolower($source . ' ' . $destination);
        foreach (['cache', 'transcode', '/tmp', '/temp'] as $ignored) {
            if (str_contains($haystack, $ignored)) {
                return false;
            }
        }

        if (str_starts_with($source, '/DATA/AppData/')) {
            return true;
        }

        foreach (['/config', '/configs', '/database', '/db', '/etc/'] as $hint) {
            if ($destination === rtrim($hint, '/') || str_starts_with($destination, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function deduplicateMounts(array $mounts): array
    {
        $seen = [];
        $result = [];
        foreach ($mounts as $mount) {
            $key = implode('|', [
                $mount['source'],
                $mount['destination'],
                $mount['service_name'] ?? '',
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $mount;
        }

        return $result;
    }

    private function humanizeName(string $value): string
    {
        $value = preg_replace('/[-_.]+/', ' ', trim($value)) ?: $value;
        return ucwords($value);
    }

    private function setSetting(string $key, ?string $value): void
    {
        $this->database->execute(
            'INSERT INTO settings(key, value, updated_at) VALUES (:key, :value, :updated_at) ' .
            'ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['key' => $key, 'value' => $value, 'updated_at' => date('c')]
        );
    }
}
