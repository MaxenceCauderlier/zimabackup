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
    ): array {
        if ($sources === []) {
            throw new RuntimeException('Restic backup requires at least one source.');
        }

        $process = new Process([
            $this->binary,
            'backup',
            '--repo', $repositoryPath,
            '--password-file', $passwordFile,
            '--json',
            '--host', 'zimabackup',
            '--tag', 'zimabackup-job=' . $jobUuid,
            ...$sources,
        ]);
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
