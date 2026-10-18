<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Tests\Support;

use Erikwang2013\ClickHouse\Support\Pet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PetTest extends TestCase
{
    public function testFormsAreStable(): void
    {
        $this->assertSame([
            Pet::SMILE,
            Pet::JOY,
            Pet::CRY,
            Pet::DEPRESSED,
            Pet::TERRIFIED,
            Pet::ANGRY,
            Pet::COQUETTISH,
            Pet::CUTE,
            Pet::PLEASING,
            Pet::KNOCKING,
            Pet::FACEPALM,
            Pet::TONGUE,
        ], Pet::forms());
        $this->assertCount(12, Pet::forms());
        $this->assertSame(Pet::forms(), array_values(array_unique(Pet::forms())));
    }

    public function testEveryFormHasSvg(): void
    {
        foreach (Pet::forms() as $form) {
            $this->assertTrue(Pet::has($form));
            $this->assertFileExists(Pet::path($form));

            $svg = Pet::svg($form);
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertStringContainsString('<title>', $svg);
            $this->assertStringContainsString('ClickHouse 仓鼠', $svg);
        }
    }

    public function testEveryFormHasAscii(): void
    {
        foreach (Pet::forms() as $form) {
            $this->assertCount(8, Pet::lines($form));
            $this->assertCount(4, Pet::face($form));
        }
    }

    public function testLinesDefaultToSmile(): void
    {
        $lines = Pet::lines();

        $this->assertSame(Pet::lines(Pet::SMILE), $lines);
        $this->assertCount(8, $lines);
        $this->assertSame('      / o o \\', $lines[1]);
        $this->assertSame('      |  w  |', $lines[2]);
    }

    public function testLinesChangeWithForm(): void
    {
        $this->assertNotSame(Pet::lines(Pet::SMILE), Pet::lines(Pet::ANGRY));
        $this->assertSame('      / > < \\', Pet::lines(Pet::ANGRY)[1]);
        $this->assertSame('      |  #  |', Pet::lines(Pet::ANGRY)[2]);
    }

    public function testFaceReturnsCompactArt(): void
    {
        $face = Pet::face(Pet::JOY);

        $this->assertCount(4, $face);
        $this->assertSame('   .---.', $face[0]);
        $this->assertSame('  / ^ ^ \\', $face[1]);
        $this->assertSame('  | ^_^ |', $face[2]);
        $this->assertSame('   \\___/', $face[3]);
    }

    public function testUnknownFormThrowsForLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pet::lines('unknown');
    }

    public function testUnknownFormThrowsForSvg(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pet::svg('../pet');
    }

    public function testHasRejectsUnknownForm(): void
    {
        $this->assertFalse(Pet::has('unknown'));
        $this->assertTrue(Pet::has(Pet::TONGUE));
    }
}
