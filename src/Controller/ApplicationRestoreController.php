<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationInstallService;
use ZimaBackup\Service\ApplicationRestoreService;

final class ApplicationRestoreController extends AbstractController
{
    public function create(int $applicationId): string
    {
        /** @var ApplicationRestoreService $restores */
        $restores = $this->app->service(ApplicationRestoreService::class);
        $application = $restores->snapshotApplication($applicationId);
        if ($application === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        return $this->render('application-restores/create.twig', [
            'application' => $application,
            'errors' => [],
            'values' => [
                'mode' => 'staging',
                'confirm' => '',
            ],
        ]);
    }

    public function store(int $applicationId): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var ApplicationRestoreService $restores */
        $restores = $this->app->service(ApplicationRestoreService::class);
        $application = $restores->snapshotApplication($applicationId);
        if ($application === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        $values = [
            'mode' => trim((string) ($_POST['mode'] ?? 'staging')),
            'confirm' => trim((string) ($_POST['confirm'] ?? '')),
        ];

        try {
            $restore = $restores->enqueue($applicationId, $values['mode'], $values['confirm']);
            return $this->redirect('application-restores.show', ['uuid' => $restore['uuid']]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('application-restores/create.twig', [
                'application' => $application,
                'errors' => [$exception->getMessage()],
                'values' => $values,
            ]);
        } catch (Throwable $exception) {
            return $this->render('application-restores/create.twig', [
                'application' => $application,
                'errors' => ['Unable to queue application restore: ' . $exception->getMessage()],
                'values' => $values,
            ]);
        }
    }

    public function show(string $uuid): string
    {
        /** @var ApplicationRestoreService $restores */
        $restores = $this->app->service(ApplicationRestoreService::class);
        $restore = $restores->findByUuid($uuid);
        if ($restore === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        /** @var ApplicationInstallService $installs */
        $installs = $this->app->service(ApplicationInstallService::class);

        return $this->render('application-restores/show.twig', [
            'restore' => $restore,
            'install' => $installs->latestForRestore((int) $restore['id']),
            'install_error' => null,
        ]);
    }

    public function install(string $uuid): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var ApplicationRestoreService $restores */
        $restores = $this->app->service(ApplicationRestoreService::class);
        $restore = $restores->findByUuid($uuid);
        if ($restore === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        /** @var ApplicationInstallService $installs */
        $installs = $this->app->service(ApplicationInstallService::class);

        try {
            $installs->enqueue($uuid, trim((string) ($_POST['confirm_install'] ?? '')));
            return $this->redirect('application-restores.show', ['uuid' => $uuid]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('application-restores/show.twig', [
                'restore' => $restore,
                'install' => $installs->latestForRestore((int) $restore['id']),
                'install_error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            return $this->render('application-restores/show.twig', [
                'restore' => $restore,
                'install' => $installs->latestForRestore((int) $restore['id']),
                'install_error' => 'Unable to queue application install: ' . $exception->getMessage(),
            ]);
        }
    }
}
