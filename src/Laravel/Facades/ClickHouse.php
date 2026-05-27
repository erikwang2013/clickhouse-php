<?php

namespace Erikwang2013\ClickHouse\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

class ClickHouse extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'clickhouse';
    }
}
