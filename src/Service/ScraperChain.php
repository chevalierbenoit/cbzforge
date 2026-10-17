<?php

namespace App\Service;

use App\Scraper\ScraperInterface;

class ScraperChain
{
    /** @param ScraperInterface[] $scrapers */
    public function __construct(
        private readonly iterable $scrapers = [],
    ) {
    }

    /** @return ScraperInterface[] */
    public function getScrapers(): array
    {
        return $this->scrapers instanceof \Traversable
            ? iterator_to_array($this->scrapers)
            : $this->scrapers;
    }

    public function findScraperForUrl(string $url): ?ScraperInterface
    {
        foreach ($this->getScrapers() as $scraper) {
            if ($scraper->supports($url)) {
                return $scraper;
            }
        }

        return null;
    }

    /** @return ScraperInterface[] */
    public function getActiveScrapers(SettingsService $settings): array
    {
        return array_filter(
            $this->getScrapers(),
            static fn (ScraperInterface $scraper) => '0' !== $settings->get('scraper.'.$scraper->getName().'.enabled'),
        );
    }
}
