<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ResticService
{
    public function __construct(private readonly string $binary = 'restic')
    {
    }

    public function version(): ?string
    {
        $process = new Process([$this->binary, 'version']);
        $process->setTimeout(5);

        try {
            $process->mustRun();
            return trim($process->getOutput());
        } catch (ProcessFailedException) {
            return null;
        }
    }

    public function initializeRepository(string $repositoryPath, string $passwordFile): Process
    {
        return $this->run([
            'init',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
        ], 120);
    }

    public function checkRepository(string $repositoryPath, string $passwordFile): Process
    {
        return $this->run([
            'check',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
        ], 300);
    }

    /**
     * Run a Restic backup and decode its JSON-lines progress stream.
     *
     * @param list<string> $sources Container-visible source paths.
     * @param callable(array):void|null $onMessage Receives Restic status/error/summary messages.
     * @return array Final Restic summary message.
     */
    public function backup(
        string $repositoryPath,
        string $passwordFile,
        array $sources,
        string $jobUuid,
        ?callable $onMessage = null,
        array $extraTags = [],
    ): array {
        if ($sources === []) {
            throw new RuntimeException('Restic backup requires at least one source.');
        }

        $arguments = [
            $this->binary,
            'backup',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
            '--json',
            '--host', 'zimabackup',
            '--tag', 'zimabackup-job=' . $jobUuid,
        ];
        foreach ($extraTags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $arguments[] = '--tag';
                $arguments[] = trim($tag);
            }
        }
        $arguments = [...$arguments, ...$sources];

        $process = new Process($arguments);
        $process->setTimeout(null);

        $buffer = '';
        $stderr = '';
        $stderrBuffer = '';
        $summary = null;
        $lastResticError = null;

        $consumeLine = static function (string $line) use (&$summary, &$lastResticError, $onMessage): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }

            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Ignore non-JSON informational output. stderr is retained for failures.
                return;
            }

            if (!is_array($message)) {
                return;
            }

            $type = (string) ($message['message_type'] ?? '');
            if ($type === 'summary') {
                $summary = $message;
            } elseif ($type === 'error') {
                $error = $message['error'] ?? null;
                if (is_array($error)) {
                    $lastResticError = (string) ($error['message'] ?? 'Restic reported an error.');
                } elseif (is_string($error)) {
                    $lastResticError = $error;
                }
            }

            if ($onMessage !== null) {
                $onMessage($message);
            }
        };

        $consumeErrorLine = static function (string $line) use (&$lastResticError): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($message) && ($message['message_type'] ?? null) === 'error') {
                    $error = $message['error'] ?? null;
                    if (is_array($error)) {
                        $lastResticError = (string) ($error['message'] ?? $line);
                    } elseif (is_string($error)) {
                        $lastResticError = $error;
                    }
                }
            } catch (JsonException) {
                // Human-readable stderr will still be used as a fallback below.
            }
        };

        $exitCode = $process->run(function (string $type, string $data) use (&$buffer, &$stderr, &$stderrBuffer, $consumeLine, $consumeErrorLine): void {
            if ($type === Process::ERR) {
                $stderr .= $data;
                $stderrBuffer .= $data;
                while (($position = strpos($stderrBuffer, "\n")) !== false) {
                    $line = substr($stderrBuffer, 0, $position);
                    $stderrBuffer = substr($stderrBuffer, $position + 1);
                    $consumeErrorLine($line);
                }
                return;
            }

            $buffer .= $data;
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);
                $consumeLine($line);
            }
        });

        if (trim($buffer) !== '') {
            $consumeLine($buffer);
        }
        if (trim($stderrBuffer) !== '') {
            $consumeErrorLine($stderrBuffer);
        }

        // Exit code 3 means Restic created an incomplete backup because some
        // source data could not be read. Keep the snapshot visible as a warning
        // instead of silently losing track of a potentially useful recovery point.
        if ($exitCode === 3 && is_array($summary)) {
            $summary['_partial'] = true;
            $summary['_error'] = $lastResticError ?: trim($stderr);
            return $summary;
        }

        if ($exitCode !== 0) {
            $message = $lastResticError ?: trim($stderr);
            if ($message === '') {
                $message = sprintf('Restic backup failed with exit code %d.', $exitCode);
            }
            throw new RuntimeException($message);
        }

        if (!is_array($summary) || ($summary['message_type'] ?? null) !== 'summary') {
            throw new RuntimeException('Restic completed without returning a backup summary.');
        }

        return $summary;
    }


    /**
     * Restore a snapshot into a target directory and decode Restic's JSON-lines
     * progress stream.
     *
     * @param callable(array):void|null $onMessage
     * @return array Final Restic summary message.
     */
    public function restore(
        string $repositoryPath,
        string $passwordFile,
        string $snapshotId,
        string $targetPath,
        string $overwriteMode = 'never',
        ?callable $onMessage = null,
    ): array {
        $allowedOverwriteModes = ['always', 'if-changed', 'if-newer', 'never'];
        if (!in_array($overwriteMode, $allowedOverwriteModes, true)) {
            throw new RuntimeException('Unsupported Restic overwrite mode.');
        }

        $arguments = [
            $this->binary,
            'restore',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
            '--target', $targetPath,
            '--overwrite', $overwriteMode,
            '--json',
            $snapshotId,
        ];

        $process = new Process($arguments);
        $process->setTimeout(null);

        $stdoutBuffer = '';
        $stderr = '';
        $stderrBuffer = '';
        $summary = null;
        $lastResticError = null;

        $consumeLine = static function (string $line) use (&$summary, &$lastResticError, $onMessage): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }

            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }

            if (!is_array($message)) {
                return;
            }

            $type = (string) ($message['message_type'] ?? '');
            if ($type === 'summary') {
                $summary = $message;
            } elseif ($type === 'error' || $type === 'exit_error') {
                $error = $message['error'] ?? null;
                if (is_array($error)) {
                    $lastResticError = (string) ($error['message'] ?? 'Restic reported a restore error.');
                } else {
                    $lastResticError = (string) ($message['message'] ?? $error ?? 'Restic reported a restore error.');
                }
            }

            if ($onMessage !== null) {
                $onMessage($message);
            }
        };

        $exitCode = $process->run(function (string $type, string $data) use (&$stdoutBuffer, &$stderr, &$stderrBuffer, $consumeLine): void {
            if ($type === Process::ERR) {
                $stderr .= $data;
                $stderrBuffer .= $data;
                while (($position = strpos($stderrBuffer, "\n")) !== false) {
                    $line = substr($stderrBuffer, 0, $position);
                    $stderrBuffer = substr($stderrBuffer, $position + 1);
                    $consumeLine($line);
                }
                return;
            }

            $stdoutBuffer .= $data;
            while (($position = strpos($stdoutBuffer, "\n")) !== false) {
                $line = substr($stdoutBuffer, 0, $position);
                $stdoutBuffer = substr($stdoutBuffer, $position + 1);
                $consumeLine($line);
            }
        });

        if (trim($stdoutBuffer) !== '') {
            $consumeLine($stdoutBuffer);
        }
        if (trim($stderrBuffer) !== '') {
            $consumeLine($stderrBuffer);
        }

        if ($exitCode !== 0) {
            $message = $lastResticError ?: trim($stderr);
            if ($message === '') {
                $message = sprintf('Restic restore failed with exit code %d.', $exitCode);
            }
            throw new RuntimeException($message);
        }

        if (!is_array($summary) || ($summary['message_type'] ?? null) !== 'summary') {
            throw new RuntimeException('Restic completed without returning a restore summary.');
        }

        return $summary;
    }

    /**
     * Stream Restic's JSON-lines file listing and retain only matching files.
     * This avoids loading a complete multi-million-file snapshot in memory.
     *
     * @return list<array<string, mixed>>
     */
    public function findSnapshotFiles(
        string $repositoryPath,
        string $passwordFile,
        string $snapshotId,
        string $pathFragment,
        string $suffix = '',
    ): array {
        $process = new Process([
            $this->binary,
            'ls',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
            '--json',
            $snapshotId,
        ]);
        $process->setTimeout(300);

        $buffer = '';
        $matches = [];
        $consume = static function (string $line) use (&$matches, $pathFragment, $suffix): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }
            if (!is_array($message)) {
                return;
            }
            $messageType = $message['message_type'] ?? $message['struct_type'] ?? null;
            $path = (string) ($message['path'] ?? '');
            if ($messageType !== 'node' || ($message['type'] ?? null) !== 'file' || $path === '') {
                return;
            }
            if ($pathFragment !== '' && !str_contains($path, $pathFragment)) {
                return;
            }
            if ($suffix !== '' && !str_ends_with(strtolower($path), strtolower($suffix))) {
                return;
            }
            $matches[] = $message;
        };

        $exitCode = $process->run(function (string $type, string $data) use (&$buffer, $consume): void {
            if ($type === Process::ERR) {
                return;
            }
            $buffer .= $data;
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);
                $consume($line);
            }
        });
        if (trim($buffer) !== '') {
            $consume($buffer);
        }
        if ($exitCode !== 0) {
            $message = trim($process->getErrorOutput());
            throw new RuntimeException($message !== '' ? $message : sprintf('Restic ls failed with exit code %d.', $exitCode));
        }

        return $matches;
    }

    /** Decrypt one file from a snapshot directly to memory. */
    public function dumpSnapshotFile(
        string $repositoryPath,
        string $passwordFile,
        string $snapshotId,
        string $path,
    ): string {
        $process = new Process([
            $this->binary,
            'dump',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
            $snapshotId,
            $path,
        ]);
        $process->setTimeout(120);
        $process->mustRun();
        $output = $process->getOutput();
        if (strlen($output) > 5 * 1024 * 1024) {
            throw new RuntimeException('Snapshot manifest is unexpectedly large.');
        }
        return $output;
    }

    /**
     * Execute Restic without constructing a shell command string.
     * Arguments are passed as a list so paths and user-controlled values never
     * need shell escaping. Passwords are provided through --password-file.
     */
    public function run(array $arguments, ?float $timeout = null): Process
    {
        $process = new Process([$this->binary, ...$arguments]);
        $process->setTimeout($timeout);
        $process->mustRun();

        return $process;
    }
}
