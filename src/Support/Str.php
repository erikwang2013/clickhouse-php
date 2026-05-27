<?php

namespace Erikwang2013\ClickHouse\Support;

class Str
{
    public static function snake(string $value): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($value)));
    }
}
