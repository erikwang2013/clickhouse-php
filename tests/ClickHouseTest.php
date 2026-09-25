<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Query\Result;
use Erikwang2013\ClickHouse\Schema\Builder as SchemaBuilder;
use PHPUnit\Framework\TestCase;
use Mockery;

class ClickHouseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetManager();
    }

    protected function tearDown(): void
    {
        $this->resetManager();
        Mockery::close();
    }

    private function resetManager(): void
    {
        $ref = new \ReflectionProperty(ClickHouse::class, 'manager');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function mockManager(): Manager
    {
        return Mockery::mock(Manager::class);
    }

    public function testConnectionThrowsWhenManagerNotInitialized(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('setManager');
        ClickHouse::connection();
    }

    public function testSchemaThrowsWhenManagerNotInitialized(): void
    {
        $this->expectException(ConnectionException::class);
        ClickHouse::schema();
    }

    public function testQueryThrowsWhenManagerNotInitialized(): void
    {
        $this->expectException(ConnectionException::class);
        ClickHouse::query('SELECT 1');
    }

    public function testGetManagerReturnsNullByDefault(): void
    {
        $this->assertNull(ClickHouse::getManager());
    }

    public function testSetAndGetManager(): void
    {
        $manager = $this->mockManager();
        ClickHouse::setManager($manager);
        $this->assertSame($manager, ClickHouse::getManager());
    }

    public function testBootstrapBuildsManagerFromEnv(): void
    {
        $manager = ClickHouse::bootstrap();

        $this->assertInstanceOf(Manager::class, $manager);
        $this->assertSame($manager, ClickHouse::getManager());
        $this->assertInstanceOf(Builder::class, ClickHouse::table('logs'));
    }

    public function testConnectionReturnsBuilder(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($client);
        ClickHouse::setManager($manager);

        $this->assertInstanceOf(Builder::class, ClickHouse::connection());
    }

    public function testConnectionPassesNameToManager(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->with('replica')->andReturn($client);
        ClickHouse::setManager($manager);

        $this->assertInstanceOf(Builder::class, ClickHouse::connection('replica'));
    }

    public function testTableReturnsBuilderForTable(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($client);
        ClickHouse::setManager($manager);

        $builder = ClickHouse::table('users');
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertSame('users', $builder->from);
    }

    public function testTableUsesNamedConnection(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->with('analytics')->andReturn($client);
        ClickHouse::setManager($manager);

        $builder = ClickHouse::table('events', 'analytics');
        $this->assertSame('events', $builder->from);
    }

    public function testSchemaReturnsSchemaBuilder(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->andReturn($client);
        ClickHouse::setManager($manager);

        $this->assertInstanceOf(SchemaBuilder::class, ClickHouse::schema());
    }

    public function testQueryDelegatesToClient(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->with('SELECT {x}', ['x' => 1])->andReturn(new Result([['v' => 7]]));
        $manager = $this->mockManager();
        $manager->shouldReceive('connection')->once()->andReturn($client);
        ClickHouse::setManager($manager);

        $result = ClickHouse::query('SELECT {x}', ['x' => 1]);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(['v' => 7], $result->first());
    }
}
