<?php

declare(strict_types=1);

/**
 * Laravel-style database connection config.
 *
 * Two distinct connections:
 *
 *   churchcrm  — the ChurchCRM source-of-truth database
 *                (people, families, groups, ministries, events,
 *                scheduler, campuses, etc.)
 *                Read by adapters under app/Adapters/ChurchCRM/.
 *
 *   portal     — the portal-owned database (default: u471078694_christlike_mdb)
 *                holding portal_users, portal_user_person_links,
 *                portal_user_roles, portal_sessions, portal_audit_log,
 *                etc. — every table introduced by the portal itself.
 *                Read/written by adapters under app/Adapters/Portal/.
 *
 * Each connection is configured by its own env var family. The DB_* fallback
 * is preserved for local dev where both connections may point at the same
 * server with the same credentials.
 */

return [
    'default' => env('DB_CONNECTION', 'portal'),

    'connections' => [
        'churchcrm' => [
            'driver'    => 'mysql',
            'host'      => env('CHURCHCRM_DB_HOST',     env('DB_HOST', '127.0.0.1')),
            'port'      => env('CHURCHCRM_DB_PORT',     env('DB_PORT', '3306')),
            'database'  => env('CHURCHCRM_DB_DATABASE', env('DB_DATABASE')),
            'username'  => env('CHURCHCRM_DB_USERNAME', env('DB_USERNAME')),
            'password'  => env('CHURCHCRM_DB_PASSWORD', env('DB_PASSWORD')),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict'    => true,
        ],

        'portal' => [
            'driver'    => 'mysql',
            'host'      => env('PORTAL_DB_HOST',     env('DB_HOST', '127.0.0.1')),
            'port'      => env('PORTAL_DB_PORT',     env('DB_PORT', '3306')),
            'database'  => env('PORTAL_DB_DATABASE', 'u471078694_christlike_mdb'),
            'username'  => env('PORTAL_DB_USERNAME', env('DB_USERNAME')),
            'password'  => env('PORTAL_DB_PASSWORD', env('DB_PASSWORD')),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict'    => true,
        ],
    ],
];
