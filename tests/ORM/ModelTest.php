<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\ORM;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Client\Manager;
use Erikwang2013\ClickHouse\ORM\Collection;
use Erikwang2013\ClickHouse\Query\Builder;
use Erikwang2013\ClickHouse\Query\Result;
use PHPUnit\Framework\TestCase;
use Mockery;

class TestModel extends \Erikwang2013\ClickHouse\ORM\Model
{
    protected string $table = 'test_table';
}

class PlainTestModel extends \Erikwang2013\ClickHouse\ORM\Model
{
    protected string $table = 'plain_table';
}

class ModelTest extends TestCase
{
    public function testModelGetTable(): void
    {
        $model = new TestModel();
        $this->assertSame('test_table', $model->getTable());
    }

    public function testModelAttributes(): void
    {
        $model = new TestModel(['name' => 'foo', 'value' => 42]);
        $this->assertSame('foo', $model->name);
        $this->assertSame(42, $model->value);
    }

    public function testCollectionFirst(): void
    {
        $collection = new Collection([['a' => 1], ['a' => 2]]);
        $this->assertSame(['a' => 1], $collection->first());
        $this->assertSame(['a' => 2], $collection->last());
        $this->assertCount(2, $collection);
    }

    public function testCollectionPluck(): void
    {
        $collection = new Collection([['name' => 'a'], ['name' => 'b']]);
        $this->assertSame(['a', 'b'], $collection->pluck('name'));
    }

    public function testModelSetAttribute(): void
    {
        $model = new TestModel();
        $model->name = 'bar';
        $this->assertSame('bar', $model->name);
        $this->assertSame(['name' => 'bar'], $model->getAttributes());
    }

    public function testModelMissingAttributeIsNull(): void
    {
        $this->assertNull((new TestModel())->missing);
    }

    public function testModelSaveInsertsAttributes(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('insert')->once()->with('test_table', ['name' => 'foo', 'value' => 42])->andReturn(1);
        $this->setManagerWithClient($client);

        (new TestModel(['name' => 'foo', 'value' => 42]))->save();
        $this->addToAssertionCount(1);
    }

    public function testModelQueryReturnsBuilder(): void
    {
        $this->setManagerWithClient($this->mockClient());

        $builder = TestModel::query();
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertSame('test_table', $builder->from);
    }

    public function testModelFindReturnsModel(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('query')->once()->andReturn(new Result([['id' => 1, 'name' => 'a']]));
        $this->setManagerWithClient($client);

        $model = TestModel::find(1);
        $this->assertInstanceOf(TestModel::class, $model);
        $this->assertSame(1, $model->id);
        $this->assertSame('a', $model->name);
    }

    public function testModelFindReturnsNullWhenMissing(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('query')->once()->andReturn(new Result([]));
        $this->setManagerWithClient($client);

        $this->assertNull(TestModel::find(99));
    }

    public function testModelAllReturnsCollectionOfModels(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('query')->once()->andReturn(new Result([['id' => 1], ['id' => 2]]));
        $this->setManagerWithClient($client);

        $all = TestModel::all();
        $this->assertInstanceOf(Collection::class, $all);
        $this->assertCount(2, $all);
        $this->assertInstanceOf(TestModel::class, $all->first());
        $this->assertSame(1, $all->first()->id);
    }

    public function testModelInsertReturnsCount(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('insert')->once()->with('test_table', [['x' => 1]])->andReturn(3);
        $this->setManagerWithClient($client);

        $this->assertSame(3, TestModel::insert([['x' => 1]]));
    }

    public function testModelWhereReturnsBuilder(): void
    {
        $this->setManagerWithClient($this->mockClient());

        $builder = TestModel::where('level', 'error');
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertStringContainsString("WHERE `level` = 'error'", $builder->toSql());
    }

    public function testBaseModelWhereWithThreeArgs(): void
    {
        $this->setManagerWithClient($this->mockClient());

        $builder = PlainTestModel::where('level', '=', 'error');
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertStringContainsString("WHERE `level` = 'error'", $builder->toSql());
    }

    public function testModelCallStaticDelegatesToBuilder(): void
    {
        $this->setManagerWithClient($this->mockClient());

        $builder = TestModel::orderBy('created_at', 'DESC');
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertSame([['created_at', 'DESC']], $builder->orders);
    }

    private function mockClient(): ClientInterface
    {
        return Mockery::mock(ClientInterface::class);
    }

    private function setManagerWithClient(ClientInterface $client): void
    {
        $manager = Mockery::mock(Manager::class);
        $manager->shouldReceive('connection')->andReturn($client);
        ClickHouse::setManager($manager);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(ClickHouse::class, 'manager');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        Mockery::close();
    }
}