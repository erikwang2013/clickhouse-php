<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Schema;

use Erikwang2013\ClickHouse\Schema\Column;
use PHPUnit\Framework\TestCase;

class ColumnTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $column = new Column('id', 'UInt64');
        $this->assertSame('id', $column->name);
        $this->assertSame('UInt64', $column->type);
        $this->assertSame([], $column->modifiers);
    }

    public function testToSqlWithoutModifiers(): void
    {
        $column = new Column('id', 'UInt64');
        $this->assertSame('`id` UInt64', $column->toSql());
    }

    public function testToSqlAppendsModifiersInOrder(): void
    {
        $column = new Column('name', 'String', ["DEFAULT 'unknown'", "COMMENT 'user name'", 'CODEC(ZSTD)']);
        $this->assertSame("`name` String DEFAULT 'unknown' COMMENT 'user name' CODEC(ZSTD)", $column->toSql());
    }

    public function testToSqlWithSingleModifier(): void
    {
        $column = new Column('active', 'Bool', ['DEFAULT 1']);
        $this->assertSame('`active` Bool DEFAULT 1', $column->toSql());
    }

    public function testToSqlPassesTypeThroughRaw(): void
    {
        $column = new Column('amount', 'Decimal(18, 2)');
        $this->assertSame('`amount` Decimal(18, 2)', $column->toSql());
    }

    public function testToSqlWithNestedType(): void
    {
        $column = new Column('tags', 'Array(Nullable(String))');
        $this->assertSame('`tags` Array(Nullable(String))', $column->toSql());
    }
}
