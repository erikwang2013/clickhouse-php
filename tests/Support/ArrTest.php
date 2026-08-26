<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Support;

use Erikwang2013\ClickHouse\Support\Arr;
use PHPUnit\Framework\TestCase;

class ArrTest extends TestCase
{
    public function testGetExistingTopLevelKey(): void
    {
        $this->assertSame('localhost', Arr::get(['host' => 'localhost'], 'host'));
    }

    public function testGetLiteralDottedKeyTakesPrecedence(): void
    {
        $array = ['a.b' => 'literal', 'a' => ['b' => 'nested']];
        $this->assertSame('literal', Arr::get($array, 'a.b'));
    }

    public function testGetMissingKeyWithoutDotReturnsDefault(): void
    {
        $this->assertSame('fallback', Arr::get([], 'host', 'fallback'));
        $this->assertNull(Arr::get([], 'host'));
    }

    public function testGetNestedKey(): void
    {
        $array = ['a' => ['b' => ['c' => 42]]];
        $this->assertSame(42, Arr::get($array, 'a.b.c'));
    }

    public function testGetNestedKeyMissingReturnsDefault(): void
    {
        $array = ['a' => ['b' => 1]];
        $this->assertSame('fallback', Arr::get($array, 'a.b.c', 'fallback'));
        $this->assertSame('fallback', Arr::get($array, 'a.x', 'fallback'));
    }

    public function testGetNestedPathThroughScalarReturnsDefault(): void
    {
        $this->assertSame('fallback', Arr::get(['a' => 'scalar'], 'a.b', 'fallback'));
    }

    public function testGetNestedKeyWithNullValue(): void
    {
        $this->assertNull(Arr::get(['a' => ['b' => null]], 'a.b', 'fallback'));
    }

    public function testGetEmptyKey(): void
    {
        $this->assertSame('x', Arr::get(['' => 'x'], ''));
        $this->assertSame('fallback', Arr::get([], '', 'fallback'));
    }
}
