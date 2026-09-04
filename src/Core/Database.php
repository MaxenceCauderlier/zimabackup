<?php

declare(strict_types=1);

namespace ZimaBackup\Core;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create database directory: %s', $directory));
        }

        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // SQLite does not enable foreign keys by default for every connection.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        // The web process and the worker share this database. WAL considerably
        // reduces reader/writer contention compared with the default journal.
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(string $migrationsDirectory): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS _migrations (' .
            'name TEXT PRIMARY KEY, ' .
            'executed_at TEXT NOT NULL' .
            ')'
        );

        $files = glob(rtrim($migrationsDirectory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $name = basename($file);

            // Both the web container and worker can boot simultaneously. An
            // IMMEDIATE transaction serializes migration checks + execution so
            // the same ALTER TABLE cannot run twice during startup.
            $this->pdo->exec('BEGIN IMMEDIATE');

            try {
                $statement = $this->pdo->prepare('SELECT COUNT(*) FROM _migrations WHERE name = :name');
                $statement->execute(['name' => $name]);

                if ((int) $statement->fetchColumn() > 0) {
                    $this->pdo->commit();
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException(sprintf('Unable to read migration: %s', $file));
                }

                $this->pdo->exec($sql);

                $insert = $this->pdo->prepare(
                    'INSERT INTO _migrations(name, executed_at) VALUES (:name, :executed_at)'
                );
                $insert->execute([
                    'name' => $name,
                    'executed_at' => gmdate('c'),
                ]);

                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }
        }
    }

    public function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
