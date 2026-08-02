<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Migration;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Support\Quoter;

class Repository
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $table = 'migrations',
    ) {
    }

    private function quoteTable(): string
    {
        return Quoter::table($this->table);
    }

    public function createRepository(): void
    {
        $table = $this->quoteTable();
        $this->client->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                migration String,
                batch UInt32,
                executed_at DateTime DEFAULT now()
            ) ENGINE = MergeTree()
            ORDER BY migration
        ");
    }

    public function getMigrations(): array
    {
        $table = $this->quoteTable();
        return $this->client->select("SELECT migration FROM {$table} ORDER BY migration");
    }

    public function getLastBatch(): int
    {
        $table = $this->quoteTable();
        $result = $this->client->select("SELECT max(batch) as batch FROM {$table}");
        return (int) ($result[0]['batch'] ?? 0);
    }

    public function log(string $migration, int $batch): void
    {
        $this->client->insert($this->table, ['migration' => $migration, 'batch' => $batch]);
    }

    public function delete(string $migration): void
    {
        $table = $this->quoteTable();
        $this->client->query("ALTER TABLE {$table} DELETE WHERE migration = ?", [$migration]);
    }

    public function getMigrationsByBatch(int $batch): array
    {
        $table = $this->quoteTable();
        return $this->client->select(
            "SELECT migration FROM {$table} WHERE batch = ? ORDER BY migration DESC",
            [$batch],
        );
    }
}