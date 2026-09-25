<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\Query\Builder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reads against a real server: every operator the grammar emits has to be
 * something ClickHouse actually accepts, and has to filter the way the
 * grammar's author intended.
 */
#[Group('integration')]
class QueryTest extends IntegrationTestCase
{
    private string $tableName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tableName = $this->table('it_query');
        $this->makeTable($this->tableName);
        $this->seedTable($this->tableName);
    }

    /**
     * A fresh builder per query: Builder accumulates where()/orderBy() state, so
     * reusing one instance across assertions would AND the clauses together.
     */
    private function query(): Builder
    {
        return (new Builder(static::$client))->table($this->tableName);
    }

    public function testSelectAllReturnsEveryRowAndColumn(): void
    {
        $result = $this->query()->orderBy('id')->get();

        $this->assertCount(5, $result);
        $this->assertSame(5, $result->count());

        $row = $result->first();
        $this->assertSame('alice', $row['name']);
        $this->assertEquals(1, $row['id']);
    }

    public function testSelectSpecificColumns(): void
    {
        $rows = $this->query()->select(['id', 'name'])->orderBy('id')->get()->toArray();

        $this->assertSame(['id', 'name'], array_keys($rows[0]));
        $this->assertSame('bob', $rows[1]['name']);
    }

    public function testWhereEqualityAndInequality(): void
    {
        $this->assertSame(['alice'], $this->nameColumn($this->query()->where('name', 'alice')));
        $this->assertSame(4, $this->query()->where('id', '!=', 1)->count());
        $this->assertSame(4, $this->query()->where('id', '<>', 1)->count());
    }

    public function testWhereComparisonOperators(): void
    {
        $this->assertSame(2, $this->query()->where('id', '>', 3)->count());
        $this->assertSame(2, $this->query()->where('id', '<', 3)->count());
        $this->assertSame(3, $this->query()->where('id', '>=', 3)->count());
        $this->assertSame(3, $this->query()->where('id', '<=', 3)->count());
        $this->assertSame(1, $this->query()->where('id', '=', 2)->count());
    }

    public function testWhereTwoArgumentShorthandMeansEquals(): void
    {
        $this->assertSame(1, $this->query()->where('name', 'carol')->count());
    }

    public function testWhereLike(): void
    {
        $this->assertSame(['alice'], $this->nameColumn($this->query()->where('name', 'like', 'a%')));
        $this->assertSame([], $this->nameColumn($this->query()->where('name', 'like', 'zzz%')));
        $this->assertSame(['bob', 'carol'], $this->nameColumn($this->query()->where('name', 'not like', '%e%')));
    }

    public function testWhereInAndNotIn(): void
    {
        $this->assertSame(
            ['alice', 'carol'],
            $this->nameColumn($this->query()->whereIn('id', [1, 3])),
        );
        $this->assertSame(
            ['bob', 'carol'],
            $this->nameColumn($this->query()->whereNotIn('id', [1, 4, 5])),
        );
        $this->assertSame(3, $this->query()->where('id', 'in', [1, 2, 3])->count());
    }

    public function testWhereInWithEmptyListMatchesNothing(): void
    {
        $this->assertSame(0, $this->query()->whereIn('id', [])->count());
        $this->assertSame(5, $this->query()->whereNotIn('id', [])->count());
    }

    public function testWhereBetween(): void
    {
        $this->assertSame(
            ['bob', 'carol'],
            $this->nameColumn($this->query()->whereBetween('score', [20.5, 30.5])),
        );
        $this->assertSame(
            ['alice'],
            $this->nameColumn($this->query()->where('id', 'not between', [2, 5])),
        );
    }

    public function testWhereNull(): void
    {
        static::$client->insert($this->tableName, [
            'id' => 6, 'name' => 'frank', 'score' => 60.5, 'active' => 1, 'note' => 'set',
        ]);

        $this->assertSame(5, $this->query()->whereNull('note')->count());
        $this->assertSame(['frank'], $this->nameColumn($this->query()->whereNotNull('note')));
    }

    public function testWhereRaw(): void
    {
        $this->assertSame(2, $this->query()->whereRaw('id > 3')->count());
    }

    public function testOrWhereWidensTheResultSet(): void
    {
        $this->assertSame(
            ['alice', 'bob'],
            $this->nameColumn($this->query()->where('name', 'alice')->orWhere('name', 'bob')),
        );
    }

    public function testOrderByLimitOffset(): void
    {
        $rows = $this->query()->select(['id'])->orderBy('id', 'DESC')->limit(2)->offset(1)->get()->toArray();

        $this->assertSame([4, 3], array_map(fn($row) => (int) $row['id'], $rows));
    }

    public function testFirstReturnsOneRow(): void
    {
        $row = $this->query()->orderBy('id')->first();

        $this->assertSame('alice', $row['name']);
    }

    public function testAggregates(): void
    {
        $this->assertSame(5, $this->query()->count());
        $this->assertEquals(152.5, $this->query()->sum('score'));
        $this->assertEquals(30.5, $this->query()->avg('score'));
        $this->assertEquals(10.5, $this->query()->min('score'));
        $this->assertEquals(50.5, $this->query()->max('score'));
    }

    public function testAggregateHonoursWhere(): void
    {
        $this->assertSame(3, $this->query()->where('active', 1)->count());
        $this->assertEquals(91.5, $this->query()->where('active', 1)->sum('score'));
    }

    public function testGroupByWithAggregate(): void
    {
        $rows = $this->query()
            ->select(['active'])
            ->selectRaw('count() as c')
            ->groupBy('active')
            ->orderBy('active')
            ->get()
            ->toArray();

        $this->assertSame(
            [['active' => 0, 'c' => 2], ['active' => 1, 'c' => 3]],
            array_map(fn($row) => ['active' => (int) $row['active'], 'c' => (int) $row['c']], $rows),
        );
    }

    public function testSelectRawExpression(): void
    {
        $row = $this->query()->select(['id'])->selectRaw('score * 2 as doubled')->orderBy('id')->first();

        $this->assertEquals(21.0, $row['doubled']);
    }

    public function testBindingsAreEscapedByTheTransport(): void
    {
        $rows = static::$client->query(
            'SELECT name FROM `' . $this->tableName . "` WHERE name = ? AND id > ? ORDER BY id",
            ['alice', 0],
        )->toArray();

        $this->assertSame([['name' => 'alice']], $rows);
    }

    /**
     * @return array<int, string>
     */
    private function nameColumn(Builder $query): array
    {
        return $query->select(['name'])->orderBy('id')->get()->column('name');
    }
}
