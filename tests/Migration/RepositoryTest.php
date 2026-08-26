<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Migration;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Migration\Repository;
use Erikwang2013\ClickHouse\Query\Result;
use PHPUnit\Framework\TestCase;
use Mockery;

class RepositoryTest extends TestCase
{
    private function clientMock(): ClientInterface
    {
        return Mockery::mock(ClientInterface::class);
    }

    public function testCreateRepository(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with(Mockery::on(function ($sql) {
            return str_contains($sql, 'CREATE TABLE IF NOT EXISTS `migrations`')
                && str_contains($sql, 'migration String')
                && str_contains($sql, 'batch UInt32')
                && str_contains($sql, 'ENGINE = MergeTree()');
        }));
        (new Repository($client))->createRepository();
        $this->addToAssertionCount(1);
    }

    public function testCreateRepositoryWithCustomTable(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with(Mockery::on(function ($sql) {
            return str_contains($sql, 'CREATE TABLE IF NOT EXISTS `my_migrations`');
        }));
        (new Repository($client, 'my_migrations'))->createRepository();
        $this->addToAssertionCount(1);
    }

    public function testGetMigrations(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('select')->once()
            ->with('SELECT migration FROM `migrations` ORDER BY migration')
            ->andReturn([['migration' => 'a'], ['migration' => 'b']]);

        $this->assertSame([['migration' => 'a'], ['migration' => 'b']], (new Repository($client))->getMigrations());
    }

    public function testGetLastBatch(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('select')->once()->andReturn([['batch' => 5]]);
        $this->assertSame(5, (new Repository($client))->getLastBatch());
    }

    public function testGetLastBatchWhenNoRows(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('select')->once()->andReturn([]);
        $this->assertSame(0, (new Repository($client))->getLastBatch());
    }

    public function testGetLastBatchWhenBatchIsNull(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('select')->once()->andReturn([['batch' => null]]);
        $this->assertSame(0, (new Repository($client))->getLastBatch());
    }

    public function testLog(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('insert')->once()->with('migrations', ['migration' => 'x', 'batch' => 2])->andReturn(1);

        $this->assertNull((new Repository($client))->log('x', 2));
    }

    public function testDelete(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with('ALTER TABLE `migrations` DELETE WHERE migration = ?', ['x']);
        (new Repository($client))->delete('x');
        $this->addToAssertionCount(1);
    }

    public function testGetMigrationsByBatch(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('select')->once()
            ->with('SELECT migration FROM `migrations` WHERE batch = ? ORDER BY migration DESC', [3])
            ->andReturn([['migration' => 'b'], ['migration' => 'a']]);

        $this->assertSame(
            [['migration' => 'b'], ['migration' => 'a']],
            (new Repository($client))->getMigrationsByBatch(3),
        );
    }

    public function testWaitForMutationsReturnsImmediatelyWhenNoPending(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with(Mockery::on(function ($sql) {
            return str_contains($sql, 'system.mutations')
                && str_contains($sql, "table = 'migrations'")
                && str_contains($sql, 'is_done = 0');
        }))->andReturn(new Result([['c' => 0]]));

        (new Repository($client))->waitForMutations();
        $this->addToAssertionCount(1);
    }

    public function testWaitForMutationsPollsUntilDone(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->twice()
            ->andReturn(new Result([['c' => 1]]), new Result([['c' => 0]]));

        (new Repository($client))->waitForMutations(1000);
        $this->addToAssertionCount(1);
    }

    public function testWaitForMutationsTimesOutWhenNeverDone(): void
    {
        $client = $this->clientMock();
        $client->shouldReceive('query')->atLeast()->once()->andReturn(new Result([['c' => 1]]));

        (new Repository($client))->waitForMutations(50);
        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
