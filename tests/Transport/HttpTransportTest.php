<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Transport;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Transport\HttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    private function makeConfig(array $overrides = []): Config
    {
        return new Config(array_merge([
            'host' => 'localhost',
            'port' => 8123,
            'username' => 'default',
            'password' => '',
            'database' => 'default',
        ], $overrides));
    }

    private function makeTransport(array $overrides = []): HttpTransport
    {
        return new HttpTransport($this->makeConfig($overrides));
    }

    private function injectClient(HttpTransport $transport, Client $client): void
    {
        $ref = new \ReflectionProperty($transport, 'httpClient');
        $ref->setValue($transport, $client);
    }

    public function testSendReturnsDataOnSuccess(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->with('', ['body' => 'SELECT 1 FORMAT JSON'])
            ->andReturn(new Response(200, [], '{"data":[{"id":1}]}'));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        $this->assertSame([['id' => 1]], $transport->send('SELECT 1'));
    }

    public function testSendReturnsFullDecodedBodyWhenNoDataKey(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->andReturn(new Response(200, [], '{"statistics":{"elapsed":0.1}}'));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        $this->assertSame(['statistics' => ['elapsed' => 0.1]], $transport->send('SELECT 1'));
    }

    public function testSendReturnsEmptyArrayForEmptyData(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->andReturn(new Response(200, [], '{"data":[]}'));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        $this->assertSame([], $transport->send('SELECT 1'));
    }

    public function testSendBindsParamsBeforePosting(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->with('', ['body' => "SELECT * FROM t WHERE id = 1 AND name = 'test' FORMAT JSON"])
            ->andReturn(new Response(200, [], '{"data":[]}'));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        $this->assertSame([], $transport->send('SELECT * FROM t WHERE id = ? AND name = ?', [1, 'test']));
    }

    public function testSendErrorStatusThrowsQueryException(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->andReturn(new Response(500, [], 'Server error'));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        try {
            $transport->send('SELECT 1', [1]);
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('ClickHouse query error [500]: Server error', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertSame('SELECT 1', $e->getSql());
            $this->assertSame([1], $e->getBindings());
        }
    }

    public function testSendErrorStatusTruncatesLongBody(): void
    {
        $body = str_repeat('x', 600);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->andReturn(new Response(400, [], $body));

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        try {
            $transport->send('SELECT 1');
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('ClickHouse query error [400]: ' . str_repeat('x', 500), $e->getMessage());
            $this->assertStringEndsWith('... (truncated)', $e->getMessage());
        }
    }

    public function testSendConnectExceptionThrowsConnectionException(): void
    {
        $connectException = new ConnectException('Connection refused', new Request('POST', 'http://localhost:8123/'));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()
            ->andThrow($connectException);

        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        try {
            $transport->send('SELECT 1');
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('ClickHouse connection failed: unable to connect to server.', $e->getMessage());
            $this->assertSame($connectException, $e->getPrevious());
        }
    }

    public function testCloseResetsHttpClient(): void
    {
        $client = Mockery::mock(Client::class);
        $transport = $this->makeTransport();
        $this->injectClient($transport, $client);

        $transport->close();

        $ref = new \ReflectionProperty($transport, 'httpClient');
        $this->assertNull($ref->getValue($transport));
    }

    public function testCreateClientBuildsDefaultConfiguration(): void
    {
        $transport = $this->makeTransport();

        $ref = new \ReflectionMethod($transport, 'createClient');
        /** @var Client $client */
        $client = $ref->invoke($transport);

        $this->assertSame('http://localhost:8123/', (string) $client->getConfig('base_uri'));
        $this->assertSame('default', $client->getConfig('headers')['X-ClickHouse-User']);
        $this->assertSame('', $client->getConfig('headers')['X-ClickHouse-Key']);
        $this->assertSame('default', $client->getConfig('headers')['X-ClickHouse-Database']);
        $this->assertSame('text/plain', $client->getConfig('headers')['Content-Type']);
        $this->assertSame(30, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('http_errors'));
    }

    public function testCreateClientUsesHttpsAndCustomConfig(): void
    {
        $transport = $this->makeTransport([
            'https' => true,
            'host' => 'ch.example.com',
            'port' => 8443,
            'username' => 'alice',
            'password' => 'secret',
            'database' => 'analytics',
            'timeout' => 5,
        ]);

        $ref = new \ReflectionMethod($transport, 'createClient');
        /** @var Client $client */
        $client = $ref->invoke($transport);

        $this->assertSame('https://ch.example.com:8443/', (string) $client->getConfig('base_uri'));
        $this->assertSame('alice', $client->getConfig('headers')['X-ClickHouse-User']);
        $this->assertSame('secret', $client->getConfig('headers')['X-ClickHouse-Key']);
        $this->assertSame('analytics', $client->getConfig('headers')['X-ClickHouse-Database']);
        $this->assertSame(5, $client->getConfig('timeout'));
    }

    public function testBindParamsReplacesPlaceholders(): void
    {
        $config = new Config([
            'host' => 'localhost',
            'port' => 8123,
            'username' => 'default',
            'password' => '',
            'database' => 'default',
        ]);

        $transport = new HttpTransport($config);

        $ref = new \ReflectionMethod($transport, 'bindParams');
        $result = $ref->invoke($transport, 'SELECT * FROM t WHERE id = ? AND name = ?', [1, 'test']);

        $this->assertSame("SELECT * FROM t WHERE id = 1 AND name = 'test'", $result);
    }

    public function testBindParamsQuotesNull(): void
    {
        $config = new Config([
            'host' => 'localhost', 'port' => 8123,
            'username' => 'default', 'password' => '', 'database' => 'default',
        ]);
        $transport = new HttpTransport($config);

        $ref = new \ReflectionMethod($transport, 'bindParams');
        $result = $ref->invoke($transport, 'SELECT * FROM t WHERE col = ?', [null]);

        $this->assertSame('SELECT * FROM t WHERE col = NULL', $result);
    }

    public function testBindParamsSkipsQuestionMarkInsideStringLiteral(): void
    {
        $config = new Config([
            'host' => 'localhost', 'port' => 8123,
            'username' => 'default', 'password' => '', 'database' => 'default',
        ]);
        $transport = new HttpTransport($config);

        $ref = new \ReflectionMethod($transport, 'bindParams');
        $result = $ref->invoke($transport, "SELECT * FROM t WHERE note = 'what?' AND id = ?", [5]);

        $this->assertSame("SELECT * FROM t WHERE note = 'what?' AND id = 5", $result);
    }

    public function testBindParamsMissingBindingThrows(): void
    {
        $config = new Config([
            'host' => 'localhost', 'port' => 8123,
            'username' => 'default', 'password' => '', 'database' => 'default',
        ]);
        $transport = new HttpTransport($config);

        $ref = new \ReflectionMethod($transport, 'bindParams');
        $this->expectException(\Erikwang2013\ClickHouse\Exceptions\QueryException::class);
        $ref->invoke($transport, 'SELECT * FROM t WHERE a = ? AND b = ?', [1]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
