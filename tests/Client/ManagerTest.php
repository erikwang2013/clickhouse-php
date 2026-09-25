<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace {
    /**
     * 四个框架配置文件里的 env()/database_path()/BASE_PATH 只有框架运行时才提供，
     * 这里兜一层最小实现，好让这些文件能被直接 require 进测试；装了真框架就让位。
     */
    if (!function_exists('env')) {
        function env(string $key, mixed $default = null): mixed
        {
            $value = getenv($key);

            if ($value === false) {
                return $default;
            }

            return match (strtolower($value)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $value,
            };
        }
    }

    if (!function_exists('database_path')) {
        function database_path(string $path = ''): string
        {
            return sys_get_temp_dir() . '/' . $path;
        }
    }

    if (!defined('BASE_PATH')) {
        define('BASE_PATH', sys_get_temp_dir());
    }
}

namespace Erikwang2013\ClickHouse\Tests\Client {

    use Erikwang2013\ClickHouse\Client\ClientInterface;
    use Erikwang2013\ClickHouse\Client\HttpClient;
    use Erikwang2013\ClickHouse\Client\Manager;
    use Erikwang2013\ClickHouse\Client\PooledClient;
    use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
    use Erikwang2013\ClickHouse\Pool\PoolInterface;
    use Erikwang2013\ClickHouse\Pool\SwowPool;
    use Erikwang2013\ClickHouse\Pool\SwoolePool;
    use Erikwang2013\ClickHouse\Pool\WorkermanPool;
    use Erikwang2013\ClickHouse\Tests\Pool\ChannelStub;
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

        public function testConfigWithoutPoolSectionStaysDirect(): void
        {
            $manager = new Manager($this->config);

            $this->assertInstanceOf(HttpClient::class, $manager->connection());
        }

        public function testExplicitSetPoolStillWins(): void
        {
            $pool = $this->createMock(PoolInterface::class);
            $manager = new Manager($this->config + ['pool' => ['driver' => 'none']]);
            $manager->setPool('clickhouse', $pool);

            $this->assertInstanceOf(PooledClient::class, $manager->connection());
        }

        /** 环境变量在进程内共享，用完必须清掉 */
        private const ENV_KEYS = [
            'CLICKHOUSE_CONNECTION', 'CLICKHOUSE_DRIVER', 'CLICKHOUSE_HOST', 'CLICKHOUSE_PORT',
            'CLICKHOUSE_DB', 'CLICKHOUSE_USER', 'CLICKHOUSE_PASS', 'CLICKHOUSE_TIMEOUT',
            'CLICKHOUSE_HTTPS', 'CLICKHOUSE_POOL_MIN', 'CLICKHOUSE_POOL_MAX', 'CLICKHOUSE_POOL_TIMEOUT',
            'CLICKHOUSE_QUERY_LOG',
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

        private function poolOf(ClientInterface $client): PoolInterface
        {
            $ref = new \ReflectionProperty(PooledClient::class, 'pool');
            $ref->setAccessible(true);
            return $ref->getValue($client);
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

        /**
         * fromEnv 自带 pool 配置：非协程进程里必须还能用（swoole 扩展装了但没在协程里时，
         * 真去建 Swoole 池会在预热 push 时 fatal，这条测试就是拦这个的）。
         */
        public function testFromEnvConnectionIsUsableWithoutCoroutineRuntime(): void
        {
            $this->withEnv([]);

            $client = Manager::fromEnv()->connection();

            $this->assertInstanceOf(ClientInterface::class, $client);
        }

        public function testPoolDriverNoneKeepsDirectClient(): void
        {
            $manager = new Manager($this->config + ['pool' => [
                'driver' => 'none',
                'min_connections' => 1,
                'max_connections' => 4,
            ]]);

            $this->assertInstanceOf(HttpClient::class, $manager->connection());
        }

        public function testSwoolePoolIsNotBuiltOutsideCoroutine(): void
        {
            $manager = new Manager($this->config + ['pool' => ['driver' => 'swoole']]);

            $this->assertInstanceOf(HttpClient::class, $manager->connection());
        }

        public function testUnknownPoolDriverThrows(): void
        {
            $manager = new Manager($this->config + ['pool' => ['driver' => 'redis']]);

            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('redis');
            $manager->connection();
        }

        public function testPoolConfigWrapsClientInPooledClient(): void
        {
            if (!ChannelStub::register('Workerman\Coroutine\Channel')) {
                $this->markTestSkipped('real Workerman channel installed; stub unavailable');
            }

            $manager = new Manager($this->config + ['pool' => [
                'driver' => 'workerman',
                'min_connections' => 1,
                'max_connections' => 4,
                'connection_timeout' => 0.01,
            ]]);

            $client = $manager->connection();

            $this->assertInstanceOf(PooledClient::class, $client);
            $this->assertInstanceOf(WorkermanPool::class, $this->poolOf($client));
            $this->assertSame(['active' => 0, 'idle' => 1, 'total' => 1], $this->poolOf($client)->stats());
        }

        public function testAutoDetectUsesFirstAvailableChannel(): void
        {
            if (!ChannelStub::register('Swow\Channel')) {
                $this->markTestSkipped('real Swow channel installed; stub unavailable');
            }

            $manager = new Manager($this->config + ['pool' => ['min_connections' => 1]]);

            $client = $manager->connection();

            $this->assertInstanceOf(PooledClient::class, $client);
            $this->assertInstanceOf(SwowPool::class, $this->poolOf($client));
        }

        public function testConnectionLevelPoolOverridesGlobalPool(): void
        {
            if (!ChannelStub::register('Workerman\Coroutine\Channel')) {
                $this->markTestSkipped('real Workerman channel installed; stub unavailable');
            }

            $manager = new Manager([
                'default' => 'clickhouse',
                'connections' => [
                    'clickhouse' => $this->config['connections']['clickhouse'] + [
                        'pool' => ['min_connections' => 1, 'max_connections' => 8],
                    ],
                ],
                'pool' => ['driver' => 'workerman', 'min_connections' => 3, 'max_connections' => 8],
            ]);

            $this->assertSame(1, $this->poolOf($manager->connection())->stats()['idle']);
        }

        public function testPooledConnectionIsReusedAcrossCalls(): void
        {
            if (!ChannelStub::register('Workerman\Coroutine\Channel')) {
                $this->markTestSkipped('real Workerman channel installed; stub unavailable');
            }

            $manager = new Manager($this->config + ['pool' => [
                'driver' => 'workerman',
                'min_connections' => 1,
                'max_connections' => 4,
                'connection_timeout' => 0.01,
            ]]);

            $this->assertSame($manager->connection(), $manager->connection());
        }

        /**
         * @return array<string, array{string}>
         */
        public static function frameworkConfigProvider(): array
        {
            $root = __DIR__ . '/../../src';

            return [
                'laravel' => [$root . '/Laravel/config/clickhouse.php'],
                'thinkphp' => [$root . '/ThinkPHP/config/clickhouse.php'],
                'webman' => [$root . '/Webman/config/clickhouse.php'],
                'hyperf' => [$root . '/Hyperf/config/clickhouse.php'],
            ];
        }

        /**
         * README 声称四家框架读同一套 CLICKHOUSE_* 变量名，这里按 README 逐个核。
         *
         * @dataProvider frameworkConfigProvider
         */
        public function testFrameworkConfigReadsDocumentedEnvVars(string $file): void
        {
            $this->withEnv([
                'CLICKHOUSE_CONNECTION' => 'olap',
                'CLICKHOUSE_DRIVER' => 'http',
                'CLICKHOUSE_HOST' => 'ch.example.com',
                'CLICKHOUSE_PORT' => '9123',
                'CLICKHOUSE_DB' => 'analytics',
                'CLICKHOUSE_USER' => 'reader',
                'CLICKHOUSE_PASS' => 'secret',
                'CLICKHOUSE_TIMEOUT' => '7',
                'CLICKHOUSE_HTTPS' => 'true',
                'CLICKHOUSE_POOL_MIN' => '1',
                'CLICKHOUSE_POOL_MAX' => '4',
                'CLICKHOUSE_POOL_TIMEOUT' => '2.5',
                'CLICKHOUSE_QUERY_LOG' => 'true',
            ]);

            $config = require $file;

            // default 必须指向 connections 里真实存在的键，否则 CLICKHOUSE_CONNECTION 一改就报「未配置」
            $this->assertArrayHasKey($config['default'], $config['connections'], $file);
            $this->assertSame('olap', $config['default'], $file);

            $conn = $config['connections']['olap'];
            $this->assertSame('http', $conn['driver'], $file);
            $this->assertSame('ch.example.com', $conn['host'], $file);
            $this->assertEquals(9123, $conn['port'], $file);
            $this->assertSame('analytics', $conn['database'], $file);
            $this->assertSame('reader', $conn['username'], $file);
            $this->assertSame('secret', $conn['password'], $file);
            $this->assertEquals(7, $conn['timeout'], $file);
            $this->assertArrayHasKey('https', $conn, $file);
            $this->assertTrue($conn['https'], $file);

            $this->assertEquals(1, $config['pool']['min_connections'], $file);
            $this->assertEquals(4, $config['pool']['max_connections'], $file);
            $this->assertEquals(2.5, $config['pool']['connection_timeout'], $file);
            $this->assertTrue($config['query_log'], $file);
        }

        /**
         * @dataProvider frameworkConfigProvider
         */
        public function testFrameworkConfigDefaultsWhenEnvUnset(string $file): void
        {
            $this->withEnv([]);

            $config = require $file;

            $this->assertArrayHasKey($config['default'], $config['connections'], $file);

            $conn = $config['connections'][$config['default']];
            $this->assertSame('http', $conn['driver'], $file);
            $this->assertSame('localhost', $conn['host'], $file);
            $this->assertEquals(8123, $conn['port'], $file);
            $this->assertSame('default', $conn['database'], $file);
            $this->assertArrayHasKey('https', $conn, $file);
            $this->assertFalse($conn['https'], $file);
            $this->assertFalse($config['query_log'], $file);
        }

        public function testFrameworkConfigHttpsFalseStaysFalse(): void
        {
            $this->withEnv(['CLICKHOUSE_HTTPS' => 'false']);

            $config = require __DIR__ . '/../../src/ThinkPHP/config/clickhouse.php';

            $this->assertFalse($config['connections'][$config['default']]['https']);
        }
    }
}
