<?php

namespace App\Tests\Scraper;

use App\Model\ComicInfo;
use App\Scraper\GoogleBooksScraper;
use App\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GoogleBooksScraperTest extends TestCase
{
    public function testGetName(): void
    {
        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SettingsService::class),
        );
        $this->assertSame('Google Books', $scraper->getName());
    }

    public function testGetConfigFields(): void
    {
        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SettingsService::class),
        );
        $fields = $scraper->getConfigFields();
        $this->assertCount(1, $fields);
        $this->assertSame('google_books_api_key', $fields[0]['key']);
        $this->assertSame('password', $fields[0]['type']);
    }

    public function testSupportsGoogleBooksId(): void
    {
        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SettingsService::class),
        );
        $this->assertTrue($scraper->supports('abc123XYZ'));
        $this->assertTrue($scraper->supports('volume-id-123'));
    }

    public function testSupportsRejectsInvalid(): void
    {
        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SettingsService::class),
        );
        $this->assertFalse($scraper->supports('http://example.com'));
        $this->assertFalse($scraper->supports('some.path.with.dots'));
    }

    public function testSearchReturnsResults(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'items' => [
                [
                    'id' => 'vol123',
                    'volumeInfo' => [
                        'title' => 'Spawn',
                        'subtitle' => 'Questions personnelles',
                        'publishedDate' => '2020',
                        'publisher' => 'Image Comics',
                        'imageLinks' => ['thumbnail' => 'https://example.com/cover.jpg'],
                        'canonicalVolumeLink' => 'https://books.google.com/books?id=vol123',
                    ],
                ],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $results = $scraper->search('Spawn');

        $this->assertCount(1, $results);
        $this->assertSame('vol123', $results[0]->identifier);
        $this->assertSame('Spawn', $results[0]->title);
    }

    public function testSearchReturnsEmptyWhenNoKey(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(false);

        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $settings,
        );
        $results = $scraper->search('Spawn');
        $this->assertSame([], $results);
    }

    public function testScrapeReturnsComicInfo(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Spawn',
                'authors' => ['Todd McFarlane'],
                'publishedDate' => '2020-06-15',
                'description' => 'A great comic.',
                'language' => 'en',
                'industryIdentifiers' => [
                    ['type' => 'ISBN_13', 'identifier' => '9781234567890'],
                ],
                'categories' => ['Comics', 'Horror'],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->title);
        $this->assertSame('Todd McFarlane', $info->artist);
        $this->assertSame(2020, $info->year);
        $this->assertSame('A great comic.', $info->summary);
        $this->assertSame('9781234567890', $info->isbn);
        $this->assertSame('en', $info->languageIso);
        $this->assertSame(['Comics', 'Horror'], $info->genre);
    }

    public function testScrapeReturnsEmptyInfoWhenNoKey(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(false);

        $scraper = new GoogleBooksScraper(
            $this->createStub(HttpClientInterface::class),
            $settings,
        );
        $info = $scraper->scrape('vol123');
        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertNull($info->title);
    }

    public function testSearchHandlesHttpError(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(
            $this->createStub(TransportExceptionInterface::class),
        );

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $results = $scraper->search('Spawn');
        $this->assertSame([], $results);
    }

    public function testScrapeHandlesHttpError(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(
            $this->createStub(HttpExceptionInterface::class),
        );

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');
        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertNull($info->title);
    }

    public function testScrapeUsesSeriesInfo(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Astérix - L\'Iris blanc',
                'subtitle' => 'Tome 40',
                'seriesInfo' => [
                    'volumeSeries' => [
                        ['seriesId' => 'Astérix', 'orderNumber' => 40],
                    ],
                ],
                'authors' => ['Fabcaro'],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertSame('Astérix', $info->series);
        $this->assertSame(40, $info->number);
        $this->assertSame('Tome 40', $info->title);
    }

    public function testScrapeParseFrenchBdTitleSeriesTitleNumber(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Astérix - L\'Iris blanc - n°40',
                'authors' => ['Fabcaro'],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertSame('Astérix', $info->series);
        $this->assertSame(40, $info->number);
        $this->assertSame('L\'Iris blanc', $info->title);
    }

    public function testScrapeParseFrenchBdTitleTomePattern(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Astérix Tome 40 - L\'Iris blanc',
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertSame('Astérix', $info->series);
        $this->assertSame(40, $info->number);
        $this->assertSame('L\'Iris blanc', $info->title);
    }

    public function testScrapeParseFrenchBdTitleNumberDashTitle(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Astérix 40 - L\'Iris blanc',
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertSame('Astérix', $info->series);
        $this->assertSame(40, $info->number);
        $this->assertSame('L\'Iris blanc', $info->title);
    }

    public function testScrapeParseSimpleSeriesDashTitle(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Spawn - Questions personnelles',
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Questions personnelles', $info->title);
        $this->assertNull($info->number);
    }

    public function testScrapeFallsBackToFullTitleWhenNoPatternMatches(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('has')->with('google_books_api_key')->willReturn(true);
        $settings->method('get')->willReturn('test-key');

        $responseData = [
            'id' => 'vol123',
            'volumeInfo' => [
                'title' => 'Some Plain Book Title',
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode($responseData));
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $scraper = new GoogleBooksScraper($httpClient, $settings);
        $info = $scraper->scrape('vol123');

        $this->assertNull($info->series);
        $this->assertNull($info->number);
        $this->assertSame('Some Plain Book Title', $info->title);
    }
}
