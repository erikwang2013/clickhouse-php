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

    /**
     * 注意：$type（及 $modifiers）是原生 SQL 片段，用于 array('tags', 'String') 这类开放类型，
     * 不做转义，切勿把用户输入直接传进来。
     */
    public function toSql(): string
    {
        $sql = Quoter::column($this->name) . ' ' . $this->type;
        foreach ($this->modifiers as $modifier) {
            $sql .= ' ' . $modifier;
        }
        return $sql;
    }
}