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

    protected function tearDown(): void
    {
        Mockery::close();
    }
}