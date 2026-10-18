<?php

namespace App\Tests\Support;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;
use App\Scraper\ScraperInterface;

class FakeScraper implements ScraperInterface
{
    public function getName(): string
    {
        return 'Fake';
    }

    public function supports(string $identifier): bool
    {
        return str_starts_with($identifier, 'fake:');
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function search(string $query): array
    {
        return [
            new ScrapeResult(
                identifier: 'fake:spawn',
                title: 'Spawn',
                subtitle: 'Tome 1',
                year: 1992,
                publisher: 'Image Comics',
                pageUrl: 'fake:spawn',
            ),
        ];
    }

    public function scrape(string $identifier): ComicInfo
    {
        return new ComicInfo(
            series: 'Spawn',
            title: 'La Naissance',
            number: 1,
            writer: 'Todd McFarlane',
            artist: 'Todd McFarlane',
            publisher: 'Image Comics',
            year: 1992,
            summary: 'Le début de Spawn.',
            isbn: '9780000000000',
            languageIso: 'fr',
            genre: ['Superhero', 'Horror'],
        );
    }
}
