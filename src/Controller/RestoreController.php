<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationRestoreService;
use ZimaBackup\Service\RestoreService;
use ZimaBackup\Service\SnapshotBrowserService;

final class RestoreController extends AbstractController
{
    public function index(): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        /** @var ApplicationRestoreService $applicationRestores */
        $applicationRestores = $this->app->service(ApplicationRestoreService::class);
        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('restores/index.twig', [
            'restores' => $restores->all(),
            'application_restores' => $applicationRestores->all(),
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function create(int $runId): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $snapshot = $restores->snapshotByBackupRunId($runId);

        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        return $this->renderCreate($snapshot, [], []);
    }

    public function select(int $runId): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $snapshot = $restores->snapshotByBackupRunId($runId);
        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        /** @var SnapshotBrowserService $browser */
        $browser = $this->app->service(SnapshotBrowserService::class);
        try {
            $paths = $browser->normalizeSelection(is_array($_POST['paths'] ?? null) ? $_POST['paths'] : []);
            if ($paths === []) {
                throw new InvalidArgumentException('Select at least one file or folder to restore.');
            }
            return $this->renderCreate($snapshot, $paths, []);
        } catch (InvalidArgumentException $exception) {
            $path = (string) ($_POST['browse_path'] ?? '/');
            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('flash_error', $exception->getMessage());
            header('Location: ' . $this->app->router()->generate('snapshots.browse', ['runId' => $runId]) . '?path=' . rawurlencode($path), true, 303);
            return '';
        }
    }

    public function store(int $runId): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $snapshot = $restores->snapshotByBackupRunId($runId);
        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        $selectedPaths = is_array($_POST['selected_paths'] ?? null) ? $_POST['selected_paths'] : [];
        $values = [
            'target_path' => trim((string) ($_POST['target_path'] ?? '')),
            'selected_paths' => $selectedPaths,
        ];

        try {
            $restore = $restores->enqueue($runId, $values['target_path'], $selectedPaths);
            return $this->redirect('restores.show', ['uuid' => $restore['uuid']]);
        } catch (InvalidArgumentException $exception) {
            return $this->render('restores/create.twig', [
                'snapshot' => $snapshot,
                'values' => $values,
                'errors' => [$exception->getMessage()],
                'selected_paths' => $selectedPaths,
            ]);
        } catch (Throwable $exception) {
            return $this->render('restores/create.twig', [
                'snapshot' => $snapshot,
                'values' => $values,
                'errors' => ['Unable to queue restore: ' . $exception->getMessage()],
                'selected_paths' => $selectedPaths,
            ]);
        }
    }

    public function show(string $uuid): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        $restore = $restores->findByUuid($uuid);

        if ($restore === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }
        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('restores/show.twig', [
            'restore' => $restore,
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function cleanup(string $uuid): string
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
            /** @var RestoreService $restores */
            $restores = $this->app->service(RestoreService::class);
            $restores->enqueueCleanup($uuid);
            $session->set('flash_success', 'Restore cleanup queued.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('restores.show', ['uuid' => $uuid]);
    }

    private function renderCreate(array $snapshot, array $selectedPaths, array $errors): string
    {
        /** @var RestoreService $restores */
        $restores = $this->app->service(RestoreService::class);
        return $this->render('restores/create.twig', [
            'snapshot' => $snapshot,
            'values' => [
                'target_path' => $restores->defaultTarget($snapshot),
                'selected_paths' => $selectedPaths,
            ],
            'selected_paths' => $selectedPaths,
            'errors' => $errors,
        ]);
    }
}
