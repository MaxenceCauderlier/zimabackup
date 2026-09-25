<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ResticService;
use ZimaBackup\Service\SettingsService;

final class SettingsController extends AbstractController
{
    public function index(): string
    {
        /** @var SettingsService $settings */
        $settings = $this->app->service(SettingsService::class);
        /** @var ResticService $restic */
        $restic = $this->app->service(ResticService::class);
        /** @var Session $session */
        $session = $this->app->service(Session::class);

        $versionPath = $this->app->rootPath() . '/VERSION';
        $version = is_file($versionPath) ? trim((string) file_get_contents($versionPath)) : 'dev';

        return $this->render('settings/index.twig', [
            'values' => $settings->all(),
            'version' => $version,
            'restic_version' => $restic->version(),
            'docker_socket' => getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock',
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
        ]);
    }

    public function update(): string
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
            /** @var SettingsService $settings */
            $settings = $this->app->service(SettingsService::class);
            $settings->update([
                'restore.default_root' => $_POST['restore_default_root'] ?? '',
                'application_restore.default_root' => $_POST['application_restore_default_root'] ?? '',
                'apps.discovery.interval' => $_POST['apps_discovery_interval'] ?? 120,
                'maintenance.auto_check' => isset($_POST['maintenance_auto_check']),
                'maintenance.check_interval_days' => $_POST['maintenance_check_interval_days'] ?? 7,
                'maintenance.auto_prune' => isset($_POST['maintenance_auto_prune']),
                'maintenance.prune_interval_days' => $_POST['maintenance_prune_interval_days'] ?? 30,
            ]);
            $session->set('flash_success', 'Settings saved. The worker will use the new values automatically.');
        } catch (InvalidArgumentException $exception) {
            $session->set('flash_error', $exception->getMessage());
        }

        return $this->redirect('settings');
    }
}
