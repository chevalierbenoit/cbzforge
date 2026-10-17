<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;

interface ScraperInterface
{
    public function getName(): string;

    public function supports(string $identifier): bool;

    /** @return array<int, array{key: string, label: string, type: 'text'|'password'}> */
    public function getConfigFields(): array;

    /** @return ScrapeResult[] */
    public function search(string $query): array;

    public function scrape(string $identifier): ComicInfo;
}
