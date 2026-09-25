<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Pool;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\PoolException;
use Erikwang2013\ClickHouse\Pool\AbstractPool;
use PHPUnit\Framework\TestCase;
use Mockery;

/** 用 ChannelStub 当 channel，计数逻辑不需要协程运行时也能测 */
class StubChannelPool extends AbstractPool
{
    protected function newChannel(int $capacity): mixed
    {
        return new ChannelStub($capacity);
    }

    protected function push(mixed $client, float $timeout): bool
    {
        return $this->channel->push($client, $timeout);
    }

    protected function pop(float $timeout): mixed
    {
        return $this->channel->pop($timeout);
    }

    protected function idleCount(): int
    {
        return $this->channel->getLength();
    }
}

class AbstractPoolTest extends TestCase
{
    private int $created = 0;

    private function pool(int $min, int $max): StubChannelPool
    {
        return new StubChannelPool(function () {
            $this->created++;
            return Mockery::mock(ClientInterface::class);
        }, [
            'min_connections' => $min,
            'max_connections' => $max,
            'connection_timeout' => 0.01,
        ]);
    }

    public function testPrewarmConnectionsCountAsIdleNotActive(): void
    {
        $pool = $this->pool(2, 4);

        $this->assertSame(2, $this->created);
        $this->assertSame(['active' => 0, 'idle' => 2, 'total' => 2], $pool->stats());
    }

    public function testBorrowingMovesConnectionFromIdleToActive(): void
    {
        $pool = $this->pool(2, 4);

        $pool->get();
        $this->assertSame(['active' => 1, 'idle' => 1, 'total' => 2], $pool->stats());

        $pool->get();
        $this->assertSame(['active' => 2, 'idle' => 0, 'total' => 2], $pool->stats());
        $this->assertSame(2, $this->created);
    }

    public function testReturningMovesConnectionBackToIdle(): void
    {
        $pool = $this->pool(2, 4);

        $a = $pool->get();
        $b = $pool->get();
        $this->assertSame(['active' => 2, 'idle' => 0, 'total' => 2], $pool->stats());

        $pool->put($a);
        $this->assertSame(['active' => 1, 'idle' => 1, 'total' => 2], $pool->stats());

        $pool->put($b);
        $this->assertSame(['active' => 0, 'idle' => 2, 'total' => 2], $pool->stats());
    }

    public function testNewConnectionBeyondPrewarmIsActive(): void
    {
        $pool = $this->pool(1, 4);

        $pool->get();
        $pool->get();

        $this->assertSame(2, $this->created);
        $this->assertSame(['active' => 2, 'idle' => 0, 'total' => 2], $pool->stats());
    }

    public function testExhaustionCountsBorrowedConnectionsOnly(): void
    {
        $pool = $this->pool(2, 2);

        $borrowed = $pool->get();
        $pool->get();
        $this->assertSame(['active' => 2, 'idle' => 0, 'total' => 2], $pool->stats());

        try {
            $pool->get();
            $this->fail('expected PoolException');
        } catch (PoolException) {
        }

        // 归还一条后又能借到，且没有新建连接
        $pool->put($borrowed);
        $this->assertSame($borrowed, $pool->get());
        $this->assertSame(2, $this->created);
    }

    public function testPutOnClosedPoolDropsConnectionAndDecrementsActive(): void
    {
        $pool = $this->pool(1, 4);
        $client = $pool->get();
        $pool->close();

        $pool->put($client);

        $this->assertSame(['active' => 0, 'idle' => 0, 'total' => 0], $pool->stats());
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
