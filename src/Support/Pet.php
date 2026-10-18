<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * 项目宠物的矢量与终端形态。
 *
 * 矢量文件位于 docs/pet-expressions/，只用于展示，不参与任何查询逻辑。
 */
final class Pet
{
    public const SMILE = 'smile';
    public const JOY = 'joy';
    public const CRY = 'cry';
    public const DEPRESSED = 'depressed';
    public const TERRIFIED = 'terrified';
    public const ANGRY = 'angry';
    public const COQUETTISH = 'coquettish';
    public const CUTE = 'cute';
    public const PLEASING = 'pleasing';
    public const KNOCKING = 'knocking';
    public const FACEPALM = 'facepalm';
    public const TONGUE = 'tongue';

    public const DEFAULT_FORM = self::SMILE;

    public const FORMS = [
        self::SMILE,
        self::JOY,
        self::CRY,
        self::DEPRESSED,
        self::TERRIFIED,
        self::ANGRY,
        self::COQUETTISH,
        self::CUTE,
        self::PLEASING,
        self::KNOCKING,
        self::FACEPALM,
        self::TONGUE,
    ];

    /**
     * @var array<string, array{eyes: string, mouth: string}>
     */
    private const FACES = [
        self::SMILE => ['eyes' => 'o o', 'mouth' => ' w '],
        self::JOY => ['eyes' => '^ ^', 'mouth' => '^_^'],
        self::CRY => ['eyes' => 'T T', 'mouth' => ' ~ '],
        self::DEPRESSED => ['eyes' => '- -', 'mouth' => ' _ '],
        self::TERRIFIED => ['eyes' => 'O O', 'mouth' => ' O '],
        self::ANGRY => ['eyes' => '> <', 'mouth' => ' # '],
        self::COQUETTISH => ['eyes' => '^ ^', 'mouth' => ' o '],
        self::CUTE => ['eyes' => '^ ^', 'mouth' => ' w '],
        self::PLEASING => ['eyes' => '. .', 'mouth' => ' u '],
        self::KNOCKING => ['eyes' => 'o o', 'mouth' => ' o '],
        self::FACEPALM => ['eyes' => 'x x', 'mouth' => ' _ '],
        self::TONGUE => ['eyes' => 'o -', 'mouth' => ' P '],
    ];

    /**
     * @return list<string>
     */
    public static function forms(): array
    {
        return self::FORMS;
    }

    public static function has(string $form): bool
    {
        return isset(self::FACES[$form]);
    }

    /**
     * 返回完整终端横幅。
     *
     * @return list<string>
     */
    public static function lines(string $form = self::DEFAULT_FORM): array
    {
        $face = self::faceParts($form);

        return [
            '       .---.',
            '      / ' . $face['eyes'] . ' \\',
            '      | ' . $face['mouth'] . ' |',
            '     ~\\_____/~',
            '      /|   |\\',
            '     / |[ ]| \\',
            '    (__|___|__)',
            '      _______',
        ];
    }

    /**
     * 返回紧凑终端表情。
     *
     * @return list<string>
     */
    public static function face(string $form = self::DEFAULT_FORM): array
    {
        $face = self::faceParts($form);

        return [
            '   .---.',
            '  / ' . $face['eyes'] . ' \\',
            '  | ' . $face['mouth'] . ' |',
            '   \\___/',
        ];
    }

    public static function svg(string $form): string
    {
        $path = self::path($form);
        $svg = file_get_contents($path);

        if ($svg === false) {
            throw new RuntimeException(sprintf('Unable to read pet form SVG: %s', $path));
        }

        return $svg;
    }

    public static function path(string $form): string
    {
        self::assertForm($form);

        $path = dirname(__DIR__, 2) . '/docs/pet-expressions/' . $form . '.svg';

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Pet form SVG not found: %s', $path));
        }

        return $path;
    }

    /**
     * @return array{eyes: string, mouth: string}
     */
    private static function faceParts(string $form): array
    {
        self::assertForm($form);

        return self::FACES[$form];
    }

    private static function assertForm(string $form): void
    {
        if (!self::has($form)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown pet form "%s". Available forms: %s.',
                $form,
                implode(', ', self::FORMS)
            ));
        }
    }
}
