<?php

namespace App\Tests\Service;

use App\Service\FilenameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilenameParserTest extends TestCase
{
    private FilenameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FilenameParser();
    }

    public static function provideFilenames(): array
    {
        return [
            'dash T prefix' => ['Spawn - T01 - Origines.cbz', 'Spawn', 1, 'Origines'],
            'dash T prefix no ext' => ['Spawn - T01 - Origines', 'Spawn', 1, 'Origines'],
            'dash number' => ['Spawn - 1 - Origines.cbz', 'Spawn', 1, 'Origines'],

            'underscore T prefix' => ['Spawn_T01_Origines.cbz', 'Spawn', 1, 'Origines'],
            'complex series underscore T' => [
                'Warrior_Princess_and_the_Barbaric_King_The_T05_Noriaki_Kotoba.cbz',
                'Warrior Princess and the Barbaric King The',
                5,
                'Noriaki Kotoba',
            ],
            'french underscore T' => ['Largo_Winch_T01_L_heritier.cbz', 'Largo Winch', 1, 'L heritier'],
            'underscore number' => ['Spawn_1_Origines.cbz', 'Spawn', 1, 'Origines'],

            'hash number' => ['Spawn #1.cbz', 'Spawn', 1, null],
            'hash number with title' => ['Spawn #1 - Origines.cbz', 'Spawn', 1, 'Origines'],

            'v prefix' => ['Spawn_v1.cbz', 'Spawn', 1, null],

            'vol prefix' => ['Spawn_Vol_1.cbz', 'Spawn', 1, null],
            'vol dot prefix' => ['Spawn Vol. 1.cbz', 'Spawn', 1, null],

            'T prefix no title' => ['Spawn_T01.cbz', 'Spawn', 1, null],

            'number only' => ['Spawn_01.cbz', 'Spawn', 1, null],
            'number only 2 digits' => ['Series_Name_05.cbz', 'Series Name', 5, null],
            'number only 3 digits' => ['Series_123.cbz', 'Series', 123, null],

            'number then title' => ['Spawn 1 Origines.cbz', 'Spawn', 1, 'Origines'],

            'simple name' => ['simple_name.cbz', 'simple name', null, null],
            'no extension' => ['no_extension', 'no extension', null, null],
            'already clean' => ['Spawn.cbz', 'Spawn', null, null],

            'multiple numbers' => ['Spawn_01_02.cbz', 'Spawn 01', 2, null],
            'number not at end' => ['Spawn_01_Origines_Extra.cbz', 'Spawn', 1, 'Origines Extra'],

            'number glued to dash, release tags' => ['Swing -01(Best of Fusion Comics 03.2019)@9-art-BD.cbr', 'Swing', 1, null],
            'number glued to dash' => ['Swing -02.cbz', 'Swing', 2, null],
            'number glued to dash with title' => ['Swing -03 - Un titre.cbz', 'Swing', 3, 'Un titre'],
            'year in parentheses' => ['Astérix #39 (2021).cbz', 'Astérix', 39, null],
            'square brackets' => ['Spawn 1 [HQ] Origines.cbz', 'Spawn', 1, 'Origines'],
        ];
    }

    #[DataProvider('provideFilenames')]
    public function testParse(
        string $filename,
        ?string $expectedSeries,
        ?int $expectedNumber,
        ?string $expectedTitle,
    ): void {
        $result = $this->parser->parse($filename);
        $this->assertSame($expectedSeries, $result->seriesName, 'series name mismatch');
        $this->assertSame($expectedNumber, $result->number, 'number mismatch');
        $this->assertSame($expectedTitle, $result->title, 'title mismatch');
    }
}
