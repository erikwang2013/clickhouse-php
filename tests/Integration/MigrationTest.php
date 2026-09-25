<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Integration;

use Erikwang2013\ClickHouse\Migration\Migrator;
use Erikwang2013\ClickHouse\Migration\Repository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs the migrator against a real server, using a throwaway directory for
 * migration files and a throwaway repository table.
 */
#[Group('integration')]
class MigrationTest extends IntegrationTestCase
{
    private string $dir = '';
    private string $suffix = '';
    private string $repositoryTable = '';
    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ch_it_migration_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);

        // Unique so repeated runs in the same process cannot collide on the
        // migration class names the migrator derives from the file names.
        $this->suffix = bin2hex(random_bytes(3));
        $this->repositoryTable = $this->table('it_migrations');
        $this->repository = new Repository(static::$client, $this->repositoryTable);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testInstallCreatesTheRepositoryTable(): void
    {
        $this->assertFalse(static::$schema->hasTable($this->repositoryTable));

        $this->migrator()->install();

        $this->assertTrue(static::$schema->hasTable($this->repositoryTable));
        $this->assertSame([], $this->repository->getMigrations());
        $this->assertSame(0, $this->repository->getLastBatch());
    }

    public function testRunAppliesPendingMigrationsOnlyOnce(): void
    {
        $first = $this->table('it_mig_target');
        $second = $this->table('it_mig_target');

        $files = [
            $this->writeMigration(1, 'first', $first),
            $this->writeMigration(2, 'second', $second),
        ];

        $migrator = $this->migrator();
        $migrator->install();

        $this->assertSame($files, $migrator->run());
        $this->assertTrue(static::$schema->hasTable($first));
        $this->assertTrue(static::$schema->hasTable($second));
        $this->assertSame(1, $this->repository->getLastBatch());
        $this->assertSame($files, array_column($this->repository->getMigrations(), 'migration'));

        // Everything is already applied, so a second run is a no-op.
        $this->assertSame([], $migrator->run());
        $this->assertSame(1, $this->repository->getLastBatch());
    }

    public function testRunWithoutMigrationFilesDoesNothing(): void
    {
        $migrator = $this->migrator();
        $migrator->install();

        $this->assertSame([], $migrator->run());
    }

    public function testRollbackDropsTheMigratedTablesAndClearsTheRepository(): void
    {
        $first = $this->table('it_mig_target');
        $files = [$this->writeMigration(1, 'first', $first)];

        $migrator = $this->migrator();
        $migrator->install();
        $migrator->run();

        $this->assertSame($files, $migrator->rollback());
        $this->assertFalse(static::$schema->hasTable($first));

        // The repository delete is an asynchronous mutation.
        $this->repository->waitForMutations();

        $this->assertSame([], $this->repository->getMigrations());
        $this->assertSame([], $migrator->rollback());
    }

    public function testRefreshRollsBackAndReapplies(): void
    {
        $first = $this->table('it_mig_target');
        $files = [$this->writeMigration(1, 'first', $first)];

        $migrator = $this->migrator();
        $migrator->install();
        $migrator->run();

        $migrator->refresh();

        $this->assertTrue(static::$schema->hasTable($first));
        $this->assertSame($files, array_column($this->repository->getMigrations(), 'migration'));
    }

    public function testRollbackHonoursTheStepLimit(): void
    {
        $first = $this->table('it_mig_target');
        $second = $this->table('it_mig_target');

        $this->writeMigration(1, 'first', $first);
        $this->writeMigration(2, 'second', $second);

        $migrator = $this->migrator();
        $migrator->install();
        $migrator->run();

        // Both files ran in batch 1, ordered by file name; one step unwinds the
        // last one only.
        $this->assertCount(1, $migrator->rollback(1));
        $this->assertTrue(static::$schema->hasTable($first));
    }

    private function migrator(): Migrator
    {
        return new Migrator(static::$client, $this->repository, $this->dir);
    }

    /**
     * Writes a migration that creates $table on up() and drops it on down().
     *
     * @return string the file name the migrator will report
     */
    private function writeMigration(int $sequence, string $label, string $table): string
    {
        $file = sprintf('%04d_create_it_%s_%s', $sequence, $this->suffix, $label);

        // Same derivation the migrator uses, so file name and class agree.
        $class = str_replace('_', '', ucwords(preg_replace('/^\d+_/', '', $file), '_'));

        $php = <<<PHP
        <?php

        class {$class} extends \\Erikwang2013\\ClickHouse\\Migration\\Migration
        {
            public function up(): void
            {
                \$this->schema->create('{$table}', function (\$blueprint) {
                    \$blueprint->uint32('id');
                    \$blueprint->string('name');
                    \$blueprint->engine('MergeTree')->orderBy(['id']);
                });
            }

            public function down(): void
            {
                \$this->schema->drop('{$table}');
            }
        }

        PHP;

        file_put_contents($this->dir . '/' . $file . '.php', $php);

        return $file;
    }
}
