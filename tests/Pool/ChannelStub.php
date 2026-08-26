<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Pool;

/**
 * Emulates the channel API used by SwoolePool/SwowPool/WorkermanPool.
 * push()/pop() return false on full/empty (timeout), matching Swoole semantics.
 */
class ChannelStub
{
    /** @var list<mixed> */
    private array $items = [];
    private bool $closed = false;

    /** @var list<string> */
    private static array $aliased = [];

    public function __construct(private readonly int $capacity)
    {
    }

    /**
     * Alias this stub to $target unless the real class is already loaded.
     * Returns false when the real class exists, so callers can fall back.
     */
    public static function register(string $target): bool
    {
        if (in_array($target, self::$aliased, true)) {
            return true;
        }
        if (class_exists($target, false)) {
            return false;
        }
        class_alias(self::class, $target);
        self::$aliased[] = $target;
        return true;
    }

    public function push(mixed $data, int|float $timeout = -1): bool
    {
        if ($this->closed || count($this->items) >= $this->capacity) {
            return false;
        }
        $this->items[] = $data;
        return true;
    }

    public function pop(int|float $timeout = -1): mixed
    {
        // like real swoole channels, close() blocks push but pop still drains
        if ($this->items === []) {
            return false;
        }
        return array_shift($this->items);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function getLength(): int
    {
        return count($this->items);
    }

    public function stats(): array
    {
        return ['queue_num' => count($this->items)];
    }
}
