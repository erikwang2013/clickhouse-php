<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Transport;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Transport\TcpTransport;
use Erikwang2013\ClickHouse\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class TcpTransportTest extends TestCase
{
    private function createTransport(): TcpTransport
    {
        return new TcpTransport(new Config([
            'host' => 'localhost',
            'port' => 9000,
            'username' => 'default',
            'password' => '',
            'database' => 'default',
        ]));
    }

    public function testSendThrowsConnectionException(): void
    {
        $transport = $this->createTransport();

        try {
            $transport->send('SELECT 1');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Native TCP transport not yet implemented. Use HTTP driver.', $e->getMessage());
        }
    }

    public function testSendThrowsConnectionExceptionWithBindings(): void
    {
        $transport = $this->createTransport();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Native TCP transport not yet implemented. Use HTTP driver.');
        $transport->send('SELECT * FROM t WHERE id = ?', [1]);
    }

    public function testCloseOnUnopenedTransportIsNoOp(): void
    {
        $transport = $this->createTransport();

        $transport->close();
        $transport->close();

        $ref = new \ReflectionProperty($transport, 'socket');
        $this->assertNull($ref->getValue($transport));
    }

    public function testCloseClosesOpenSocket(): void
    {
        $transport = $this->createTransport();

        $ref = new \ReflectionProperty($transport, 'socket');
        $ref->setValue($transport, fopen('php://memory', 'r'));

        $transport->close();

        $this->assertNull($ref->getValue($transport));
    }

    public function testImplementsTransportInterface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, $this->createTransport());
    }
}
