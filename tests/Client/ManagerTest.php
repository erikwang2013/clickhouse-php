<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Client;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;

class ManagerTest extends TestCase
{
    private array $config = [
        'default' => 'clickhouse',
        'connections' => [
            'clickhouse' => [
                'driver' => 'http',
                'host' => 'localhost',
                'port' => 8123,
                'username' => 'default',
                'password' => '',
                'database' => 'default',
            ],
            'native' => [
                'driver' => 'tcp',
                'host' => 'localhost',
                'port' => 9000,
                'username' => 'default',
                'password' => '',
                'database' => 'default',
            ],
        ],
    ];

    public function testConnectionReturnsClient(): void
    {
        $manager = new Manager($this->config);
        $client = $manager->connection();
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    public function testConnectionReturnsSameInstance(): void
    {
        $manager = new Manager($this->config);
        $a = $manager->connection('clickhouse');
        $b = $manager->connection('clickhouse');
        $this->assertSame($a, $b);
    }

    public function testUnknownConnectionThrows(): void
    {
        $manager = new Manager($this->config);
        $this->expectException(ConnectionException::class);
        $manager->connection('nonexistent');
    }

    public function testDefaultConnection(): void
    {
        $manager = new Manager($this->config);
        $client = $manager->connection();
        $this->assertInstanceOf(ClientInterface::class, $client);
    }

    /** 环境变量在进程内共享，用完必须清掉 */
    private const ENV_KEYS = [
        'CLICKHOUSE_CONNECTION', 'CLICKHOUSE_DRIVER', 'CLICKHOUSE_HOST', 'CLICKHOUSE_PORT',
        'CLICKHOUSE_DB', 'CLICKHOUSE_USER', 'CLICKHOUSE_PASS', 'CLICKHOUSE_TIMEOUT',
        'CLICKHOUSE_HTTPS', 'CLICKHOUSE_POOL_MIN', 'CLICKHOUSE_POOL_MAX', 'CLICKHOUSE_POOL_TIMEOUT',
    ];

    protected function tearDown(): void
    {
        $this->withEnv([]);
    }

    private function withEnv(array $vars): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
        foreach ($vars as $key => $value) {
            putenv("$key=$value");
        }
    }

    private function configOf(Manager $manager): array
    {
        $ref = new \ReflectionProperty(Manager::class, 'config');
        $ref->setAccessible(true);
        return $ref->getValue($manager);
    }

    public function testFromEnvUsesDocumentedDefaults(): void
    {
        $this->withEnv([]);

        $config = $this->configOf(Manager::fromEnv());

        $this->assertSame('default', $config['default']);
        $conn = $config['connections']['default'];
        $this->assertSame('http', $conn['driver']);
        $this->assertSame('localhost', $conn['host']);
        $this->assertSame(8123, $conn['port']);
        $this->assertSame('default', $conn['database']);
        $this->assertSame('default', $conn['username']);
        $this->assertSame('', $conn['password']);
        $this->assertSame(30, $conn['timeout']);
        $this->assertFalse($conn['https']);
        $this->assertSame(2, $config['pool']['min_connections']);
        $this->assertSame(16, $config['pool']['max_connections']);
        $this->assertSame(5.0, $config['pool']['connection_timeout']);
    }

    public function testFromEnvReadsVariablesAndCastsTypes(): void
    {
        $this->withEnv([
            'CLICKHOUSE_CONNECTION' => 'olap',
            'CLICKHOUSE_HOST' => 'ch.example.com',
            'CLICKHOUSE_PORT' => '9123',
            'CLICKHOUSE_DB' => 'analytics',
            'CLICKHOUSE_USER' => 'reader',
            'CLICKHOUSE_PASS' => 'secret',
            'CLICKHOUSE_TIMEOUT' => '5',
            'CLICKHOUSE_HTTPS' => 'true',
            'CLICKHOUSE_POOL_MIN' => '1',
            'CLICKHOUSE_POOL_MAX' => '4',
            'CLICKHOUSE_POOL_TIMEOUT' => '2.5',
        ]);

        $config = $this->configOf(Manager::fromEnv());

        $this->assertSame('olap', $config['default']);
        $conn = $config['connections']['olap'];
        $this->assertSame('ch.example.com', $conn['host']);
        $this->assertSame(9123, $conn['port']);
        $this->assertSame('analytics', $conn['database']);
        $this->assertSame('reader', $conn['username']);
        $this->assertSame('secret', $conn['password']);
        $this->assertSame(5, $conn['timeout']);
        $this->assertTrue($conn['https']);
        $this->assertSame(1, $config['pool']['min_connections']);
        $this->assertSame(4, $config['pool']['max_connections']);
        $this->assertSame(2.5, $config['pool']['connection_timeout']);
    }

    public function testFromEnvBlankVariablesFallBackToDefaults(): void
    {
        $this->withEnv(['CLICKHOUSE_HOST' => '', 'CLICKHOUSE_PORT' => '']);

        $config = $this->configOf(Manager::fromEnv());

        $this->assertSame('localhost', $config['connections']['default']['host']);
        $this->assertSame(8123, $config['connections']['default']['port']);
    }

    public function testFromEnvNamedConnectionIsTheDefaultOne(): void
    {
        $this->withEnv(['CLICKHOUSE_CONNECTION' => 'olap']);

        $manager = Manager::fromEnv();

        $this->assertInstanceOf(ClientInterface::class, $manager->connection());
        $this->assertInstanceOf(ClientInterface::class, $manager->connection('olap'));
    }
}