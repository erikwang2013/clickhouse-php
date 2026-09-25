<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Support\Quoter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Writes are the regression hotspot: the transport used to append
 * " FORMAT JSON" to every statement, which the server tolerates on SELECT,
 * CREATE and ALTER but rejects on INSERT ("Cannot parse input: expected '('
 * before: 'FORMAT JSON'"). Unit tests with a stub transport never noticed.
 *
 * Every test here asserts both the returned row count and the values as the
 * server read them back.
 */
#[Group('integration')]
class InsertTest extends IntegrationTestCase
{
    public function testSingleRowInsertReturnsOneAndStoresValues(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $inserted = static::$client->insert($table, [
            'id' => 1,
            'name' => 'alice',
            'score' => 1.5,
            'active' => true,
        ]);

        $this->assertSame(1, $inserted);

        $row = $this->fetchById($table, 1);

        $this->assertSame('alice', $row['name']);
        $this->assertEquals(1.5, $row['score']);
        $this->assertEquals(1, $row['active']);
        $this->assertNull($row['note']);
    }

    public function testBatchInsertStoresEveryRow(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $inserted = static::$client->insert($table, [
            ['id' => 1, 'name' => 'alice', 'score' => 1.5, 'active' => 1],
            ['id' => 2, 'name' => 'bob', 'score' => 2.5, 'active' => 0],
            ['id' => 3, 'name' => 'carol', 'score' => 3.5, 'active' => 1],
        ]);

        $this->assertSame(3, $inserted);
        $this->assertSame(
            ['alice', 'bob', 'carol'],
            $this->names($table),
        );
        $this->assertEquals(7.5, $this->sumScore($table));
    }

    /**
     * More than 1000 rows crosses HttpClient::insert()'s chunk boundary.
     */
    public function testBatchInsertBatchesBeyondOneChunk(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $rows = [];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = ['id' => $i, 'name' => 'user' . $i, 'score' => 1.0, 'active' => 1];
        }

        $this->assertSame(1001, static::$client->insert($table, $rows));
        $this->assertEquals(1001, $this->rowCount($table));
        $this->assertEquals(1001, $this->scalar($table, 'max(id)'));
        $this->assertSame('user1001', $this->fetchById($table, 1001)['name']);
    }

    public function testInsertEscapesQuotesBackslashesAndNewlines(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $names = [
            "O'Brien",
            'back\\slash',
            "quote ' and \\ both",
            "line\nbreak",
        ];

        $rows = [];
        foreach ($names as $i => $name) {
            $rows[] = ['id' => $i + 1, 'name' => $name, 'score' => 0.0, 'active' => 0];
        }

        static::$client->insert($table, $rows);

        $this->assertSame($names, $this->names($table));
    }

    public function testInsertNullAndValueIntoNullableColumn(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        static::$client->insert($table, [
            ['id' => 1, 'name' => 'with_note', 'score' => 1.0, 'active' => 1, 'note' => 'hello'],
            ['id' => 2, 'name' => 'without_note', 'score' => 2.0, 'active' => 1, 'note' => null],
        ]);

        $this->assertSame('hello', $this->fetchById($table, 1)['note']);
        $this->assertNull($this->fetchById($table, 2)['note']);
    }

    public function testInsertEmptyArrayReportsZeroRows(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $this->assertSame(0, static::$client->insert($table, []));
        $this->assertEquals(0, $this->rowCount($table));
    }

    public function testQueryBuilderInsertWritesRows(): void
    {
        $table = $this->table('it_insert');
        $this->makeTable($table);

        $builder = new Builder(static::$client);

        $this->assertSame(2, $builder->table($table)->insert([
            ['id' => 1, 'name' => 'alice', 'score' => 1.0, 'active' => 1],
            ['id' => 2, 'name' => 'bob', 'score' => 2.0, 'active' => 0],
        ]));

        $this->assertSame(['alice', 'bob'], $this->names($table));
    }

    public function testInsertIntoSubsetOfColumnsLeavesTheRestAtTypeDefault(): void
    {
        $table = $this->table('it_insert');

        static::$schema->create($table, function (Blueprint $blueprint) {
            $blueprint->uint32('id');
            $blueprint->string('name');
            $blueprint->uint32('qty');
            $blueprint->engine('MergeTree')->orderBy(['id']);
        });

        // `qty` is absent from the payload, so the generated column list must
        // line up with the VALUES tuple and leave qty at its type default.
        $this->assertSame(1, static::$client->insert($table, ['id' => 7, 'name' => 'alice']));

        $row = $this->fetchById($table, 7);
        $this->assertSame('alice', $row['name']);
        $this->assertEquals(0, $row['qty']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchById(string $table, int $id): array
    {
        return static::$client->query(
            'SELECT * FROM ' . Quoter::table($table) . ' WHERE id = ? LIMIT 1',
            [$id],
        )->first();
    }

    /**
     * @return array<int, string>
     */
    private function names(string $table): array
    {
        return static::$client->query(
            'SELECT name FROM ' . Quoter::table($table) . ' ORDER BY id',
        )->column('name');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->scalar($table, 'count()');
    }

    private function sumScore(string $table): float
    {
        return (float) $this->scalar($table, 'sum(score)');
    }

    private function scalar(string $table, string $expression): mixed
    {
        $row = static::$client->query(
            "SELECT {$expression} AS value FROM " . Quoter::table($table),
        )->first();

        return $row['value'];
    }
}
