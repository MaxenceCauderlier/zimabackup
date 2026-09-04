<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\RepositoryService;

final class RepositoryController extends AbstractController
{
    public function index(): string
    {
        /** @var RepositoryService $repositories */
        $repositories = $this->app->service(RepositoryService::class);

        return $this->render('repositories/index.twig', [
            'repositories' => $repositories->all(),
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
