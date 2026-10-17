<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;

class DummyScraper implements ScraperInterface
{
    public function getName(): string
    {
        return 'Dummy';
    }

    public function supports(string $identifier): bool
    {
        return str_starts_with($identifier, 'dummy:');
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function search(string $query): array
    {
        return [
            new ScrapeResult(
                identifier: 'dummy:spawn',
                title: 'Spawn',
                subtitle: 'Tome 1',
                year: 1992,
                publisher: 'Image Comics',
                pageUrl: 'dummy:spawn',
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
