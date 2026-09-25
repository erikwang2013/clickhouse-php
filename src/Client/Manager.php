<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Pool\PoolInterface;
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
        $transport = $this->createTransport($connConfig);

        return new HttpClient($transport, $connConfig, $this->logger);
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