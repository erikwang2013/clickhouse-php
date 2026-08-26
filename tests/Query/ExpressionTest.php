<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Query;

use Erikwang2013\ClickHouse\Query\Expression;
use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase
{
    public function testGetValueReturnsConstructorValue(): void
    {
        $expression = new Expression('now()');
        $this->assertSame('now()', $expression->getValue());
    }

    public function testToStringReturnsValue(): void
    {
        $expression = new Expression('COUNT(DISTINCT id)');
        $this->assertSame('COUNT(DISTINCT id)', (string) $expression);
    }

    public function testToStringEmptyString(): void
    {
        $expression = new Expression('');
        $this->assertSame('', (string) $expression);
        $this->assertSame('', $expression->getValue());
    }
}
