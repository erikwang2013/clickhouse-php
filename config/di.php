<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Yii3\ClickHouseConnection;

/** @var array $params */

/*
 * 把 Manager 与便捷入口注册成容器服务（yiisoft/di 每个容器只保留一份实例），
 * 于是控制器、服务里直接构造函数注入即可：
 *
 *     public function __construct(private ClickHouseConnection $clickhouse) {}
 *     public function __construct(private Manager $clickhouse) {}   // 需要多连接 / 底层客户端时
 */
return [
    Manager::class => static fn (): Manager => new Manager($params['erikwang2013/clickhouse-php']),
    ClickHouseConnection::class => static fn (Manager $manager): ClickHouseConnection => new ClickHouseConnection($manager),
];
