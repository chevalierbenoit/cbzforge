<?php

namespace App\Twig;

use App\Service\ScraperChain;
use App\Service\SettingsService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ScraperExtension extends AbstractExtension
{
    public function __construct(
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('scrapers_config', [$this, 'getScrapersConfig']),
        ];
    }

    /**
     * @return array<int, array{name: string, fields: array<int, array<string, mixed>>, configured: bool, enabled: bool}>
     */
    public function getScrapersConfig(bool $activeOnly = false): array
    {
        $result = [];
        foreach ($this->scraperChain->getScrapers() as $scraper) {
            $name = $scraper->getName();
            $enabled = '0' !== $this->settings->get('scraper.'.$name.'.enabled');
            if ($activeOnly && !$enabled) {
                continue;
            }
            $fields = $scraper->getConfigFields();
            $configured = true;
            foreach ($fields as $field) {
                if (!$this->settings->has($field['key'])) {
                    $configured = false;
                    break;
                }
            }
            $result[] = [
                'name' => $name,
                'fields' => $fields,
                'configured' => $configured,
                'enabled' => $enabled,
            ];
        }

        return $result;
    }
}
