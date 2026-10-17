<?php

namespace App\Tests\Scraper;

use App\Model\ComicInfo;
use App\Scraper\DummyScraper;
use PHPUnit\Framework\TestCase;

class DummyScraperTest extends TestCase
{
    public function testGetName(): void
    {
        $scraper = new DummyScraper();
        $this->assertSame('Dummy', $scraper->getName());
    }

    public function testSupportsDummyIdentifier(): void
    {
        $scraper = new DummyScraper();
        $this->assertTrue($scraper->supports('dummy:spawn'));
        $this->assertFalse($scraper->supports('https://www.bdgest.com/album/123'));
        $this->assertFalse($scraper->supports('bedetheque.com'));
    }

    public function testSearchReturnsFixedResults(): void
    {
        $scraper = new DummyScraper();
        $results = $scraper->search('anything');

        $this->assertCount(1, $results);
        $this->assertSame('Spawn', $results[0]->title);
        $this->assertSame(1992, $results[0]->year);
        $this->assertSame('Image Comics', $results[0]->publisher);
    }

    public function testScrapeReturnsFixedComicInfo(): void
    {
        $scraper = new DummyScraper();
        $info = $scraper->scrape('dummy:spawn');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('La Naissance', $info->title);
        $this->assertSame(1, $info->number);
        $this->assertSame('Todd McFarlane', $info->writer);
        $this->assertSame('Todd McFarlane', $info->artist);
        $this->assertSame('Image Comics', $info->publisher);
        $this->assertSame(1992, $info->year);
        $this->assertSame('fr', $info->languageIso);
    }
}
