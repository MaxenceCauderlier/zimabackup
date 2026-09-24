<?php

declare(strict_types=1);

use ZimaBackup\Controller\ApplicationController;
use ZimaBackup\Controller\ApplicationRestoreController;
use ZimaBackup\Controller\BackupController;
use ZimaBackup\Controller\DashboardController;
use ZimaBackup\Controller\PageController;
use ZimaBackup\Controller\RepositoryController;
use ZimaBackup\Controller\RestoreController;
use ZimaBackup\Controller\SnapshotController;
use ZimaBackup\Controller\SettingsController;
use ZimaBackup\Controller\SnapshotApplicationController;
use ZimaBackup\Core\Application;

return static function (Application $app): void {
    $router = $app->router();

    $router->addMatchTypes([
        'uuid' => '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-4[0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}',
    ]);

    $router->map('GET', '/', [DashboardController::class, 'index'], 'dashboard');

    $router->map('GET', '/applications', [ApplicationController::class, 'index'], 'applications');
    $router->map('POST', '/applications/refresh', [ApplicationController::class, 'refresh'], 'applications.refresh');

    $router->map('GET', '/backups', [BackupController::class, 'index'], 'backups');
    $router->map('GET', '/backups/new', [BackupController::class, 'create'], 'backups.create');
    $router->map('POST', '/backups', [BackupController::class, 'store'], 'backups.store');
    $router->map('GET', '/backups/[uuid:uuid]', [BackupController::class, 'show'], 'backups.show');
    $router->map('GET', '/backups/[uuid:uuid]/edit', [BackupController::class, 'edit'], 'backups.edit');
    $router->map('POST', '/backups/[uuid:uuid]/update', [BackupController::class, 'update'], 'backups.update');
    $router->map('POST', '/backups/[uuid:uuid]/toggle', [BackupController::class, 'toggle'], 'backups.toggle');
    $router->map('POST', '/backups/[uuid:uuid]/delete', [BackupController::class, 'delete'], 'backups.delete');
    $router->map('POST', '/backups/[uuid:uuid]/run', [BackupController::class, 'run'], 'backups.run');

    $router->map('GET', '/repositories', [RepositoryController::class, 'index'], 'repositories');
    $router->map('GET', '/repositories/new', [RepositoryController::class, 'create'], 'repositories.create');
    $router->map('POST', '/repositories', [RepositoryController::class, 'store'], 'repositories.store');
    $router->map('GET', '/repositories/[uuid:uuid]/edit', [RepositoryController::class, 'edit'], 'repositories.edit');
    $router->map('POST', '/repositories/[uuid:uuid]/update', [RepositoryController::class, 'update'], 'repositories.update');
    $router->map('POST', '/repositories/[uuid:uuid]/check', [RepositoryController::class, 'check'], 'repositories.check');
    $router->map('POST', '/repositories/[uuid:uuid]/reinitialize', [RepositoryController::class, 'reinitialize'], 'repositories.reinitialize');
    $router->map('POST', '/repositories/[uuid:uuid]/remove', [RepositoryController::class, 'remove'], 'repositories.remove');
    $router->map('GET', '/repositories/[uuid:uuid]/recovery-key', [RepositoryController::class, 'recovery'], 'repositories.recovery');

    $router->map('GET', '/snapshots', [SnapshotController::class, 'index'], 'snapshots');
    $router->map('POST', '/snapshots/[i:runId]/forget', [SnapshotController::class, 'forget'], 'snapshots.forget');
    $router->map('GET', '/snapshots/[i:runId]/applications', [SnapshotApplicationController::class, 'index'], 'snapshots.applications');
    $router->map('POST', '/snapshots/[i:runId]/applications/inspect', [SnapshotApplicationController::class, 'inspect'], 'snapshots.applications.inspect');

    $router->map('GET', '/snapshot-applications/[i:applicationId]/restore', [ApplicationRestoreController::class, 'create'], 'application-restores.create');
    $router->map('POST', '/snapshot-applications/[i:applicationId]/restore', [ApplicationRestoreController::class, 'store'], 'application-restores.store');
    $router->map('GET', '/application-restores/[uuid:uuid]', [ApplicationRestoreController::class, 'show'], 'application-restores.show');
    $router->map('POST', '/application-restores/[uuid:uuid]/install', [ApplicationRestoreController::class, 'install'], 'application-restores.install');

    $router->map('GET', '/restores', [RestoreController::class, 'index'], 'restores');
    $router->map('GET', '/restores/from/[i:runId]', [RestoreController::class, 'create'], 'restores.create');
    $router->map('POST', '/restores/from/[i:runId]', [RestoreController::class, 'store'], 'restores.store');
    $router->map('GET', '/restores/[uuid:uuid]', [RestoreController::class, 'show'], 'restores.show');
    $router->map('GET', '/settings', [SettingsController::class, 'index'], 'settings');
    $router->map('POST', '/settings', [SettingsController::class, 'update'], 'settings.update');
};
