<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Laravel\Console;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Migration\Repository;
use Erikwang2013\ClickHouse\Support\Pet;
use Illuminate\Console\Command;

class MigrationInstallCommand extends Command
{
    protected $signature = 'clickhouse:migration:install';
    protected $description = 'Create the ClickHouse migration repository';

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
            $repository->createRepository();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->printPetFace(Pet::CRY);
            throw $e;
        }

        $this->info('ClickHouse migration table created successfully.');
        $this->newLine();
        $this->printPetFace(Pet::JOY);
        return 0;
    }

    private function printPetFace(string $form): void
    {
        foreach (Pet::face($form) as $line) {
            $this->line($line);
        }
    }
}