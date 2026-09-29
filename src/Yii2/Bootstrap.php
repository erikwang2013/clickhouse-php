<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

namespace Erikwang2013\ClickHouse\Yii2;

use yii\base\BootstrapInterface;

/**
 * 零配置接入：把本类加进应用的 bootstrap，clickhouse 组件会自动注册（web 与 console 都生效）。
 *
 *     // config/web.php、config/console.php
 *     'bootstrap' => ['Erikwang2013\ClickHouse\Yii2\Bootstrap'],
 *
 * 应用自己配了 clickhouse 组件时不覆盖；参数走 CLICKHOUSE_* 环境变量。
 */
class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!$app->has('clickhouse')) {
            $app->set('clickhouse', ['class' => ClickHouse::class]);
        }

        // 组件是懒加载的，这里主动取一次，把 Manager 交给静态门面 ClickHouse::table()。
        // Manager 只解析配置、不建连接，bootstrap 阶段没有网络开销。
        $app->get('clickhouse');
    }
}
