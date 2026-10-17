<?php

namespace App\Tests\Service;

use App\Model\ComicInfo;
use App\Service\ArchiveService;
use App\Service\FileSystemHelper;
use App\Service\MetadataService;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

class MetadataServiceTest extends TestCase
{
    private MetadataService $service;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->service = new MetadataService(new ArchiveService());
    }

    public function testReadReturnsNullWhenNoComicInfo(): void
    {
        $path = $this->createTestCbz(['page01.jpg']);

        $result = $this->service->read($path);

        $this->assertNull($result);
    }

    public function testReadReturnsComicInfoWhenPresent(): void
    {
        $xml = '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series><Number>1</Number></ComicInfo>';
        $path = $this->createTestCbz(['page01.jpg', 'ComicInfo.xml'], ['ComicInfo.xml' => $xml]);

        $info = $this->service->read($path);

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame(1, $info->number);
    }

    public function testWriteInsertsComicInfoIntoArchive(): void
    {
        $path = $this->createTestCbz(['page01.jpg']);
        $info = new ComicInfo(series: 'Spawn', number: 1, title: 'La Naissance');

        $this->service->write($path, $info);

        $read = $this->service->read($path);
        $this->assertNotNull($read);
        $this->assertSame('Spawn', $read->series);
        $this->assertSame(1, $read->number);
    }

    public function testCreateEmpty(): void
    {
        $info = $this->service->createEmpty();

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertNull($info->series);
        $this->assertNull($info->number);
        $this->assertNull($info->title);
    }

    public function testDeleteRemovesComicInfo(): void
    {
        $xml = '<?xml version="1.0"?><ComicInfo><Series>Spawn</Series></ComicInfo>';
        $path = $this->createTestCbz(['page01.jpg', 'ComicInfo.xml'], ['ComicInfo.xml' => $xml]);

        $this->assertNotNull($this->service->read($path));

        $this->service->delete($path);

        $this->assertNull($this->service->read($path));
    }

    public function testDeleteWithoutMetadata(): void
    {
        $path = $this->createTestCbz(['page01.jpg']);

        $this->service->delete($path);

        $this->assertNull($this->service->read($path));
    }

    public function testWriteUpdatesExistingComicInfo(): void
    {
        $oldXml = '<?xml version="1.0"?><ComicInfo><Series>Old</Series></ComicInfo>';
        $path = $this->createTestCbz(['page01.jpg', 'ComicInfo.xml'], ['ComicInfo.xml' => $oldXml]);
        $info = new ComicInfo(series: 'Updated', number: 2);

        $this->service->write($path, $info);

        $read = $this->service->read($path);
        $this->assertSame('Updated', $read->series);
        $this->assertSame(2, $read->number);
    }

    #[WithoutErrorHandler]
    public function testWriteFailsOnReadOnlyDirectory(): void
    {
        if (\function_exists('posix_getuid') && 0 === posix_getuid()) {
            $this->markTestSkipped('Cannot test rename failure as root (chmod does not block root)');
        }

        $tmpDir = sys_get_temp_dir().'/meta-write-fail-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';

        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->close();

        chmod($tmpDir, 0555);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write');

        try {
            $this->service->write($cbzPath, new ComicInfo(series: 'Test', number: 1));
        } finally {
            chmod($tmpDir, 0777);
            @unlink($cbzPath);
            @rmdir($tmpDir);
        }
    }

    #[WithoutErrorHandler]
    public function testDeleteFailsOnReadOnlyDirectory(): void
    {
        if (\function_exists('posix_getuid') && 0 === posix_getuid()) {
            $this->markTestSkipped('Cannot test rename failure as root (chmod does not block root)');
        }

        $tmpDir = sys_get_temp_dir().'/meta-delete-fail-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbzPath = $tmpDir.'/test.cbz';

        $zip = new \ZipArchive();
        $zip->open($cbzPath, \ZipArchive::CREATE);
        $zip->addFromString('page01.jpg', 'image-data');
        $zip->addFromString('ComicInfo.xml', '<?xml version="1.0"?><ComicInfo><Series>Test</Series></ComicInfo>');
        $zip->close();

        chmod($tmpDir, 0555);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete');

        try {
            $this->service->delete($cbzPath);
        } finally {
            chmod($tmpDir, 0777);
            @unlink($cbzPath);
            @rmdir($tmpDir);
        }
    }

    private function createTestCbz(array $files, array $contents = []): string
    {
        $path = sys_get_temp_dir().'/test-'.uniqid().'.cbz';
        $this->tempFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $file) {
            $zip->addFromString($file, $contents[$file] ?? 'data');
        }
        $zip->close();

        return $path;
    }

    public function testRrmdirReturnsEarlyForFile(): void
    {
        $file = sys_get_temp_dir().'/rrmdir_file_'.uniqid();
        touch($file);

        FileSystemHelper::rrmdir($file);

        $this->assertFileExists($file);
        unlink($file);
    }

    public function testRrmdirRemovesNestedDirectories(): void
    {
        $base = sys_get_temp_dir().'/rrmdir_nested_'.uniqid();
        mkdir($base.'/sub', 0777, true);
        touch($base.'/sub/file.txt');
        touch($base.'/root.txt');

        FileSystemHelper::rrmdir($base);

        $this->assertFileDoesNotExist($base);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }
}
