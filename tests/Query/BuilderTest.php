<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Query;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Query\Expression;
use Erikwang2013\ClickHouse\Query\Grammar;
use Erikwang2013\ClickHouse\Query\Result;
use PHPUnit\Framework\TestCase;
use Mockery;

class BuilderTest extends TestCase
{
    private function createBuilder(): Builder
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->andReturn(new Result([]));
        return new Builder($client);
    }

    public function testBasicSelectSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'error')->limit(10);
        $sql = $builder->toSql();
        $this->assertStringContainsString('SELECT * FROM `logs`', $sql);
        $this->assertStringContainsString("WHERE level = 'error'", $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testWhereInSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereIn('level', ['error', 'warn']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("level IN ('error', 'warn')", $sql);
    }

    public function testWhereBetweenSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereBetween('date', ['2024-01-01', '2024-01-31']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("WHERE date BETWEEN '2024-01-01' AND '2024-01-31'", $sql);
    }

    public function testWhereNullSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNull('deleted_at');
        $sql = $builder->toSql();
        $this->assertStringContainsString('WHERE deleted_at IS NULL', $sql);
    }

    public function testOrderByAndGroupBy(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->groupBy('level')->orderBy('count', 'DESC');
        $sql = $builder->toSql();
        $this->assertStringContainsString('GROUP BY level', $sql);
        $this->assertStringContainsString('ORDER BY count DESC', $sql);
    }

    public function testInsertSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs');
        $sql = (new \Erikwang2013\ClickHouse\Query\Grammar())->compileInsert($builder, [
            ['name' => 'test', 'value' => 42],
        ]);
        $this->assertStringContainsString('INSERT INTO `logs`', $sql);
        $this->assertStringContainsString("'test'", $sql);
        $this->assertStringContainsString('42', $sql);
    }

    public function testWhereRawWithAndCombination(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('status', 'active')->whereRaw('some_column > 0');
        $sql = $builder->toSql();
        $this->assertStringContainsString("WHERE status = 'active' AND some_column > 0", $sql);
    }

    public function testExpressionNotQuoted(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('date', '>=', new Expression('today()'));
        $sql = $builder->toSql();
        $this->assertStringContainsString('WHERE date >= today()', $sql);
    }

    public function testCountDoesNotMutateColumns(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->select('id', 'name');
        $builder->count();
        $sql = $builder->toSql();
        $this->assertStringContainsString('SELECT id, name FROM', $sql);
    }

    public function testFirstDoesNotMutateLimit(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs');
        $builder->first();
        $sql = $builder->toSql();
        $this->assertStringNotContainsString('LIMIT 1', $sql);
    }

    public function testWhereNotNullSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNotNull('deleted_at');
        $sql = $builder->toSql();
        $this->assertStringContainsString('WHERE deleted_at IS NOT NULL', $sql);
    }

    public function testOrWhereSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'error')->orWhere('level', 'warn');
        $sql = $builder->toSql();
        $this->assertStringContainsString("level = 'error' OR level = 'warn'", $sql);
    }

    public function testWhereNotInSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNotIn('level', ['debug', 'trace']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("level NOT IN ('debug', 'trace')", $sql);
    }

    public function testOffsetSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->limit(10)->offset(20);
        $sql = $builder->toSql();
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testDeleteSql(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'debug');
        $sql = $grammar->compileDelete($builder);
        $this->assertStringContainsString('ALTER TABLE `logs` DELETE', $sql);
        $this->assertStringContainsString("WHERE level = 'debug'", $sql);
    }

    public function testEmptyFromThrows(): void
    {
        $builder = $this->createBuilder();
        $this->expectException(QueryException::class);
        $builder->toSql();
    }

    public function testBetweenValidationThrows(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereBetween('date', ['2024-01-01']);
        $this->expectException(\InvalidArgumentException::class);
        $builder->toSql();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}