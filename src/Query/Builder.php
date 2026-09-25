<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Query;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Support\Quoter;

class Builder
{
    public array $columns = [];
    public string $from = '';
    public array $wheres = [];
    public array $prewheres = [];
    public array $havings = [];
    public array $orders = [];
    public array $groups = [];
    public array $settings = [];
    public bool $final = false;
    public ?float $sample = null;
    public ?int $limit = null;
    public ?int $offset = null;

    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like', 'ilike', 'not ilike', 'in', 'not in', 'between', 'not between', 'glob', 'not glob'];

    public function __construct(
        private ClientInterface $client,
        private ?Grammar $grammar = null,
    ) {
        $this->grammar ??= new Grammar();
    }

    public function table(string $table): static
    {
        $this->from = $table;
        return $this;
    }

    public function from(string $table): static
    {
        return $this->table($table);
    }

    public function select(string|array $columns = ['*']): static
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    public function selectRaw(string $expression): static
    {
        $this->columns[] = new Expression($expression);
        return $this;
    }

    public function final(): static
    {
        $this->final = true;
        return $this;
    }

    public function sample(float $ratio): static
    {
        $this->sample = $ratio;
        return $this;
    }

    public function settings(array $settings): static
    {
        $this->settings = array_merge($this->settings, $settings);
        return $this;
    }

    public function where(string|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        $this->wheres[] = $this->makeCondition($column, $operator, $value, $boolean);
        return $this;
    }

    public function prewhere(string|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        $this->prewheres[] = $this->makeCondition($column, $operator, $value, 'and');
        return $this;
    }

    public function having(string|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        $this->havings[] = $this->makeCondition($column, $operator, $value, 'and');
        return $this;
    }

    private function makeCondition(string|Expression $column, mixed $operator, mixed $value, string $boolean): array
    {
        $operator = strtolower((string) $operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsupported operator: $operator");
        }
        $type = match (true) {
            in_array($operator, ['in', 'not in'], true) => 'in',
            in_array($operator, ['between', 'not between'], true) => 'between',
            default => 'basic',
        };
        return [$type, $column, $operator, $value, $boolean];
    }

    public function orWhere(string|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        return $this->where($column, $operator, $value, 'or');
    }

    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['in', $column, 'in', $values, 'and'];
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['in', $column, 'not in', $values, 'and'];
        return $this;
    }

    public function whereBetween(string $column, array $values): static
    {
        $this->wheres[] = ['between', $column, 'between', $values, 'and'];
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['null', $column, 'null', null, 'and'];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['null', $column, 'not null', null, 'and'];
        return $this;
    }

    public function whereRaw(string $sql, string $boolean = 'and'): static
    {
        $this->wheres[] = ['raw', $sql, null, null, $boolean];
        return $this;
    }

    /**
     * 原生 HAVING 片段。having() 会把第一个参数当标识符加反引号，
     * 因此聚合条件（如 count() > 100）要用这里，或传 new Expression('count()')。
     * 与 whereRaw 一样是原生 SQL 通道，勿传用户输入。
     */
    public function havingRaw(string $sql, string $boolean = 'and'): static
    {
        $this->havings[] = ['raw', $sql, null, null, $boolean];
        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: $direction");
        }
        $this->orders[] = [$column, $direction];
        return $this;
    }

    public function groupBy(string|Expression ...$columns): static
    {
        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): Result
    {
        $sql = $this->grammar->compileSelect($this);
        return $this->client->query($sql);
    }

    public function first(): mixed
    {
        $original = $this->limit;
        try {
            return $this->limit(1)->get()->first();
        } finally {
            $this->limit = $original;
        }
    }

    public function count(): int
    {
        return (int) $this->aggregate('count');
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate('sum', $column);
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate('avg', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    private function aggregate(string $fn, ?string $column = null): mixed
    {
        $original = $this->columns;
        $target = $column === null || $column === '*' || str_ends_with($column, '.*') ? '*' : Quoter::column($column);
        $this->columns = [new Expression("$fn($target) as aggregate")];
        try {
            return $this->get()->first()['aggregate'] ?? null;
        } finally {
            $this->columns = $original;
        }
    }

    public function insert(array $data): int
    {
        return $this->client->insert($this->from, $data);
    }

    public function delete(): int
    {
        $sql = $this->grammar->compileDelete($this);
        $result = $this->client->query($sql);
        return $result->count();
    }

    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }
}
