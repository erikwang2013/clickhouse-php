<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\ORM;

use Erikwang2013\ClickHouse\ORM\Collection;
use PHPUnit\Framework\TestCase;
use Mockery;

class CollectionTest extends TestCase
{
    public function testCount(): void
    {
        $this->assertCount(2, new Collection([1, 2]));
        $this->assertCount(0, new Collection([]));
    }

    public function testFirstAndLast(): void
    {
        $collection = new Collection([['a' => 1], ['a' => 2], ['a' => 3]]);
        $this->assertSame(['a' => 1], $collection->first());
        $this->assertSame(['a' => 3], $collection->last());
    }

    public function testFirstAndLastOnEmptyCollection(): void
    {
        $collection = new Collection([]);
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }

    public function testFirstWithZeroValueIsNotTreatedAsEmpty(): void
    {
        $collection = new Collection([0, 5]);
        $this->assertSame(0, $collection->first());
    }

    public function testToArray(): void
    {
        $items = [['a' => 1], ['b' => 2]];
        $this->assertSame($items, (new Collection($items))->toArray());
    }

    public function testMap(): void
    {
        $collection = new Collection([1, 2, 3]);
        $mapped = $collection->map(fn($n) => $n * 10);
        $this->assertInstanceOf(Collection::class, $mapped);
        $this->assertSame([10, 20, 30], $mapped->toArray());
        $this->assertSame([1, 2, 3], $collection->toArray());
    }

    public function testMapOnEmptyCollection(): void
    {
        $this->assertSame([], (new Collection([]))->map(fn($n) => $n)->toArray());
    }

    public function testFilter(): void
    {
        $collection = new Collection([1, 2, 3, 4]);
        $filtered = $collection->filter(fn($n) => $n % 2 === 0);
        $this->assertInstanceOf(Collection::class, $filtered);
        $this->assertSame([2, 4], $filtered->toArray());
    }

    public function testFilterReindexesKeys(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $filtered = $collection->filter(fn($n) => $n > 1);
        $this->assertSame([2, 3], $filtered->toArray());
        $this->assertSame([0, 1], array_keys($filtered->toArray()));
    }

    public function testFilterOnEmptyCollection(): void
    {
        $this->assertSame([], (new Collection([]))->filter(fn($n) => true)->toArray());
    }

    public function testPluck(): void
    {
        $collection = new Collection([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $this->assertSame(['a', 'b', 'c'], $collection->pluck('name'));
    }

    public function testPluckMissingColumnYieldsNulls(): void
    {
        $collection = new Collection([['id' => 1], ['id' => 2]]);
        $this->assertSame([], $collection->pluck('missing'));
    }

    public function testPluckOnEmptyCollection(): void
    {
        $this->assertSame([], (new Collection([]))->pluck('name'));
    }

    public function testIteration(): void
    {
        $collection = new Collection([10, 20]);
        $seen = [];
        foreach ($collection as $item) {
            $seen[] = $item;
        }
        $this->assertSame([10, 20], $seen);
    }

    public function testArrayAccessGetAndExists(): void
    {
        $collection = new Collection(['x' => 1]);
        $this->assertTrue(isset($collection['x']));
        $this->assertFalse(isset($collection['y']));
        $this->assertSame(1, $collection['x']);
    }

    public function testOffsetSetThrows(): void
    {
        $collection = new Collection([1]);
        $this->expectException(\LogicException::class);
        $collection[0] = 99;
    }

    public function testOffsetUnsetThrows(): void
    {
        $collection = new Collection([1]);
        $this->expectException(\LogicException::class);
        unset($collection[0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
