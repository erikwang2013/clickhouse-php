<?php

namespace Erikwang2013\ClickHouse\Webman;

class Install
{
    public static function install(): void
    {
    }

    public static function configPath(): string
    {
        return __DIR__ . '/config/clickhouse.php';
    }
}
