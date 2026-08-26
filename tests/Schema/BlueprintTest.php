<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Schema;

use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Schema\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BlueprintTest extends TestCase
{
    public static function scalarTypes(): array
    {
        return [
            'string' => ['string', 'String'],
            'int8' => ['int8', 'Int8'],
            'int16' => ['int16', 'Int16'],
            'int32' => ['int32', 'Int32'],
            'int64' => ['int64', 'Int64'],
            'uint8' => ['uint8', 'UInt8'],
            'uint16' => ['uint16', 'UInt16'],
            'uint32' => ['uint32', 'UInt32'],
            'uint64' => ['uint64', 'UInt64'],
            'float32' => ['float32', 'Float32'],
            'float64' => ['float64', 'Float64'],
            'date' => ['date', 'Date'],
            'dateTime' => ['dateTime', 'DateTime'],
            'uuid' => ['uuid', 'UUID'],
            'bool' => ['bool', 'Bool'],
        ];
    }

    #[DataProvider('scalarTypes')]
    public function testScalarColumnTypes(string $method, string $type): void
    {
        $blueprint = new Blueprint();
        $column = $blueprint->$method('col');

        $this->assertInstanceOf(Column::class, $column);
        $this->assertSame('col', $column->name);
        $this->assertSame($type, $column->type);
        $this->assertSame([$column], $blueprint->columns);
    }

    public function testFixedString(): void
    {
        $blueprint = new Blueprint();
        $column = $blueprint->fixedString('code', 16);
        $this->assertSame('FixedString(16)', $column->type);
    }

    public function testDecimal(): void
    {
        $blueprint = new Blueprint();
        $column = $blueprint->decimal('price', 18, 4);
        $this->assertSame('Decimal(18, 4)', $column->type);
    }

    public function testDateTime64DefaultsToPrecisionThree(): void
    {
        $blueprint = new Blueprint();
        $this->assertSame('DateTime64(3)', $blueprint->dateTime64('ts')->type);
    }

    public function testDateTime64WithCustomPrecision(): void
    {
        $blueprint = new Blueprint();
        $this->assertSame('DateTime64(6)', $blueprint->dateTime64('ts', 6)->type);
    }

    public function testWrappedColumnTypes(): void
    {
        $blueprint = new Blueprint();
        $this->assertSame('Array(UInt8)', $blueprint->array('tags', 'UInt8')->type);
        $this->assertSame('Nullable(String)', $blueprint->nullable('desc', 'String')->type);
        $this->assertSame('LowCardinality(String)', $blueprint->lowCardinality('city', 'String')->type);
    }

    public function testColumnsAppendInOrder(): void
    {
        $blueprint = new Blueprint();
        $blueprint->string('a');
        $blueprint->string('b');
        $blueprint->string('c');

        $this->assertCount(3, $blueprint->columns);
        $this->assertSame(['a', 'b', 'c'], array_map(fn(Column $c) => $c->name, $blueprint->columns));
    }

    public function testDefaultsAreEmpty(): void
    {
        $blueprint = new Blueprint();
        $this->assertSame([], $blueprint->columns);
        $this->assertNull($blueprint->getEngine());
        $this->assertNull($blueprint->getPartitionBy());
        $this->assertSame([], $blueprint->getOrderBy());
        $this->assertNull($blueprint->getPrimaryKey());
        $this->assertNull($blueprint->getSampleBy());
        $this->assertNull($blueprint->getTtl());
        $this->assertSame([], $blueprint->getSettings());
    }

    public function testEngineConfigurationIsFluent(): void
    {
        $blueprint = new Blueprint();
        $result = $blueprint
            ->engine('ReplacingMergeTree(version)')
            ->partitionBy('toYYYYMM(date)')
            ->orderBy(['date', 'id'])
            ->primaryKey('id')
            ->sampleBy('rand()')
            ->ttl('date + INTERVAL 30 DAY')
            ->settings(['index_granularity' => 8192, 'min_rows_for_wide_part' => 0]);

        $this->assertSame($blueprint, $result);
        $this->assertSame('ReplacingMergeTree(version)', $blueprint->getEngine());
        $this->assertSame('toYYYYMM(date)', $blueprint->getPartitionBy());
        $this->assertSame(['date', 'id'], $blueprint->getOrderBy());
        $this->assertSame('id', $blueprint->getPrimaryKey());
        $this->assertSame('rand()', $blueprint->getSampleBy());
        $this->assertSame('date + INTERVAL 30 DAY', $blueprint->getTtl());
        $this->assertSame(['index_granularity' => 8192, 'min_rows_for_wide_part' => 0], $blueprint->getSettings());
    }

    public function testEngineOptionsAreOverwritable(): void
    {
        $blueprint = new Blueprint();
        $blueprint->engine('MergeTree');
        $blueprint->engine('Log');
        $blueprint->partitionBy('a');
        $blueprint->partitionBy('b');

        $this->assertSame('Log', $blueprint->getEngine());
        $this->assertSame('b', $blueprint->getPartitionBy());
    }
}
