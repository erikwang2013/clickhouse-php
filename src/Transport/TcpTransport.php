<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Transport;

use Erikwang2013\ClickHouse\Support\Config;

class TcpTransport implements TransportInterface
{
    private mixed $socket = null;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function send(string $sql, array $bindings = []): mixed
    {
        throw new \RuntimeException(
            'Native TCP transport not yet implemented. Use HTTP driver.'
        );
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}