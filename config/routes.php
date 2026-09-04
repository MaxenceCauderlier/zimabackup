<?php

declare(strict_types=1);

use ZimaBackup\Controller\DashboardController;
use ZimaBackup\Controller\PageController;
use ZimaBackup\Controller\RepositoryController;
use ZimaBackup\Core\Application;

return static function (Application $app): void {
    $router = $app->router();

    $router->addMatchTypes([
    'uuid' => '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-4[0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}',
]);

    $router->map('GET', '/', [DashboardController::class, 'index'], 'dashboard');
    $router->map('GET', '/backups', [PageController::class, 'backups'], 'backups');

    $router->map('GET', '/repositories', [RepositoryController::class, 'index'], 'repositories');
    $router->map('GET', '/repositories/new', [RepositoryController::class, 'create'], 'repositories.create');
    $router->map('POST', '/repositories', [RepositoryController::class, 'store'], 'repositories.store');
    $router->map('GET', '/repositories/[uuid:uuid]/recovery-key', [RepositoryController::class, 'recovery'], 'repositories.recovery');

    $router->map('GET', '/snapshots', [PageController::class, 'snapshots'], 'snapshots');
    $router->map('GET', '/settings', [PageController::class, 'settings'], 'settings');
};
