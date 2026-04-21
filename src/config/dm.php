<?php

return [
    'dm' => [
        'driver'         => 'dm',
        'tns'            => env('DB_TNS', ''),
        'host'           => env('DB_HOST', '127.0.0.1'),
        'port'           => env('DB_PORT', '5236'),
        'database'       => env('DB_DATABASE', 'laravel'),
        'username'       => env('DB_USERNAME', 'laravel'),
        'password'       => env('DB_PASSWORD', 'laravel'),
        'charset'        => env('DB_CHARSET', 'UTF8'),
        'prefix'         => env('DB_PREFIX', ''),
        'prefix_schema'  => env('DB_SCHEMA', ''),
    ],
];
