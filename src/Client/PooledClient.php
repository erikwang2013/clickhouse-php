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
        $client = $this->pool->get();
        try {
            return $client->query($sql, $bindings);
        } finally {
            $this->pool->put($client);
        }
    }

    public function select(string $sql, array $bindings = []): array
    {
        $client = $this->pool->get();
        try {
            return $client->select($sql, $bindings);
        } finally {
            $this->pool->put($client);
        }
    }

    public function insert(string $table, array $data): int
    {
        $client = $this->pool->get();
        try {
            return $client->insert($table, $data);
        } finally {
            $this->pool->put($client);
        }
    }

    public function ping(): bool
    {
        $client = $this->pool->get();
        try {
            return $client->ping();
        } finally {
            $this->pool->put($client);
        }
    }
}
