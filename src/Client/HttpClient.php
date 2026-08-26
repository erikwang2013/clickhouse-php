<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Query\Result;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Support\Quoter;
use Erikwang2013\ClickHouse\Transport\TransportInterface;

class HttpClient implements ClientInterface
{
    public function __construct(
        private TransportInterface $transport,
        private Config $config,
    ) {
    }

    public function query(string $sql, array $bindings = []): Result
    {
        $result = $this->transport->send($sql, $bindings);

        if (is_array($result)) {
            if (isset($result['rows'])) {
                return new Result($result['rows'], $result['rows_before_limit_at_least'] ?? null, $result['meta'] ?? null);
            }
            return new Result($result);
        }

        return new Result([]);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->toArray();
    }

    public function insert(string $table, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $isSingle = isset($data[0]) && is_array($data[0]) ? false : true;
        $rows = $isSingle ? [$data] : $data;

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(fn($c) => Quoter::column((string) $c), $columns));

        $tableQuoted = Quoter::table($table);
        $inserted = 0;

        foreach (array_chunk($rows, 1000) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $escaped = array_map(fn($v) => $this->escape($v), array_values($row));
                $values[] = '(' . implode(', ', $escaped) . ')';
            }

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $tableQuoted,
                $columnList,
                implode(', ', $values),
            );

            $this->query($sql);
            $inserted += count($values);
        }

        return $inserted;
    }

    public function ping(): bool
    {
        try {
            $this->transport->send('SELECT 1', []);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function escape(mixed $value): string
    {
        return Quoter::value($value);
    }
}