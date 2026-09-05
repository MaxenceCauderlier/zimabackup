<?php

declare(strict_types=1);

return [
    // Keep container paths identical to ZimaOS host paths. Restic snapshots then
    // record /DATA/... and /media/... instead of implementation-specific paths.
    'logical_roots' => [
        '/DATA',
        '/media',
    ],
    'container_roots' => [
        '/DATA' => '/DATA',
        '/media' => '/media',
    ],
];
