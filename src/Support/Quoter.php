<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Support;

use Erikwang2013\ClickHouse\Query\Expression;

class Quoter
{
    public static function value(mixed $value): string
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return "'" . addcslashes((string) $value, "\\'") . "'";
    }

    public static function table(string $table): string
    {
        return implode('.', array_map(fn($p) => "`$p`", explode('.', $table)));
    }
}
