<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open Library (openlibrary.org): free, no key. Good coverage of French BD by ISBN.
 *
 * Identifiers: "isbn:<ISBN>" (an edition, the most precise), or an Open Library URL.
 */
class OpenLibraryScraper implements ScraperInterface
{
    private const BASE_URL = 'https://openlibrary.org';
    private const COVER_URL = 'https://covers.openlibrary.org/b/id/%s-M.jpg';
    private const LANGUAGES = ['fre' => 'fr', 'eng' => 'en', 'jpn' => 'ja'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'Open Library';
    }

    public function supports(string $identifier): bool
    {
        return 1 === preg_match('#^https?://openlibrary\.org/(isbn|books|works)/#', $identifier);
    }

    public function getConfigFields(): array
    {
        return [];
    }

    /** @return ScrapeResult[] */
    public function search(string $query): array
    {
        try {
            $data = $this->getJson(self::BASE_URL.'/search.json', [
                'q' => $query,
                'limit' => 10,
                'fields' => 'key,title,subtitle,author_name,first_publish_year,publisher,cover_i,isbn',
            ]);
        } catch (TransportExceptionInterface|HttpExceptionInterface|\JsonException) {
            return [];
        }

        $results = [];
        foreach ($data['docs'] ?? [] as $doc) {
            $isbn = $this->pickIsbn($doc['isbn'] ?? []);
            $key = $doc['key'] ?? null;
            $title = $doc['title'] ?? null;
            if (null === $title || (null === $isbn && null === $key)) {
                continue;
            }

            $results[] = new ScrapeResult(
                identifier: null !== $isbn ? 'isbn:'.$isbn : self::BASE_URL.$key,
                title: $title,
                subtitle: isset($doc['author_name']) ? implode(', ', \array_slice($doc['author_name'], 0, 3)) : null,
                year: isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                publisher: $doc['publisher'][0] ?? null,
                coverUrl: isset($doc['cover_i']) ? \sprintf(self::COVER_URL, $doc['cover_i']) : null,
                pageUrl: null !== $key ? self::BASE_URL.$key : null,
            );
        }

        return $results;
    }

    public function scrape(string $identifier): ComicInfo
    {
        $url = str_starts_with($identifier, 'isbn:')
            ? self::BASE_URL.'/isbn/'.substr($identifier, 5)
            : $identifier;

        try {
            $data = $this->getJson(rtrim($url, '/').'.json');
        } catch (TransportExceptionInterface|HttpExceptionInterface|\JsonException) {
            return new ComicInfo();
        }

        $requestedIsbn = str_starts_with($identifier, 'isbn:') ? substr($identifier, 5) : null;

        return $this->toComicInfo($data, $requestedIsbn);
    }

    /** @param array<string, mixed> $data an edition (/isbn, /books) or a work (/works) record */
    private function toComicInfo(array $data, ?string $requestedIsbn): ComicInfo
    {
        $rawTitle = $data['title'] ?? null;
        $series = isset($data['series'][0]) && \is_string($data['series'][0]) ? $data['series'][0] : null;
        $number = null;
        $title = $rawTitle;

        // Album titles often read "Series -1- Album title"; split them when the API gives no series.
        if (null !== $rawTitle && preg_match('/^(.+?)\s*-\s*(\d+)\s*-\s*(.+)$/u', $rawTitle, $m)) {
            $series ??= trim($m[1]);
            $number = (int) $m[2];
            $title = trim($m[3]);
        }

        // The first two authors of BD albums are usually the writer and the artist.
        $names = [];
        foreach (\array_slice($data['authors'] ?? [], 0, 2) as $author) {
            $key = $author['key'] ?? ($author['author']['key'] ?? null);
            $name = \is_string($key) ? $this->authorName($key) : null;
            if (null !== $name) {
                $names[] = $name;
            }
        }

        $year = null;
        if (isset($data['publish_date']) && preg_match('/(\d{4})/', (string) $data['publish_date'], $m)) {
            $year = (int) $m[1];
        }

        $languageKey = $data['languages'][0]['key'] ?? null;
        $language = \is_string($languageKey) ? (self::LANGUAGES[basename($languageKey)] ?? null) : null;

        return new ComicInfo(
            series: $series,
            title: $title,
            number: $number,
            writer: $names[0] ?? null,
            artist: $names[1] ?? null,
            publisher: $data['publishers'][0] ?? null,
            year: $year,
            summary: $this->description($data['description'] ?? null),
            isbn: $requestedIsbn ?? $this->pickIsbn($data['isbn_13'] ?? $data['isbn'] ?? []),
            languageIso: $language,
        );
    }

    private function authorName(string $key): ?string
    {
        try {
            $data = $this->getJson(self::BASE_URL.$key.'.json');
        } catch (TransportExceptionInterface|HttpExceptionInterface|\JsonException) {
            return null;
        }

        return \is_string($data['name'] ?? null) ? $data['name'] : null;
    }

    /** @param mixed $description a string, or an object with a value (works) */
    private function description(mixed $description): ?string
    {
        $text = \is_array($description) ? ($description['value'] ?? null) : $description;

        return \is_string($text) && '' !== trim($text) ? trim($text) : null;
    }

    /** @param array<array-key, mixed> $isbns the 13-digit form is preferred */
    private function pickIsbn(array $isbns): ?string
    {
        foreach ($isbns as $isbn) {
            if (\is_string($isbn) && 13 === \strlen($isbn)) {
                return $isbn;
            }
        }

        return isset($isbns[0]) && \is_string($isbns[0]) ? $isbns[0] : null;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function getJson(string $url, array $query = []): array
    {
        $response = $this->httpClient->request('GET', $url, ['query' => $query]);

        return json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
