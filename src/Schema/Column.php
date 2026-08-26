<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Schema;

use Erikwang2013\ClickHouse\Support\Quoter;

class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public array $modifiers = [],
    ) {
    }

    public function toSql(): string
    {
        $sql = Quoter::column($this->name) . ' ' . $this->type;
        foreach ($this->modifiers as $modifier) {
            $sql .= ' ' . $modifier;
        }
        return $sql;
    }
}