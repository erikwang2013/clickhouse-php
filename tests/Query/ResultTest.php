<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Query;

use Erikwang2013\ClickHouse\Query\Result;
use PHPUnit\Framework\TestCase;

class ResultTest extends TestCase
{
    public function testRowCountDefaultsToDataCount(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);
        $this->assertSame(2, $result->count());
    }

    public function testExplicitRowCountWins(): void
    {
        $result = new Result([['id' => 1]], 7);
        $this->assertSame(7, $result->count());
    }

    public function testExplicitRowCountZeroIsPreserved(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]], 0);
        $this->assertSame(0, $result->count());
    }

    public function testEmptyDataCountIsZero(): void
    {
        $result = new Result([]);
        $this->assertSame(0, $result->count());
    }

    public function testImplementsIteratorAggregateCountableArrayAccess(): void
    {
        $result = new Result([]);
        $this->assertInstanceOf(\IteratorAggregate::class, $result);
        $this->assertInstanceOf(\Countable::class, $result);
        $this->assertInstanceOf(\ArrayAccess::class, $result);
    }

    public function testGetIteratorYieldsRows(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }
        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    public function testFirstReturnsFirstRow(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);
        $this->assertSame(['id' => 1], $result->first());
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $result = new Result([]);
        $this->assertNull($result->first());
    }

    public function testFirstReturnsFalsyFirstRow(): void
    {
        $result = new Result([[]]);
        $this->assertSame([], $result->first());
    }

    public function testToArrayReturnsData(): void
    {
        $data = [['id' => 1], ['id' => 2]];
        $result = new Result($data);
        $this->assertSame($data, $result->toArray());
    }

    public function testColumnExtractsValues(): void
    {
        $result = new Result([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
        $this->assertSame([1, 2], $result->column('id'));
    }

    public function testColumnWithMissingKeyReturnsEmpty(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);
        $this->assertSame([], $result->column('missing'));
    }

    public function testColumnOnEmptyResult(): void
    {
        $result = new Result([]);
        $this->assertSame([], $result->column('id'));
    }

    public function testOffsetExists(): void
    {
        $result = new Result([['id' => 1]]);
        $this->assertTrue(isset($result[0]));
        $this->assertFalse(isset($result[1]));
        $this->assertFalse(isset($result['missing']));
    }

    public function testOffsetGet(): void
    {
        $result = new Result([['id' => 1]]);
        $this->assertSame(['id' => 1], $result[0]);
    }

    public function testOffsetSetThrows(): void
    {
        $result = new Result([]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Result is read-only');
        $result[0] = ['id' => 1];
    }

    public function testOffsetUnsetThrows(): void
    {
        $result = new Result([['id' => 1]]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Result is read-only');
        unset($result[0]);
    }
}
