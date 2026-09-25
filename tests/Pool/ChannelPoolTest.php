<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Pool;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\PoolException;
use Erikwang2013\ClickHouse\Pool\SwoolePool;
use Erikwang2013\ClickHouse\Pool\SwowPool;
use Erikwang2013\ClickHouse\Pool\WorkermanPool;
use PHPUnit\Framework\TestCase;
use Mockery;

class ChannelPoolTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, int}>
     */
    public static function poolProvider(): array
    {
        return [
            'swoole' => [SwoolePool::class, 'Swoole\Coroutine\Channel', 16],
            'swow' => [SwowPool::class, 'Swow\Channel', 16],
            'workerman' => [WorkermanPool::class, 'Workerman\Coroutine\Channel', 8],
        ];
    }

    private function runWithChannel(string $channelClass, \Closure $body): void
    {
        if (ChannelStub::register($channelClass)) {
            $body();
            return;
        }
        if (function_exists('Swoole\Coroutine\run')) {
            // real swoole loaded: channel calls require a coroutine context
            \Swoole\Coroutine\run($body);
            return;
        }
        $this->markTestSkipped("Real {$channelClass} loaded; no coroutine runtime available");
    }

    private function countingFactory(int &$count): \Closure
    {
        return function () use (&$count) {
            $count++;
            return Mockery::mock(ClientInterface::class);
        };
    }

    /**
     * @dataProvider poolProvider
     */
    public function testConstructorPrewarmsMinConnections(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 3,
                'max_connections' => 10,
                'connection_timeout' => 0.01,
            ]);

            $this->assertSame(3, $created);
            $this->assertSame(['active' => 0, 'idle' => 3, 'total' => 3], $pool->stats());
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testGetReusesIdleConnection(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 2,
                'max_connections' => 10,
                'connection_timeout' => 0.01,
            ]);

            $client = $pool->get();

            $this->assertInstanceOf(ClientInterface::class, $client);
            $this->assertSame(2, $created);
            $this->assertSame(['active' => 1, 'idle' => 1, 'total' => 2], $pool->stats());
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testGetCreatesNewConnectionWhenIdleEmpty(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 1,
                'max_connections' => 5,
                'connection_timeout' => 0.01,
            ]);

            $pool->get();
            $pool->get();

            $this->assertSame(2, $created);
            $this->assertSame(['active' => 2, 'idle' => 0, 'total' => 2], $pool->stats());
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testExhaustionThrowsPoolException(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 0,
                'max_connections' => 2,
                'connection_timeout' => 0.01,
            ]);

            $pool->get();
            $pool->get();

            $caught = null;
            try {
                $pool->get();
            } catch (PoolException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(PoolException::class, $caught);
            $this->assertSame(2, $created);
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testPutReturnsConnectionToPool(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 1,
                'max_connections' => 5,
                'connection_timeout' => 0.01,
            ]);

            $client = $pool->get();
            $this->assertSame(0, $pool->stats()['idle']);

            $pool->put($client);

            $this->assertSame(1, $pool->stats()['idle']);
            $this->assertSame($client, $pool->get());
            $this->assertSame(1, $created);
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testPutOnFullChannelDropsConnection(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $pool = new $poolClass(fn() => Mockery::mock(ClientInterface::class), [
                'min_connections' => 0,
                'max_connections' => 2,
                'connection_timeout' => 0.01,
            ]);

            $pool->put(Mockery::mock(ClientInterface::class));
            $pool->put(Mockery::mock(ClientInterface::class));
            $pool->put(Mockery::mock(ClientInterface::class)); // channel full: dropped

            $this->assertSame(0, $pool->stats()['active']);
            $this->assertSame(2, $pool->stats()['idle']);
            $this->assertSame(2, $pool->stats()['total']);
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testCloseRejectsPutsAndGetFallsBackToFactory(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), [
                'min_connections' => 1,
                'max_connections' => 8,
                'connection_timeout' => 0.01,
            ]);

            $client = $pool->get(); // drain the channel so it is empty when closed
            $pool->close();

            // closed channel rejects pushes: the put is dropped, active decremented
            $pool->put($client);
            $this->assertSame(0, $pool->stats()['active']);

            // get() still hands out a fresh client via the factory
            $new = $pool->get();
            $this->assertInstanceOf(ClientInterface::class, $new);
            $this->assertNotSame($client, $new);
            $this->assertSame(2, $created);
            $this->assertSame(1, $pool->stats()['active']);
        });
    }

    /**
     * @dataProvider poolProvider
     */
    public function testDefaultConfigPrewarmsAndEnforcesMax(string $poolClass, string $channelClass, int $defaultMax): void
    {
        $this->runWithChannel($channelClass, function () use ($poolClass, $defaultMax) {
            $created = 0;
            $pool = new $poolClass($this->countingFactory($created), ['connection_timeout' => 0.01]);

            $this->assertSame(2, $created); // default min_connections

            $exhausted = false;
            try {
                while (true) {
                    $pool->get();
                }
            } catch (PoolException) {
                $exhausted = true;
            }

            $this->assertTrue($exhausted);
            $this->assertSame($defaultMax, $created); // default max_connections
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
