<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RenameControllerTest extends WebTestCase
{
    use AdminClientTrait;

    public function testPreviewRequiresLibraryId(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/api/files/rename/preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['path' => 'test.cbz']));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('library_id required', $data['error']);
    }

    public function testPreviewLibraryNotFound(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/api/files/rename/preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['library_id' => 99999, 'path' => 'test.cbz']));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testPreviewNoMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No metadata. Please scrape first.', $data['error']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testExecuteRequiresLibraryId(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['path' => 'test.cbz']));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('library_id required', $data['error']);
    }

    public function testExecuteLibraryNotFound(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['library_id' => 99999, 'path' => 'test.cbz']));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testExecuteNoMetadata(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename2-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No metadata. Please scrape first.', $data['error']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testExecuteWithCustomPath(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename3-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $customPath = $tmpDir.'/renamed.cbz';
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'new_path' => $customPath,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($customPath, $data['new_path']);
        $this->assertFileExists($customPath);
    }

    public function testPreviewWithEpubConversion(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-epub-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $epubPath = $tmpDir.'/test.epub';
        $zip = new \ZipArchive();
        $zip->open($epubPath, \ZipArchive::CREATE);
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.epub',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringEndsWith('.cbz', $data['new_filename']);
        $this->assertStringEndsWith('.cbz', $data['new_path']);

        unlink($epubPath);
        rmdir($tmpDir);
    }

    public function testExecuteConversionFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-epub2-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $epubPath = $tmpDir.'/test.epub';
        $zip = new \ZipArchive();
        $zip->open($epubPath, \ZipArchive::CREATE);
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.epub',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No images found in EPUB', $data['error']);

        unlink($epubPath);
        rmdir($tmpDir);
    }

    public function testExecuteWithCustomPathNewDir(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename4-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $customPath = $tmpDir.'/subdir/renamed.cbz';
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'new_path' => $customPath,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($customPath, $data['new_path']);
        $this->assertFileExists($customPath);

        unlink($customPath);
        rmdir($tmpDir.'/subdir');
        rmdir($tmpDir);
    }

    public function testExecuteRejectsCustomPathOutsideLibrary(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-outside-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir.'/library');
        mkdir($library->getPath(), 0777, true);
        $cbzPath = $library->getPath().'/test.cbz';
        copy($tmpDir.'/test.cbz', $cbzPath);
        $em->persist($library);
        $em->flush();

        $outsidePath = $tmpDir.'/escaped.cbz';
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'new_path' => $outsidePath,
        ]));

        $this->assertResponseStatusCodeSame(400);
        $this->assertFileDoesNotExist($outsidePath);
        $this->assertFileExists($cbzPath);

        unlink($cbzPath);
        unlink($tmpDir.'/test.cbz');
        rmdir($library->getPath());
        rmdir($tmpDir);
    }

    public function testExecuteAutomaticRename(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename5-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Spawn', $data['new_path']);
        $this->assertFileExists($data['new_path']);

        $seriesDir = \dirname($data['new_path']);
        unlink($data['new_path']);
        rmdir($seriesDir);
        rmdir($tmpDir);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testExecuteWithCustomPathRenameFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename6-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        touch($tmpDir.'/blocking-file');

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $customPath = $tmpDir.'/blocking-file/renamed.cbz';
        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'new_path' => $customPath,
        ]));

        $this->assertResponseStatusCodeSame(500);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Unable to rename to:', $data['error']);

        unlink($tmpDir.'/blocking-file');
        unlink($cbzPath);
        rmdir($tmpDir);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testExecuteAutomaticRenameFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename7-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>');
        $zip->close();

        touch($tmpDir.'/Spawn');

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
        ]));

        $this->assertResponseStatusCodeSame(500);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Failed to rename', $data['error']);

        unlink($tmpDir.'/Spawn');
        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testPreviewWithExpression(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-prev-expr-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number><Title>La Naissance</Title></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'expression' => '{serie}/{numero} - {titre}',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Spawn/1 - La Naissance.cbz', $data['new_path']);

        unlink($cbzPath);
        rmdir($tmpDir);
    }

    public function testExecuteWithExpression(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename-expr-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';
        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number><Title>La Naissance</Title></ComicInfo>');
        $zip->close();

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename/execute', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbz',
            'expression' => '{serie} #{numero} - {titre}',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Spawn #1 - La Naissance.cbz', $data['new_path']);
        $this->assertFileExists($data['new_path']);

        unlink($data['new_path']);
        $seriesDir = \dirname($data['new_path']);
        rmdir($seriesDir);
        rmdir($tmpDir);
    }
}
