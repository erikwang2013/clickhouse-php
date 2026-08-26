<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Exceptions;

use Erikwang2013\ClickHouse\Exceptions\ClickHouseException;
use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Exceptions\PoolException;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Exceptions\TimeoutException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testClickHouseExceptionIsRuntimeException(): void
    {
        $previous = new \RuntimeException('root cause');
        $exception = new ClickHouseException('boom', 42, $previous);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('boom', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testClickHouseExceptionDefaults(): void
    {
        $exception = new ClickHouseException('boom');
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testQueryExceptionStoresSqlAndBindings(): void
    {
        $exception = new QueryException('syntax error', 'SELECT * FROM t', ['x' => 1], 10);
        $this->assertInstanceOf(ClickHouseException::class, $exception);
        $this->assertSame('syntax error', $exception->getMessage());
        $this->assertSame('SELECT * FROM t', $exception->getSql());
        $this->assertSame(['x' => 1], $exception->getBindings());
        $this->assertSame(10, $exception->getCode());
    }

    public function testQueryExceptionDefaultsAndChainsPrevious(): void
    {
        $previous = new \RuntimeException('root');
        $exception = new QueryException('boom', 'SELECT 1', previous: $previous);
        $this->assertSame([], $exception->getBindings());
        $this->assertSame('SELECT 1', $exception->getSql());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(0, $exception->getCode());
    }

    public function testQueryExceptionNamedArguments(): void
    {
        $exception = new QueryException(message: 'boom', sql: 'SELECT 1', code: 7);
        $this->assertSame(7, $exception->getCode());
        $this->assertSame([], $exception->getBindings());
        $this->assertNull($exception->getPrevious());
    }

    public function testPoolExceptionIsClickHouseException(): void
    {
        $exception = new PoolException('pool exhausted');
        $this->assertInstanceOf(ClickHouseException::class, $exception);
        $this->assertSame('pool exhausted', $exception->getMessage());
    }

    public function testConnectionExceptionIsClickHouseException(): void
    {
        $exception = new ConnectionException('cannot connect');
        $this->assertInstanceOf(ClickHouseException::class, $exception);
        $this->assertSame('cannot connect', $exception->getMessage());
    }

    public function testTimeoutExceptionIsConnectionException(): void
    {
        $exception = new TimeoutException('timed out', 5);
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertInstanceOf(ClickHouseException::class, $exception);
        $this->assertSame('timed out', $exception->getMessage());
        $this->assertSame(5, $exception->getCode());
    }
}
