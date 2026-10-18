<?php

namespace App\Scraper;

use App\Model\ComicInfo;
use App\Model\ScrapeResult;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MangaDex (api.mangadex.org): free, public read endpoints, no key.
 *
 * Works at series level: a manga's volume number is not part of its record, so the
 * number stays empty. MangaDex asks that its name be credited where its data is shown.
 */
class MangaDexScraper implements ScraperInterface
{
    private const API_URL = 'https://api.mangadex.org';
    private const SITE_URL = 'https://mangadex.org/title/';
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'MangaDex';
    }

    public function supports(string $identifier): bool
    {
        return 1 === preg_match('#^https?://mangadex\.org/title/'.self::UUID.'#', $identifier);
    }

    public function getConfigFields(): array
    {
        return [];
    }

    /** @return ScrapeResult[] */
    public function search(string $query): array
    {
        try {
            $data = $this->getJson('/manga', [
                'title' => $query,
                'limit' => 10,
                'includes' => ['cover_art'],
                'order' => ['relevance' => 'desc'],
            ]);
        } catch (TransportExceptionInterface|HttpExceptionInterface|\JsonException) {
            return [];
        }

        $results = [];
        foreach ($data['data'] ?? [] as $manga) {
            $id = $manga['id'] ?? null;
            $title = $this->pickTitle($manga['attributes']['title'] ?? []);
            if (!\is_string($id) || null === $title) {
                continue;
            }

            $results[] = new ScrapeResult(
                identifier: $id,
                title: $title,
                year: isset($manga['attributes']['year']) ? (int) $manga['attributes']['year'] : null,
                coverUrl: $this->coverUrl($manga, '256'),
                pageUrl: self::SITE_URL.$id,
            );
        }

        return $results;
    }

    public function scrape(string $identifier): ComicInfo
    {
        if (1 !== preg_match('/'.self::UUID.'/', $identifier, $m)) {
            return new ComicInfo();
        }

        try {
            $data = $this->getJson('/manga/'.$m[0], [
                'includes' => ['author', 'artist', 'cover_art'],
            ]);
        } catch (TransportExceptionInterface|HttpExceptionInterface|\JsonException) {
            return new ComicInfo();
        }

        $manga = $data['data'] ?? [];
        $attributes = $manga['attributes'] ?? [];
        $relations = $manga['relationships'] ?? [];

        return new ComicInfo(
            series: $this->pickTitle($attributes['title'] ?? []),
            writer: $this->names($relations, 'author'),
            artist: $this->names($relations, 'artist'),
            year: isset($attributes['year']) ? (int) $attributes['year'] : null,
            summary: $this->description($attributes['description'] ?? []),
            genre: $this->genres($attributes['tags'] ?? []),
            languageIso: \is_string($attributes['originalLanguage'] ?? null) ? $attributes['originalLanguage'] : null,
        );
    }

    /** @param array<string, mixed> $titles */
    private function pickTitle(array $titles): ?string
    {
        if (\is_string($titles['en'] ?? null) && '' !== $titles['en']) {
            return $titles['en'];
        }

        foreach ($titles as $title) {
            if (\is_string($title) && '' !== $title) {
                return $title;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $manga */
    private function coverUrl(array $manga, string $size): ?string
    {
        foreach ($manga['relationships'] ?? [] as $relation) {
            if ('cover_art' === ($relation['type'] ?? null) && isset($relation['attributes']['fileName'], $manga['id'])) {
                return \sprintf('https://uploads.mangadex.org/covers/%s/%s.%s.jpg', $manga['id'], $relation['attributes']['fileName'], $size);
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $relations */
    private function names(array $relations, string $type): ?string
    {
        $names = [];
        foreach ($relations as $relation) {
            if ($type === ($relation['type'] ?? null) && \is_string($relation['attributes']['name'] ?? null)) {
                $names[] = $relation['attributes']['name'];
            }
        }

        return $names ? implode(', ', array_unique($names)) : null;
    }

    /** @param array<string, mixed> $description */
    private function description(array $description): ?string
    {
        $text = $description['en'] ?? (reset($description) ?: null);
        if (!\is_string($text)) {
            return null;
        }

        // Descriptions carry MangaDex's BBCode-like tags ([b], [url=...]); keep the text.
        $text = trim(preg_replace('/\[\/?[a-z]+(=[^\]]*)?\]/i', '', $text) ?? '');

        return '' !== $text ? $text : null;
    }

    /**
     * @param list<array<string, mixed>> $tags
     *
     * @return list<string>|null
     */
    private function genres(array $tags): ?array
    {
        $genres = [];
        foreach ($tags as $tag) {
            $name = $tag['attributes']['name']['en'] ?? null;
            if (($tag['attributes']['group'] ?? null) === 'genre' && \is_string($name)) {
                $genres[] = $name;
            }
        }

        return $genres ?: null;
    }

    /**
     * Builds the query string by hand: MangaDex expects repeated includes[] and order[] keys.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function getJson(string $path, array $params = []): array
    {
        $parts = [];
        foreach ($params as $key => $value) {
            foreach ((array) $value as $subKey => $item) {
                $name = \is_array($value) && !array_is_list($value) ? $key.'['.$subKey.']' : (\is_array($value) ? $key.'[]' : $key);
                $parts[] = rawurlencode($name).'='.rawurlencode((string) $item);
            }
        }

        $url = self::API_URL.$path.($parts ? '?'.implode('&', $parts) : '');
        $response = $this->httpClient->request('GET', $url);

        return json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
