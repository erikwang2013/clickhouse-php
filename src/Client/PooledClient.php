<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Pool\PoolInterface;
use Erikwang2013\ClickHouse\Query\Result;

class PooledClient implements ClientInterface
{
    public function __construct(
        private PoolInterface $pool,
    ) {
    }

    public function query(string $sql, array $bindings = []): Result
    {
        return $this->withClient(fn(ClientInterface $c) => $c->query($sql, $bindings));
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->withClient(fn(ClientInterface $c) => $c->select($sql, $bindings));
    }

    public function insert(string $table, array $data): int
    {
        return $this->withClient(fn(ClientInterface $c) => $c->insert($table, $data));
    }

    public function ping(): bool
    {
        return $this->withClient(fn(ClientInterface $c) => $c->ping());
    }

    private function withClient(callable $fn): mixed
    {
        $client = $this->pool->get();
        try {
            return $fn($client);
        } finally {
            $this->pool->put($client);
        }
    }
}
