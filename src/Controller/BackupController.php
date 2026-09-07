<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationDiscoveryService;
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
        /** @var ApplicationDiscoveryService $applications */
        $applications = $this->app->service(ApplicationDiscoveryService::class);

        $apps = $applications->all();
        $discovery = $applications->status();
        if ($apps === [] && !in_array($discovery['status'], ['queued', 'scanning'], true)) {
            $applications->enqueueRefresh();
            $discovery = $applications->status();
        }

        return $this->render('backups/create.twig', [
            'repositories' => $backups->readyRepositories(),
            'applications' => $apps,
            'discovery' => $discovery,
            'values' => [
                'name' => '',
                'repository_id' => '',
                'sources' => [''],
                'application_ids' => [],
                'app_mounts' => [],
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

        $applicationIds = $_POST['application_ids'] ?? [];
        if (!is_array($applicationIds)) {
            $applicationIds = [];
        }
        $applicationIds = array_values(array_unique(array_filter(array_map('intval', $applicationIds), static fn (int $id): bool => $id > 0)));

        $postedMounts = $_POST['app_mounts'] ?? [];
        if (!is_array($postedMounts)) {
            $postedMounts = [];
        }

        $appMounts = [];
        $applicationSelections = [];
        foreach ($applicationIds as $applicationId) {
            $mountIds = $postedMounts[(string) $applicationId] ?? $postedMounts[$applicationId] ?? [];
            if (!is_array($mountIds)) {
                $mountIds = [];
            }
            $mountIds = array_values(array_unique(array_filter(array_map('intval', $mountIds), static fn (int $id): bool => $id > 0)));
            $appMounts[(string) $applicationId] = $mountIds;
            $applicationSelections[] = [
                'application_id' => $applicationId,
                'mount_ids' => $mountIds,
            ];
        }

        $values = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'repository_id' => (string) ($_POST['repository_id'] ?? ''),
            'sources' => array_values(array_map(static fn ($value): string => trim((string) $value), $sources)),
            'application_ids' => $applicationIds,
            'app_mounts' => $appMounts,
        ];

        if ($values['sources'] === []) {
            $values['sources'] = [''];
        }

        try {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            $job = $backups->create(
                $values['name'],
                (int) $values['repository_id'],
                $values['sources'],
                $applicationSelections
            );

            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('flash_success', 'Backup job created. You can run it now.');

            return $this->redirect('backups.show', ['uuid' => $job['uuid']]);
        } catch (InvalidArgumentException $exception) {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            /** @var ApplicationDiscoveryService $applications */
            $applications = $this->app->service(ApplicationDiscoveryService::class);
            return $this->render('backups/create.twig', [
                'repositories' => $backups->readyRepositories(),
                'applications' => $applications->all(),
                'discovery' => $applications->status(),
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
