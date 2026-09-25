<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Query;

use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Support\Quoter;

class Grammar
{
    public function compileSelect(Builder $builder): string
    {
        if (empty($builder->from)) {
            throw new QueryException('Table name is required.', '');
        }

        $columns = $builder->columns ?: ['*'];

        $sql = 'SELECT ' . implode(', ', array_map(fn($column) => $this->quoteColumn($column), $columns));
        $sql .= ' FROM ' . $this->quoteTable($builder->from);
        if ($builder->final) {
            $sql .= ' FINAL';
        }
        if ($builder->sample !== null) {
            // 用 Quoter 而不是 (string)：默认 precision=14 会把 0.3333333333333333 截成 0.33333333333333
            $sql .= ' SAMPLE ' . Quoter::value($builder->sample);
        }

        return $sql
            . $this->compileConditions($builder->prewheres, 'PREWHERE')
            . $this->compileConditions($builder->wheres, 'WHERE')
            . $this->compileGroups($builder)
            . $this->compileConditions($builder->havings, 'HAVING')
            . $this->compileOrders($builder)
            . $this->compileLimit($builder)
            . $this->compileSettings($builder);
    }

    public function compileDelete(Builder $builder): string
    {
        $sql = 'ALTER TABLE ' . $this->quoteTable($builder->from) . ' DELETE';
        if (empty($builder->wheres)) {
            throw new QueryException('Refusing to delete without a WHERE clause.', $sql);
        }
        return $sql . $this->compileConditions($builder->wheres, 'WHERE');
    }

    /**
     * Quote a select column: expressions pass through, wildcards stay bare,
     * aliases are quoted on both sides, everything else is an identifier.
     */
    private function quoteColumn(mixed $column): string
    {
        if ($column instanceof Expression) {
            return $column->getValue();
        }

        $column = (string) $column;
        if ($column === '*' || str_ends_with($column, '.*')) {
            return $column;
        }
        if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $column, $matches) === 1) {
            return $this->quoteColumn(trim($matches[1])) . ' as ' . Quoter::column(trim($matches[2]));
        }
        return Quoter::column($column);
    }

    private function compileConditions(array $conditions, string $keyword): string
    {
        if (empty($conditions)) {
            return '';
        }

        $clauses = [];
        foreach ($conditions as $where) {
            [$type, $column, $operator, $value, $boolean] = $where;

            $prefix = empty($clauses) ? '' : ($boolean === 'or' ? 'OR ' : 'AND ');

            if ($type === 'raw') {
                $clauses[] = $prefix . $column;
                continue;
            }

            if ($type === 'basic') {
                if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
                    $null = $operator === '=' ? 'NULL' : 'NOT NULL';
                    $clauses[] = $prefix . Quoter::column($column) . ' IS ' . $null;
                } else {
                    $clauses[] = $prefix . Quoter::column($column) . ' ' . $operator . ' ' . $this->quote($value);
                }
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

        return ' ' . $keyword . ' ' . implode(' ', $clauses);
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

    private function compileSettings(Builder $builder): string
    {
        if (empty($builder->settings)) {
            return '';
        }
        $pairs = [];
        foreach ($builder->settings as $key => $value) {
            $pairs[] = Quoter::column((string) $key) . ' = ' . $this->quote($value);
        }
        return ' SETTINGS ' . implode(', ', $pairs);
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
