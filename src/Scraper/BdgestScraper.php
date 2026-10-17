<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BdgestScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.bedetheque.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'BDGest';
    }

    public function supports(string $identifier): bool
    {
        return str_contains($identifier, 'bdgest.com') || str_contains($identifier, 'bedetheque.com');
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function search(string $query): array
    {
        try {
            $seriesList = $this->searchSeriesWithFallbacks($query);
            if (empty($seriesList)) {
                return [];
            }

            $first = $seriesList[0];
            $seriesId = (int) $first['id'];
            $seriesName = $first['label'];

            $response = $this->httpClient->request('GET', self::BASE_URL.'/serie/index/s/'.$seriesId);
            $html = $response->getContent();

            return $this->parseSeriesAlbums($html, $seriesName);
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            return [];
        }
    }

    public function scrape(string $identifier): ComicInfo
    {
        $url = str_starts_with($identifier, 'http') ? $identifier : self::BASE_URL.$identifier;

        if (preg_match('#/serie-\d+#', $url)) {
            return $this->scrapeFirstAlbumFromSeries($url);
        }

        $response = $this->httpClient->request('GET', $url);
        $html = $response->getContent();

        return $this->parseAlbumPage($html);
    }

    private function scrapeFirstAlbumFromSeries(string $url): ComicInfo
    {
        $response = $this->httpClient->request('GET', $url);
        $html = $response->getContent();

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);

        $series = $this->getXpathText($xpath, '//div[contains(@class, "bandeau-info")]//h1/a');

        $uls = $xpath->query("//ul[contains(@class, 'liste-albums')]");
        $secondUl = $uls->item(1);
        if ($secondUl instanceof \DOMElement) {
            $items = $secondUl->getElementsByTagName('li');
            foreach ($items as $item) {
                $links = $item->getElementsByTagName('a');
                if (0 === $links->length) {
                    continue;
                }

                $href = $links->item(0)->getAttribute('href');
                if (empty($href) || str_contains($href, 'creation')) {
                    continue;
                }

                $albumUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL.$href;

                return $this->scrape($albumUrl);
            }
        }

        return new ComicInfo(series: $series);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchSeriesWithFallbacks(string $query): array
    {
        $candidates = [$query];

        $cleaned = self::cleanVolumeSuffix($query);
        if ($cleaned !== $query) {
            $candidates[] = $cleaned;
        }

        $inverted = self::invertArticle($query);
        if ($inverted !== $query) {
            $candidates[] = $inverted;
        }

        $invertedCleaned = self::invertArticle($cleaned);
        if ($invertedCleaned !== $cleaned && $invertedCleaned !== $inverted) {
            $candidates[] = $invertedCleaned;
        }

        foreach ($candidates as $candidate) {
            $seriesList = $this->searchSeries($candidate);
            if (!empty($seriesList)) {
                return $seriesList;
            }
        }

        return [];
    }

    private static function invertArticle(string $query): string
    {
        $trimmed = trim($query);
        if (preg_match('/^((?:Le|La|Les|Un|Une|Des)\s+|L[’\'])(.+)$/iu', $trimmed, $m)) {
            return trim($m[2]).' ('.trim($m[1]).')';
        }

        return $query;
    }

    private static function cleanVolumeSuffix(string $query): string
    {
        $patterns = [
            '/\s*#\d+\s*$/',
            '/\s+Tome\s+\d+\s*$/i',
            '/\s+Volume\s+\d+\s*$/i',
            '/\s+Vol\.?\s*\d+\s*$/i',
            '/\s+-\s+\d+\s*$/',
            '/\s+\d+\s*$/',
        ];

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $query);
            if ($cleaned !== $query) {
                return trim($cleaned);
            }
        }

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function searchSeries(string $query): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL.'/ajax/series', [
            'query' => ['term' => $query],
        ]);

        return json_decode($response->getContent(), true) ?? [];
    }

    /**
     * @return list<ScrapeResult>
     */
    private function parseSeriesAlbums(string $html, string $seriesName): array
    {
        $results = [];
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);

        $uls = $xpath->query("//ul[contains(@class, 'liste-albums')]");
        if ($uls->length < 2) {
            return [];
        }

        $secondUl = $uls->item(1);
        if (!$secondUl instanceof \DOMElement) {
            return [];
        }

        $items = $secondUl->getElementsByTagName('li');

        foreach ($items as $item) {
            $links = $item->getElementsByTagName('a');
            if (0 === $links->length) {
                continue;
            }

            $link = $links->item(0);
            $href = $link->getAttribute('href');
            $title = trim($link->textContent);

            if (empty($href) || empty($title)) {
                continue;
            }
            if (str_contains($href, 'creation')) {
                continue;
            }

            $number = null;
            if (preg_match('/Tome-(\d+)-/', $href, $m)) {
                $number = (int) $m[1];
            }

            $displayTitle = $seriesName;
            if (null !== $number) {
                $displayTitle .= ' #'.$number;
            }
            $displayTitle .= ' - '.$title;

            $pageUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL.$href;

            $results[] = new ScrapeResult(
                identifier: $pageUrl,
                title: $displayTitle,
                subtitle: null !== $number ? (string) $number : null,
                year: null,
                publisher: null,
                coverUrl: null,
                pageUrl: $pageUrl,
            );
        }

        return $results;
    }

    private function parseAlbumPage(string $html): ComicInfo
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);

        $series = $this->getXpathText($xpath, '//div[contains(@class, "bandeau-info")]//h1/a');
        $h2 = $this->getXpathText($xpath, '//div[contains(@class, "bandeau-info")]//h2');

        $number = null;
        $title = null;
        if (null !== $h2) {
            if (preg_match('/^\s*(\d+)\s*\.\s*(.+)$/s', $h2, $m)) {
                $number = (int) $m[1];
                $title = trim($m[2]);
            } elseif (preg_match('/^\s*(\d+)\s*$/', $h2, $m)) {
                $number = (int) $m[1];
            } else {
                $title = trim($h2);
            }
        }

        $writer = null;
        $artist = null;
        $penciler = null;
        $inker = null;
        $colorist = null;
        $authorLinks = $xpath->query('//div[contains(@class, "liste-auteurs")]/a');
        $authorMetiers = $xpath->query('//div[contains(@class, "liste-auteurs")]/span[contains(@class, "metier")]');

        if (false !== $authorLinks && false !== $authorMetiers) {
            for ($i = 0; $i < $authorLinks->length; ++$i) {
                $name = trim($authorLinks->item($i)->textContent);
                $role = ($i < $authorMetiers->length) ? trim($authorMetiers->item($i)->textContent) : '';

                if (str_contains($role, 'Scénario')) {
                    $writer = $this->normalizeAuthorName($name);
                } elseif (str_contains($role, 'Dessin')) {
                    $artist = $this->normalizeAuthorName($name);
                    $penciler = $artist;
                } elseif (str_contains($role, 'Encre')) {
                    $inker = $this->normalizeAuthorName($name);
                } elseif (str_contains($role, 'Couleur')) {
                    $colorist = $this->normalizeAuthorName($name);
                }
            }
        }

        if (null === $writer && false !== $authorLinks && $authorLinks->length > 0) {
            $writer = $this->normalizeAuthorName(trim($authorLinks->item(0)->textContent));
        }

        $publisher = $this->getXpathText($xpath, '//span[@itemprop="publisher"]');
        if (null === $publisher) {
            $publisherNode = $xpath->query('//span[contains(@class, "editeur")]')->item(0);
            $publisher = $publisherNode ? trim($publisherNode->textContent) : null;
        }

        $yearRaw = $this->getXpathText($xpath, '//span[contains(@class, "annee")]');
        $year = null !== $yearRaw ? (int) $yearRaw : null;

        $isbn = $this->getXpathText($xpath, '//span[@itemprop="isbn"]');

        $summary = null;
        $descNode = $xpath->query('//span[@itemprop="description"]')->item(0);
        if ($descNode) {
            $summary = trim($descNode->textContent);
            $summary = preg_replace('/\s+/', ' ', $summary);
            $summary = str_replace('Lire la suite', '', $summary);
            $summary = trim($summary);
        }

        $pagesRaw = $this->getXpathText($xpath, '//span[@itemprop="numberOfPages"]');
        $pages = null !== $pagesRaw ? (int) $pagesRaw : null;

        return new ComicInfo(
            series: $series,
            title: $title,
            number: $number,
            writer: $writer,
            artist: $artist,
            penciler: $penciler,
            inker: $inker,
            colorist: $colorist,
            publisher: $publisher,
            year: $year,
            isbn: $isbn,
            summary: $summary,
            languageIso: 'fr',
        );
    }

    private function getXpathText(\DOMXPath $xpath, string $expr, ?\DOMNode $context = null): ?string
    {
        $node = null !== $context
            ? $xpath->query($expr, $context)->item(0)
            : $xpath->query($expr)->item(0);

        return $node ? trim($node->textContent) : null;
    }

    private function normalizeAuthorName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^(.+?),\s*(.+)$/', $name, $m)) {
            return trim($m[2]).' '.trim($m[1]);
        }

        return $name;
    }
}
