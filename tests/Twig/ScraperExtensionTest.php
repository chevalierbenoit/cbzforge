<?php

namespace App\Tests\Twig;

use App\Scraper\ScraperInterface;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use App\Twig\ScraperExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

class ScraperExtensionTest extends TestCase
{
    public function testGetFunctions(): void
    {
        $extension = new ScraperExtension(
            $this->createStub(ScraperChain::class),
            $this->createStub(SettingsService::class),
        );

        $functions = $extension->getFunctions();
        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('scrapers_config', $functions[0]->getName());
    }

    public function testGetScrapersConfigReturnsAll(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('BDGest');
        $scraper->method('getConfigFields')->willReturn([]);

        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->atLeastOnce())->method('get')->with('scraper.BDGest.enabled')->willReturn('1');

        $chain = $this->createStub(ScraperChain::class);
        $chain->method('getScrapers')->willReturn([$scraper]);

        $extension = new ScraperExtension($chain, $settings);
        $config = $extension->getScrapersConfig();

        $this->assertCount(1, $config);
        $this->assertSame('BDGest', $config[0]['name']);
        $this->assertSame([], $config[0]['fields']);
        $this->assertTrue($config[0]['configured']);
        $this->assertTrue($config[0]['enabled']);
    }

    public function testGetScrapersConfigFiltersInactiveWhenActiveOnly(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('BDGest');
        $scraper->method('getConfigFields')->willReturn([]);

        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->atLeastOnce())->method('get')->with('scraper.BDGest.enabled')->willReturn('0');

        $chain = $this->createStub(ScraperChain::class);
        $chain->method('getScrapers')->willReturn([$scraper]);

        $extension = new ScraperExtension($chain, $settings);
        $config = $extension->getScrapersConfig(activeOnly: true);

        $this->assertCount(0, $config);
    }

    public function testGetScrapersConfigDetectsNotConfigured(): void
    {
        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Google Books');
        $scraper->method('getConfigFields')->willReturn([
            ['key' => 'google_books_api_key', 'label' => 'API Key', 'type' => 'password'],
        ]);

        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->atLeastOnce())->method('get')->with('scraper.Google Books.enabled')->willReturn('1');
        $settings->expects($this->atLeastOnce())->method('has')->with('google_books_api_key')->willReturn(false);

        $chain = $this->createStub(ScraperChain::class);
        $chain->method('getScrapers')->willReturn([$scraper]);

        $extension = new ScraperExtension($chain, $settings);
        $config = $extension->getScrapersConfig();

        $this->assertCount(1, $config);
        $this->assertFalse($config[0]['configured']);
    }
}
