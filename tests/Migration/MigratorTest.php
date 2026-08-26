<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Migration;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Migration\Migrator;
use Erikwang2013\ClickHouse\Migration\Repository;
use PHPUnit\Framework\TestCase;
use Mockery;

class MigratorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ch_mig_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
        Mockery::close();
    }

    private function writeMigration(string $file, string $class, string $body): string
    {
        $php = "<?php\n\nclass {$class} extends \\Erikwang2013\\ClickHouse\\Migration\\Migration\n{\n{$body}\n}\n";
        file_put_contents($this->dir . '/' . $file . '.php', $php);
        return $file;
    }

    private function makeMigrator(ClientInterface $client, Repository $repository): Migrator
    {
        return new Migrator($client, $repository, $this->dir);
    }

    private function clientMock(): ClientInterface
    {
        return Mockery::mock(ClientInterface::class);
    }

    private function repositoryMock(): Repository
    {
        return Mockery::mock(Repository::class);
    }

    public function testInstallCreatesRepository(): void
    {
        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('createRepository')->once();

        $this->makeMigrator($client, $repository)->install();
        $this->addToAssertionCount(1);
    }

    public function testRunWithPendingMigrations(): void
    {
        $alpha = $this->writeMigration('20240101000001_create_alpha_table', 'CreateAlphaTable', 'public function up(): void { $this->schema->drop(\'alpha\'); }');
        $beta = $this->writeMigration('20240101000002_create_beta_table', 'CreateBetaTable', 'public function up(): void { $this->schema->drop(\'beta\'); }');

        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with('DROP TABLE IF EXISTS `alpha`');
        $client->shouldReceive('query')->once()->with('DROP TABLE IF EXISTS `beta`');

        $repository = $this->repositoryMock();
        $repository->shouldReceive('getMigrations')->once()->andReturn([]);
        $repository->shouldReceive('getLastBatch')->once()->andReturn(2);
        $repository->shouldReceive('log')->once()->with($alpha, 3);
        $repository->shouldReceive('log')->once()->with($beta, 3);

        $this->assertSame([$alpha, $beta], $this->makeMigrator($client, $repository)->run());
    }

    public function testRunReturnsEmptyWhenNoPendingMigrations(): void
    {
        $one = $this->writeMigration('20240101_done_one', 'DoneOne', 'public function up(): void {}');
        $two = $this->writeMigration('20240101_done_two', 'DoneTwo', 'public function up(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getMigrations')->once()->andReturn([['migration' => $one], ['migration' => $two]]);

        $this->assertSame([], $this->makeMigrator($client, $repository)->run());
    }

    public function testRunSkipsAlreadyRanMigrations(): void
    {
        $keep = $this->writeMigration('20240101000001_keep_one', 'KeepOne', 'public function up(): void {}');
        $pending = $this->writeMigration('20240101000002_keep_two', 'KeepTwo', 'public function up(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getMigrations')->once()->andReturn([['migration' => $keep]]);
        $repository->shouldReceive('getLastBatch')->once()->andReturn(0);
        $repository->shouldReceive('log')->once()->with($pending, 1);

        $this->assertSame([$pending], $this->makeMigrator($client, $repository)->run());
    }

    public function testRunFailureWrapsOriginalException(): void
    {
        $ok = $this->writeMigration('20240101000001_ok_mig', 'OkMig', 'public function up(): void {}');
        $bad = $this->writeMigration('20240101000002_fail_mig', 'FailMig', 'public function up(): void { throw new \RuntimeException(\'boom\'); }');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getMigrations')->once()->andReturn([]);
        $repository->shouldReceive('getLastBatch')->once()->andReturn(0);
        $repository->shouldReceive('log')->once()->with($ok, 1);

        try {
            $this->makeMigrator($client, $repository)->run();
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString("Migration [{$bad}] failed", $e->getMessage());
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertSame($bad, $e->getSql());
            $this->assertSame(['batch' => 1, 'previously_run' => [$ok]], $e->getBindings());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('boom', $e->getPrevious()->getMessage());
        }
    }

    public function testRollbackThrowsWhenMigrationFileMissing(): void
    {
        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->once()->andReturn(1);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)
            ->andReturn([['migration' => 'ghost_migration']]);

        try {
            $this->makeMigrator($client, $repository)->rollback();
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ghost_migration.php', $e->getMessage());
            $this->assertSame($this->dir . '/ghost_migration.php', $e->getSql());
        }
    }

    public function testRunThrowsWrappedWhenMigrationClassMissing(): void
    {
        $file = $this->writeMigration('20240101_orphan_file', 'TotallyDifferentClass', 'public function up(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getMigrations')->once()->andReturn([]);
        $repository->shouldReceive('getLastBatch')->once()->andReturn(0);

        try {
            $this->makeMigrator($client, $repository)->run();
            $this->fail('expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString("Migration [{$file}] failed", $e->getMessage());
            $this->assertInstanceOf(QueryException::class, $e->getPrevious());
            $this->assertSame($this->dir . '/' . $file . '.php', $e->getPrevious()->getSql());
        }
    }

    public function testRollbackRunsDownAndDeletes(): void
    {
        $a = $this->writeMigration('20240101_roll_a', 'RollA', 'public function up(): void {} public function down(): void { $this->schema->drop(\'roll_a\'); }');
        $b = $this->writeMigration('20240101_roll_b', 'RollB', 'public function up(): void {} public function down(): void { $this->schema->drop(\'roll_b\'); }');

        $client = $this->clientMock();
        $client->shouldReceive('query')->once()->with('DROP TABLE IF EXISTS `roll_a`');
        $client->shouldReceive('query')->once()->with('DROP TABLE IF EXISTS `roll_b`');

        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->once()->andReturn(7);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(7)
            ->andReturn([['migration' => $a], ['migration' => $b]]);
        $repository->shouldReceive('delete')->once()->with($a);
        $repository->shouldReceive('delete')->once()->with($b);

        $this->assertSame([$a, $b], $this->makeMigrator($client, $repository)->rollback());
    }

    public function testRollbackWithSteps(): void
    {
        $a = $this->writeMigration('20240101_step_a', 'StepA', 'public function up(): void {}
    public function down(): void {}');
        $b = $this->writeMigration('20240101_step_b', 'StepB', 'public function up(): void {}
    public function down(): void {}');
        $c = $this->writeMigration('20240101_step_c', 'StepC', 'public function up(): void {}
    public function down(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->once()->andReturn(1);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(1)->andReturn([
            ['migration' => $a],
            ['migration' => $b],
            ['migration' => $c],
        ]);
        $repository->shouldReceive('delete')->once()->with($a);
        $repository->shouldReceive('delete')->once()->with($b);

        $this->assertSame([$a, $b], $this->makeMigrator($client, $repository)->rollback(2));
    }

    public function testRollbackReturnsEmptyWhenNoBatch(): void
    {
        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->once()->andReturn(0);
        $repository->shouldNotReceive('getMigrationsByBatch');

        $this->assertSame([], $this->makeMigrator($client, $repository)->rollback());
    }

    public function testRefreshWaitsForMutationsAfterRollback(): void
    {
        $a = $this->writeMigration('20240101_refresh_a', 'RefreshA', 'public function up(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->andReturn(4);
        $repository->shouldReceive('getMigrationsByBatch')->once()->with(4)->andReturn([['migration' => $a]]);
        $repository->shouldReceive('delete')->once()->with($a);
        $repository->shouldReceive('waitForMutations')->once();
        $repository->shouldReceive('getMigrations')->once()->andReturn([['migration' => $a]]);

        $this->makeMigrator($client, $repository)->refresh();
        $this->addToAssertionCount(1);
    }

    public function testRefreshSkipsWaitWhenNothingToRollback(): void
    {
        $a = $this->writeMigration('20240101_refresh_b', 'RefreshB', 'public function up(): void {}');

        $client = $this->clientMock();
        $repository = $this->repositoryMock();
        $repository->shouldReceive('getLastBatch')->andReturn(0);
        $repository->shouldNotReceive('getMigrationsByBatch');
        $repository->shouldNotReceive('delete');
        $repository->shouldNotReceive('waitForMutations');
        $repository->shouldReceive('getMigrations')->once()->andReturn([]);
        $repository->shouldReceive('log')->once()->with($a, 1);

        $this->makeMigrator($client, $repository)->refresh();
        $this->addToAssertionCount(1);
    }
}
