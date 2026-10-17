<?php

namespace App\Controller;

use App\Scraper\ScraperInterface;
use App\Service\FilenameParser;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ScrapeSearchController extends AbstractController
{
    public function __construct(
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
        private readonly FilenameParser $filenameParser,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/scrape/search', name: 'file_scrape_search', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $scraperName = $data['scraper'] ?? null;
        $query = $data['query'] ?? null;
        $path = $data['path'] ?? '';

        if (!$query || !$scraperName) {
            return $this->json(['error' => $this->translator->trans('error.query_scraper_required')], 400);
        }

        $useParsed = $data['_use_parsed_query'] ?? null;
        if ($useParsed) {
            $query = $useParsed;
        } elseif ($path) {
            $basename = pathinfo(basename($path), \PATHINFO_FILENAME);
            if ($query === $basename) {
                $parsed = $this->filenameParser->parse(basename($path));
                $query = $parsed->seriesName;
            }
        }

        $scraper = $this->findScraper($scraperName);
        if (!$scraper) {
            return $this->json(['error' => $this->translator->trans('error.scraper_not_found', ['%name%' => $scraperName])], 400);
        }
        $results = $scraper->search($query);

        return $this->json(['results' => $results]);
    }

    private function findScraper(string $name): ?ScraperInterface
    {
        foreach ($this->scraperChain->getScrapers() as $scraper) {
            if ($scraper->getName() === $name) {
                if ('0' === $this->settings->get('scraper.'.$name.'.enabled')) {
                    return null;
                }

                return $scraper;
            }
        }

        return null;
    }
}
