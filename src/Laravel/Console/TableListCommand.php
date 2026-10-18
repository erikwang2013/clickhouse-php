<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Laravel\Console;

use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Support\Pet;
use Illuminate\Console\Command;

class TableListCommand extends Command
{
    protected $signature = 'clickhouse:table-list {database? : Database name}';
    protected $description = 'List all tables in ClickHouse';

    public function handle(): int
    {
        foreach (Pet::lines() as $line) {
            $this->line($line);
        }
        $this->newLine();

        try {
            $database = $this->argument('database') ?? 'default';
            $tables = ClickHouse::schema()->getTables($database);
            $this->table(['Table'], array_map(fn($t) => [$t['name'] ?? $t], $tables));
        } catch (\Throwable $e) {
            $this->newLine();
            $this->printPetFace(Pet::CRY);
            throw $e;
        }

        $this->newLine();
        $this->printPetFace(Pet::CUTE);
        return 0;
    }

    private function printPetFace(string $form): void
    {
        foreach (Pet::face($form) as $line) {
            $this->line($line);
        }
    }
}