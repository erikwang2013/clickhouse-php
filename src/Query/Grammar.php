<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Query;

use Erikwang2013\ClickHouse\Support\Quoter;

class Grammar
{
    public function compileSelect(Builder $builder): string
    {
        if (empty($builder->from)) {
            throw new \Erikwang2013\ClickHouse\Exceptions\QueryException('Table name is required.', '');
        }

        $columns = $builder->columns ?: ['*'];

        $sql = 'SELECT ' . implode(', ', $columns);
        $sql .= ' FROM ' . $this->quoteTable($builder->from);

        return $this->compileWheres($builder, $sql)
            . $this->compileGroups($builder)
            . $this->compileOrders($builder)
            . $this->compileLimit($builder);
    }

    public function compileDelete(Builder $builder): string
    {
        $sql = 'ALTER TABLE ' . $this->quoteTable($builder->from) . ' DELETE';
        return $this->compileWheres($builder, $sql);
    }

    private function compileWheres(Builder $builder, string $sql): string
    {
        if (empty($builder->wheres)) {
            return $sql;
        }

        $clauses = [];
        foreach ($builder->wheres as $where) {
            [$type, $column, $operator, $value, $boolean] = $where;

            $prefix = empty($clauses) ? '' : ($boolean === 'or' ? 'OR ' : 'AND ');

            if ($type === 'raw') {
                $clauses[] = $prefix . $column;
                continue;
            }

            if ($type === 'basic') {
                $clauses[] = $prefix . Quoter::column($column) . ' ' . $operator . ' ' . $this->quote($value);
            } elseif ($type === 'in') {
                $values = (array) $value;
                if ($values === []) {
                    $clauses[] = $prefix . ($operator === 'not in' ? '1 = 1' : '0 = 1');
                } else {
                    $quoted = implode(', ', array_map(fn($v) => $this->quote($v), $values));
                    $not = $operator === 'not in' ? 'NOT ' : '';
                    $clauses[] = $prefix . Quoter::column($column) . ' ' . $not . 'IN (' . $quoted . ')';
                }
            } elseif ($type === 'between') {
                if (count((array) $value) !== 2) {
                    throw new \InvalidArgumentException('whereBetween requires exactly two values.');
                }
                $not = $operator === 'not between' ? 'NOT ' : '';
                $clauses[] = $prefix . Quoter::column($column) . ' ' . $not . 'BETWEEN ' . $this->quote($value[0]) . ' AND ' . $this->quote($value[1]);
            } elseif ($type === 'null') {
                $not = $operator === 'not null' ? 'NOT ' : '';
                $clauses[] = $prefix . Quoter::column($column) . ' IS ' . $not . 'NULL';
            }
        }

        return $sql . ' WHERE ' . implode(' ', $clauses);
    }

    private function compileGroups(Builder $builder): string
    {
        if (empty($builder->groups)) {
            return '';
        }
        return ' GROUP BY ' . implode(', ', array_map(fn($c) => Quoter::column($c), $builder->groups));
    }

    private function compileOrders(Builder $builder): string
    {
        if (empty($builder->orders)) {
            return '';
        }
        $orders = [];
        foreach ($builder->orders as [$column, $direction]) {
            $orders[] = Quoter::column($column) . ' ' . $direction;
        }
        return ' ORDER BY ' . implode(', ', $orders);
    }

    private function compileLimit(Builder $builder): string
    {
        $sql = '';
        if ($builder->limit !== null) {
            $sql .= ' LIMIT ' . $builder->limit;
        }
        if ($builder->offset !== null) {
            $sql .= ' OFFSET ' . $builder->offset;
        }
        return $sql;
    }

    private function quoteTable(string $table): string
    {
        return Quoter::table($table);
    }

    public function quote(mixed $value): string
    {
        return Quoter::value($value);
    }
}