<?php

namespace App\Tests\Model;

use App\Model\ScrapeResult;
use PHPUnit\Framework\TestCase;

class ScrapeResultTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $result = new ScrapeResult(
            identifier: '123',
            title: 'Spawn',
            subtitle: '1',
            year: 1992,
            publisher: 'Image Comics',
            coverUrl: 'https://example.com/cover.jpg',
            pageUrl: 'https://example.com/page',
        );

        $this->assertSame('123', $result->identifier);
        $this->assertSame('Spawn', $result->title);
        $this->assertSame('1', $result->subtitle);
        $this->assertSame(1992, $result->year);
        $this->assertSame('Image Comics', $result->publisher);
        $this->assertSame('https://example.com/cover.jpg', $result->coverUrl);
        $this->assertSame('https://example.com/page', $result->pageUrl);
    }

    public function testConstructorWithMinimalData(): void
    {
        $result = new ScrapeResult(identifier: 'abc', title: 'Test');

        $this->assertSame('abc', $result->identifier);
        $this->assertSame('Test', $result->title);
        $this->assertNull($result->subtitle);
        $this->assertNull($result->year);
        $this->assertNull($result->publisher);
        $this->assertNull($result->coverUrl);
        $this->assertNull($result->pageUrl);
    }
}
