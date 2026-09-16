<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

/**
 * Recreates a restored application through the Docker Engine API.
 *
 * The service only installs after an original-path restore completed. Existing
 * Docker applications are never replaced. If container creation/start fails,
 * Docker objects created by this install attempt are removed, while restored
 * application data is deliberately left untouched.
 */
final class ApplicationInstallService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly DockerEngineClient $docker,
        private readonly TaskQueueService $queue,
    ) {
    }

    public function latestForRestore(int $restoreRunId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM application_install_runs WHERE application_restore_run_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $restoreRunId]
        );
        return $this->decode($row);
    }

    public function findById(int $id): ?array
    {
        return $this->decode($this->database->fetchOne(
            'SELECT * FROM application_install_runs WHERE id = :id',
            ['id' => $id]
        ));
    }

    public function enqueue(string $restoreUuid, string $confirm): array
    {
        if (strtoupper(trim($confirm)) !== 'INSTALL') {
            throw new InvalidArgumentException('Type INSTALL to confirm application creation and startup.');
        }

        $restore = $this->restoreByUuid($restoreUuid);
        if ($restore === null) {
            throw new InvalidArgumentException('Application restore not found.');
        }
        if ($restore['status'] !== 'success') {
            throw new InvalidArgumentException('The application data restore must complete successfully before installation.');
        }
        if ($restore['mode'] !== 'original') {
            throw new InvalidArgumentException('Automatic installation requires an Original paths restore. Staging-only restores cannot be installed automatically.');
        }

        $selected = $this->decodeList((string) $restore['selected_mounts_json']);
        $applied = $this->decodeList((string) $restore['applied_paths_json']);
        $appliedLookup = array_fill_keys(array_map('strval', $applied), true);
        foreach ($selected as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $source = (string) ($mount['source'] ?? '');
            if ($source !== '' && !isset($appliedLookup[$source])) {
                throw new InvalidArgumentException(sprintf('Application data path has not been restored to its original location: %s', $source));
            }
        }

        if ($this->isApplicationPresent((string) $restore['app_key'])) {
            throw new InvalidArgumentException('This application is already present in Docker. ZimaBackup will not replace an existing deployment.');
        }

        $active = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM application_install_runs WHERE application_restore_run_id = :id AND status IN ('pending', 'running')",
            ['id' => (int) $restore['id']]
        );
        if ($active > 0) {
            throw new InvalidArgumentException('An installation is already queued or running for this restore.');
        }

        $successful = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM application_install_runs WHERE application_restore_run_id = :id AND status = 'success'",
            ['id' => (int) $restore['id']]
        );
        if ($successful > 0) {
            throw new InvalidArgumentException('This restored application has already been installed successfully.');
        }

        $manifest = $this->loadManifest($restore);
        $projectName = $this->safeName((string) ($manifest['project_name'] ?? $manifest['name'] ?? $restore['app_name']));
        $uuid = Uuid::v4();
        $now = date('c');

        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->database->execute(
                "INSERT INTO application_install_runs(uuid, application_restore_run_id, app_key, app_name, project_name, status, stage, created_at) " .
                "VALUES (:uuid, :restore_id, :app_key, :app_name, :project_name, 'pending', 'queued', :created_at)",
                [
                    'uuid' => $uuid,
                    'restore_id' => (int) $restore['id'],
                    'app_key' => (string) $restore['app_key'],
                    'app_name' => (string) $restore['app_name'],
                    'project_name' => $projectName,
                    'created_at' => $now,
                ]
            );
            $installId = $this->database->lastInsertId();
            $this->queue->enqueue('application.install', ['application_install_run_id' => $installId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->findById($installId) ?? [];
    }

    public function execute(int $installRunId): void
    {
        $install = $this->database->fetchOne(
            'SELECT ai.*, ar.uuid AS restore_uuid, ar.status AS restore_status, ar.mode AS restore_mode, ar.staging_path, ' .
            'ar.selected_mounts_json, ar.applied_paths_json, sa.manifest_path ' .
            'FROM application_install_runs ai ' .
            'JOIN application_restore_runs ar ON ar.id = ai.application_restore_run_id ' .
            'JOIN snapshot_applications sa ON sa.id = ar.snapshot_application_id ' .
            'WHERE ai.id = :id',
            ['id' => $installRunId]
        );
        if ($install === null) {
            throw new RuntimeException('Application install run not found.');
        }
        if ($install['restore_status'] !== 'success' || $install['restore_mode'] !== 'original') {
            throw new RuntimeException('Application installation requires a successful original-path restore.');
        }
        if ($this->isApplicationPresent((string) $install['app_key'])) {
            throw new RuntimeException('Application appeared in Docker before installation started. Existing deployments are never replaced.');
        }

        $manifest = $this->loadManifest($install);
        $containers = is_array($manifest['containers'] ?? null) ? array_values($manifest['containers']) : [];
        if ($containers === []) {
            throw new RuntimeException('The backup manifest contains no container to install.');
        }

        $projectName = $this->safeName((string) ($manifest['project_name'] ?? $manifest['name'] ?? $install['app_name']));
        $hostRoots = $this->currentHostRoots();

        $this->database->execute(
            "UPDATE application_install_runs SET status = 'running', stage = 'preflight', started_at = :started_at, finished_at = NULL, error = NULL WHERE id = :id",
            ['started_at' => date('c'), 'id' => $installRunId]
        );

        $existingNames = [];
        foreach ($this->docker->containers(true) as $existing) {
            foreach ((array) ($existing['Names'] ?? []) as $name) {
                $existingNames[ltrim((string) $name, '/')] = true;
            }
        }

        $plans = [];
        $networkNames = [];
        foreach ($containers as $index => $container) {
            if (!is_array($container)) {
                continue;
            }
            $plan = $this->containerPlan($container, $projectName, $hostRoots, $index);
            if (isset($existingNames[$plan['name']])) {
                throw new RuntimeException(sprintf('Docker container name already exists: %s', $plan['name']));
            }
            $plans[] = $plan;
            foreach ($plan['networks'] as $networkName) {
                if (!in_array($networkName, ['bridge', 'host', 'none'], true)) {
                    $networkNames[$networkName] = true;
                }
            }
        }
        if ($plans === []) {
            throw new RuntimeException('No installable container could be reconstructed.');
        }

        $createdContainers = [];
        $createdNetworks = [];

        try {
            $this->setStage($installRunId, 'pulling-images');
            foreach (array_values(array_unique(array_column($plans, 'image'))) as $image) {
                if (!$this->docker->imageExists($image)) {
                    $this->docker->pullImage($image);
                }
            }

            $this->setStage($installRunId, 'creating-networks');
            foreach (array_keys($networkNames) as $networkName) {
                if ($this->docker->networkExists($networkName)) {
                    continue;
                }
                $networkId = $this->docker->createNetwork($networkName, [
                    'com.docker.compose.project' => $projectName,
                    'com.docker.compose.network' => $this->networkRole($networkName, $projectName),
                    'org.zimabackup.restored' => 'true',
                ]);
                $createdNetworks[$networkName] = $networkId;
                $this->persistObjects($installRunId, $createdContainers, $createdNetworks);
            }

            $this->setStage($installRunId, 'creating-containers');
            foreach ($plans as $plan) {
                $containerId = $this->docker->createContainer($plan['name'], $plan['configuration']);
                $createdContainers[$plan['name']] = $containerId;
                $this->persistObjects($installRunId, $createdContainers, $createdNetworks);
            }

            $this->setStage($installRunId, 'starting-containers');
            foreach ($createdContainers as $containerId) {
                $this->docker->startContainer($containerId);
            }

            $this->setStage($installRunId, 'verifying');
            $this->verifyRunning($createdContainers);

            $this->database->execute(
                "UPDATE application_install_runs SET status = 'success', stage = 'running', container_ids_json = :containers, created_networks_json = :networks, finished_at = :finished_at, error = NULL WHERE id = :id",
                [
                    'containers' => json_encode($createdContainers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'networks' => json_encode($createdNetworks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'finished_at' => date('c'),
                    'id' => $installRunId,
                ]
            );
        } catch (Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($createdContainers, true) as $name => $containerId) {
                try {
                    $this->docker->stopContainer($containerId, 5);
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = sprintf('stop %s: %s', $name, $rollbackException->getMessage());
                }
                try {
                    $this->docker->removeContainer($containerId, true);
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = sprintf('remove %s: %s', $name, $rollbackException->getMessage());
                }
            }
            foreach (array_reverse($createdNetworks, true) as $networkName => $networkId) {
                try {
                    $this->docker->removeNetwork($networkId);
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = sprintf('network %s: %s', $networkName, $rollbackException->getMessage());
                }
            }

            $message = $exception->getMessage();
            if ($rollbackErrors !== []) {
                $message .= ' Docker rollback warnings: ' . implode(' | ', $rollbackErrors);
            }
            $this->markFailed($installRunId, $message);
            throw new RuntimeException($message, 0, $exception);
        }
    }

    public function markFailed(int $installRunId, string $error): void
    {
        $this->database->execute(
            "UPDATE application_install_runs SET status = 'failed', stage = 'failed', finished_at = :finished_at, error = :error WHERE id = :id",
            [
                'finished_at' => date('c'),
                'error' => substr($error, 0, 6000),
                'id' => $installRunId,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    private function restoreByUuid(string $uuid): ?array
    {
        return $this->database->fetchOne(
            'SELECT ar.*, sa.manifest_path FROM application_restore_runs ar ' .
            'JOIN snapshot_applications sa ON sa.id = ar.snapshot_application_id ' .
            'WHERE ar.uuid = :uuid',
            ['uuid' => $uuid]
        );
    }

    /** @return array<string, mixed> */
    private function loadManifest(array $restoreOrInstall): array
    {
        $stagingLogical = (string) ($restoreOrInstall['staging_path'] ?? '');
        $manifestPath = trim((string) ($restoreOrInstall['manifest_path'] ?? ''));
        if ($stagingLogical === '' || $manifestPath === '') {
            throw new RuntimeException('Restored application manifest path is missing.');
        }
        $staging = $this->paths->toContainerPath($stagingLogical);
        $localManifest = rtrim($staging, '/') . '/' . ltrim($manifestPath, '/');
        if (!is_readable($localManifest)) {
            throw new RuntimeException(sprintf('Restored application manifest is missing from staging: %s', $manifestPath));
        }

        try {
            $manifest = json_decode((string) file_get_contents($localManifest), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Restored application manifest is invalid JSON.', 0, $exception);
        }
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'zimabackup.application-manifest.v1') {
            throw new RuntimeException('Restored application manifest has an unsupported schema.');
        }
        return $manifest;
    }

    /** @return array{0?: never}|array<string, string> */
    private function currentHostRoots(): array
    {
        $roots = ['/DATA' => '/DATA', '/media' => '/media'];
        foreach ($this->docker->containers(true) as $container) {
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            if (($labels['org.zimabackup.internal'] ?? null) !== 'true') {
                continue;
            }
            $id = (string) ($container['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            $inspect = $this->docker->inspectContainer($id);
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

    /** @return array{name:string,image:string,networks:list<string>,configuration:array<string,mixed>} */
    private function containerPlan(array $container, string $projectName, array $hostRoots, int $index): array
    {
        $image = trim((string) ($container['image'] ?? ''));
        if ($image === '') {
            throw new RuntimeException('A restored container has no image reference.');
        }

        $service = $this->safeName((string) ($container['service'] ?? ('service-' . ($index + 1))));
        $name = trim((string) ($container['name'] ?? ''));
        $name = ltrim($name, '/');
        if ($name === '') {
            $name = $projectName . '-' . $service . '-1';
        }

        $labels = is_array($container['compose_metadata'] ?? null) ? $container['compose_metadata'] : [];
        foreach ($labels as $key => $value) {
            $labels[(string) $key] = (string) $value;
        }
        $labels['com.docker.compose.project'] = $projectName;
        $labels['com.docker.compose.service'] = $service;
        $labels['com.docker.compose.oneoff'] = 'False';
        $labels['org.zimabackup.restored'] = 'true';

        $configuration = [
            'Image' => $image,
            'Labels' => $labels,
        ];

        $this->copyArray($configuration, 'Env', $container, 'environment');
        $this->copyArray($configuration, 'Cmd', $container, 'command');
        if (array_key_exists('entrypoint', $container) && $container['entrypoint'] !== null && $container['entrypoint'] !== []) {
            $configuration['Entrypoint'] = $container['entrypoint'];
        }
        $this->copyScalar($configuration, 'User', $container, 'user');
        $this->copyScalar($configuration, 'WorkingDir', $container, 'working_dir');
        $this->copyScalar($configuration, 'Domainname', $container, 'domainname');
        if (is_array($container['exposed_ports'] ?? null) && $container['exposed_ports'] !== []) {
            $configuration['ExposedPorts'] = $container['exposed_ports'];
        }
        if (is_array($container['healthcheck'] ?? null) && $container['healthcheck'] !== []) {
            $configuration['Healthcheck'] = $container['healthcheck'];
        }
        if (trim((string) ($container['stop_signal'] ?? '')) !== '') {
            $configuration['StopSignal'] = (string) $container['stop_signal'];
        }

        $hostConfig = [];
        $binds = [];
        foreach ((array) ($container['mounts'] ?? []) as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $type = (string) ($mount['Type'] ?? $mount['type'] ?? '');
            $destination = trim((string) ($mount['Destination'] ?? $mount['destination'] ?? ''));
            if ($destination === '') {
                continue;
            }
            if ($type === 'volume') {
                $volumeName = trim((string) ($mount['Name'] ?? ''));
                throw new RuntimeException(sprintf('Automatic install is blocked because service %s uses named Docker volume %s. Named-volume data is not backed up yet.', $service, $volumeName ?: '(unknown)'));
            }
            if ($type !== 'bind') {
                throw new RuntimeException(sprintf('Automatic install does not support mount type %s for service %s yet.', $type ?: 'unknown', $service));
            }

            $logicalSource = trim((string) ($mount['Source'] ?? $mount['source'] ?? ''));
            $hostSource = $this->hostPathForLogicalSource($logicalSource, $hostRoots, (bool) ($mount['RW'] ?? true));
            $binding = $hostSource . ':' . $destination;
            if (($mount['RW'] ?? true) === false) {
                $binding .= ':ro';
            }
            $binds[] = $binding;
        }
        if ($binds !== []) {
            $hostConfig['Binds'] = array_values(array_unique($binds));
        }

        foreach ([
            'restart_policy' => 'RestartPolicy',
            'port_bindings' => 'PortBindings',
            'devices' => 'Devices',
            'device_requests' => 'DeviceRequests',
            'cap_add' => 'CapAdd',
            'cap_drop' => 'CapDrop',
            'security_opt' => 'SecurityOpt',
            'extra_hosts' => 'ExtraHosts',
            'dns' => 'Dns',
            'dns_search' => 'DnsSearch',
            'sysctls' => 'Sysctls',
            'group_add' => 'GroupAdd',
            'ulimits' => 'Ulimits',
        ] as $sourceKey => $targetKey) {
            $value = $container[$sourceKey] ?? null;
            if (is_array($value) && $value !== []) {
                $hostConfig[$targetKey] = $value;
            }
        }

        if (($container['privileged'] ?? false) === true) {
            $hostConfig['Privileged'] = true;
        }
        if (($container['read_only_rootfs'] ?? false) === true) {
            $hostConfig['ReadonlyRootfs'] = true;
        }
        foreach ([
            'shm_size' => 'ShmSize',
        ] as $sourceKey => $targetKey) {
            $value = (int) ($container[$sourceKey] ?? 0);
            if ($value > 0) {
                $hostConfig[$targetKey] = $value;
            }
        }
        foreach ([
            'ipc_mode' => 'IpcMode',
            'pid_mode' => 'PidMode',
            'runtime' => 'Runtime',
        ] as $sourceKey => $targetKey) {
            $value = trim((string) ($container[$sourceKey] ?? ''));
            if ($value !== '') {
                $hostConfig[$targetKey] = $value;
            }
        }
        $resources = is_array($container['resources'] ?? null) ? $container['resources'] : [];
        foreach (['memory' => 'Memory', 'nano_cpus' => 'NanoCpus', 'cpu_shares' => 'CpuShares'] as $sourceKey => $targetKey) {
            $value = (int) ($resources[$sourceKey] ?? 0);
            if ($value > 0) {
                $hostConfig[$targetKey] = $value;
            }
        }

        $containerNetworks = is_array($container['networks'] ?? null) ? $container['networks'] : [];
        $networkNames = array_values(array_map('strval', array_keys($containerNetworks)));
        $networkMode = trim((string) ($container['network_mode'] ?? ''));
        if (in_array($networkMode, ['host', 'none', 'bridge'], true)) {
            $hostConfig['NetworkMode'] = $networkMode;
            if ($networkMode !== 'bridge') {
                $networkNames = [];
            }
        } elseif ($networkNames !== []) {
            $primary = in_array($networkMode, $networkNames, true) ? $networkMode : $networkNames[0];
            $hostConfig['NetworkMode'] = $primary;
        }

        if ($hostConfig !== []) {
            $configuration['HostConfig'] = $hostConfig;
        }

        if ($networkNames !== []) {
            $endpoints = [];
            foreach ($networkNames as $networkName) {
                if (in_array($networkName, ['bridge', 'host', 'none'], true)) {
                    continue;
                }
                $networkData = is_array($containerNetworks[$networkName] ?? null) ? $containerNetworks[$networkName] : [];
                $aliases = array_values(array_unique(array_filter(array_map('strval', (array) ($networkData['aliases'] ?? [])))));
                if (!in_array($service, $aliases, true)) {
                    $aliases[] = $service;
                }
                $endpoints[$networkName] = ['Aliases' => $aliases];
            }
            if ($endpoints !== []) {
                $configuration['NetworkingConfig'] = ['EndpointsConfig' => $endpoints];
            }
        }

        return [
            'name' => $name,
            'image' => $image,
            'networks' => $networkNames,
            'configuration' => $configuration,
        ];
    }

    private function hostPathForLogicalSource(string $source, array $hostRoots, bool $readWrite): string
    {
        $source = rtrim($source, '/');
        foreach (['/DATA', '/media'] as $logicalRoot) {
            if ($source === $logicalRoot || str_starts_with($source, $logicalRoot . '/')) {
                $hostRoot = rtrim((string) ($hostRoots[$logicalRoot] ?? $logicalRoot), '/');
                $candidate = $hostRoot . substr($source, strlen($logicalRoot));
                if (!file_exists($this->paths->toContainerPath($source))) {
                    throw new RuntimeException(sprintf('Restored bind source does not exist: %s', $source));
                }
                return $candidate;
            }
        }

        // Two common read-only system mounts are safe to reuse verbatim. Other
        // arbitrary host binds require manual review instead of automatic root
        // access during a disaster recovery install.
        if (!$readWrite && in_array($source, ['/etc/localtime', '/etc/timezone'], true)) {
            return $source;
        }
        throw new RuntimeException(sprintf('Automatic install refused host bind outside /DATA and /media: %s', $source));
    }

    /** @param array<string,string> $containers */
    private function verifyRunning(array $containers): void
    {
        $deadline = microtime(true) + 15.0;
        $lastProblems = [];

        do {
            $lastProblems = [];
            $allRunning = true;
            foreach ($containers as $name => $containerId) {
                $inspect = $this->docker->inspectContainer($containerId);
                $state = is_array($inspect['State'] ?? null) ? $inspect['State'] : [];
                if (($state['Running'] ?? false) !== true) {
                    $allRunning = false;
                    $lastProblems[] = sprintf('%s is %s (exit %s)', $name, (string) ($state['Status'] ?? 'not running'), (string) ($state['ExitCode'] ?? '?'));
                    continue;
                }
                $health = is_array($state['Health'] ?? null) ? trim((string) ($state['Health']['Status'] ?? '')) : '';
                if ($health === 'unhealthy') {
                    throw new RuntimeException(sprintf('Container %s started but is unhealthy.', $name));
                }
            }
            if ($allRunning) {
                return;
            }
            usleep(750000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Restored containers did not remain running: ' . implode('; ', $lastProblems));
    }

    private function isApplicationPresent(string $appKey): bool
    {
        foreach ($this->docker->containers(true) as $container) {
            if (!is_array($container)) {
                continue;
            }
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            $project = trim((string) ($labels['com.docker.compose.project'] ?? ''));
            $id = (string) ($container['Id'] ?? '');
            $candidate = $project !== '' ? 'compose:' . $project : ($id !== '' ? 'container:' . $id : '');
            if ($candidate !== '' && hash_equals($appKey, $candidate)) {
                return true;
            }
        }
        return false;
    }

    private function setStage(int $id, string $stage): void
    {
        $this->database->execute(
            'UPDATE application_install_runs SET stage = :stage WHERE id = :id',
            ['stage' => $stage, 'id' => $id]
        );
    }

    private function persistObjects(int $id, array $containers, array $networks): void
    {
        $this->database->execute(
            'UPDATE application_install_runs SET container_ids_json = :containers, created_networks_json = :networks WHERE id = :id',
            [
                'containers' => json_encode($containers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'networks' => json_encode($networks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'id' => $id,
            ]
        );
    }

    private function networkRole(string $networkName, string $projectName): string
    {
        foreach ([$projectName . '_', $projectName . '-'] as $prefix) {
            if (str_starts_with($networkName, $prefix)) {
                return substr($networkName, strlen($prefix)) ?: 'default';
            }
        }
        return $networkName;
    }

    private function safeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'restored-app';
        $value = trim($value, '-_.');
        return $value !== '' ? $value : 'restored-app';
    }

    private function copyArray(array &$target, string $targetKey, array $source, string $sourceKey): void
    {
        $value = $source[$sourceKey] ?? null;
        if (is_array($value) && $value !== []) {
            $target[$targetKey] = array_values($value);
        }
    }

    private function copyScalar(array &$target, string $targetKey, array $source, string $sourceKey): void
    {
        $value = trim((string) ($source[$sourceKey] ?? ''));
        if ($value !== '') {
            $target[$targetKey] = $value;
        }
    }

    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['container_ids'] = $this->decodeList((string) ($row['container_ids_json'] ?? '[]'));
        $row['created_networks'] = $this->decodeList((string) ($row['created_networks_json'] ?? '[]'));
        return $row;
    }

    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
