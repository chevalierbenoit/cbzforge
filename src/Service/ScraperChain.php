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

    /** Whether the admin has switched the scraper on. */
    public function isEnabled(ScraperInterface $scraper, SettingsService $settings): bool
    {
        return '0' !== $settings->get('scraper.'.$scraper->getName().'.enabled');
    }

    /** Whether every required setting of the scraper has a value. */
    public function isConfigured(ScraperInterface $scraper, SettingsService $settings): bool
    {
        foreach ($scraper->getConfigFields() as $field) {
            if (($field['required'] ?? false) && !$settings->has($field['key'])) {
                return false;
            }
        }

        return true;
    }

    /** A scraper is available when it is enabled and configured. */
    public function isAvailable(ScraperInterface $scraper, SettingsService $settings): bool
    {
        return $this->isEnabled($scraper, $settings) && $this->isConfigured($scraper, $settings);
    }

    /** @return ScraperInterface[] */
    public function getAvailableScrapers(SettingsService $settings): array
    {
        return array_values(array_filter(
            $this->getScrapers(),
            fn (ScraperInterface $scraper) => $this->isAvailable($scraper, $settings),
        ));
    }
}
