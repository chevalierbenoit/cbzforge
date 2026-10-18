<?php

namespace App\Controller;

use App\Service\ScraperChain;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ScrapeUrlController extends AbstractController
{
    public function __construct(
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/scrape/url', name: 'file_scrape_url', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? null;

        if (!$url) {
            return $this->json(['error' => $this->translator->trans('error.url_required')], 400);
        }

        try {
            $scraper = $this->scraperChain->findScraperForUrl($url);
            if (!$scraper) {
                return $this->json(['error' => $this->translator->trans('error.no_scraper_for_url')], 400);
            }
            if (!$this->scraperChain->isAvailable($scraper, $this->settings)) {
                return $this->json(['error' => $this->translator->trans('error.scraper_disabled')], 400);
            }
            $info = $scraper->scrape($url);

            return $this->json(['comic_info' => $this->serializeInfo($info)]);
        } catch (TransportExceptionInterface $e) {
            return $this->json(['error' => $this->translator->trans('error.connection_error', ['%message%' => $e->getMessage()])], 502);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInfo(\App\Model\ComicInfo $info): array
    {
        return [
            'series' => $info->series,
            'title' => $info->title,
            'number' => $info->number,
            'writer' => $info->writer,
            'penciler' => $info->penciler ?? $info->artist,
            'inker' => $info->inker,
            'colorist' => $info->colorist,
            'letterer' => $info->letterer,
            'cover_artist' => $info->coverArtist,
            'artist' => $info->artist,
            'publisher' => $info->publisher,
            'year' => $info->year,
            'summary' => $info->summary,
            'isbn' => $info->isbn,
            'age_rating' => $info->ageRating,
            'language_iso' => $info->languageIso,
            'genre' => $info->genre,
            'characters' => $info->characters,
        ];
    }
}
