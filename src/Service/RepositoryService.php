<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class RepositoryService
{
    public function __construct(
        private readonly Database $database,
        private readonly PathService $paths,
        private readonly ResticService $restic,
        private readonly TaskQueueService $queue,
        private readonly string $secretsDirectory,
    ) {
    }

    public function all(): array
    {
        $repositories = $this->database->fetchAll(
            'SELECT r.*, ' .
            "(SELECT COUNT(*) FROM operations o WHERE o.repository_id = r.id AND o.status IN ('pending', 'running')) AS running_operations, " .
            "(SELECT COUNT(*) FROM backup_jobs bj WHERE bj.repository_id = r.id AND bj.deleted_at IS NULL) AS active_jobs " .
            'FROM repositories r WHERE r.archived_at IS NULL ORDER BY r.created_at DESC'
        );

        foreach ($repositories as &$repository) {
            $this->decoratePresence($repository);
        }
        unset($repository);

        return $repositories;
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->database->fetchOne('SELECT * FROM repositories WHERE uuid = :uuid', ['uuid' => $uuid]);
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM repositories WHERE id = :id', ['id' => $id]);
    }

    public function create(string $name, string $path): array
    {
        $name = $this->validateName($name);
        $path = $this->paths->normalizeLogicalPath($path);
        $containerPath = $this->paths->toContainerPath($path);

        if (in_array($path, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException('Choose a dedicated subdirectory, for example /media/Backup/ZimaBackup.');
        }

        if ((int) $this->database->scalar(
            'SELECT COUNT(*) FROM repositories WHERE path = :path AND archived_at IS NULL',
            ['path' => $path]
        ) > 0) {
            throw new InvalidArgumentException('An active repository already uses this path.');
        }

        if ($this->repositoryConfigExists($containerPath)) {
            throw new InvalidArgumentException(
                'A Restic repository already exists at this path. Reconnecting an existing repository is not implemented yet; do not initialize over existing backup data.'
            );
        }

        if (!$this->pathIsMissingOrEmpty($containerPath)) {
            throw new InvalidArgumentException('Repository destination must be absent or empty before initialization.');
        }

        $uuid = Uuid::v4();
        $recoveryKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $passwordFile = rtrim($this->secretsDirectory, '/') . '/' . $uuid . '.secret';

        if (!is_dir($this->secretsDirectory) && !mkdir($this->secretsDirectory, 0700, true) && !is_dir($this->secretsDirectory)) {
            throw new RuntimeException('Unable to create the repository secrets directory.');
        }
        if (file_put_contents($passwordFile, $recoveryKey . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the repository recovery key.');
        }
        chmod($passwordFile, 0600);

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $now = date('c');
            $this->database->execute(
                'INSERT INTO repositories(uuid, name, type, path, password_file, status, created_at, updated_at) ' .
                'VALUES (:uuid, :name, :type, :path, :password_file, :status, :created_at, :updated_at)',
                [
                    'uuid' => $uuid,
                    'name' => $name,
                    'type' => 'local',
                    'path' => $path,
                    'password_file' => $passwordFile,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $repositoryId = $this->database->lastInsertId();
            $operationId = $this->queue->enqueue('repository.init', ['repository_id' => $repositoryId]);
            $this->database->execute(
                'UPDATE operations SET repository_id = :repository_id WHERE id = :id',
                ['repository_id' => $repositoryId, 'id' => $operationId]
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($passwordFile);
            throw $exception;
        }

        $repository = $this->findByUuid($uuid);
        if ($repository === null) {
            throw new RuntimeException('Repository was created but could not be reloaded.');
        }

        return ['repository' => $repository, 'recovery_key' => $recoveryKey];
    }

    public function updateName(string $uuid, string $name): void
    {
        $repository = $this->findByUuid($uuid);
        if ($repository === null || $repository['archived_at'] !== null) {
            throw new InvalidArgumentException('Repository not found.');
        }
        $this->database->execute(
            'UPDATE repositories SET name = :name, updated_at = :updated_at WHERE id = :id',
            ['name' => $this->validateName($name), 'updated_at' => date('c'), 'id' => $repository['id']]
        );
    }

    public function enqueueCheck(string $uuid): void
    {
        $repository = $this->findByUuid($uuid);
        if ($repository === null || $repository['archived_at'] !== null) {
            throw new InvalidArgumentException('Repository not found.');
        }

        $containerPath = $this->paths->toContainerPath((string) $repository['path']);
        if (!$this->repositoryConfigExists($containerPath)) {
            $message = $this->missingMessage((string) $repository['path']);
            $this->markMissing((int) $repository['id'], $message);
            throw new InvalidArgumentException($message);
        }

        if (!in_array($repository['status'], ['ready', 'failed', 'missing'], true)) {
            throw new InvalidArgumentException('Repository must finish initialization before it can be checked.');
        }

        $active = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM operations WHERE repository_id = :id AND type = 'repository.check' AND status IN ('pending', 'running')",
            ['id' => $repository['id']]
        );
        if ($active > 0) {
            throw new InvalidArgumentException('A repository integrity check is already queued or running.');
        }

        $operationId = $this->queue->enqueue('repository.check', ['repository_id' => (int) $repository['id']]);
        $this->database->execute(
            'UPDATE operations SET repository_id = :repository_id WHERE id = :operation_id',
            ['repository_id' => $repository['id'], 'operation_id' => $operationId]
        );
        $this->database->execute(
            "UPDATE repositories SET last_check_status = 'queued', last_check_error = NULL WHERE id = :id",
            ['id' => $repository['id']]
        );
    }

    public function check(int $repositoryId): void
    {
        $repository = $this->findById($repositoryId);
        if ($repository === null) {
            throw new RuntimeException('Repository not found.');
        }

        $path = $this->paths->toContainerPath((string) $repository['path']);
        if (!$this->repositoryConfigExists($path)) {
            $message = $this->missingMessage((string) $repository['path']);
            $this->markMissing($repositoryId, $message);
            throw new RuntimeException($message);
        }

        $passwordFile = (string) $repository['password_file'];
        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key file is missing or unreadable.');
        }

        $this->database->execute(
            "UPDATE repositories SET last_check_status = 'running', last_check_error = NULL WHERE id = :id",
            ['id' => $repositoryId]
        );
        $this->restic->checkRepository($path, $passwordFile);
        $now = date('c');
        $this->database->execute(
            "UPDATE repositories SET status = 'ready', error = NULL, missing_since = NULL, last_check_status = 'success', last_check_at = :checked_at, last_check_error = NULL, updated_at = :checked_at WHERE id = :id",
            ['checked_at' => $now, 'id' => $repositoryId]
        );
    }

    public function markCheckFailed(int $repositoryId, string $error): void
    {
        $repository = $this->findById($repositoryId);
        if ($repository !== null) {
            $containerPath = $this->paths->toContainerPath((string) $repository['path']);
            if (!$this->repositoryConfigExists($containerPath)) {
                $this->markMissing($repositoryId, $this->missingMessage((string) $repository['path']));
                return;
            }
        }

        $this->database->execute(
            "UPDATE repositories SET last_check_status = 'failed', last_check_at = :checked_at, last_check_error = :error WHERE id = :id",
            ['checked_at' => date('c'), 'error' => substr($error, 0, 4000), 'id' => $repositoryId]
        );
    }

    public function enqueueReinitialize(string $uuid): void
    {
        $repository = $this->findByUuid($uuid);
        if ($repository === null || $repository['archived_at'] !== null) {
            throw new InvalidArgumentException('Repository not found.');
        }

        $containerPath = $this->paths->toContainerPath((string) $repository['path']);
        if ($this->repositoryConfigExists($containerPath)) {
            throw new InvalidArgumentException('A Restic config file is present again. Run an integrity check instead of reinitializing.');
        }
        if (!$this->pathIsMissingOrEmpty($containerPath)) {
            throw new InvalidArgumentException('Reinitialization is blocked because the repository path contains files. Nothing was changed.');
        }
        if (!is_readable((string) $repository['password_file'])) {
            throw new InvalidArgumentException('The existing repository recovery key is missing or unreadable.');
        }

        $activeOperations = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM operations WHERE repository_id = :id AND status IN ('pending', 'running')",
            ['id' => $repository['id']]
        );
        if ($activeOperations > 0) {
            throw new InvalidArgumentException('Wait for active repository operations to finish before reinitializing it.');
        }

        $operationId = $this->queue->enqueue('repository.reinitialize', ['repository_id' => (int) $repository['id']]);
        $this->database->execute(
            'UPDATE operations SET repository_id = :repository_id WHERE id = :operation_id',
            ['repository_id' => $repository['id'], 'operation_id' => $operationId]
        );
        $this->database->execute(
            "UPDATE repositories SET status = 'reinitializing', error = NULL, last_check_status = NULL, last_check_error = NULL, updated_at = :updated_at WHERE id = :id",
            ['updated_at' => date('c'), 'id' => $repository['id']]
        );
    }

    public function reinitialize(int $repositoryId): int
    {
        $repository = $this->findById($repositoryId);
        if ($repository === null) {
            throw new RuntimeException('Repository not found.');
        }

        $containerPath = $this->paths->toContainerPath((string) $repository['path']);
        if ($this->repositoryConfigExists($containerPath)) {
            throw new RuntimeException('Refusing to reinitialize: a Restic repository already exists at the configured path.');
        }
        if (!$this->pathIsMissingOrEmpty($containerPath)) {
            throw new RuntimeException('Refusing to reinitialize: the repository path is not empty.');
        }

        $passwordFile = (string) $repository['password_file'];
        if (!is_readable($passwordFile)) {
            throw new RuntimeException('Repository recovery key file is missing or unreadable.');
        }

        $parent = dirname($containerPath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create repository parent directory: %s', $parent));
        }

        $this->restic->initializeRepository($containerPath, $passwordFile);

        // Once Restic init succeeds this is a new, empty repository. Historical
        // snapshot IDs from the lost repository can no longer be considered
        // restorable, even if the verification step below later fails.
        $unavailable = $this->markHistoricalSnapshotsUnavailable($repositoryId);

        $this->restic->checkRepository($containerPath, $passwordFile);
        $now = date('c');
        $this->database->execute(
            "UPDATE repositories SET status = 'ready', error = NULL, missing_since = NULL, last_check_at = :updated_at, last_check_status = 'success', last_check_error = NULL, updated_at = :updated_at WHERE id = :id",
            ['updated_at' => $now, 'id' => $repositoryId]
        );

        return $unavailable;
    }

    public function markReinitializeFailed(int $repositoryId, string $error): void
    {
        $repository = $this->findById($repositoryId);
        if ($repository !== null) {
            $containerPath = $this->paths->toContainerPath((string) $repository['path']);
            if (!$this->repositoryConfigExists($containerPath)) {
                $this->markMissing($repositoryId, $this->missingMessage((string) $repository['path']));
                return;
            }
        }

        // If init already created a new Restic config before a later step
        // failed, the old snapshot identifiers are still invalid for this path.
        $this->markHistoricalSnapshotsUnavailable($repositoryId);
        $this->markFailed($repositoryId, $error);
    }

    private function markHistoricalSnapshotsUnavailable(int $repositoryId): int
    {
        return $this->database->execute(
            "UPDATE backup_runs SET snapshot_state = 'unavailable', snapshot_unavailable_reason = :reason " .
            "WHERE backup_job_id IN (SELECT id FROM backup_jobs WHERE repository_id = :repository_id) " .
            "AND snapshot_id IS NOT NULL AND COALESCE(snapshot_state, 'present') IN ('present', 'forgetting')",
            [
                'repository_id' => $repositoryId,
                'reason' => 'Repository was reinitialized after its physical storage disappeared. This historical snapshot is no longer present in the new empty repository.',
            ]
        );
    }

    public function archive(string $uuid): void
    {
        $repository = $this->findByUuid($uuid);
        if ($repository === null || $repository['archived_at'] !== null) {
            throw new InvalidArgumentException('Repository not found.');
        }
        $activeJobs = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM backup_jobs WHERE repository_id = :id AND deleted_at IS NULL',
            ['id' => $repository['id']]
        );
        if ($activeJobs > 0) {
            throw new InvalidArgumentException('Delete or move all active backup jobs before removing this repository.');
        }
        $activeOperations = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM operations WHERE repository_id = :id AND status IN ('pending', 'running')",
            ['id' => $repository['id']]
        );
        if ($activeOperations > 0) {
            throw new InvalidArgumentException('Wait for active repository operations to finish before removing it.');
        }

        $this->database->execute(
            'UPDATE repositories SET archived_at = :archived_at, updated_at = :archived_at WHERE id = :id',
            ['archived_at' => date('c'), 'id' => $repository['id']]
        );
    }

    public function initialize(int $repositoryId): void
    {
        $repository = $this->findById($repositoryId);
        if ($repository === null) {
            throw new RuntimeException('Repository not found.');
        }
        if ($repository['status'] === 'ready') {
            return;
        }

        $containerPath = $this->paths->toContainerPath((string) $repository['path']);
        $passwordFile = (string) $repository['password_file'];
        $this->database->execute(
            "UPDATE repositories SET status = 'initializing', error = NULL, updated_at = :updated_at WHERE id = :id",
            ['updated_at' => date('c'), 'id' => $repositoryId]
        );

        if ($this->repositoryConfigExists($containerPath)) {
            throw new RuntimeException('Refusing to initialize: a Restic repository already exists at this path.');
        }
        if (!$this->pathIsMissingOrEmpty($containerPath)) {
            throw new RuntimeException('Refusing to initialize: repository destination is not empty.');
        }

        $parent = dirname($containerPath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create repository parent directory: %s', $parent));
        }

        $this->restic->initializeRepository($containerPath, $passwordFile);
        $this->restic->checkRepository($containerPath, $passwordFile);
        $now = date('c');
        $this->database->execute(
            "UPDATE repositories SET status = 'ready', error = NULL, missing_since = NULL, last_check_at = :updated_at, last_check_status = 'success', last_check_error = NULL, updated_at = :updated_at WHERE id = :id",
            ['updated_at' => $now, 'id' => $repositoryId]
        );
    }

    public function markFailed(int $repositoryId, string $error): void
    {
        $this->database->execute(
            "UPDATE repositories SET status = 'failed', error = :error, updated_at = :updated_at WHERE id = :id",
            ['error' => substr($error, 0, 4000), 'updated_at' => date('c'), 'id' => $repositoryId]
        );
    }

    private function decoratePresence(array &$repository): void
    {
        $containerPath = $this->paths->toContainerPath((string) $repository['path']);
        $present = $this->repositoryConfigExists($containerPath);
        $repository['storage_present'] = $present;
        $repository['storage_empty'] = $this->pathIsMissingOrEmpty($containerPath);
        $repository['can_reinitialize'] = !$present && $repository['storage_empty'];

        if (!$present && in_array((string) $repository['status'], ['ready', 'missing'], true)) {
            $message = $this->missingMessage((string) $repository['path']);
            if ($repository['status'] !== 'missing') {
                $this->markMissing((int) $repository['id'], $message);
            }
            $repository['status'] = 'missing';
            $repository['error'] = $message;
            $repository['missing_since'] = $repository['missing_since'] ?: date('c');
        }
    }

    private function markMissing(int $repositoryId, string $message): void
    {
        $now = date('c');
        $this->database->execute(
            "UPDATE repositories SET status = 'missing', error = :error, missing_since = COALESCE(missing_since, :missing_since), last_check_status = 'failed', last_check_at = :checked_at, last_check_error = :error, updated_at = :updated_at WHERE id = :id",
            [
                'error' => substr($message, 0, 4000),
                'missing_since' => $now,
                'checked_at' => $now,
                'updated_at' => $now,
                'id' => $repositoryId,
            ]
        );
    }

    private function repositoryConfigExists(string $containerPath): bool
    {
        return is_file(rtrim($containerPath, '/') . '/config');
    }

    private function pathIsMissingOrEmpty(string $containerPath): bool
    {
        if (!file_exists($containerPath)) {
            return true;
        }
        if (!is_dir($containerPath)) {
            return false;
        }

        $entries = scandir($containerPath);
        if ($entries === false) {
            return false;
        }

        return array_values(array_diff($entries, ['.', '..'])) === [];
    }

    private function missingMessage(string $logicalPath): string
    {
        return sprintf(
            'Repository storage is no longer available. ZimaBackup still has the repository configuration and recovery key, but %s/config could not be found.',
            rtrim($logicalPath, '/')
        );
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Repository name must contain between 1 and 100 characters.');
        }
        return $name;
    }
}
