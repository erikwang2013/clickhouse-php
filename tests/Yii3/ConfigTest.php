<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


declare(strict_types=1);

namespace Erikwang2013\ClickHouse\Tests\Yii3;

use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Yii3\ClickHouseConnection;
use PHPUnit\Framework\TestCase;

/**
 * Yii3 侧没有类要测，要测的是 config/params.php 与 config/di.php 的约定：
 * 两边的键名 / 类名必须对得上，对不上只有真跑起来才炸。不需要 yiisoft/di 在场。
 */
class ConfigTest extends TestCase
{
    private const PACKAGE = 'erikwang2013/clickhouse-php';

    private function params(): array
    {
        return require dirname(__DIR__, 2) . '/config/params.php';
    }

    /** di.php 读的 $params['erikwang2013/clickhouse-php'] 必须真的存在且结构完整。 */
    public function testParamsProvideManagerConfig(): void
    {
        $params = $this->params();

        self::assertArrayHasKey(self::PACKAGE, $params);

        $config = $params[self::PACKAGE];

        self::assertArrayHasKey('default', $config);
        self::assertArrayHasKey('connections', $config);
        self::assertArrayHasKey($config['default'], $config['connections'], 'default 指向的连接必须存在');
    }

    /** di.php 用 params 建出的 Manager 真的拿到了那些参数。 */
    public function testDiBuildsManagerFromParams(): void
    {
        $definitions = $this->definitions();

        self::assertArrayHasKey(Manager::class, $definitions);

        $manager = $definitions[Manager::class]();
        self::assertInstanceOf(Manager::class, $manager);

        $ref = new \ReflectionProperty(Manager::class, 'config');
        $ref->setAccessible(true);

        $expected = $this->params()[self::PACKAGE];
        self::assertSame($expected, $ref->getValue($manager));
    }

    /** 便捷入口可用，且 table() 能编出 SQL（不连服务器）。 */
    public function testDiBuildsConnectionWrapper(): void
    {
        $definitions = $this->definitions();

        self::assertArrayHasKey(ClickHouseConnection::class, $definitions);

        $connection = $definitions[ClickHouseConnection::class]($definitions[Manager::class]());

        self::assertInstanceOf(ClickHouseConnection::class, $connection);
        self::assertStringContainsString('events', $connection->table('events')->where('level', 'error')->toSql());
    }

    private function definitions(): array
    {
        $params = $this->params();

        return require dirname(__DIR__, 2) . '/config/di.php';
    }
}
