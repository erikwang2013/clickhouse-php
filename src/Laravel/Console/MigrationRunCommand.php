<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Laravel\Console;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Migration\Migrator;
use Erikwang2013\ClickHouse\Migration\Repository;
use Erikwang2013\ClickHouse\Support\Pet;
use Illuminate\Console\Command;

class MigrationRunCommand extends Command
{
    protected $signature = 'clickhouse:migration:run';
    protected $description = 'Run pending ClickHouse migrations';

    public function handle(): int
    {
        foreach (Pet::lines() as $line) {
            $this->line($line);
        }
        $this->newLine();

        try {
            $config = config('clickhouse.migrations');
            $repository = new Repository(
                ClickHouse::getManager()->connection(),
                $config['table'] ?? 'clickhouse_migrations',
            );
            $migrator = new Migrator(ClickHouse::getManager()->connection(), $repository, $config['path']);
            $run = $migrator->run();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->printPetFace(Pet::CRY);
            throw $e;
        }

        if (empty($run)) {
            $this->info('No pending migrations.');
            $this->newLine();
            $this->printPetFace(Pet::SMILE);
        } else {
            foreach ($run as $migration) {
                $this->line("  <info>Migrated:</info> $migration");
            }
            $this->newLine();
            $this->printPetFace(Pet::JOY);
        }
        return 0;
    }

    private function printPetFace(string $form): void
    {
        foreach (Pet::face($form) as $line) {
            $this->line($line);
        }
    }
}