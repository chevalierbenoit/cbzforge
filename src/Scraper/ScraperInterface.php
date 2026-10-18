<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;

interface ScraperInterface
{
    public function getName(): string;

    public function supports(string $identifier): bool;

    /**
     * Settings the admin fills in for this scraper. A field with `required => true`
     * must be set before the scraper is available; `required` defaults to false.
     *
     * @return array<int, array{key: string, label: string, type: 'text'|'password', required?: bool}>
     */
    public function getConfigFields(): array;

    /** @return ScrapeResult[] */
    public function search(string $query): array;

    public function scrape(string $identifier): ComicInfo;
}
