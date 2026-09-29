<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

namespace Erikwang2013\ClickHouse\Yii3;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Query\Result;
use Erikwang2013\ClickHouse\Schema\Builder as SchemaBuilder;

/**
 * 由包的 config/di.php 注册进 yiisoft/di，构造函数注入即可用：
 *
 *     public function __construct(private ClickHouseConnection $clickhouse) {}
 *
 *     $this->clickhouse->table('logs')->where('level', 'error')->get();
 *
 * 需要多连接、连接池或底层客户端时注入 Manager 本身（也一并注册了）。
 */
final class ClickHouseConnection
{
    public function __construct(
        private Manager $manager,
    ) {
    }

    public function manager(): Manager
    {
        return $this->manager;
    }

    public function client(?string $name = null): ClientInterface
    {
        return $this->manager->connection($name);
    }

    public function table(string $table, ?string $connection = null): Builder
    {
        return (new Builder($this->manager->connection($connection)))->table($table);
    }

    public function schema(?string $connection = null): SchemaBuilder
    {
        return new SchemaBuilder($this->manager->connection($connection));
    }

    public function query(string $sql, array $bindings = []): Result
    {
        return $this->manager->connection()->query($sql, $bindings);
    }

    public function ping(?string $name = null): bool
    {
        return $this->manager->connection($name)->ping();
    }
}
