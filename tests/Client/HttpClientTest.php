<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Client;

use Erikwang2013\ClickHouse\Client\HttpClient;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use Mockery;

class HttpClientTest extends TestCase
{
    public function testSelectReturnsArray(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')
            ->with('SELECT * FROM test', [])
            ->andReturn(['rows' => [['id' => 1]]]);

        $config = new Config(['database' => 'default']);
        $client = new HttpClient($transport, $config);

        $result = $client->select('SELECT * FROM test');
        $this->assertSame([['id' => 1]], $result);
    }

    public function testInsertGeneratesSql(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andReturn(['rows' => []]);

        $config = new Config(['database' => 'default']);
        $client = new HttpClient($transport, $config);

        $count = $client->insert('test', ['name' => 'foo', 'value' => 42]);
        $this->assertSame(1, $count);
    }

    public function testInsertBatchReturnsCount(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andReturn(['rows' => []]);

        $config = new Config(['database' => 'default']);
        $client = new HttpClient($transport, $config);

        $rows = [
            ['name' => 'a', 'value' => 1],
            ['name' => 'b', 'value' => 2],
        ];
        $count = $client->insert('test', $rows);
        $this->assertSame(2, $count);
    }

    public function testPingReturnsTrueOnSuccess(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->with('SELECT 1', [])->andReturn(['rows' => [[1]]]);

        $config = new Config(['database' => 'default']);
        $client = new HttpClient($transport, $config);

        $this->assertTrue($client->ping());
    }

    public function testInsertOrdersValuesByFirstRowColumnsNotRowOrder(): void
    {
        $captured = null;
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andReturnUsing(function ($sql) use (&$captured) {
            $captured = $sql;
            return ['rows' => []];
        });

        $client = new HttpClient($transport, new Config([]));
        $client->insert('t', [
            ['a' => 1, 'b' => 2],
            ['b' => 3, 'a' => 4],
        ]);

        // 第二行 a/b 顺序不同，取值必须按列顺序（a=4, b=3），不能按位置硬塞
        $this->assertSame('INSERT INTO `t` (`a`, `b`) VALUES (1, 2), (4, 3)', $captured);
    }

    public function testInsertRejectsRaggedRows(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->never();

        $client = new HttpClient($transport, new Config([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing column');
        $client->insert('t', [
            ['a' => 1, 'b' => 2],
            ['a' => 3],
        ]);
    }

    public function testInsertRejectsRowWithUnknownColumn(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->never();

        $client = new HttpClient($transport, new Config([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unexpected column');
        $client->insert('t', [
            ['a' => 1],
            ['a' => 2, 'zzz' => 9],
        ]);
    }

    public function testQueryThrowsWhenBodyIsNotJson(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andReturn("1\n2\n");

        $client = new HttpClient($transport, new Config([]));

        // 静默返回空集会让调用方以为没数据，必须显式报错并指向 raw()
        $this->expectException(\Erikwang2013\ClickHouse\Exceptions\QueryException::class);
        $this->expectExceptionMessage('non-JSON');
        $client->query('SELECT 1 FORMAT CSV');
    }

    public function testStreamDelegatesToStreamingTransport(): void
    {
        $transport = new class implements TransportInterface, \Erikwang2013\ClickHouse\Transport\StreamingTransportInterface {
            public function send(string $sql, array $bindings = []): mixed
            {
                return [];
            }

            public function close(): void
            {
            }

            public function sendStream(string $sql, array $bindings = []): \Generator
            {
                yield ['id' => 1];
                yield ['id' => 2];
            }

            public function sendRaw(string $sql, array $bindings = []): string
            {
                return "raw body: $sql";
            }
        };

        $client = new HttpClient($transport, new Config([]));

        $rows = [];
        foreach ($client->stream('SELECT * FROM t') as $row) {
            $rows[] = $row;
        }

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
        $this->assertSame('raw body: SELECT 1 FORMAT CSV', $client->raw('SELECT 1 FORMAT CSV'));
    }

    public function testStreamThrowsWhenTransportCannotStream(): void
    {
        $transport = Mockery::mock(TransportInterface::class);
        $client = new HttpClient($transport, new Config([]));

        $this->expectException(\Erikwang2013\ClickHouse\Exceptions\QueryException::class);
        $this->expectExceptionMessage('does not support streaming');

        foreach ($client->stream('SELECT 1') as $row) {
            break;
        }
    }

    public function testLoggerReceivesTruncatedSqlForHugeInserts(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $messages = [];

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->andReturn(['rows' => []]);

        $client = new HttpClient($transport, new Config([]), $logger);
        $client->insert('t', array_fill(0, 1000, ['a' => str_repeat('x', 200)]));

        $this->assertCount(1, $logger->messages);
        $this->assertLessThan(2100, strlen($logger->messages[0]));
        $this->assertStringContainsString('bytes total', $logger->messages[0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}