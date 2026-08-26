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
        $this->assertStringContainsString("WHERE `level` = 'error'", $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testWhereInSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereIn('level', ['error', 'warn']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("`level` IN ('error', 'warn')", $sql);
    }

    public function testWhereBetweenSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereBetween('date', ['2024-01-01', '2024-01-31']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("WHERE `date` BETWEEN '2024-01-01' AND '2024-01-31'", $sql);
    }

    public function testWhereNullSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNull('deleted_at');
        $sql = $builder->toSql();
        $this->assertStringContainsString('WHERE `deleted_at` IS NULL', $sql);
    }

    public function testOrderByAndGroupBy(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->groupBy('level')->orderBy('count', 'DESC');
        $sql = $builder->toSql();
        $this->assertStringContainsString('GROUP BY `level`', $sql);
        $this->assertStringContainsString('ORDER BY `count` DESC', $sql);
    }

    public function testInsertDelegatesToClient(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('insert')->once()->with('logs', [['name' => 'test', 'value' => 42]])->andReturn(1);
        $builder = new Builder($client);
        $builder->table('logs');
        $this->assertSame(1, $builder->insert([['name' => 'test', 'value' => 42]]));
    }

    public function testWhereRawWithAndCombination(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('status', 'active')->whereRaw('some_column > 0');
        $sql = $builder->toSql();
        $this->assertStringContainsString("WHERE `status` = 'active' AND some_column > 0", $sql);
    }

    public function testExpressionNotQuoted(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('date', '>=', new Expression('today()'));
        $sql = $builder->toSql();
        $this->assertStringContainsString('WHERE `date` >= today()', $sql);
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
        $this->assertStringContainsString('WHERE `deleted_at` IS NOT NULL', $sql);
    }

    public function testOrWhereSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'error')->orWhere('level', 'warn');
        $sql = $builder->toSql();
        $this->assertStringContainsString("`level` = 'error' OR `level` = 'warn'", $sql);
    }

    public function testWhereNotInSql(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNotIn('level', ['debug', 'trace']);
        $sql = $builder->toSql();
        $this->assertStringContainsString("`level` NOT IN ('debug', 'trace')", $sql);
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
        $this->assertStringContainsString("WHERE `level` = 'debug'", $sql);
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

    public function testWhereInEmptyGeneratesFalseCondition(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereIn('level', []);
        $sql = $builder->toSql();
        $this->assertStringContainsString('0 = 1', $sql);
        $this->assertStringNotContainsString('IN ()', $sql);
    }

    public function testWhereNotInEmptyGeneratesTrueCondition(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->whereNotIn('level', []);
        $sql = $builder->toSql();
        $this->assertStringContainsString('1 = 1', $sql);
    }

    public function testWhereWithInOperatorCompilesAsInClause(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'in', ['error', 'warn']);
        $this->assertStringContainsString("`level` IN ('error', 'warn')", $builder->toSql());
    }

    public function testWhereWithNotBetweenOperatorCompilesAsNotBetween(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('date', 'not between', ['2024-01-01', '2024-01-31']);
        $this->assertStringContainsString("`date` NOT BETWEEN '2024-01-01' AND '2024-01-31'", $builder->toSql());
    }

    public function testInvalidOperatorThrows(): void
    {
        $builder = $this->createBuilder();
        $this->expectException(\InvalidArgumentException::class);
        $builder->where('level', 'INJECT_ME', 'x');
    }

    public function testFirstRestoresLimitOnError(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andThrow(new QueryException('boom', 'SELECT 1'));
        $builder = new Builder($client);
        $builder->table('logs');

        try {
            $builder->first();
            $this->fail('expected QueryException');
        } catch (QueryException) {
        }

        $this->assertStringNotContainsString('LIMIT 1', $builder->toSql());
    }

    public function testFromAlias(): void
    {
        $builder = $this->createBuilder();
        $builder->from('logs');
        $this->assertStringContainsString('FROM `logs`', $builder->toSql());
    }

    public function testSelectArrayAndMultipleArgs(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->select(['id', 'name']);
        $this->assertStringContainsString('SELECT id, name FROM', $builder->toSql());

        $builder2 = $this->createBuilder();
        $builder2->table('logs')->select('id', 'name');
        $this->assertStringContainsString('SELECT id, name FROM', $builder2->toSql());
    }

    public function testSelectRawAppendsColumn(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->select('id')->selectRaw('COUNT(*) as total');
        $this->assertStringContainsString('SELECT id, COUNT(*) as total FROM', $builder->toSql());
    }

    public function testWhereLikeOperators(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('name', 'like', 'a%')->where('tag', 'not like', 'x%')->where('code', 'ilike', 'b%')->where('path', 'glob', '*c');
        $sql = $builder->toSql();
        $this->assertStringContainsString("`name` like 'a%'", $sql);
        $this->assertStringContainsString("`tag` not like 'x%'", $sql);
        $this->assertStringContainsString("`code` ilike 'b%'", $sql);
        $this->assertStringContainsString("`path` glob '*c'", $sql);
    }

    public function testOrWhereTwoArgs(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->where('level', 'error')->orWhere('level', 'warn');
        $sql = $builder->toSql();
        $this->assertStringContainsString("`level` = 'error' OR `level` = 'warn'", $sql);
    }

    public function testOrderByInvalidDirectionThrows(): void
    {
        $builder = $this->createBuilder();
        $this->expectException(\InvalidArgumentException::class);
        $builder->orderBy('count', 'SIDEWAYS');
    }

    public function testGroupByMultipleArguments(): void
    {
        $builder = $this->createBuilder();
        $builder->table('logs')->groupBy('level', 'status');
        $sql = $builder->toSql();
        $this->assertStringContainsString('GROUP BY `level`, `status`', $sql);
    }

    public function testGetReturnsResultAndPassesSql(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->with('SELECT * FROM `logs`')->andReturn(new Result([['id' => 1]]));
        $builder = new Builder($client);
        $result = $builder->table('logs')->get();
        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(['id' => 1], $result->first());
    }

    public function testFirstReturnsNullOnEmptyResult(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([]));
        $builder = new Builder($client);
        $this->assertNull($builder->table('logs')->first());
    }

    public function testSumAvgMinMaxAggregates(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->andReturn(new Result([['aggregate' => 5]]));
        $builder = new Builder($client);
        $builder->table('logs');
        $this->assertSame(5.0, $builder->sum('price'));
        $this->assertSame(5.0, $builder->avg('price'));
        $this->assertSame(5, $builder->min('price'));
        $this->assertSame(5, $builder->max('price'));
    }

    public function testCountReturnsZeroOnEmptyResult(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andReturn(new Result([]));
        $builder = new Builder($client);
        $this->assertSame(0, $builder->table('logs')->count());
    }

    public function testAggregatesReturnNullOnEmptyResult(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->andReturn(new Result([]));
        $builder = new Builder($client);
        $builder->table('logs');
        $this->assertNull($builder->min('price'));
        $this->assertNull($builder->max('price'));
        $this->assertSame(0.0, $builder->sum('price'));
    }

    public function testAggregateRestoresColumnsOnError(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->andThrow(new QueryException('boom', 'SELECT 1'));
        $builder = new Builder($client);
        $builder->table('logs')->select('id', 'name');

        try {
            $builder->count();
            $this->fail('expected QueryException');
        } catch (QueryException) {
        }

        $this->assertStringContainsString('SELECT id, name FROM', $builder->toSql());
    }

    public function testDeleteMethodCallsClientAndReturnsCount(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->once()->with('ALTER TABLE `logs` DELETE WHERE `level` = \'debug\'')->andReturn(new Result([], 3));
        $builder = new Builder($client);
        $builder->table('logs')->where('level', 'debug');
        $this->assertSame(3, $builder->delete());
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}