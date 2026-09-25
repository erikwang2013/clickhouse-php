<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Support\Quoter;
use PHPUnit\Framework\Attributes\Group;

/**
 * DDL against a real server: the compiled CREATE/ALTER/DROP has to be accepted
 * by ClickHouse and the introspection helpers have to report what was created.
 */
#[Group('integration')]
class SchemaTest extends IntegrationTestCase
{
    public function testCreateThenHasTable(): void
    {
        $table = $this->table('it_schema');

        static::$schema->create($table, function (Blueprint $blueprint) {
            $blueprint->uint32('id');
            $blueprint->string('name');
            $blueprint->engine('MergeTree')->orderBy(['id']);
        });

        $this->assertTrue(static::$schema->hasTable($table));
        $this->assertFalse(static::$schema->hasTable('it_schema_missing_' . bin2hex(random_bytes(5))));
    }

    public function testCreateIsIdempotent(): void
    {
        $table = $this->table('it_schema');

        $create = function () use ($table) {
            static::$schema->create($table, function (Blueprint $blueprint) {
                $blueprint->uint32('id');
                $blueprint->engine('MergeTree')->orderBy(['id']);
            });
        };

        $create();
        $create();

        $this->assertTrue(static::$schema->hasTable($table));
    }

    public function testCreateWithoutColumnsIsRejected(): void
    {
        $table = $this->table('it_schema');

        try {
            static::$schema->create($table, function (Blueprint $blueprint) {
                // no columns
            });
            $this->fail('An empty blueprint should not create a table.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($table, $e->getMessage());
        }

        $this->assertFalse(static::$schema->hasTable($table));
    }

    public function testCreateAppliesEngineAndOrderBy(): void
    {
        $table = $this->table('it_schema');

        static::$schema->create($table, function (Blueprint $blueprint) {
            $blueprint->date('day');
            $blueprint->string('level');
            $blueprint->engine('MergeTree')
                ->partitionBy('toYYYYMM(day)')
                ->orderBy(['day', 'level']);
        });

        $row = static::$client->query(
            'SELECT engine, partition_key, sorting_key FROM system.tables'
            . ' WHERE database = currentDatabase() AND name = ?',
            [$table],
        )->first();

        $this->assertSame('MergeTree', $row['engine']);
        $this->assertSame('toYYYYMM(day)', $row['partition_key']);
        $this->assertStringContainsString('day', $row['sorting_key']);
        $this->assertStringContainsString('level', $row['sorting_key']);
    }

    public function testGetTableInfoDescribesColumns(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        $info = static::$schema->getTableInfo($table);
        $types = array_column($info, 'type', 'name');

        $this->assertSame(
            ['id', 'name', 'score', 'active', 'note'],
            array_keys($types),
        );
        $this->assertSame('UInt32', $types['id']);
        $this->assertSame('String', $types['name']);
        $this->assertSame('Float64', $types['score']);
        $this->assertSame('Nullable(String)', $types['note']);
    }

    public function testGetTablesListsTheCreatedTable(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        $names = array_column(static::$schema->getTables(static::database()), 'name');

        $this->assertContains($table, $names);
    }

    public function testAlterAddsOneColumn(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        static::$schema->alter($table, function (Blueprint $blueprint) {
            $blueprint->int32('qty');
        });

        $types = array_column(static::$schema->getTableInfo($table), 'type', 'name');

        $this->assertArrayHasKey('qty', $types);
        $this->assertSame('Int32', $types['qty']);
    }

    public function testAlterAddsSeveralColumnsInOneStatement(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        static::$schema->alter($table, function (Blueprint $blueprint) {
            $blueprint->string('tag');
            $blueprint->dateTime('seen_at');
            $blueprint->array('tags', 'String');
        });

        $types = array_column(static::$schema->getTableInfo($table), 'type', 'name');

        $this->assertSame('String', $types['tag']);
        $this->assertSame('DateTime', $types['seen_at']);
        $this->assertSame('Array(String)', $types['tags']);
    }

    public function testAlterWithoutColumnsIsRejected(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        $before = static::$schema->getTableInfo($table);

        try {
            static::$schema->alter($table, function (Blueprint $blueprint) {
                // no columns
            });
            $this->fail('An empty blueprint should not alter a table.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($table, $e->getMessage());
        }

        $this->assertSame($before, static::$schema->getTableInfo($table));
    }

    public function testDropRemovesTheTable(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        $this->assertTrue(static::$schema->hasTable($table));

        static::$schema->drop($table);

        $this->assertFalse(static::$schema->hasTable($table));
    }

    public function testDropOnMissingTableIsANoOp(): void
    {
        $table = $this->table('it_schema');

        static::$schema->drop($table);

        $this->assertFalse(static::$schema->hasTable($table));
    }

    public function testAlteredColumnAcceptsWrites(): void
    {
        $table = $this->table('it_schema');
        $this->makeTable($table);

        static::$schema->alter($table, function (Blueprint $blueprint) {
            $blueprint->uint32('qty');
        });

        static::$client->insert($table, ['id' => 1, 'name' => 'alice', 'score' => 1.0, 'active' => 1, 'qty' => 9]);

        $row = static::$client->query(
            'SELECT qty FROM ' . Quoter::table($table) . ' LIMIT 1',
        )->first();

        $this->assertEquals(9, $row['qty']);
    }
}
