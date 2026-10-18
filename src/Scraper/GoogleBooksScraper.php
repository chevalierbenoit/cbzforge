<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;
use App\Service\SettingsService;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleBooksScraper implements ScraperInterface
{
    private const API_BASE = 'https://www.googleapis.com/books/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsService $settings,
    ) {
    }

    public function getName(): string
    {
        return 'Google Books';
    }

    public function supports(string $identifier): bool
    {
        return 1 === preg_match('/^[a-zA-Z0-9_-]+$/', $identifier);
    }

    public function getConfigFields(): array
    {
        return [
            [
                'key' => 'google_books_api_key',
                'label' => 'Clef API Google Books',
                'type' => 'password',
                'required' => true,
            ],
        ];
    }

    public function search(string $query): array
    {
        if (!$this->settings->has('google_books_api_key')) {
            return [];
        }

        $key = $this->settings->get('google_books_api_key');
        try {
            $response = $this->httpClient->request('GET', self::API_BASE.'/volumes', [
                'query' => [
                    'q' => $query,
                    'key' => $key,
                    'langRestrict' => 'fr',
                    'maxResults' => 10,
                ],
            ]);
            $data = json_decode($response->getContent(), true);
        } catch (TransportExceptionInterface|HttpExceptionInterface) {
            return [];
        }

        $results = [];

        foreach ($data['items'] ?? [] as $item) {
            $info = $item['volumeInfo'] ?? [];
            $results[] = new ScrapeResult(
                identifier: $item['id'],
                title: $info['title'] ?? '',
                subtitle: $info['subtitle'] ?? null,
                year: isset($info['publishedDate']) ? (int) substr($info['publishedDate'], 0, 4) : null,
                publisher: $info['publisher'] ?? null,
                coverUrl: $info['imageLinks']['thumbnail'] ?? null,
                pageUrl: $info['canonicalVolumeLink'] ?? null,
            );
        }

        return $results;
    }

    public function scrape(string $identifier): ComicInfo
    {
        if (!$this->settings->has('google_books_api_key')) {
            return new ComicInfo();
        }

        $key = $this->settings->get('google_books_api_key');
        try {
            $response = $this->httpClient->request('GET', self::API_BASE.'/volumes/'.$identifier, [
                'query' => ['key' => $key],
            ]);
            $data = json_decode($response->getContent(), true);
        } catch (TransportExceptionInterface|HttpExceptionInterface) {
            return new ComicInfo();
        }
        $info = $data['volumeInfo'] ?? [];

        $isbn = null;
        if (isset($info['industryIdentifiers'])) {
            foreach ($info['industryIdentifiers'] as $id) {
                if ('ISBN_13' === $id['type']) {
                    $isbn = $id['identifier'];
                    break;
                }
            }
        }

        $year = null;
        if (isset($info['publishedDate'])) {
            $year = (int) substr($info['publishedDate'], 0, 4);
        }

        // Try seriesInfo from Google Books API first
        $series = null;
        $number = null;
        $title = $info['title'] ?? null;

        if (isset($info['seriesInfo']['volumeSeries'][0])) {
            $vs = $info['seriesInfo']['volumeSeries'][0];
            if (!empty($vs['seriesId'])) {
                $series = $vs['seriesId'];
            }
            if (isset($vs['orderNumber'])) {
                $number = $vs['orderNumber'];
            }
            // Use subtitle as the volume title when seriesInfo is present
            if (!empty($info['subtitle'])) {
                $title = $info['subtitle'];
            }
        }

        // Fallback: parse French BD patterns from the title
        if (!$series && $title) {
            [$parsedSeries, $parsedNumber, $parsedTitle] = $this->parseBdTitle($title);
            $series = $parsedSeries;
            $number = $parsedNumber ?? $number;
            $title = $parsedTitle ?? $title;
        }

        return new ComicInfo(
            series: $series,
            title: $title,
            number: $number,
            artist: isset($info['authors']) ? implode(', ', $info['authors']) : null,
            year: $year,
            summary: isset($info['description']) ? trim(html_entity_decode(strip_tags($info['description']))) : null,
            isbn: $isbn,
            languageIso: $info['language'] ?? null,
            genre: $info['categories'] ?? null,
        );
    }

    /**
     * @return array{string|null, int|null, string|null}
     */
    private function parseBdTitle(string $title): array
    {
        // "Series - Title - n°X"
        if (preg_match('/^(.+?)\s*[-–]\s*(.+?)\s*[-–]\s*(?:n°|n)\s*(\d+)$/iu', $title, $m)) {
            return [trim($m[1]), (int) $m[3], trim($m[2])];
        }

        // "Series Tome X - Title" or "Series T.X: Title"
        if (preg_match('/^(.+?)\s*(?:Tome|T)\s*(\d+)\s*[-–:]\s*(.+)$/iu', $title, $m)) {
            return [trim($m[1]), (int) $m[2], trim($m[3])];
        }

        // "Series X - Title" (e.g., "Astérix 40 - L'Iris blanc")
        if (preg_match('/^(.+?)\s*(\d+)\s*[-–]\s*(.+)$/u', $title, $m)) {
            return [trim($m[1]), (int) $m[2], trim($m[3])];
        }

        // "Series - Title"
        if (preg_match('/^(.+?)\s*[-–]\s*(.+)$/u', $title, $m)) {
            return [trim($m[1]), null, trim($m[2])];
        }

        return [null, null, null];
    }
}
