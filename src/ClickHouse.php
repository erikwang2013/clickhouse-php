<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Schema\Builder as SchemaBuilder;
use Psr\Log\LoggerInterface;

class ClickHouse
{
    protected static ?Manager $manager = null;

    public static function setManager(Manager $manager): void
    {
        static::$manager = $manager;
    }

    /**
     * 从 CLICKHOUSE_* 环境变量一键初始化，免写配置数组。
     * 原生 PHP 项目 composer require 后即可：ClickHouse::bootstrap();
     */
    public static function bootstrap(?LoggerInterface $logger = null): Manager
    {
        return static::$manager = Manager::fromEnv($logger);
    }

    public static function getManager(): ?Manager
    {
        return static::$manager;
    }

    public static function connection(?string $name = null): Builder
    {
        return new Builder(static::manager()->connection($name));
    }

    /**
     * 取底层客户端本身（需要 select/insert/ping 时用这个，connection() 返回的是查询构造器）。
     */
    public static function client(?string $name = null): ClientInterface
    {
        return static::manager()->connection($name);
    }

    /**
     * 健康检查：连不上返回 false，不抛异常。
     */
    public static function ping(?string $name = null): bool
    {
        return static::client($name)->ping();
    }

    public static function table(string $table, ?string $connection = null): Builder
    {
        return static::connection($connection)->table($table);
    }

    public static function schema(): SchemaBuilder
    {
        return new SchemaBuilder(static::manager()->connection());
    }

    public static function query(string $sql, array $bindings = []): Query\Result
    {
        return static::manager()->connection()->query($sql, $bindings);
    }

    private static function manager(): Manager
    {
        if (static::$manager === null) {
            throw new Exceptions\ConnectionException('ClickHouse manager not initialized. Call ClickHouse::setManager() first.');
        }
        return static::$manager;
    }
}