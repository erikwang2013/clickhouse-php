<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

/*
 * Yii3 由 yiisoft/config 读取本文件（见 composer.json 的 extra.config-plugin）。
 * 应用侧在自己的 config/params.php 里覆写 'erikwang2013/clickhouse-php' 这一段即可，
 * 不需要改这个包。字段含义见 README「配置」一节。
 */
return [
    'erikwang2013/clickhouse-php' => [
        'default' => 'default',
        'connections' => [
            'default' => [
                'driver' => 'http',
                'host' => 'localhost',
                'port' => 8123,
                'database' => 'default',
                'username' => 'default',
                'password' => '',
                'timeout' => 30,
                'https' => false,
            ],
        ],
    ],
];
