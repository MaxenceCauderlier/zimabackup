<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\SnapshotBrowserService;
use ZimaBackup\Service\SnapshotService;

final class SnapshotController extends AbstractController
{
    public function index(): string
    {
        /** @var SnapshotService $snapshots */
        $snapshots = $this->app->service(SnapshotService::class);
        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('snapshots/index.twig', [
            'snapshots' => $snapshots->all(),
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function browse(int $runId): string
    {
        /** @var SnapshotBrowserService $browser */
        $browser = $this->app->service(SnapshotBrowserService::class);
        $snapshot = $browser->snapshot($runId);
        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        try {
            $path = $browser->normalizePath((string) ($_GET['path'] ?? '/'));
            $listing = $browser->listing($runId, $path);
            if ($listing === null) {
                $browser->enqueue($runId, $path);
                $listing = $browser->listing($runId, $path);
            }
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            return $this->render('snapshots/browse.twig', [
                'snapshot' => $snapshot,
                'path' => '/',
                'listing' => null,
                'breadcrumbs' => $browser->breadcrumbs('/'),
                'browse_error' => $exception->getMessage(),
            ]);
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);
        return $this->render('snapshots/browse.twig', [
            'snapshot' => $snapshot,
            'path' => $path,
            'listing' => $listing,
            'breadcrumbs' => $browser->breadcrumbs($path),
            'browse_error' => null,
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function refreshBrowse(int $runId): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        if (!$csrf->isValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return $this->render('errors/419.twig');
        }

        /** @var SnapshotBrowserService $browser */
        $browser = $this->app->service(SnapshotBrowserService::class);
        try {
            $path = $browser->normalizePath((string) ($_POST['path'] ?? '/'));
            $browser->enqueue($runId, $path, true);
        } catch (Throwable) {
            $path = '/';
        }

        header('Location: ' . $this->app->router()->generate('snapshots.browse', ['runId' => $runId]) . '?path=' . rawurlencode($path), true, 303);
        return '';
    }

    public function forget(int $runId): string
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
            /** @var SnapshotService $snapshots */
            $snapshots = $this->app->service(SnapshotService::class);
            $snapshots->enqueueForget($runId);
            $session->set('flash_success', 'Recovery point deletion queued. ZimaBackup will remove it from backup storage in the background.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('snapshots');
    }
}
