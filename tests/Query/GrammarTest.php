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

class GrammarTest extends TestCase
{
    private function createBuilder(): Builder
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->andReturn(new Result([]));
        return new Builder($client);
    }

    public function testCompileSelectMinimal(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs');
        $this->assertSame('SELECT * FROM `logs`', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWithColumns(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->select('id', 'name');
        $this->assertSame('SELECT id, name FROM `logs`', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWithArrayColumns(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->select(['id', 'name']);
        $this->assertSame('SELECT id, name FROM `logs`', $grammar->compileSelect($builder));
    }

    public function testCompileSelectEmptyFromThrows(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Table name is required.');
        $grammar->compileSelect($builder);
    }

    public function testCompileSelectBasicWhereString(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('level', 'error');
        $this->assertSame("SELECT * FROM `logs` WHERE `level` = 'error'", $grammar->compileSelect($builder));
    }

    public function testCompileSelectBasicWhereScalarTypes(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')
            ->where('age', 42)
            ->where('deleted_at', null)
            ->where('active', true)
            ->where('score', 1.5);
        $this->assertSame(
            'SELECT * FROM `logs` WHERE `age` = 42 AND `deleted_at` = NULL AND `active` = 1 AND `score` = 1.5',
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectBasicWhereEscapesQuotes(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('name', "O'Reilly \\ Jr");
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `name` = 'O\\'Reilly \\\\ Jr'",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectBasicWhereExpression(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('date', '>=', new Expression('today()'));
        $this->assertSame('SELECT * FROM `logs` WHERE `date` >= today()', $grammar->compileSelect($builder));
    }

    public function testCompileSelectMultipleWheresAnd(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('a', 1)->where('b', 2)->where('c', 3);
        $this->assertSame('SELECT * FROM `logs` WHERE `a` = 1 AND `b` = 2 AND `c` = 3', $grammar->compileSelect($builder));
    }

    public function testCompileSelectOrWhere(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('level', 'error')->orWhere('level', 'warn');
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `level` = 'error' OR `level` = 'warn'",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectFirstClauseGetsNoBooleanPrefix(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereRaw('x > 1', 'or')->whereRaw('y > 2', 'and');
        $this->assertSame('SELECT * FROM `logs` WHERE x > 1 AND y > 2', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWhereIn(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereIn('level', ['error', 'warn']);
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `level` IN ('error', 'warn')",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectWhereInWithExpressions(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereIn('date', [new Expression('today()'), '2024-01-01']);
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `date` IN (today(), '2024-01-01')",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectWhereNotIn(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereNotIn('level', ['debug', 'trace']);
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `level` NOT IN ('debug', 'trace')",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectWhereInEmpty(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereIn('level', []);
        $this->assertSame('SELECT * FROM `logs` WHERE 0 = 1', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWhereNotInEmpty(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereNotIn('level', []);
        $this->assertSame('SELECT * FROM `logs` WHERE 1 = 1', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWhereBetween(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereBetween('date', ['2024-01-01', '2024-01-31']);
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `date` BETWEEN '2024-01-01' AND '2024-01-31'",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectWhereBetweenInvalidCountThrows(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereBetween('date', ['2024-01-01']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whereBetween requires exactly two values.');
        $grammar->compileSelect($builder);
    }

    public function testCompileSelectWhereNull(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereNull('deleted_at');
        $this->assertSame('SELECT * FROM `logs` WHERE `deleted_at` IS NULL', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWhereNotNull(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->whereNotNull('deleted_at');
        $this->assertSame('SELECT * FROM `logs` WHERE `deleted_at` IS NOT NULL', $grammar->compileSelect($builder));
    }

    public function testCompileSelectWhereRawMixed(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('status', 'active')->whereRaw('some_column > 0');
        $this->assertSame(
            "SELECT * FROM `logs` WHERE `status` = 'active' AND some_column > 0",
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectAllBasicOperatorsPassThrough(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')
            ->where('a', '!=', 1)
            ->where('b', '<>', 2)
            ->where('c', '<', 3)
            ->where('d', '>', 4)
            ->where('e', '<=', 5)
            ->where('f', '>=', 6)
            ->where('g', 'like', 'x%')
            ->where('h', 'not like', 'x%')
            ->where('i', 'ilike', 'x%')
            ->where('j', 'not ilike', 'x%')
            ->where('k', 'glob', '*x')
            ->where('l', 'not glob', '*x');
        $sql = $grammar->compileSelect($builder);
        $this->assertStringContainsString('`a` != 1', $sql);
        $this->assertStringContainsString('`b` <> 2', $sql);
        $this->assertStringContainsString('`c` < 3', $sql);
        $this->assertStringContainsString('`d` > 4', $sql);
        $this->assertStringContainsString('`e` <= 5', $sql);
        $this->assertStringContainsString('`f` >= 6', $sql);
        $this->assertStringContainsString("`g` like 'x%'", $sql);
        $this->assertStringContainsString("`h` not like 'x%'", $sql);
        $this->assertStringContainsString("`i` ilike 'x%'", $sql);
        $this->assertStringContainsString("`j` not ilike 'x%'", $sql);
        $this->assertStringContainsString("`k` glob '*x'", $sql);
        $this->assertStringContainsString("`l` not glob '*x'", $sql);
    }

    public function testCompileSelectGroupBy(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->groupBy('level');
        $this->assertSame('SELECT * FROM `logs` GROUP BY `level`', $grammar->compileSelect($builder));
    }

    public function testCompileSelectGroupByMultipleColumns(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->groupBy('a', 'b.c');
        $this->assertSame('SELECT * FROM `logs` GROUP BY `a`, `b`.`c`', $grammar->compileSelect($builder));
    }

    public function testCompileSelectOrderBy(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->orderBy('count', 'DESC')->orderBy('name');
        $this->assertSame(
            'SELECT * FROM `logs` ORDER BY `count` DESC, `name` ASC',
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectLimitOnly(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->limit(10);
        $this->assertSame('SELECT * FROM `logs` LIMIT 10', $grammar->compileSelect($builder));
    }

    public function testCompileSelectOffsetOnly(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->offset(20);
        $this->assertSame('SELECT * FROM `logs` OFFSET 20', $grammar->compileSelect($builder));
    }

    public function testCompileSelectLimitAndOffset(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->limit(10)->offset(20);
        $this->assertSame('SELECT * FROM `logs` LIMIT 10 OFFSET 20', $grammar->compileSelect($builder));
    }

    public function testCompileSelectFullClauseOrder(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')
            ->select('id')
            ->where('level', 'error')
            ->groupBy('level')
            ->orderBy('count', 'DESC')
            ->limit(5)
            ->offset(10);
        $this->assertSame(
            'SELECT id FROM `logs` WHERE `level` = \'error\' GROUP BY `level` ORDER BY `count` DESC LIMIT 5 OFFSET 10',
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileSelectQuotesDottedTableAndColumn(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('db.logs')->where('weird`col', 1);
        $this->assertSame(
            'SELECT * FROM `db`.`logs` WHERE `weird\`col` = 1',
            $grammar->compileSelect($builder),
        );
    }

    public function testCompileDeleteWithWhere(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs')->where('level', 'debug');
        $this->assertSame(
            "ALTER TABLE `logs` DELETE WHERE `level` = 'debug'",
            $grammar->compileDelete($builder),
        );
    }

    public function testCompileDeleteWithoutWhere(): void
    {
        $grammar = new Grammar();
        $builder = $this->createBuilder()->table('logs');
        $this->assertSame('ALTER TABLE `logs` DELETE', $grammar->compileDelete($builder));
    }

    public function testQuoteScalarTypes(): void
    {
        $grammar = new Grammar();
        $this->assertSame("'x'", $grammar->quote('x'));
        $this->assertSame('42', $grammar->quote(42));
        $this->assertSame('1.5', $grammar->quote(1.5));
        $this->assertSame('NULL', $grammar->quote(null));
        $this->assertSame('1', $grammar->quote(true));
        $this->assertSame('0', $grammar->quote(false));
    }

    public function testQuoteExpressionAndEscaping(): void
    {
        $grammar = new Grammar();
        $this->assertSame('now()', $grammar->quote(new Expression('now()')));
        $this->assertSame("'O\\'Reilly'", $grammar->quote("O'Reilly"));
        $this->assertSame("'a\\\\b'", $grammar->quote('a\\b'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
