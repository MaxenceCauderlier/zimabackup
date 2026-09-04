<?php

declare(strict_types=1);

namespace ZimaBackup\Service;

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
     * Execute Restic without constructing a shell command string.
     *
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
