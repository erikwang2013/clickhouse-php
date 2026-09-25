<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Transport;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Exceptions\TimeoutException;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Support\Quoter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

class HttpTransport implements TransportInterface, StreamingTransportInterface
{
    /**
     * 只有会返回结果集的语句才追加 FORMAT JSON。
     * INSERT 等写语句追加会直接语法错误（ClickHouse: expected '(' before: 'FORMAT JSON'）。
     */
    private const RESULT_STATEMENTS = [
        'SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'CHECK', 'EXISTS',
    ];

    /** 用户自己写了 FORMAT 子句（含 FORMAT CSV 这类）时不再追加 */
    private const FORMAT_CLAUSE = '/\bFORMAT\s+[A-Za-z0-9_]+\s*;?\s*$/i';

    private const STREAM_CHUNK = 65536;

    private ?Client $httpClient;

    public function __construct(
        private Config $config,
    ) {
        $this->httpClient = null;
    }

    public function send(string $sql, array $bindings = []): mixed
    {
        $bound = $this->bindParams($sql, $bindings);
        $body = (string) $this->request($this->withFormat($bound), $sql, $bindings)->getBody();

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            // 非 JSON（例如用户自带 FORMAT CSV）：原样返回，交由上层决定怎么用
            return $body;
        }

        return $decoded['data'] ?? $decoded;
    }

    /**
     * 逐行流式读取结果，内存占用与结果集大小无关。用于大结果集。
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function sendStream(string $sql, array $bindings = []): \Generator
    {
        $bound = $this->bindParams($sql, $bindings);
        $response = $this->request($this->withFormat($bound, 'JSONEachRow'), $sql, $bindings, true);
        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(self::STREAM_CHUNK);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if ($line !== '') {
                    yield json_decode($line, true);
                }
            }
        }

        $buffer = trim($buffer);
        if ($buffer !== '') {
            yield json_decode($buffer, true);
        }
    }

    /**
     * 原样返回响应体（不解析 JSON）。用于 CSVRaw/TSV 之类自带 FORMAT 的查询。
     */
    public function sendRaw(string $sql, array $bindings = []): string
    {
        $bound = $this->bindParams($sql, $bindings);

        return (string) $this->request($this->withFormat($bound, null), $sql, $bindings)->getBody();
    }

    public function close(): void
    {
        $this->httpClient = null;
    }

    /**
     * 发送请求并把传输层异常映射成本库异常。
     *
     * 注意：不把原始 Guzzle 异常挂到 previous 上——它持有 Request 对象，
     * 里面有 X-ClickHouse-Key 等凭据头，任何递归 dump previous 的地方都会打出密码。
     * 只挂一个仅含消息的 RuntimeException，调试信息不丢、凭据不外泄。
     */
    private function request(string $statement, string $sql, array $bindings, bool $stream = false): ResponseInterface
    {
        $this->httpClient ??= $this->createClient();

        try {
            $options = ['body' => $statement];
            if ($stream) {
                $options['stream'] = true;
            }
            $response = $this->httpClient->post('', $options);
        } catch (ConnectException $e) {
            throw new ConnectionException(
                'ClickHouse connection failed: unable to connect to server.',
                0,
                new \RuntimeException($this->sanitize($e->getMessage())),
            );
        } catch (TransferException $e) {
            // 复用连接被重置、传输超时等
            $message = $this->sanitize($e->getMessage());
            $previous = new \RuntimeException($message);

            if (($e->getHandlerContext()['errno'] ?? null) === 28 || stripos($message, 'timed out') !== false) {
                throw new TimeoutException('ClickHouse request timed out: ' . $message, 0, $previous);
            }

            throw new ConnectionException('ClickHouse transport error: ' . $message, 0, $previous);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $body = (string) $response->getBody();
            $truncated = mb_substr($body, 0, 500);
            if (mb_strlen($body) > 500) {
                $truncated .= '... (truncated)';
            }

            throw new QueryException(
                sprintf('ClickHouse query error [%d]: %s', $statusCode, $this->sanitize($truncated)),
                $sql,
                $bindings,
                $statusCode,
            );
        }

        return $response;
    }

    /**
     * 按语句类型决定是否追加 FORMAT 子句。$format 为 null 表示完全不加。
     */
    private function withFormat(string $sql, ?string $default = 'JSON'): string
    {
        if ($default === null || !$this->needsFormatClause($sql)) {
            return $sql;
        }

        return $sql . ' FORMAT ' . $default;
    }

    private function needsFormatClause(string $sql): bool
    {
        $sql = ltrim($sql);

        // 跳过前导注释，否则 /* x */ SELECT 会被误判成非查询语句
        while ($sql !== '') {
            if (str_starts_with($sql, '--')) {
                $pos = strpos($sql, "\n");
                if ($pos === false) {
                    return false;
                }
                $sql = ltrim(substr($sql, $pos + 1));
                continue;
            }
            if (str_starts_with($sql, '/*')) {
                $pos = strpos($sql, '*/');
                if ($pos === false) {
                    return false;
                }
                $sql = ltrim(substr($sql, $pos + 2));
                continue;
            }
            break;
        }

        if (preg_match('/^([A-Za-z]+)/', $sql, $m) !== 1) {
            return false;
        }

        if (!in_array(strtoupper($m[1]), self::RESULT_STATEMENTS, true)) {
            return false;
        }

        // 用户自己写了 FORMAT，别叠加
        return preg_match(self::FORMAT_CLAUSE, $sql) !== 1;
    }

    private function createClient(): Client
    {
        return new Client([
            'base_uri' => sprintf(
                '%s://%s:%d/',
                $this->config->get('https', false) ? 'https' : 'http',
                $this->validHost((string) $this->config->get('host', 'localhost')),
                $this->validPort($this->config->get('port', 8123)),
            ),
            'headers' => [
                'X-ClickHouse-User' => $this->config->get('username', 'default'),
                'X-ClickHouse-Key' => $this->config->get('password', ''),
                'X-ClickHouse-Database' => $this->config->get('database', 'default'),
                'Content-Type' => 'text/plain',
            ],
            'timeout' => $this->config->get('timeout', 30),
            'http_errors' => false,
            // 跟随跳转会把 X-ClickHouse-* 凭据头转发给跳转目标（Guzzle 只清理
            // Authorization/Cookie），因此关闭重定向
            'allow_redirects' => false,
        ]);
    }

    /**
     * host 直接拼进 base_uri，含 : / @ ? 等字符可以改写目标主机与端口，
     * 这里按主机名/IPv4/带方括号的 IPv6 白名单校验。
     */
    private function validHost(string $host): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) === 1
            || preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $host) === 1) {
            return $host;
        }

        throw new ConnectionException("Invalid ClickHouse host [{$host}].");
    }

    private function validPort(mixed $port): int
    {
        $port = (int) $port;

        if ($port < 1 || $port > 65535) {
            throw new ConnectionException("Invalid ClickHouse port [{$port}].");
        }

        return $port;
    }

    /**
     * 去掉换行与控制字符，避免服务端返回的内容在日志里伪造行。
     */
    private function sanitize(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', $text));
    }

    /**
     * 替换 ? 占位符。需识别字符串字面量与标识符引用，避免把其中的 ? 当成占位符：
     * - 'string'：反斜杠转义
     * - `identifier` 与 "identifier"：双写自身转义
     */
    private function bindParams(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $result = '';
        $index = 0;
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $result .= $char;

                // ClickHouse 里字符串与引用标识符都用反斜杠转义（Quoter::column 也输出 \`）
                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $result .= $sql[++$i];
                    }
                } elseif ($char === $quote) {
                    // 双写自身同样表示转义，不算结束
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $result .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '`' || $char === '"') {
                $quote = $char;
                $result .= $char;
                continue;
            }

            if ($char === '?') {
                if (!array_key_exists($index, $bindings)) {
                    throw new QueryException("Missing binding for placeholder #{$index}.", $sql, $bindings);
                }
                $result .= $this->quoteValue($bindings[$index++]);
                continue;
            }

            $result .= $char;
        }

        if ($index < count($bindings)) {
            throw new QueryException(
                sprintf('Too many bindings: %d given, %d placeholder(s) in the query.', count($bindings), $index),
                $sql,
                $bindings,
            );
        }

        return $result;
    }

    private function quoteValue(mixed $value): string
    {
        return Quoter::value($value);
    }
}
