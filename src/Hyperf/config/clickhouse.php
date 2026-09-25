<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

// 连接名与变量名和 Laravel/ThinkPHP/Webman 配置一致
$connection = env('CLICKHOUSE_CONNECTION', 'clickhouse');

return [
    'default' => $connection,
    'connections' => [
        $connection => [
            'driver' => env('CLICKHOUSE_DRIVER', 'http'),
            'host' => env('CLICKHOUSE_HOST', 'localhost'),
            'port' => (int) env('CLICKHOUSE_PORT', 8123),
            'database' => env('CLICKHOUSE_DB', 'default'),
            'username' => env('CLICKHOUSE_USER', 'default'),
            'password' => env('CLICKHOUSE_PASS', ''),
            'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 30),
            'https' => (bool) env('CLICKHOUSE_HTTPS', false),
        ],
    ],
    'migrations' => [
        'path' => BASE_PATH . '/database/clickhouse-migrations',
        'table' => 'clickhouse_migrations',
    ],
    'pool' => [
        'min_connections' => (int) env('CLICKHOUSE_POOL_MIN', 2),
        'max_connections' => (int) env('CLICKHOUSE_POOL_MAX', 16),
        'connection_timeout' => (float) env('CLICKHOUSE_POOL_TIMEOUT', 5.0),
    ],
    'query_log' => (bool) env('CLICKHOUSE_QUERY_LOG', false),
];