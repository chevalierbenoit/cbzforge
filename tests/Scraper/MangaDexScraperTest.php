<?php

namespace App\Tests\Scraper;

use App\Scraper\MangaDexScraper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MangaDexScraperTest extends TestCase
{
    private const ID = 'a1c7c817-4e59-43b7-9365-09675a149a6f';

    public function testSupportsMangaDexTitleUrls(): void
    {
        $scraper = new MangaDexScraper(new MockHttpClient());

        $this->assertSame('MangaDex', $scraper->getName());
        $this->assertTrue($scraper->supports('https://mangadex.org/title/'.self::ID.'/one-piece'));
        $this->assertFalse($scraper->supports('https://mangadex.org/'));
        $this->assertSame([], $scraper->getConfigFields());
    }

    public function testSearchReturnsSeriesWithCover(): void
    {
        $body = ['data' => [[
            'id' => self::ID,
            'attributes' => ['title' => ['en' => 'One Piece'], 'year' => 1997],
            'relationships' => [['type' => 'cover_art', 'attributes' => ['fileName' => 'cover.jpg']]],
        ]]];
        $client = new MockHttpClient(new MockResponse(json_encode($body), ['http_code' => 200]));

        $results = (new MangaDexScraper($client))->search('one piece');

        $this->assertCount(1, $results);
        $this->assertSame(self::ID, $results[0]->identifier);
        $this->assertSame('One Piece', $results[0]->title);
        $this->assertSame(1997, $results[0]->year);
        $this->assertSame('https://uploads.mangadex.org/covers/'.self::ID.'/cover.jpg.256.jpg', $results[0]->coverUrl);
        $this->assertSame('https://mangadex.org/title/'.self::ID, $results[0]->pageUrl);
    }

    public function testScrapeReadsSeriesCreditsAndGenres(): void
    {
        $body = ['data' => [
            'id' => self::ID,
            'attributes' => [
                'title' => ['ja' => 'ワンピース', 'en' => 'One Piece'],
                'year' => 1997,
                'originalLanguage' => 'ja',
                'description' => ['en' => 'Pirates [b]adventure[/b] [url=https://x]link[/url]'],
                'tags' => [
                    ['attributes' => ['name' => ['en' => 'Action'], 'group' => 'genre']],
                    ['attributes' => ['name' => ['en' => 'Time Travel'], 'group' => 'theme']],
                ],
            ],
            'relationships' => [
                ['type' => 'author', 'attributes' => ['name' => 'Oda Eiichiro']],
                ['type' => 'artist', 'attributes' => ['name' => 'Oda Eiichiro']],
            ],
        ]];
        $client = new MockHttpClient(new MockResponse(json_encode($body), ['http_code' => 200]));

        $info = (new MangaDexScraper($client))->scrape('https://mangadex.org/title/'.self::ID.'/one-piece');

        $this->assertSame('One Piece', $info->series);
        $this->assertNull($info->number);
        $this->assertSame('Oda Eiichiro', $info->writer);
        $this->assertSame(1997, $info->year);
        $this->assertSame('Pirates adventure link', $info->summary);
        $this->assertSame(['Action'], $info->genre);
        $this->assertSame('ja', $info->languageIso);
    }

    public function testScrapeWithoutUuidReturnsEmptyInfo(): void
    {
        $client = new MockHttpClient([]);

        $this->assertNull((new MangaDexScraper($client))->scrape('not-an-id')->series);
    }
}
