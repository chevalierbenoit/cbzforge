<?php

namespace App\Tests\Service;

use App\Scraper\ScraperInterface;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use PHPUnit\Framework\TestCase;

class ScraperChainTest extends TestCase
{
    public function testGetScrapersReturnsRegisteredScrapers(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Test');

        $chain = new ScraperChain([$scraper]);
        $list = $chain->getScrapers();

        $this->assertCount(1, $list);
        $this->assertSame('Test', $list[0]->getName());
    }

    public function testGetScrapersReturnsEmptyArrayWhenNone(): void
    {
        $chain = new ScraperChain([]);
        $this->assertEmpty($chain->getScrapers());
    }

    public function testFindScraperByUrlReturnsMatchingScraper(): void
    {
        $bdgest = $this->createMock(ScraperInterface::class);
        $bdgest->method('getName')->willReturn('BDGest');
        $bdgest->expects($this->once())->method('supports')->with('https://www.bdgest.com/album/123')->willReturn(true);

        $chain = new ScraperChain([$bdgest]);
        $found = $chain->findScraperForUrl('https://www.bdgest.com/album/123');

        $this->assertNotNull($found);
        $this->assertSame('BDGest', $found->getName());
    }

    public function testFindScraperByUrlReturnsNullWhenNoMatch(): void
    {
        $chain = new ScraperChain([]);
        $this->assertNull($chain->findScraperForUrl('https://example.com'));
    }

    public function testGetAvailableScrapersFiltersDisabled(): void
    {
        $scraper1 = $this->createStub(ScraperInterface::class);
        $scraper1->method('getName')->willReturn('Google Books');
        $scraper1->method('getConfigFields')->willReturn([]);
        $scraper2 = $this->createStub(ScraperInterface::class);
        $scraper2->method('getName')->willReturn('BDGest');
        $scraper2->method('getConfigFields')->willReturn([]);

        $settings = $this->createStub(SettingsService::class);
        // BDGest disabled (enabled=0), Google Books enabled (no value yet)
        $settings->method('get')->willReturnMap([
            ['scraper.BDGest.enabled', '0'],
            ['scraper.Google Books.enabled', null],
        ]);

        $chain = new ScraperChain([$scraper1, $scraper2]);
        $available = $chain->getAvailableScrapers($settings);

        $this->assertCount(1, $available);
        $this->assertSame('Google Books', $available[0]->getName());
    }

    public function testScraperWithMissingRequiredSettingIsNotAvailable(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Google Books');
        $scraper->method('getConfigFields')->willReturn([
            ['key' => 'google_books_api_key', 'label' => 'Key', 'type' => 'password', 'required' => true],
        ]);

        $settings = $this->createStub(SettingsService::class);
        $settings->method('get')->willReturn(null);
        $settings->method('has')->willReturn(false);

        $chain = new ScraperChain([$scraper]);

        $this->assertFalse($chain->isConfigured($scraper, $settings));
        $this->assertFalse($chain->isAvailable($scraper, $settings));
        $this->assertSame([], $chain->getAvailableScrapers($settings));
    }

    public function testOptionalSettingDoesNotBlockAvailability(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Custom');
        $scraper->method('getConfigFields')->willReturn([
            ['key' => 'custom.optional', 'label' => 'Optional', 'type' => 'text'],
        ]);

        $settings = $this->createStub(SettingsService::class);
        $settings->method('get')->willReturn(null);
        $settings->method('has')->willReturn(false);

        $chain = new ScraperChain([$scraper]);

        $this->assertTrue($chain->isConfigured($scraper, $settings));
        $this->assertTrue($chain->isAvailable($scraper, $settings));
    }

    public function testAvailableRequiresEnabledAndConfigured(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Google Books');
        $scraper->method('getConfigFields')->willReturn([
            ['key' => 'google_books_api_key', 'label' => 'Key', 'type' => 'password', 'required' => true],
        ]);

        $chain = new ScraperChain([$scraper]);

        $configured = $this->createStub(SettingsService::class);
        $configured->method('get')->willReturnMap([['scraper.Google Books.enabled', '1']]);
        $configured->method('has')->willReturn(true);
        $this->assertTrue($chain->isAvailable($scraper, $configured));

        $disabled = $this->createStub(SettingsService::class);
        $disabled->method('get')->willReturnMap([['scraper.Google Books.enabled', '0']]);
        $disabled->method('has')->willReturn(true);
        $this->assertFalse($chain->isAvailable($scraper, $disabled));
    }
}
