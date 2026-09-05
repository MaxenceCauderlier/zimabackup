<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\BackupService;

final class BackupController extends AbstractController
{
    public function index(): string
    {
        /** @var BackupService $backups */
        $backups = $this->app->service(BackupService::class);

        return $this->render('backups/index.twig', [
            'jobs' => $backups->all(),
            'repositories' => $backups->readyRepositories(),
        ]);
    }

    public function create(): string
    {
        /** @var BackupService $backups */
        $backups = $this->app->service(BackupService::class);

        return $this->render('backups/create.twig', [
            'repositories' => $backups->readyRepositories(),
            'values' => [
                'name' => '',
                'repository_id' => '',
                'sources' => ['/DATA/Documents'],
            ],
            'errors' => [],
        ]);
    }

    public function store(): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        $sources = $_POST['sources'] ?? [];
        if (!is_array($sources)) {
            $sources = [];
        }

        $values = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'repository_id' => (string) ($_POST['repository_id'] ?? ''),
            'sources' => array_values(array_map(static fn ($value): string => trim((string) $value), $sources)),
        ];

        try {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            $job = $backups->create(
                $values['name'],
                (int) $values['repository_id'],
                $values['sources']
            );

            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('flash_success', 'Backup job created. You can run it now.');

            return $this->redirect('backups.show', ['uuid' => $job['uuid']]);
        } catch (InvalidArgumentException $exception) {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            return $this->render('backups/create.twig', [
                'repositories' => $backups->readyRepositories(),
                'values' => $values,
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    public function show(string $uuid): string
    {
        /** @var BackupService $backups */
        $backups = $this->app->service(BackupService::class);
        $job = $backups->findByUuid($uuid);

        if ($job === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('backups/show.twig', [
            'job' => $job,
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function run(string $uuid): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);

        try {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            $backups->enqueueRunByUuid($uuid);
            $session->set('flash_success', 'Backup queued. The worker will start it automatically.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }

        return $this->redirect('backups.show', ['uuid' => $uuid]);
    }
}
