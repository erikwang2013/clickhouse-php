<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Client;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\PooledClient;
use Erikwang2013\ClickHouse\Pool\PoolInterface;
use Erikwang2013\ClickHouse\Query\Result;
use Mockery;
use PHPUnit\Framework\TestCase;

class PooledClientTest extends TestCase
{
    public function testBorrowsAndReturnsClientPerQuery(): void
    {
        $inner = Mockery::mock(ClientInterface::class);
        $inner->shouldReceive('query')->once()->with('SELECT 1', [])->andReturn(new Result([]));

        $pool = Mockery::mock(PoolInterface::class);
        $pool->shouldReceive('get')->once()->andReturn($inner);
        $pool->shouldReceive('put')->once()->with($inner);

        $result = (new PooledClient($pool))->query('SELECT 1');
        $this->assertInstanceOf(Result::class, $result);
    }

    public function testReturnsClientWhenQueryThrows(): void
    {
        $inner = Mockery::mock(ClientInterface::class);
        $inner->shouldReceive('query')->once()->andThrow(new \RuntimeException('boom'));

        $pool = Mockery::mock(PoolInterface::class);
        $pool->shouldReceive('get')->once()->andReturn($inner);
        $pool->shouldReceive('put')->once()->with($inner);

        try {
            (new PooledClient($pool))->query('SELECT 1');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
