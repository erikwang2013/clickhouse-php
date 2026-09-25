<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


// 连接名取自环境变量，connections 的键必须跟着它走，否则 default 会指向不存在的连接
$connection = env('CLICKHOUSE_CONNECTION', 'default');

return [
    'default' => $connection,
    'connections' => [
        $connection => [
            'driver' => env('CLICKHOUSE_DRIVER', 'http'),
            'host' => env('CLICKHOUSE_HOST', 'localhost'),
            'port' => env('CLICKHOUSE_PORT', 8123),
            'database' => env('CLICKHOUSE_DB', 'default'),
            'username' => env('CLICKHOUSE_USER', 'default'),
            'password' => env('CLICKHOUSE_PASS', ''),
            'timeout' => env('CLICKHOUSE_TIMEOUT', 30),
            'https' => env('CLICKHOUSE_HTTPS', false),
        ],
    ],
    'migrations' => [
        'path' => database_path('clickhouse-migrations'),
        'table' => 'clickhouse_migrations',
    ],
    'pool' => [
        'min_connections' => env('CLICKHOUSE_POOL_MIN', 1),
        'max_connections' => env('CLICKHOUSE_POOL_MAX', 8),
        'connection_timeout' => env('CLICKHOUSE_POOL_TIMEOUT', 5),
    ],
    'query_log' => env('CLICKHOUSE_QUERY_LOG', false),
];
