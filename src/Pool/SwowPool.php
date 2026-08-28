<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Pool;

class SwowPool extends AbstractPool
{
    protected function newChannel(int $capacity): mixed
    {
        return new \Swow\Channel($capacity);
    }

    protected function push(mixed $client, float $timeout): bool
    {
        return $this->channel->push($client, (int) ($timeout * 1000));
    }

    protected function pop(float $timeout): mixed
    {
        return $this->channel->pop((int) ($timeout * 1000));
    }

    protected function idleCount(): int
    {
        return $this->channel->getLength();
    }
}
