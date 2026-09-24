<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\RepositoryService;

final class RepositoryController extends AbstractController
{
    public function index(): string
    {
        /** @var RepositoryService $repositories */
        $repositories = $this->app->service(RepositoryService::class);

        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('repositories/index.twig', [
            'repositories' => $repositories->all(),
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function create(): string
    {
        return $this->render('repositories/create.twig', [
            'values' => [
                'name' => '',
                'path' => '/media/Backup/ZimaBackup',
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

        $values = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'path' => trim((string) ($_POST['path'] ?? '')),
        ];

        try {
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $created = $repositories->create($values['name'], $values['path']);

            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('repository_recovery_' . $created['repository']['uuid'], $created['recovery_key']);

            return $this->redirect('repositories.recovery', ['uuid' => $created['repository']['uuid']]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('repositories/create.twig', [
                'values' => $values,
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    public function edit(string $uuid): string
    {
        /** @var RepositoryService $repositories */
        $repositories = $this->app->service(RepositoryService::class);
        $repository = $repositories->findByUuid($uuid);
        if ($repository === null || $repository['archived_at'] !== null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        return $this->render('repositories/edit.twig', [
            'repository' => $repository,
            'errors' => [],
        ]);
    }

    public function update(string $uuid): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        try {
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $repositories->updateName($uuid, (string) ($_POST['name'] ?? ''));
            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('flash_success', 'Repository updated.');
            return $this->redirect('repositories');
        } catch (InvalidArgumentException $exception) {
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $repository = $repositories->findByUuid($uuid);
            if ($repository === null) {
                http_response_code(404);
                return $this->render('errors/404.twig');
            }
            $repository['name'] = trim((string) ($_POST['name'] ?? ''));
            return $this->render('repositories/edit.twig', ['repository' => $repository, 'errors' => [$exception->getMessage()]]);
        }
    }

    public function check(string $uuid): string
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
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $repositories->enqueueCheck($uuid);
            $session->set('flash_success', 'Repository integrity check queued.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('repositories');
    }


    public function reinitialize(string $uuid): string
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
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $repositories->enqueueReinitialize($uuid);
            $session->set('flash_success', 'Repository reinitialization queued. A new empty Restic repository will be created with the existing recovery key.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }

        return $this->redirect('repositories');
    }

    public function remove(string $uuid): string
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
            /** @var RepositoryService $repositories */
            $repositories = $this->app->service(RepositoryService::class);
            $repositories->archive($uuid);
            $session->set('flash_success', 'Repository removed from active configuration. Backup data on disk was not deleted.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('repositories');
    }

    public function recovery(string $uuid): string
    {
        /** @var RepositoryService $repositories */
        $repositories = $this->app->service(RepositoryService::class);
        $repository = $repositories->findByUuid($uuid);

        if ($repository === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);
        $recoveryKey = $session->pull('repository_recovery_' . $uuid);

        return $this->render('repositories/recovery.twig', [
            'repository' => $repository,
            'recovery_key' => is_string($recoveryKey) ? $recoveryKey : null,
        ]);
    }
}
