<?php

declare(strict_types=1);

return [
    'name' => 'ZimaBackup',
    'env' => getenv('APP_ENV') ?: 'prod',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => getenv('TZ') ?: 'UTC',
];
