<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use InvalidArgumentException;
use Throwable;
use ZimaBackup\Core\Session;
use ZimaBackup\Security\Csrf;
use ZimaBackup\Service\ApplicationDiscoveryService;
use ZimaBackup\Service\BackupService;
use ZimaBackup\Service\RetentionService;
use ZimaBackup\Service\SchedulerService;

final class BackupController extends AbstractController
{
    public function index(): string
    {
        /** @var BackupService $backups */
        $backups = $this->app->service(BackupService::class);

        /** @var Session $session */
        $session = $this->app->service(Session::class);
        return $this->render('backups/index.twig', [
            'jobs' => $backups->all(),
            'repositories' => $backups->readyRepositories(),
            'flash_success' => $session->pull('flash_success'),
            'flash_error' => $session->pull('flash_error'),
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
                'schedule_type' => 'manual',
                'schedule_time' => '03:00',
                'schedule_weekday' => 1,
                'retention_enabled' => false,
                'keep_last' => 3,
                'keep_daily' => 7,
                'keep_weekly' => 4,
                'keep_monthly' => 6,
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
            'schedule_type' => (string) ($_POST['schedule_type'] ?? 'manual'),
            'schedule_time' => (string) ($_POST['schedule_time'] ?? '03:00'),
            'schedule_weekday' => (int) ($_POST['schedule_weekday'] ?? 1),
            'retention_enabled' => isset($_POST['retention_enabled']),
            'keep_last' => (int) ($_POST['keep_last'] ?? 3),
            'keep_daily' => (int) ($_POST['keep_daily'] ?? 7),
            'keep_weekly' => (int) ($_POST['keep_weekly'] ?? 4),
            'keep_monthly' => (int) ($_POST['keep_monthly'] ?? 6),
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
                $applicationSelections,
                $values
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

    public function edit(string $uuid): string
    {
        /** @var BackupService $backups */
        $backups = $this->app->service(BackupService::class);
        $job = $backups->findByUuid($uuid);
        if ($job === null) {
            http_response_code(404);
            return $this->render('errors/404.twig');
        }
        $repositories = $backups->readyRepositories();
        $knownIds = array_map(static fn (array $repository): int => (int) $repository['id'], $repositories);
        if (!in_array((int) $job['repository_id'], $knownIds, true)) {
            $repositories[] = [
                'id' => (int) $job['repository_id'],
                'name' => (string) $job['repository_name'],
                'path' => (string) $job['repository_path'],
                'status' => (string) $job['repository_status'],
            ];
        }
        /** @var SchedulerService $scheduler */
        $scheduler = $this->app->service(SchedulerService::class);
        $job['schedule_form'] = $scheduler->formValues((string) $job['schedule_type'], $job['schedule_value'] ?? null);
        return $this->render('backups/edit.twig', [
            'job' => $job,
            'repositories' => $repositories,
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
        $sources = $_POST['sources'] ?? [];
        if (!is_array($sources)) {
            $sources = [];
        }
        try {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            $backups->update(
                $uuid,
                (string) ($_POST['name'] ?? ''),
                (int) ($_POST['repository_id'] ?? 0),
                $sources,
                isset($_POST['enabled']),
                [
                    'schedule_type' => $_POST['schedule_type'] ?? 'manual',
                    'schedule_time' => $_POST['schedule_time'] ?? '03:00',
                    'schedule_weekday' => $_POST['schedule_weekday'] ?? 1,
                    'retention_enabled' => isset($_POST['retention_enabled']),
                    'keep_last' => $_POST['keep_last'] ?? 3,
                    'keep_daily' => $_POST['keep_daily'] ?? 7,
                    'keep_weekly' => $_POST['keep_weekly'] ?? 4,
                    'keep_monthly' => $_POST['keep_monthly'] ?? 6,
                ]
            );
            /** @var Session $session */
            $session = $this->app->service(Session::class);
            $session->set('flash_success', 'Backup job updated.');
            return $this->redirect('backups.show', ['uuid' => $uuid]);
        } catch (InvalidArgumentException $exception) {
            /** @var BackupService $backups */
            $backups = $this->app->service(BackupService::class);
            $job = $backups->findByUuid($uuid);
            if ($job === null) {
                http_response_code(404);
                return $this->render('errors/404.twig');
            }
            $job['name'] = trim((string) ($_POST['name'] ?? ''));
            $job['repository_id'] = (int) ($_POST['repository_id'] ?? 0);
            $job['enabled'] = isset($_POST['enabled']) ? 1 : 0;
            $job['sources'] = array_map(static fn ($path): array => ['path' => trim((string) $path), 'label' => ''], $sources);
            $job['schedule_type'] = (string) ($_POST['schedule_type'] ?? 'manual');
            $job['schedule_form'] = [
                'time' => (string) ($_POST['schedule_time'] ?? '03:00'),
                'weekday' => (int) ($_POST['schedule_weekday'] ?? 1),
            ];
            $job['retention_enabled'] = isset($_POST['retention_enabled']) ? 1 : 0;
            $job['keep_last'] = (int) ($_POST['keep_last'] ?? 3);
            $job['keep_daily'] = (int) ($_POST['keep_daily'] ?? 7);
            $job['keep_weekly'] = (int) ($_POST['keep_weekly'] ?? 4);
            $job['keep_monthly'] = (int) ($_POST['keep_monthly'] ?? 6);
            $repositories = $backups->readyRepositories();
            $knownIds = array_map(static fn (array $repository): int => (int) $repository['id'], $repositories);
            if (!in_array((int) $job['repository_id'], $knownIds, true)) {
                $repositories[] = [
                    'id' => (int) $job['repository_id'],
                    'name' => (string) $job['repository_name'],
                    'path' => (string) $job['repository_path'],
                    'status' => (string) $job['repository_status'],
                ];
            }
            return $this->render('backups/edit.twig', [
                'job' => $job,
                'repositories' => $repositories,
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    public function delete(string $uuid): string
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
            $backups->delete($uuid);
            $session->set('flash_success', 'Backup job deleted. Existing Restic snapshots were kept.');
            return $this->redirect('backups');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
            return $this->redirect('backups.show', ['uuid' => $uuid]);
        }
    }

    public function toggle(string $uuid): string
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
            $enabled = $backups->toggle($uuid);
            $session->set('flash_success', $enabled ? 'Backup job enabled.' : 'Backup job disabled.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('backups.show', ['uuid' => $uuid]);
    }

    public function applyRetention(string $uuid): string
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
            /** @var RetentionService $retention */
            $retention = $this->app->service(RetentionService::class);
            $retention->enqueueForJobUuid($uuid);
            $session->set('flash_success', 'Retention policy queued.');
        } catch (Throwable $exception) {
            $session->set('flash_error', $exception->getMessage());
        }
        return $this->redirect('backups.show', ['uuid' => $uuid]);
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
