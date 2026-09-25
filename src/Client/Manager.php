<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Pool\PoolInterface;
use Erikwang2013\ClickHouse\Pool\SwoolePool;
use Erikwang2013\ClickHouse\Pool\SwowPool;
use Erikwang2013\ClickHouse\Pool\WorkermanPool;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Transport\HttpTransport;
use Erikwang2013\ClickHouse\Transport\TransportInterface;
use Psr\Log\LoggerInterface;

class Manager
{
    private array $connections = [];
    private array $pools = [];
    private string $defaultConnection;

    public function __construct(
        private array $config,
        private ?LoggerInterface $logger = null,
    ) {
        $this->defaultConnection = $config['default'] ?? 'default';
    }

    /**
     * 用 CLICKHOUSE_* 环境变量构建配置，供不依赖框架的原生 PHP 项目使用。
     * 变量名与默认值见 README「环境变量」一节。
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        $name = self::env('CLICKHOUSE_CONNECTION', 'default');

        return new self([
            'default' => $name,
            'connections' => [
                $name => [
                    'driver' => self::env('CLICKHOUSE_DRIVER', 'http'),
                    'host' => self::env('CLICKHOUSE_HOST', 'localhost'),
                    'port' => (int) self::env('CLICKHOUSE_PORT', 8123),
                    'database' => self::env('CLICKHOUSE_DB', 'default'),
                    'username' => self::env('CLICKHOUSE_USER', 'default'),
                    'password' => self::env('CLICKHOUSE_PASS', ''),
                    'timeout' => (int) self::env('CLICKHOUSE_TIMEOUT', 30),
                    'https' => filter_var(self::env('CLICKHOUSE_HTTPS', false), FILTER_VALIDATE_BOOL),
                ],
            ],
            'pool' => [
                'min_connections' => (int) self::env('CLICKHOUSE_POOL_MIN', 2),
                'max_connections' => (int) self::env('CLICKHOUSE_POOL_MAX', 16),
                'connection_timeout' => (float) self::env('CLICKHOUSE_POOL_TIMEOUT', 5.0),
            ],
        ], $logger);
    }

    /**
     * 读取环境变量，未设置或为空串时取默认值。
     */
    private static function env(string $key, mixed $default): mixed
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * connection() 的别名：门面（含 Laravel Facade，它把静态调用转发到 Manager）上
     * `ClickHouse::client()` 与 `ClickHouse::connection()` 语义不同，用这个拿客户端本身。
     */
    public function client(?string $name = null): ClientInterface
    {
        return $this->connection($name);
    }

    public function connection(?string $name = null): ClientInterface
    {
        $name ??= $this->defaultConnection;

        if (isset($this->pools[$name])) {
            return $this->connections[$name] ??= new PooledClient($this->pools[$name]);
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        return $this->connections[$name] = $this->make($name);
    }

    public function setPool(string $name, PoolInterface $pool): void
    {
        $this->pools[$name] = $pool;
    }

    private function make(string $name): ClientInterface
    {
        $connections = $this->config['connections'] ?? [];

        if (!isset($connections[$name])) {
            throw new ConnectionException("ClickHouse connection [{$name}] not configured.");
        }

        $connConfig = new Config($connections[$name]);
        $pool = $this->createPool($connConfig);

        if ($pool !== null) {
            return new PooledClient($pool);
        }

        return new HttpClient($this->createTransport($connConfig), $connConfig, $this->logger);
    }

    /**
     * 连接自带的 pool 覆盖全局 pool；两处都没配 pool 就直连（与池化功能引入前一致）。
     */
    private function createPool(Config $connConfig): ?PoolInterface
    {
        $poolConfig = array_replace($this->config['pool'] ?? [], $connConfig->get('pool', []) ?? []);

        if ($poolConfig === []) {
            return null;
        }

        $driver = $poolConfig['driver'] ?? null;

        if ($driver === 'none') {
            return null;
        }

        $poolClass = self::poolClass($driver);

        if ($poolClass === null) {
            // 没有可用的协程运行时（典型 FPM/CLI）：不池化，也不限流
            return null;
        }

        $factory = fn(): ClientInterface => new HttpClient(
            $this->createTransport($connConfig), $connConfig, $this->logger,
        );

        return new $poolClass($factory, $poolConfig);
    }

    /**
     * 解析池实现。$driver 为 null 时按 swoole → swow → workerman 找运行时真正可用的通道；
     * 返回 null 表示当前进程池化不了（调用方退回直连）。只看通道类是否存在，不看 extension_loaded。
     */
    private static function poolClass(?string $driver): ?string
    {
        return match ($driver) {
            null => self::poolClass('swoole') ?? self::poolClass('swow') ?? self::poolClass('workerman'),
            'swoole' => self::swooleAvailable() ? SwoolePool::class : null,
            'swow' => class_exists(\Swow\Channel::class) ? SwowPool::class : null,
            'workerman' => class_exists(\Workerman\Coroutine\Channel::class) ? WorkermanPool::class : null,
            default => throw new ConnectionException("Unsupported ClickHouse pool driver [{$driver}]."),
        };
    }

    /**
     * Swoole 的 Channel 在协程外调用是 fatal 且捕获不住，所以光有类还不够，必须真的在协程里。
     */
    private static function swooleAvailable(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\Channel::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    private function createTransport(Config $config): TransportInterface
    {
        $driver = $config->get('driver', 'http');

        return match ($driver) {
            'http' => new HttpTransport($config),
            'native', 'tcp' => throw new ConnectionException('Native TCP transport not yet implemented. Use HTTP driver.'),
            default => throw new ConnectionException("Unsupported ClickHouse driver [{$driver}]."),
        };
    }
}