<?php

namespace App\Tests\Scraper;

use App\Scraper\OpenLibraryScraper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenLibraryScraperTest extends TestCase
{
    public function testSupportsOpenLibraryUrls(): void
    {
        $scraper = new OpenLibraryScraper(new MockHttpClient());

        $this->assertSame('Open Library', $scraper->getName());
        $this->assertTrue($scraper->supports('https://openlibrary.org/isbn/9782205000962'));
        $this->assertTrue($scraper->supports('https://openlibrary.org/works/OL45883W'));
        $this->assertFalse($scraper->supports('https://www.bedetheque.com/album/1'));
        $this->assertSame([], $scraper->getConfigFields());
    }

    public function testSearchPrefersIsbnIdentifiers(): void
    {
        $client = $this->client([
            'search.json' => ['docs' => [
                ['key' => '/works/OL45883W', 'title' => 'Astérix le Gaulois', 'author_name' => ['René Goscinny', 'Albert Uderzo'], 'first_publish_year' => 1961, 'publisher' => ['Dargaud'], 'cover_i' => 973281, 'isbn' => ['2205000964', '9782205000962']],
                ['key' => '/works/OL1W', 'title' => 'Sans ISBN'],
            ]],
        ]);

        $results = (new OpenLibraryScraper($client))->search('asterix');

        $this->assertCount(2, $results);
        $this->assertSame('isbn:9782205000962', $results[0]->identifier);
        $this->assertSame('René Goscinny, Albert Uderzo', $results[0]->subtitle);
        $this->assertSame(1961, $results[0]->year);
        $this->assertSame('Dargaud', $results[0]->publisher);
        $this->assertSame('https://covers.openlibrary.org/b/id/973281-M.jpg', $results[0]->coverUrl);
        $this->assertSame('https://openlibrary.org/works/OL1W', $results[1]->identifier);
    }

    public function testScrapeEditionByIsbnSplitsSeriesNumberAndTitle(): void
    {
        $client = $this->client([
            'isbn/9782205000962.json' => [
                'title' => 'Astérix -1- Astérix le Gaulois',
                'publishers' => ['Dargaud'],
                'publish_date' => '1984',
                'authors' => [['key' => '/authors/OL1A']],
                'languages' => [['key' => '/languages/fre']],
            ],
            'authors/OL1A.json' => ['name' => 'René Goscinny'],
        ]);

        $info = (new OpenLibraryScraper($client))->scrape('isbn:9782205000962');

        $this->assertSame('Astérix', $info->series);
        $this->assertSame('Astérix le Gaulois', $info->title);
        $this->assertSame(1, $info->number);
        $this->assertSame('René Goscinny', $info->writer);
        $this->assertNull($info->artist);
        $this->assertSame('Dargaud', $info->publisher);
        $this->assertSame(1984, $info->year);
        $this->assertSame('9782205000962', $info->isbn);
        $this->assertSame('fr', $info->languageIso);
    }

    public function testScrapeWorkUsesSeriesAndDescription(): void
    {
        $client = $this->client([
            'works/OL45883W.json' => [
                'title' => 'Le Devin',
                'description' => ['type' => '/type/text', 'value' => 'Une aventure.'],
                'authors' => [['author' => ['key' => '/authors/OL1A']], ['author' => ['key' => '/authors/OL2A']]],
                'series' => ['Une Aventure d\'Astérix'],
            ],
            'authors/OL1A.json' => ['name' => 'René Goscinny'],
            'authors/OL2A.json' => ['name' => 'Albert Uderzo'],
        ]);

        $info = (new OpenLibraryScraper($client))->scrape('https://openlibrary.org/works/OL45883W');

        $this->assertSame('Une Aventure d\'Astérix', $info->series);
        $this->assertSame('Le Devin', $info->title);
        $this->assertSame('René Goscinny', $info->writer);
        $this->assertSame('Albert Uderzo', $info->artist);
        $this->assertSame('Une aventure.', $info->summary);
    }

    public function testNetworkErrorReturnsEmptyResults(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);

        $this->assertSame([], (new OpenLibraryScraper($client))->search('asterix'));
    }

    /** @param array<string, array<string, mixed>> $routes path suffix => JSON body */
    private function client(array $routes): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($routes): MockResponse {
            foreach ($routes as $suffix => $body) {
                if (str_contains($url, $suffix)) {
                    return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }
}
