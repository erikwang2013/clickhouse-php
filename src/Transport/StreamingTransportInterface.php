<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Transport;

/**
 * 不经 JSON 整体解析的结果通道：stream() 逐行、sendRaw() 原样返回。
 *
 * 单独成接口而不是加进 TransportInterface，是为了不给第三方的传输实现造成 BC 破坏。
 */
interface StreamingTransportInterface
{
    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function sendStream(string $sql, array $bindings = []): \Generator;

    public function sendRaw(string $sql, array $bindings = []): string;
}
