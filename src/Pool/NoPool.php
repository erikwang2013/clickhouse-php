<?php

namespace Erikwang2013\ClickHouse\Pool;

use Erikwang2013\ClickHouse\Client\ClientInterface;

class NoPool implements PoolInterface
{
    public function __construct(
        private readonly \Closure $factory,
    ) {
    }

    public function get(): ClientInterface
    {
        return ($this->factory)();
    }

    public function put(ClientInterface $client): void
    {
    }

    public function stats(): array
    {
        return ['active' => 0, 'idle' => 0, 'total' => 0];
    }

    public function close(): void
    {
    }
}
