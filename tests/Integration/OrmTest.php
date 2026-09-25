<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\ORM\Collection;
use Erikwang2013\ClickHouse\ORM\Model;
use PHPUnit\Framework\Attributes\Group;

/**
 * Model standing in for a user table; the table name is swapped per test so
 * tests can run concurrently against the same database.
 */
class OrmUserModel extends Model
{
    public static string $tableName = '';

    public function getTable(): string
    {
        return static::$tableName;
    }
}

#[Group('integration')]
class OrmTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OrmUserModel::$tableName = $this->table('it_orm');
        $this->makeTable(OrmUserModel::$tableName);
        $this->seedTable(OrmUserModel::$tableName);
    }

    public function testSavePersistsTheModelAttributes(): void
    {
        (new OrmUserModel(['id' => 6, 'name' => 'frank', 'score' => 60.5, 'active' => 1]))->save();

        $model = OrmUserModel::find(6);

        $this->assertInstanceOf(OrmUserModel::class, $model);
        $this->assertSame('frank', $model->name);
        $this->assertEquals(60.5, $model->score);
    }

    public function testFindReturnsNullWhenNoRowMatches(): void
    {
        $this->assertNull(OrmUserModel::find(999));
    }

    public function testFindReturnsTheRequestedRow(): void
    {
        $model = OrmUserModel::find(3);

        $this->assertInstanceOf(OrmUserModel::class, $model);
        $this->assertSame('carol', $model->name);
        $this->assertEquals(3, $model->id);
    }

    public function testAllReturnsEveryRowAsModels(): void
    {
        $all = OrmUserModel::all();

        $this->assertInstanceOf(Collection::class, $all);
        $this->assertCount(5, $all);
        $this->assertInstanceOf(OrmUserModel::class, $all->first());

        $names = [];
        foreach ($all as $model) {
            $names[] = $model->name;
        }

        sort($names);
        $this->assertSame(['alice', 'bob', 'carol', 'dave', 'erin'], $names);
    }

    public function testStaticInsertWritesRows(): void
    {
        $this->assertSame(2, OrmUserModel::insert([
            ['id' => 10, 'name' => 'gina', 'score' => 1.0, 'active' => 1],
            ['id' => 11, 'name' => 'hugo', 'score' => 2.0, 'active' => 0],
        ]));

        $this->assertSame('gina', OrmUserModel::find(10)->name);
        $this->assertCount(7, OrmUserModel::all());
    }

    public function testGetAttributesExposesTheRow(): void
    {
        $model = OrmUserModel::find(1);

        $this->assertSame('alice', $model->getAttributes()['name']);
    }

    public function testWhereReturnsAQueryBuilder(): void
    {
        $rows = OrmUserModel::where('active', 1)->orderBy('id')->get()->toArray();

        $this->assertCount(3, $rows);
        $this->assertSame('alice', $rows[0]['name']);
    }

    public function testUnsetAttributeReadsAsNull(): void
    {
        $this->assertNull(OrmUserModel::find(1)->nonexistent);
    }
}
