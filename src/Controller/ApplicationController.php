<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationDiscoveryService;

final class ApplicationController extends AbstractController
{
    public function index(): string
    {
        /** @var ApplicationDiscoveryService $discovery */
        $discovery = $this->app->service(ApplicationDiscoveryService::class);
        $apps = $discovery->all();
        $status = $discovery->status();

        if ($apps === [] && !in_array($status['status'], ['queued', 'scanning'], true)) {
            $discovery->enqueueRefresh();
            $status = $discovery->status();
        }

        /** @var Session $session */
        $session = $this->app->service(Session::class);

        return $this->render('applications/index.twig', [
            'applications' => $apps,
            'discovery' => $status,
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function refresh(): string
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
            /** @var ApplicationDiscoveryService $discovery */
            $discovery = $this->app->service(ApplicationDiscoveryService::class);
            $queued = $discovery->enqueueRefresh();
            $session->set('flash_success', $queued ? 'Application scan queued.' : 'An application scan is already queued or running.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }

        return $this->redirect('applications');
    }
}
