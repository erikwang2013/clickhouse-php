<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Transport;

use Erikwang2013\ClickHouse\Exceptions\ConnectionException;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Support\Config;
use Erikwang2013\ClickHouse\Support\Quoter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

class HttpTransport implements TransportInterface
{
    private ?Client $httpClient;

    public function __construct(
        private Config $config,
    ) {
        $this->httpClient = null;
    }

    public function send(string $sql, array $bindings = []): mixed
    {
        $sql = $this->bindParams($sql, $bindings);
        $this->httpClient ??= $this->createClient();

        try {
            $response = $this->httpClient->post('', ['body' => $sql . ' FORMAT JSON']);
        } catch (ConnectException $e) {
            throw new ConnectionException(
                'ClickHouse connection failed: unable to connect to server.',
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode !== 200) {
            $truncated = mb_substr($body, 0, 500);
            if (mb_strlen($body) > 500) {
                $truncated .= '... (truncated)';
            }
            throw new QueryException(
                sprintf('ClickHouse query error [%d]: %s', $statusCode, $truncated),
                $sql,
                $bindings,
                $statusCode,
            );
        }

        $decoded = json_decode($body, true);
        return $decoded['data'] ?? $decoded;
    }

    public function close(): void
    {
        $this->httpClient = null;
    }

    private function createClient(): Client
    {
        return new Client([
            'base_uri' => sprintf(
                '%s://%s:%d/',
                $this->config->get('https', false) ? 'https' : 'http',
                $this->config->get('host', 'localhost'),
                $this->config->get('port', 8123),
            ),
            'headers' => [
                'X-ClickHouse-User' => $this->config->get('username', 'default'),
                'X-ClickHouse-Key' => $this->config->get('password', ''),
                'X-ClickHouse-Database' => $this->config->get('database', 'default'),
                'Content-Type' => 'text/plain',
            ],
            'timeout' => $this->config->get('timeout', 30),
            'http_errors' => false,
        ]);
    }

    private function bindParams(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $result = '';
        $index = 0;
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $result .= $char;
                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $result .= $sql[++$i];
                    }
                } elseif ($char === "'") {
                    $inString = false;
                }
                continue;
            }

            if ($char === "'") {
                $inString = true;
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

        return $result;
    }

    private function quoteValue(mixed $value): string
    {
        return Quoter::value($value);
    }
}