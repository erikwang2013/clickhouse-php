<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Support;

use Erikwang2013\ClickHouse\Support\Arr;
use Erikwang2013\ClickHouse\Support\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testGetTopLevelKey(): void
    {
        $config = new Config(['host' => 'localhost', 'port' => 8123]);
        $this->assertSame('localhost', $config->get('host'));
        $this->assertSame(8123, $config->get('port'));
    }

    public function testGetDefaultValue(): void
    {
        $config = new Config([]);
        $this->assertSame('default', $config->get('missing', 'default'));
    }

    public function testGetNestedKey(): void
    {
        $config = new Config(['connections' => ['default' => ['host' => 'localhost']]]);
        $this->assertSame('localhost', $config->get('connections.default.host'));
    }

    public function testGetMissingKeyReturnsNullDefault(): void
    {
        $config = new Config(['host' => 'localhost']);
        $this->assertNull($config->get('port'));
    }

    public function testGetLiteralDottedKeyTakesPrecedence(): void
    {
        $config = new Config(['a.b' => 'literal']);
        $this->assertSame('literal', $config->get('a.b'));
    }

    public function testGetNestedMissingReturnsDefault(): void
    {
        $config = new Config(['a' => ['b' => 1]]);
        $this->assertSame('fallback', $config->get('a.b.c', 'fallback'));
    }

    public function testAllReturnsConfigArray(): void
    {
        $config = new Config(['host' => 'localhost', 'port' => 8123]);
        $this->assertSame(['host' => 'localhost', 'port' => 8123], $config->all());
    }
}