<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Support;

/**
 * 项目宠物的终端形象：圆滚滚的仓鼠——PHP 紫围巾 + ClickHouse 小房子吊牌。
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
            '       .---.',
            '      / o o \\',
            '      |  w  |',
            '     ~\\_____/~',
            '      /|   |\\',
            '     / |[ ]| \\',
            '    (__|___|__)',
            '      _______',
        ];
    }
}
