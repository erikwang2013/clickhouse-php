<?php

namespace Erikwang2013\ClickHouse\ThinkPHP;

use think\Facade;

class Facade extends Facade
{
    protected static function getFacadeClass(): string
    {
        return 'clickhouse';
    }
}
