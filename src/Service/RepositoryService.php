<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use InvalidArgumentException;
use RuntimeException;
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
        return $this->database->fetchAll(
            'SELECT r.*, ' .
            "(SELECT COUNT(*) FROM operations o WHERE o.repository_id = r.id AND o.status = 'running') AS running_operations " .
            'FROM repositories r ORDER BY r.created_at DESC'
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->database->fetchOne('SELECT * FROM repositories WHERE uuid = :uuid', ['uuid' => $uuid]);
    }

    public function findById(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM repositories WHERE id = :id', ['id' => $id]);
    }

    /**
     * Persist a repository in pending state and queue its Restic initialization.
     * The generated recovery key is returned once to the controller and is never
     * stored in SQLite.
     *
     * @return array{repository: array, recovery_key: string}
     */
    public function create(string $name, string $path): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Repository name must contain between 1 and 100 characters.');
        }

        $path = $this->paths->normalizeLogicalPath($path);
        $this->paths->toContainerPath($path); // Also asserts that the path is inside an allowed root.

        if (in_array($path, ['/DATA', '/media'], true)) {
            throw new InvalidArgumentException('Choose a dedicated subdirectory, for example /media/Backup/ZimaBackup.');
        }

        if ($this->database->scalar('SELECT COUNT(*) FROM repositories WHERE path = :path', ['path' => $path])) {
            throw new InvalidArgumentException('A repository already uses this path.');
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
        } catch (\Throwable $exception) {
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

        return [
            'repository' => $repository,
            'recovery_key' => $recoveryKey,
        ];
    }

    /** Execute repository initialization from the privileged worker only. */
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

        $parent = dirname($containerPath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create repository parent directory: %s', $parent));
        }

        $this->restic->initializeRepository($containerPath, $passwordFile);
        $this->restic->checkRepository($containerPath, $passwordFile);

        $this->database->execute(
            "UPDATE repositories SET status = 'ready', error = NULL, updated_at = :updated_at WHERE id = :id",
            ['updated_at' => date('c'), 'id' => $repositoryId]
        );
    }

    public function markFailed(int $repositoryId, string $error): void
    {
        $this->database->execute(
            "UPDATE repositories SET status = 'failed', error = :error, updated_at = :updated_at WHERE id = :id",
            [
                'error' => substr($error, 0, 4000),
                'updated_at' => date('c'),
                'id' => $repositoryId,
            ]
        );
    }
}
