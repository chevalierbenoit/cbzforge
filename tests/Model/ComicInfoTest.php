<?php

namespace App\Tests\Model;

use App\Model\ComicInfo;
use PHPUnit\Framework\TestCase;

class ComicInfoTest extends TestCase
{
    public function testComicInfoCanBeCreated(): void
    {
        $info = new ComicInfo(
            series: 'Spawn',
            title: 'La Naissance',
            number: 1,
            writer: 'Todd McFarlane',
            artist: 'Todd McFarlane',
            publisher: 'Image Comics',
            year: 1992,
            summary: 'Le début de Spawn.',
            isbn: '9780000000000',
            genre: ['Superhero', 'Horror'],
        );

        $this->assertSame('Spawn', $info->series);
        $this->assertSame('La Naissance', $info->title);
        $this->assertSame(1, $info->number);
    }

    public function testComicInfoWithMinimalData(): void
    {
        $info = new ComicInfo();

        $this->assertNull($info->series);
        $this->assertNull($info->title);
        $this->assertNull($info->number);
        $this->assertSame('fr', $info->languageIso);
    }

    public function testToXmlReturnsValidComicInfoXml(): void
    {
        $info = new ComicInfo(series: 'Spawn', number: 1, title: 'La Naissance');
        $xml = $info->toXml();

        $this->assertStringContainsString('<Series>Spawn</Series>', $xml);
        $this->assertStringContainsString('<Number>1</Number>', $xml);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $this->assertEquals('ComicInfo', $doc->documentElement->tagName);
    }

    public function testFromXmlParsesValidXml(): void
    {
        $xml = '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>';
        $info = ComicInfo::fromXml($xml);

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame(1, $info->number);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testFromXmlInvalidXml(): void
    {
        $this->expectException(\RuntimeException::class);
        ComicInfo::fromXml('not xml');
    }
}
