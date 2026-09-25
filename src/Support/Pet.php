<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Support;

/**
 * 项目宠物的终端形象：黄屋顶的小房子（ClickHouse）+ 象耳象鼻（PHP）+ 脚下柱状图（列式存储）。
 * 只用于命令行横幅，不参与任何查询逻辑。矢量版见 docs/pet.svg。
 */
final class Pet
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            '       /\\',
            '      /  \\',
            '     /____\\',
            '     |(  o|\\_',
            '     |    |  )',
            '     |__[]|/',
            "     '----'",
            '    _|_ _|_ _|_',
        ];
    }
}
