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

    public function testValueKeepsFloatPrecision(): void
    {
        // 默认 precision=14 会输出 1.2345678901235，存进去就是另一个数
        $this->assertSame('1.2345678901234567', Quoter::value(1.2345678901234567));
        $this->assertSame('0.1', Quoter::value(0.1));
        // json_encode 会把 1.0 写成 "1"，对 Float64 列仍是合法字面量
        $this->assertSame('1', Quoter::value(1.0));
    }

    public function testValueRejectsNanAndInf(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quoter::value(NAN);
    }

    public function testValueRejectsInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quoter::value(INF);
    }

    public function testValueQuotesArrayAsClickHouseArrayLiteral(): void
    {
        $this->assertSame('[1, 2, 3]', Quoter::value([1, 2, 3]));
        $this->assertSame("['a', 'b']", Quoter::value(['a', 'b']));
        $this->assertSame('[]', Quoter::value([]));
    }

    public function testValueRejectsObjectWithoutToString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quoter::value(new \stdClass());
    }

    public function testValueQuotesStringableObject(): void
    {
        $object = new class {
            public function __toString(): string
            {
                return 'x';
            }
        };

        $this->assertSame("'x'", Quoter::value($object));
    }

    public function testColumnHandlesEmptyString(): void
    {
        $this->assertSame('``', Quoter::column(''));
    }
}
