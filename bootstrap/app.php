<?php

declare(strict_types=1);

use App\Providers\PortalServiceProvider;

return [
    'providers' => [
        PortalServiceProvider::class,
    ],
    'routes' => [
        'web' => __DIR__ . '/../routes/web.php',
        'api' => __DIR__ . '/../routes/api.php',
    ],
];

