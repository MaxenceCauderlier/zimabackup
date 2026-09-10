<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\SnapshotApplicationService;

final class SnapshotApplicationController extends AbstractController
{
    public function index(int $runId): string
    {
        /** @var SnapshotApplicationService $service */
        $service = $this->app->service(SnapshotApplicationService::class);
        $snapshot = $service->snapshot($runId);
        if ($snapshot === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }

        $scan = $service->scan($runId);
        if ($scan === null && (int) $snapshot['configured_app_count'] > 0) {
            try {
                $service->enqueueInspection($runId);
                $scan = $service->scan($runId);
            } catch (Throwable) {
                $scan = $service->scan($runId);
            }
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('snapshots/applications.twig', [
            'snapshot' => $snapshot,
            'scan' => $scan,
            'applications' => $service->applications($runId),
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function inspect(int $runId): string
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
            /** @var SnapshotApplicationService $service */
            $service = $this->app->service(SnapshotApplicationService::class);
            $queued = $service->enqueueInspection($runId, true);
            $session->set('flash_success', $queued ? 'Application inspection queued.' : 'Application inspection is already running.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }

        return $this->redirect('snapshots.applications', ['runId' => $runId]);
    }
}
