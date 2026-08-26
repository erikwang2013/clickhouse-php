<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Schema;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Query\Result;
use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Schema\Grammar;
use Mockery;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    public function testCreateTableSql(): void
    {
        $grammar = new Grammar();
        $blueprint = new Blueprint();
        $blueprint->date('date');
        $blueprint->string('level');
        $blueprint->engine('MergeTree')
            ->partitionBy('toYYYYMM(date)')
            ->orderBy(['date', 'level']);

        $sql = $grammar->compileCreate('logs', $blueprint);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `logs`', $sql);
        $this->assertStringContainsString('ENGINE = MergeTree', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(date)', $sql);
        $this->assertStringContainsString('ORDER BY (date, level)', $sql);
    }

    public function testDropTableSql(): void
    {
        $grammar = new Grammar();
        $this->assertSame('DROP TABLE IF EXISTS `logs`', $grammar->compileDrop('logs'));
    }

    public function testAlterSql(): void
    {
        $grammar = new Grammar();
        $blueprint = new Blueprint();
        $blueprint->string('source');
        $blueprint->nullable('description', 'String');

        $sql = $grammar->compileAlterAdd('logs', $blueprint);
        $this->assertStringContainsString('ALTER TABLE `logs`', $sql);
        $this->assertStringContainsString('ADD COLUMN `source` String', $sql);
        $this->assertStringContainsString('ADD COLUMN `description` Nullable(String)', $sql);
    }

    public function testAllColumnTypes(): void
    {
        $blueprint = new Blueprint();
        $blueprint->int32('id');
        $blueprint->string('name');
        $blueprint->float64('score');
        $blueprint->dateTime('created_at');
        $blueprint->nullable('description', 'String');
        $blueprint->bool('active');

        $this->assertCount(6, $blueprint->columns);
        $this->assertSame('Int32', $blueprint->columns[0]->type);
        $this->assertSame('Nullable(String)', $blueprint->columns[4]->type);
        $this->assertSame('Bool', $blueprint->columns[5]->type);
    }

    public function testHasTableFalseWhenCountIsZero(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([['c' => 0]]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $this->assertFalse($builder->hasTable('logs'));
    }

    public function testHasTableTrueWhenCountIsPositive(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([['c' => 1]]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $this->assertTrue($builder->hasTable('logs'));
    }

    public function testHasTableFalseWhenNoRow(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $this->assertFalse($builder->hasTable('logs'));
    }

    public function testHasTableFalseWhenCKeyMissing(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([['name' => 'logs']]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $this->assertFalse($builder->hasTable('logs'));
    }

    public function testHasTableSendsQualifiedExistsQuery(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()
            ->with("SELECT count() AS c FROM system.tables WHERE database = 'analytics' AND name = 'logs'")
            ->andReturn(new Result([['c' => 0]]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $this->assertFalse($builder->hasTable('analytics.logs'));
    }

    public function testCreateExecutesCompiledSql(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $sql = null;
        $client->shouldReceive('query')->once()
            ->with(Mockery::capture($sql))
            ->andReturn(new Result([]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $builder->create('logs', function (Blueprint $blueprint) {
            $blueprint->string('name');
            $blueprint->uint8('level');
        });

        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `logs` (`name` String, `level` UInt8) ENGINE = MergeTree ORDER BY tuple()',
            $sql,
        );
    }

    public function testCreateSkipsQueryWhenBlueprintHasNoColumns(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('query');
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $builder->create('logs', function (Blueprint $blueprint) {
            $blueprint->engine('MergeTree');
        });

        $this->expectNotToPerformAssertions();
    }

    public function testDropExecutesSql(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $sql = null;
        $client->shouldReceive('query')->once()
            ->with(Mockery::capture($sql))
            ->andReturn(new Result([]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);
        $builder->drop('logs');

        $this->assertSame('DROP TABLE IF EXISTS `logs`', $sql);
    }

    public function testAlterExecutesCompiledSql(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $sql = null;
        $client->shouldReceive('query')->once()
            ->with(Mockery::capture($sql))
            ->andReturn(new Result([]));
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $builder->alter('logs', function (Blueprint $blueprint) {
            $blueprint->string('source');
            $blueprint->nullable('description', 'String');
        });

        $this->assertSame(
            'ALTER TABLE `logs` ADD COLUMN `source` String, ADD COLUMN `description` Nullable(String)',
            $sql,
        );
    }

    public function testAlterSkipsQueryWhenBlueprintHasNoColumns(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldNotReceive('query');
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $builder->alter('logs', function (Blueprint $blueprint) {
        });

        $this->expectNotToPerformAssertions();
    }

    public function testGetTablesDelegatesToSelect(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('select')->once()
            ->with('SHOW TABLES FROM `analytics`')
            ->andReturn([['name' => 'logs'], ['name' => 'events']]);
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $this->assertSame([['name' => 'logs'], ['name' => 'events']], $builder->getTables('analytics'));
    }

    public function testGetTablesDefaultsToDefaultDatabase(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('select')->once()
            ->with('SHOW TABLES FROM `default`')
            ->andReturn([]);
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $this->assertSame([], $builder->getTables());
    }

    public function testGetTableInfoDelegatesToSelect(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('select')->once()
            ->with('DESCRIBE TABLE `logs`')
            ->andReturn([['name' => 'id', 'type' => 'UInt64']]);
        $builder = new \Erikwang2013\ClickHouse\Schema\Builder($client);

        $this->assertSame([['name' => 'id', 'type' => 'UInt64']], $builder->getTableInfo('logs'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}