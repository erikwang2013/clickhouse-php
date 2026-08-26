<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Support;

use Erikwang2013\ClickHouse\Query\Expression;
use Erikwang2013\ClickHouse\Support\Quoter;
use PHPUnit\Framework\TestCase;

class QuoterTest extends TestCase
{
    public function testValueReturnsExpressionValue(): void
    {
        $this->assertSame('now()', Quoter::value(new Expression('now()')));
    }

    public function testValueQuotesNull(): void
    {
        $this->assertSame('NULL', Quoter::value(null));
    }

    public function testValueFormatsNumbers(): void
    {
        $this->assertSame('42', Quoter::value(42));
        $this->assertSame('3.14', Quoter::value(3.14));
    }

    public function testValueFormatsBooleans(): void
    {
        $this->assertSame('1', Quoter::value(true));
        $this->assertSame('0', Quoter::value(false));
    }

    public function testValueQuotesStrings(): void
    {
        $this->assertSame("'hello'", Quoter::value('hello'));
        $this->assertSame("'42'", Quoter::value('42'));
        $this->assertSame("''", Quoter::value(''));
    }

    public function testValueEscapesQuotesAndBackslashes(): void
    {
        $this->assertSame("'it\\'s \\\\ here'", Quoter::value("it's \\ here"));
    }

    public function testTableQuotesLikeColumn(): void
    {
        $this->assertSame('`logs`', Quoter::table('logs'));
        $this->assertSame('`db`.`logs`', Quoter::table('db.logs'));
    }

    public function testColumnQuotesSimpleIdentifier(): void
    {
        $this->assertSame('`id`', Quoter::column('id'));
    }

    public function testColumnQuotesDottedPath(): void
    {
        $this->assertSame('`a`.`b`.`c`', Quoter::column('a.b.c'));
    }

    public function testColumnEscapesBackticks(): void
    {
        $this->assertSame('`a\\`b`', Quoter::column('a`b'));
    }

    public function testColumnWithDotsAndBackticks(): void
    {
        $this->assertSame('`a`.`b\\`c`', Quoter::column('a.b`c'));
    }

    public function testColumnHandlesEmptyString(): void
    {
        $this->assertSame('``', Quoter::column(''));
    }
}
