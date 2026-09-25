<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Client;

/**
 * 大数据量/非 JSON 结果的出口。
 *
 * 单独成接口而不是加进 ClientInterface，是为了不给第三方的客户端实现造成 BC 破坏；
 * 需要时用 `$client instanceof StreamingClientInterface` 判断。
 */
interface StreamingClientInterface
{
    /**
     * 逐行流式读取结果，内存与结果集大小无关。
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $sql, array $bindings = []): \Generator;

    /**
     * 原样返回响应体（不解析 JSON），用于自带 FORMAT 的查询。
     */
    public function raw(string $sql, array $bindings = []): string;
}
