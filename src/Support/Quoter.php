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
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new \InvalidArgumentException('Cannot quote NAN or INF as a ClickHouse literal.');
            }

            // 不能用默认 precision(14) 转字符串，会静默丢精度（1.2345678901234567 → 1.2345678901235）。
            // json_encode 在 serialize_precision=-1 下输出最短往返表示。
            $encoded = json_encode($value);

            return $encoded === false ? sprintf('%.17G', $value) : $encoded;
        }
        if (is_array($value)) {
            return '[' . implode(', ', array_map([self::class, 'value'], array_values($value))) . ']';
        }
        if (is_object($value)) {
            if (!method_exists($value, '__toString')) {
                throw new \InvalidArgumentException(
                    'Cannot quote object of class ' . get_class($value) . ' as a ClickHouse literal.'
                );
            }
            $value = (string) $value;
        }
        if (is_resource($value)) {
            throw new \InvalidArgumentException('Cannot quote a resource as a ClickHouse literal.');
        }

        return "'" . addcslashes((string) $value, "\\'") . "'";
    }

    public static function table(string $table): string
    {
        return self::column($table);
    }

    /**
     * 标识符引用。传 Expression 时原样返回（原生 SQL 通道），
     * 这样 where/having/orderBy/groupBy 里的函数与子查询写法才走得通。
     */
    public static function column(mixed $id): string
    {
        if ($id instanceof Expression) {
            return $id->getValue();
        }

        return implode('.', array_map(
            fn($p) => '`' . str_replace('`', '\\`', $p) . '`',
            explode('.', (string) $id),
        ));
    }
}
