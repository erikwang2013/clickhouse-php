<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Pool;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\PoolException;

abstract class AbstractPool implements PoolInterface
{
    protected mixed $channel;
    private int $activeCount = 0;
    private int $minConnections;
    private int $maxConnections;
    private float $connectionTimeout;

    public function __construct(
        private \Closure $factory,
        private array $config = [],
    ) {
        $this->minConnections = $config['min_connections'] ?? 2;
        $this->maxConnections = $config['max_connections'] ?? $this->defaultMaxConnections();
        $this->connectionTimeout = $config['connection_timeout'] ?? 5.0;

        $this->channel = $this->newChannel($this->maxConnections);

        // 预热只把连接放进 channel 里待借，不算已借出
        for ($i = 0; $i < $this->minConnections; $i++) {
            $this->push(($this->factory)(), $this->connectionTimeout);
        }
    }

    public function get(): ClientInterface
    {
        $client = $this->pop($this->connectionTimeout);

        if ($client === false) {
            if ($this->activeCount >= $this->maxConnections) {
                throw new PoolException($this->name() . ': connection pool exhausted');
            }
            $client = ($this->factory)();
        }

        $this->activeCount++;

        return $client;
    }

    public function put(ClientInterface $client): void
    {
        $this->activeCount = max(0, $this->activeCount - 1);

        // 池已满或已关闭时推不回去，连接就此丢弃
        $this->push($client, $this->connectionTimeout);
    }

    /** active = 已借出，idle = 池内空闲，total = active + idle */
    public function stats(): array
    {
        $idle = $this->idleCount();

        return [
            'active' => $this->activeCount,
            'idle' => $idle,
            'total' => $this->activeCount + $idle,
        ];
    }

    public function close(): void
    {
        $this->channel->close();
    }

    protected function defaultMaxConnections(): int
    {
        return 16;
    }

    private function name(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    abstract protected function newChannel(int $capacity): mixed;

    abstract protected function push(mixed $client, float $timeout): bool;

    abstract protected function pop(float $timeout): mixed;

    abstract protected function idleCount(): int;
}
