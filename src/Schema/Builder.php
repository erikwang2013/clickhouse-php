<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Schema;

use Erikwang2013\ClickHouse\Client\ClientInterface;

class Builder
{
    public function __construct(
        private ClientInterface $client,
        private ?Grammar $grammar = null,
    ) {
        $this->grammar ??= new Grammar();
    }

    public function create(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint();
        $callback($blueprint);

        if (empty($blueprint->columns)) {
            throw new \InvalidArgumentException(
                "Cannot create table [{$table}]: the blueprint defines no columns.",
            );
        }

        $sql = $this->grammar->compileCreate($table, $blueprint);
        $this->client->query($sql);
    }

    public function drop(string $table): void
    {
        $this->client->query($this->grammar->compileDrop($table));
    }

    public function alter(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint();
        $callback($blueprint);

        if (empty($blueprint->columns)) {
            throw new \InvalidArgumentException(
                "Cannot alter table [{$table}]: the blueprint defines no columns"
                . ' (alter() only supports ADD COLUMN; engine/ttl/settings are ignored here).',
            );
        }

        $this->client->query($this->grammar->compileAlterAdd($table, $blueprint));
    }

    public function hasTable(string $table): bool
    {
        $row = $this->client->query($this->grammar->compileTableExists($table))->first();
        return (int) ($row['c'] ?? 0) > 0;
    }

    public function getTables(string $database = 'default'): array
    {
        return $this->client->select($this->grammar->compileTableList($database));
    }

    public function getTableInfo(string $table): array
    {
        return $this->client->select($this->grammar->compileTableInfo($table));
    }
}