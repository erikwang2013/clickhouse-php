<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Pool\PoolInterface;
use Erikwang2013\ClickHouse\Query\Result;

class PooledClient implements ClientInterface, StreamingClientInterface
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

    /**
     * 生成器只有在真正被迭代时才取连接，迭代结束（或生成器被销毁）时归还，
     * 因此不会在流式读取期间泄漏池连接。
     */
    public function stream(string $sql, array $bindings = []): \Generator
    {
        $client = $this->pool->get();

        try {
            yield from $this->streaming($client, $sql, $bindings)->stream($sql, $bindings);
        } finally {
            $this->pool->put($client);
        }
    }

    public function raw(string $sql, array $bindings = []): string
    {
        return $this->withClient(fn(ClientInterface $c) => $this->streaming($c, $sql, $bindings)->raw($sql, $bindings));
    }

    private function streaming(ClientInterface $client, string $sql, array $bindings): StreamingClientInterface
    {
        if (!$client instanceof StreamingClientInterface) {
            throw new QueryException(
                'The pooled client does not support streaming or raw responses; use query() instead.',
                $sql,
                $bindings,
            );
        }

        return $client;
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
