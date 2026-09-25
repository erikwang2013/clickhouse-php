<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Query\Result;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Support\Quoter;
use Erikwang2013\ClickHouse\Transport\StreamingTransportInterface;
use Erikwang2013\ClickHouse\Transport\TransportInterface;
use Psr\Log\LoggerInterface;

class HttpClient implements ClientInterface, StreamingClientInterface
{
    /** 单条 INSERT 的行数上限；批量写入按此分片 */
    private const INSERT_CHUNK_SIZE = 1000;

    /** 日志里 SQL 的最大长度，避免批量插入把整条语句写进日志 */
    private const LOG_SQL_LIMIT = 2000;

    public function __construct(
        private TransportInterface $transport,
        private Config $config,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function query(string $sql, array $bindings = []): Result
    {
        $this->logger?->debug($this->truncate($sql), ['bindings' => $bindings]);

        $result = $this->transport->send($sql, $bindings);

        if (is_array($result)) {
            if (isset($result['rows'])) {
                return new Result($result['rows'], $result['rows_before_limit_at_least'] ?? null, $result['meta'] ?? null);
            }
            return new Result($result);
        }

        if (is_string($result) && $result !== '') {
            // 用户自带 FORMAT（CSV/TSV 等）时响应不是 JSON，静默返回空集会让调用方以为没有数据
            throw new QueryException(
                'Query returned a non-JSON body. Use raw() (or stream()) when the statement specifies its own FORMAT.',
                $sql,
                $bindings,
            );
        }

        return new Result([]);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->toArray();
    }

    public function stream(string $sql, array $bindings = []): \Generator
    {
        $this->logger?->debug($this->truncate($sql), ['bindings' => $bindings]);

        if (!$this->transport instanceof StreamingTransportInterface) {
            throw new QueryException(
                'The configured transport does not support streaming; use query() instead.',
                $sql,
                $bindings,
            );
        }

        yield from $this->transport->sendStream($sql, $bindings);
    }

    public function raw(string $sql, array $bindings = []): string
    {
        $this->logger?->debug($this->truncate($sql), ['bindings' => $bindings]);

        if (!$this->transport instanceof StreamingTransportInterface) {
            throw new QueryException(
                'The configured transport does not support raw responses; use query() instead.',
                $sql,
                $bindings,
            );
        }

        return $this->transport->sendRaw($sql, $bindings);
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

        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $escaped = array_map(fn($v) => $this->escape($v), $this->orderRow($row, $columns, $table));
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

    /**
     * 列名取首行，之后每行都按这个顺序取值。行与行键序不同时若按位置硬塞会静默写错列，
     * 因此这里要求同批各行的列集合一致，不一致直接报错。
     */
    private function orderRow(mixed $row, array $columns, string $table): array
    {
        if (!is_array($row)) {
            throw new \InvalidArgumentException(
                sprintf('Insert row for table [%s] must be an array, %s given.', $table, get_debug_type($row))
            );
        }

        $missing = array_diff($columns, array_keys($row));
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Insert row for table [%s] is missing column(s) [%s]; every row must have the same columns.',
                $table,
                implode(', ', $missing),
            ));
        }

        $extra = array_diff(array_keys($row), $columns);
        if ($extra !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Insert row for table [%s] has unexpected column(s) [%s]; every row must have the same columns.',
                $table,
                implode(', ', $extra),
            ));
        }

        return array_map(fn($c) => $row[$c], $columns);
    }

    private function truncate(string $sql): string
    {
        $length = strlen($sql);

        if ($length <= self::LOG_SQL_LIMIT) {
            return $sql;
        }

        return substr($sql, 0, self::LOG_SQL_LIMIT) . sprintf('... (%d bytes total)', $length);
    }

    private function escape(mixed $value): string
    {
        return Quoter::value($value);
    }
}
