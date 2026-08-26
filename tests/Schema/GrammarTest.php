<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Schema;

use Erikwang2013\ClickHouse\Schema\Blueprint;
use Erikwang2013\ClickHouse\Schema\Grammar;
use PHPUnit\Framework\TestCase;

class GrammarTest extends TestCase
{
    private function blueprint(): Blueprint
    {
        return new Blueprint();
    }

    public function testCompileCreateMinimal(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->string('name');

        $sql = (new Grammar())->compileCreate('logs', $blueprint);
        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `logs` (`name` String) ENGINE = MergeTree ORDER BY tuple()',
            $sql,
        );
    }

    public function testCompileCreateWithAllClauses(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->uint64('id');
        $blueprint->string('name');
        $blueprint->nullable('desc', 'String');
        $blueprint->engine('ReplacingMergeTree(version)')
            ->partitionBy('toYYYYMM(created_at)')
            ->orderBy(['id', 'name'])
            ->primaryKey('id')
            ->sampleBy('rand()')
            ->ttl('created_at + INTERVAL 30 DAY')
            ->settings(['index_granularity' => 8192, 'min_rows_for_wide_part' => 0]);

        $sql = (new Grammar())->compileCreate('events', $blueprint);
        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `events` (`id` UInt64, `name` String, `desc` Nullable(String))'
            . ' ENGINE = ReplacingMergeTree(version)'
            . ' PARTITION BY toYYYYMM(created_at)'
            . ' ORDER BY (id, name)'
            . ' PRIMARY KEY id'
            . ' SAMPLE BY rand()'
            . ' TTL created_at + INTERVAL 30 DAY'
            . ' SETTINGS index_granularity = 8192, min_rows_for_wide_part = 0',
            $sql,
        );
    }

    public function testCompileCreateWithoutOrderByFallsBackToTuple(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->date('date');
        $blueprint->partitionBy('toYYYYMM(date)');

        $sql = (new Grammar())->compileCreate('logs', $blueprint);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(date)', $sql);
        $this->assertStringContainsString('ORDER BY tuple()', $sql);
    }

    public function testCompileCreateQuotesQualifiedTable(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->bool('active');

        $sql = (new Grammar())->compileCreate('analytics.logs', $blueprint);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `analytics`.`logs`', $sql);
    }

    public function testCompileDrop(): void
    {
        $this->assertSame('DROP TABLE IF EXISTS `logs`', (new Grammar())->compileDrop('logs'));
    }

    public function testCompileDropWithQualifiedTable(): void
    {
        $this->assertSame('DROP TABLE IF EXISTS `db`.`logs`', (new Grammar())->compileDrop('db.logs'));
    }

    public function testCompileDropEscapesBackticksInTableName(): void
    {
        $this->assertSame('DROP TABLE IF EXISTS `we\\`ird`', (new Grammar())->compileDrop('we`ird'));
    }

    public function testCompileAlterAddSingleColumn(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->string('source');

        $sql = (new Grammar())->compileAlterAdd('logs', $blueprint);
        $this->assertSame('ALTER TABLE `logs` ADD COLUMN `source` String', $sql);
    }

    public function testCompileAlterAddMultipleColumns(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->string('source');
        $blueprint->nullable('description', 'String');

        $sql = (new Grammar())->compileAlterAdd('logs', $blueprint);
        $this->assertSame(
            'ALTER TABLE `logs` ADD COLUMN `source` String, ADD COLUMN `description` Nullable(String)',
            $sql,
        );
    }

    public function testCompileAlterAddQualifiedTable(): void
    {
        $blueprint = $this->blueprint();
        $blueprint->uuid('request_id');

        $sql = (new Grammar())->compileAlterAdd('db.logs', $blueprint);
        $this->assertSame('ALTER TABLE `db`.`logs` ADD COLUMN `request_id` UUID', $sql);
    }

    public function testCompileTableExistsUsesDefaultDatabase(): void
    {
        $this->assertSame(
            "SELECT count() AS c FROM system.tables WHERE database = 'default' AND name = 'logs'",
            (new Grammar())->compileTableExists('logs'),
        );
    }

    public function testCompileTableExistsSplitsDatabase(): void
    {
        $this->assertSame(
            "SELECT count() AS c FROM system.tables WHERE database = 'analytics' AND name = 'logs'",
            (new Grammar())->compileTableExists('analytics.logs'),
        );
    }

    public function testCompileTableExistsEscapesQuotesInName(): void
    {
        $this->assertSame(
            "SELECT count() AS c FROM system.tables WHERE database = 'default' AND name = 'a\\'b'",
            (new Grammar())->compileTableExists("a'b"),
        );
    }

    public function testCompileTableListDefaultDatabase(): void
    {
        $this->assertSame('SHOW TABLES FROM `default`', (new Grammar())->compileTableList());
    }

    public function testCompileTableListWithCustomDatabase(): void
    {
        $this->assertSame('SHOW TABLES FROM `analytics`', (new Grammar())->compileTableList('analytics'));
    }

    public function testCompileTableListSplitsQualifiedDatabase(): void
    {
        $this->assertSame('SHOW TABLES FROM `a`.`b`.`c`', (new Grammar())->compileTableList('a.b.c'));
    }

    public function testCompileTableInfo(): void
    {
        $this->assertSame('DESCRIBE TABLE `logs`', (new Grammar())->compileTableInfo('logs'));
    }

    public function testCompileTableInfoWithQualifiedTable(): void
    {
        $this->assertSame('DESCRIBE TABLE `db`.`logs`', (new Grammar())->compileTableInfo('db.logs'));
    }
}
