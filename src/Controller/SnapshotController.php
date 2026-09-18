<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
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
            $session->set('flash_success', 'Snapshot removal queued. Restic will forget the snapshot in the background.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('snapshots');
    }
}
