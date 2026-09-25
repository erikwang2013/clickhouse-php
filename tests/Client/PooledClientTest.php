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

    public function testStreamReturnsConnectionAfterExhausting(): void
    {
        $inner = Mockery::mock(ClientInterface::class, \Erikwang2013\ClickHouse\Client\StreamingClientInterface::class);
        $inner->shouldReceive('stream')->once()->andReturn((function () {
            yield ['id' => 1];
            yield ['id' => 2];
        })());

        $pool = Mockery::mock(PoolInterface::class);
        $pool->shouldReceive('get')->once()->andReturn($inner);
        $pool->shouldReceive('put')->once()->with($inner);

        $rows = [];
        foreach ((new PooledClient($pool))->stream('SELECT * FROM t') as $row) {
            $rows[] = $row;
        }

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    public function testStreamReturnsConnectionWhenConsumerStopsEarly(): void
    {
        $inner = Mockery::mock(ClientInterface::class, \Erikwang2013\ClickHouse\Client\StreamingClientInterface::class);
        $inner->shouldReceive('stream')->once()->andReturn((function () {
            yield ['id' => 1];
            yield ['id' => 2];
        })());

        $pool = Mockery::mock(PoolInterface::class);
        $pool->shouldReceive('get')->once()->andReturn($inner);
        // 消费者提前 break，生成器被销毁时也必须归还连接，否则池会被慢慢耗干
        $pool->shouldReceive('put')->once()->with($inner);

        foreach ((new PooledClient($pool))->stream('SELECT * FROM t') as $row) {
            break;
        }

        // Mockery 的 put 期望即为断言，显式计入避免 risky
        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
