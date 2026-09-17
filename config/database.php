<?php

declare(strict_types=1);

/**
 * Database connections.
 *
 *   members   — the member database (database/members/001_schema.sql): people,
 *               households, ministries, calendar, serving schedule, logins.
 *   visitors  — the visitors SQLite file (database/visitors/001_schema.sql):
 *               guest registrations and RSVPs until they are promoted.
 */

return [
    'default' => 'members',

    'connections' => [
        'members' => [
            'driver'    => 'mysql',
            'host'      => env('MEMBERS_DB_HOST', '127.0.0.1'),
            'port'      => env('MEMBERS_DB_PORT', '3306'),
            'database'  => env('MEMBERS_DB_DATABASE'),
            'username'  => env('MEMBERS_DB_USERNAME'),
            'password'  => env('MEMBERS_DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict'    => true,
        ],

        'visitors' => [
            'driver'   => 'sqlite',
            'database' => env('VISITORS_DB_PATH', 'storage/private/database/visitors.sqlite'),
        ],
    ],
];
