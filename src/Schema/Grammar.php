<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Schema;

use Erikwang2013\ClickHouse\Support\Quoter;

class Grammar
{
    public function compileCreate(string $table, Blueprint $blueprint): string
    {
        $columns = array_map(fn(Column $c) => $c->toSql(), $blueprint->columns);
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quoteTable($table) . ' (' . implode(', ', $columns) . ')';
        $sql .= ' ENGINE = ' . ($blueprint->getEngine() ?? 'MergeTree');

        if ($partitionBy = $blueprint->getPartitionBy()) {
            $sql .= ' PARTITION BY ' . $partitionBy;
        }
        if ($orderBy = $blueprint->getOrderBy()) {
            $sql .= ' ORDER BY (' . implode(', ', $orderBy) . ')';
        } else {
            $sql .= ' ORDER BY tuple()';
        }
        if ($primaryKey = $blueprint->getPrimaryKey()) {
            $sql .= ' PRIMARY KEY ' . $primaryKey;
        }
        if ($sampleBy = $blueprint->getSampleBy()) {
            $sql .= ' SAMPLE BY ' . $sampleBy;
        }
        if ($ttl = $blueprint->getTtl()) {
            $sql .= ' TTL ' . $ttl;
        }
        if ($settings = $blueprint->getSettings()) {
            $pairs = [];
            foreach ($settings as $k => $v) {
                $pairs[] = "$k = $v";
            }
            $sql .= ' SETTINGS ' . implode(', ', $pairs);
        }
        return $sql;
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table);
    }

    public function compileAlterAdd(string $table, Blueprint $blueprint): string
    {
        $columns = array_map(fn(Column $c) => 'ADD COLUMN ' . $c->toSql(), $blueprint->columns);
        return 'ALTER TABLE ' . $this->quoteTable($table) . ' ' . implode(', ', $columns);
    }

    public function compileTableExists(string $table): string
    {
        $pos = strrpos($table, '.');
        if ($pos === false) {
            $database = 'default';
            $name = $table;
        } else {
            $database = substr($table, 0, $pos);
            $name = substr($table, $pos + 1);
        }
        return 'SELECT count() AS c FROM system.tables WHERE database = ' . Quoter::value($database)
            . ' AND name = ' . Quoter::value($name);
    }

    public function compileTableList(string $database = 'default'): string
    {
        $db = implode('.', array_map(fn($p) => "`$p`", explode('.', $database)));
        return "SHOW TABLES FROM $db";
    }

    public function compileTableInfo(string $table): string
    {
        return 'DESCRIBE TABLE ' . $this->quoteTable($table);
    }

    private function quoteTable(string $table): string
    {
        return Quoter::table($table);
    }
}