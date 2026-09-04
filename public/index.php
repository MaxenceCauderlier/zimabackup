<?php

declare(strict_types=1);

use ZimaBackup\Core\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Application::create(dirname(__DIR__));

$registerRoutes = require dirname(__DIR__) . '/config/routes.php';
$registerRoutes($app);

$app->run();
