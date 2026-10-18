<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use App\Entity\Setting;
use App\Scraper\BdgestScraper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;

class ScrapeControllersTest extends WebTestCase
{
    public function testReadMetadataReturnsNullForNoMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/scrape-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/read', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['comic_info']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testReadMetadataWithRealXml(): void
    {
        $tmpDir = sys_get_temp_dir().'/scrape2-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $xml = '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->addFromString('ComicInfo.xml', $xml);
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/read', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Spawn', $data['comic_info']['series']);
        $this->assertSame(1, $data['comic_info']['number']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testUpdateMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/scrape3-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/update', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'series' => 'Spawn',
            'number' => '1',
            'title' => 'Origins',
            'writer' => 'Todd McFarlane',
            'artist' => 'Todd McFarlane',
            'penciler' => 'Todd McFarlane',
            'inker' => 'Someone',
            'colorist' => 'Another',
            'letterer' => 'Yet Another',
            'cover_artist' => 'Cover Artist',
            'publisher' => 'Image',
            'year' => '1992',
            'summary' => 'Great comic',
            'isbn' => '9780000000000',
            'age_rating' => '16+',
            'language_iso' => 'fr',
            'genre' => 'Superhero, Horror',
            'characters' => 'Spawn, Violator',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['written']);

        // Verify it was actually written
        $client->request('POST', '/api/files/scrape/read', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Spawn', $data['comic_info']['series']);
        $this->assertSame('Origins', $data['comic_info']['title']);
        $this->assertSame(1, $data['comic_info']['number']);
        $this->assertSame('Todd McFarlane', $data['comic_info']['writer']);
        $this->assertSame('Todd McFarlane', $data['comic_info']['artist']);
        $this->assertSame('Image', $data['comic_info']['publisher']);
        $this->assertSame(1992, $data['comic_info']['year']);
        $this->assertSame(['Superhero', 'Horror'], $data['comic_info']['genre']);
        $this->assertSame(['Spawn', 'Violator'], $data['comic_info']['characters']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testDeleteMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/scrape4-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Test</Series></ComicInfo>');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['deleted']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testLibraryNotFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/read', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => 99999,
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testCoverEndpointReturnsImage(): void
    {
        $tmpDir = sys_get_temp_dir().'/cover-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'fake-image-binary');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('GET', '/api/files/cover', [
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->assertResponseHeaderSame('Cache-Control', 'max-age=3600, private');

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testCoverEndpointReturnsSvgForMissingLibrary(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/files/cover', [
            'library_id' => 99999,
            'path' => 'test.cbz',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCoverEndpointMissingParams(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/files/cover');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testScrapeUrlNoScraperFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/url', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'url' => 'https://example.com/comic',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No scraper supports this URL', $data['error']);
    }

    public function testScrapeUrlWithFakeScraper(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/url', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'url' => 'fake:spawn',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('comic_info', $data);
        $this->assertSame('Spawn', $data['comic_info']['series']);
        $this->assertSame('Todd McFarlane', $data['comic_info']['writer']);
    }

    public function testScrapeUrlWithScraperDisabled(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $setting = new Setting();
        $setting->setKey('scraper.Fake.enabled');
        $setting->setValue('0');
        $em->persist($setting);
        $em->flush();

        $client->request('POST', '/api/files/scrape/url', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'url' => 'fake:spawn',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('This scraper is disabled', $data['error']);
    }

    public function testSearchWithQuery(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/search', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'query' => 'spawn',
            'scraper' => 'Fake',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $data);
        $this->assertNotEmpty($data['results']);
    }

    public function testSearchWithUnknownScraper(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/search', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'query' => 'spawn',
            'scraper' => 'NonExistent',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Scraper "NonExistent" not found or disabled', $data['error']);
    }

    public function testSearchWithDisabledScraper(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $setting = new Setting();
        $setting->setKey('scraper.NonExistent.enabled');
        $setting->setValue('0');
        $em->persist($setting);
        $em->flush();

        $client->request('POST', '/api/files/scrape/search', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'query' => 'spawn',
            'scraper' => 'NonExistent',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Scraper "NonExistent" not found or disabled', $data['error']);
    }

    public function testConfirmIdentifier(): void
    {
        $tmpDir = sys_get_temp_dir().'/confirm-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'fake:spawn',
            'scraper' => 'Fake',
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('comic_info', $data);
        $this->assertSame('Spawn', $data['comic_info']['series']);
        $this->assertTrue($data['written']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testConfirmIdentifierLibraryNotFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'fake:spawn',
            'scraper' => 'Fake',
            'library_id' => 99999,
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testConfirmIdentifierWithDisabledScraper(): void
    {
        $tmpDir = sys_get_temp_dir().'/confirm-disabled-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $setting = new Setting();
        $setting->setKey('scraper.NonExistent.enabled');
        $setting->setValue('0');
        $em->persist($setting);
        $em->flush();

        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'fake:spawn',
            'scraper' => 'NonExistent',
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Scraper "NonExistent" not found or disabled', $data['error']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testConfirmIdentifierScraperNotFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'fake:spawn',
            'scraper' => 'NonExistent',
            'library_id' => 1,
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testConfirmBatchStoresPayload(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath('/tmp');
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'fake:spawn',
            'scraper' => 'Fake',
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'batch' => true,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['queued']);

        $task = $em->find(\App\Entity\Task::class, $data['task_id']);
        $this->assertNotNull($task);
        $payload = json_decode($task->getPayload() ?? '', true);
        $this->assertSame('Fake', $payload['scraper']);
        $this->assertSame('fake:spawn', $payload['confirm_identifier']);
    }

    public function testCoverEndpointNoImages(): void
    {
        $tmpDir = sys_get_temp_dir().'/cover-noimg-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'not an image');
        $zip->close();

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('GET', '/api/files/cover', [
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('corrupted', $content);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testCoverEndpointBrokenArchive(): void
    {
        $tmpDir = sys_get_temp_dir().'/cover-broken-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        file_put_contents($cbzPath, 'this is not a valid zip archive');

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('GET', '/api/files/cover', [
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('corrupted', $content);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testConfirmIdentifierWithTransportException(): void
    {
        $tmpDir = sys_get_temp_dir().'/scraper-transport-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        touch($cbzPath);

        $client = static::createClient();
        $container = $client->getContainer();

        $mockClient = new MockHttpClient(static function () {
            throw new TransportException('Connection timeout');
        });
        $bdGestScraper = new BdgestScraper($mockClient);
        $container->set(BdgestScraper::class, $bdGestScraper);

        $em = $container->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/scrape/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'confirm_identifier' => 'https://bdgest.com/album/test',
            'scraper' => 'BDGest',
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(502);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Connection error:', $data['error']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testCoverEndpointWithCbrFile(): void
    {
        $tmpDir = sys_get_temp_dir().'/cover-cbr-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbrPath = $tmpDir.'/test.cbr';
        file_put_contents($cbrPath, 'fake rar content');

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('GET', '/api/files/cover', [
            'library_id' => $library->getId(),
            'path' => 'test.cbr',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('corrupted', $content);

        unlink($cbrPath);
        rmdir($tmpDir);
    }
}
