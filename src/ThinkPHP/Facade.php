<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\ThinkPHP;

use think\Facade as ThinkFacade;

class Facade extends ThinkFacade
{
    protected static function getFacadeClass(): string
    {
        return 'clickhouse';
    }
}