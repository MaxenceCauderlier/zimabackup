<?php

declare(strict_types=1);

use ZimaBackup\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$database = new Database($root . '/storage/database.sqlite');
$database->migrate($root . '/database/migrations');

echo "Database migrations are up to date.\n";
