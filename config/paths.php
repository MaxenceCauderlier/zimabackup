<?php

declare(strict_types=1);

return [
    // Paths displayed to the user on the ZimaOS host.
    'logical_roots' => [
        '/DATA',
        '/media',
    ],

    // Matching paths mounted inside the ZimaBackup container.
    'container_roots' => [
        '/DATA' => '/host/DATA',
        '/media' => '/host/media',
    ],
];
