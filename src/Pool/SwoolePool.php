<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Pool;

class SwoolePool extends AbstractPool
{
    protected function newChannel(int $capacity): mixed
    {
        return new \Swoole\Coroutine\Channel($capacity);
    }

    protected function push(mixed $client, float $timeout): bool
    {
        return $this->channel->push($client, $timeout);
    }

    protected function pop(float $timeout): mixed
    {
        return $this->channel->pop($timeout);
    }

    protected function idleCount(): int
    {
        return $this->channel->stats()['queue_num'];
    }
}
