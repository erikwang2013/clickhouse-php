<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


// 变量名与 Laravel/Webman 配置一致；环境变量为空串时回落默认值
$connection = getenv('CLICKHOUSE_CONNECTION') ?: 'clickhouse';

return [
    'default' => $connection,
    'connections' => [
        $connection => [
            'driver' => getenv('CLICKHOUSE_DRIVER') ?: 'http',
            'host' => getenv('CLICKHOUSE_HOST') ?: 'localhost',
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DB') ?: 'default',
            'username' => getenv('CLICKHOUSE_USER') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASS') ?: '',
            'timeout' => (int) (getenv('CLICKHOUSE_TIMEOUT') ?: 30),
            'https' => filter_var(getenv('CLICKHOUSE_HTTPS') ?: false, FILTER_VALIDATE_BOOL),
        ],
    ],
    'migrations' => [
        'path' => '',
        'table' => 'clickhouse_migrations',
    ],
    'pool' => [
        'min_connections' => (int) (getenv('CLICKHOUSE_POOL_MIN') ?: 1),
        'max_connections' => (int) (getenv('CLICKHOUSE_POOL_MAX') ?: 8),
        'connection_timeout' => (float) (getenv('CLICKHOUSE_POOL_TIMEOUT') ?: 5),
    ],
    'query_log' => filter_var(getenv('CLICKHOUSE_QUERY_LOG') ?: false, FILTER_VALIDATE_BOOL),
];
