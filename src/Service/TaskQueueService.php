<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;
use ZimaBackup\Core\Database;
use ZimaBackup\Core\Uuid;

final class TaskQueueService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Queue a privileged operation for the worker. The payload must contain
     * references/IDs only; secrets must never be copied into SQLite.
     */
    public function enqueue(string $type, array $payload): int
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode task payload.', 0, $exception);
        }

        $this->database->execute(
            'INSERT INTO operations(uuid, type, status, payload, created_at) ' .
            'VALUES (:uuid, :type, :status, :payload, :created_at)',
            [
                'uuid' => Uuid::v4(),
                'type' => $type,
                'status' => 'pending',
                'payload' => $json,
                'created_at' => date('c'),
            ]
        );

        return $this->database->lastInsertId();
    }

    /**
     * Atomically claim the oldest pending task so a future multi-worker setup
     * cannot execute the same operation twice.
     */
    public function claimNext(): ?array
    {
        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $operation = $this->database->fetchOne(
                "SELECT * FROM operations WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
            );

            if ($operation === null) {
                $pdo->commit();
                return null;
            }

            $updated = $this->database->execute(
                "UPDATE operations SET status = 'running', started_at = :started_at " .
                "WHERE id = :id AND status = 'pending'",
                [
                    'started_at' => date('c'),
                    'id' => $operation['id'],
                ]
            );

            if ($updated !== 1) {
                $pdo->rollBack();
                return null;
            }

            $pdo->commit();
            $operation['status'] = 'running';
            $operation['payload'] = json_decode((string) $operation['payload'], true, 512, JSON_THROW_ON_ERROR);

            return $operation;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function complete(int $id, array $result = []): void
    {
        $this->database->execute(
            "UPDATE operations SET status = 'success', result = :result, finished_at = :finished_at WHERE id = :id",
            [
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'finished_at' => date('c'),
                'id' => $id,
            ]
        );
    }

    public function fail(int $id, string $error): void
    {
        $this->database->execute(
            "UPDATE operations SET status = 'failed', error = :error, finished_at = :finished_at WHERE id = :id",
            [
                'error' => substr($error, 0, 4000),
                'finished_at' => date('c'),
                'id' => $id,
            ]
        );
    }
}
