<?php

namespace App\Tests\Service;

use App\Service\ArchiveService;
use PHPUnit\Framework\TestCase;

class ArchiveServiceTest extends TestCase
{
    private ArchiveService $service;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->service = new ArchiveService();
    }

    public function testListContentsReturnsFilesFromZip(): void
    {
        $archivePath = $this->createTestCbz(['page01.jpg', 'page02.jpg', 'ComicInfo.xml']);

        $contents = $this->service->listContents($archivePath);

        $this->assertContains('page01.jpg', $contents);
        $this->assertContains('ComicInfo.xml', $contents);
    }

    public function testExtractPageReturnsImageContent(): void
    {
        $archivePath = $this->createTestCbz(['page01.jpg', 'page02.jpg']);

        $content = $this->service->extractPage($archivePath, 'page01.jpg');

        $this->assertNotEmpty($content);
    }

    public function testHasMetadataReturnsTrueWhenComicInfoExists(): void
    {
        $xml = '<?xml version="1.0"?><ComicInfo><Series>Test</Series></ComicInfo>';
        $archivePath = $this->createTestCbz(['page01.jpg', 'ComicInfo.xml'], ['ComicInfo.xml' => $xml]);

        $this->assertTrue($this->service->hasMetadata($archivePath));
    }

    public function testHasMetadataReturnsFalseWhenNoComicInfo(): void
    {
        $archivePath = $this->createTestCbz(['page01.jpg']);

        $this->assertFalse($this->service->hasMetadata($archivePath));
    }

    public function testListContentsWithCbrThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->listContents('/fake/path.cbr');
    }

    public function testExtractPageThrowsExceptionForMissingEntry(): void
    {
        $archivePath = $this->createTestCbz(['page01.jpg']);

        $this->expectException(\RuntimeException::class);
        $this->service->extractPage($archivePath, 'missing.jpg');
    }

    public function testListContentsThrowsForBadArchive(): void
    {
        $path = sys_get_temp_dir().'/test-'.uniqid().'.cbz';
        $this->tempFiles[] = $path;
        file_put_contents($path, random_bytes(100));

        $this->expectException(\RuntimeException::class);
        $this->service->listContents($path);
    }

    public function testExtractPageThrowsForBadArchive(): void
    {
        $path = sys_get_temp_dir().'/test-'.uniqid().'.cbz';
        $this->tempFiles[] = $path;
        file_put_contents($path, random_bytes(100));

        $this->expectException(\RuntimeException::class);
        $this->service->extractPage($path, 'page01.jpg');
    }

    public function testHasMetadataReturnsFalseOnException(): void
    {
        $this->assertFalse($this->service->hasMetadata('/fake/path.cbr'));
    }

    public function testGetFirstImageReturnsNullForNonImageContents(): void
    {
        $archivePath = $this->createTestCbz(['ComicInfo.xml', 'notes.txt']);

        $result = $this->service->getFirstImage($archivePath);

        $this->assertNull($result);
    }

    public function testGetFirstImageReturnsNullForBrokenArchive(): void
    {
        $path = sys_get_temp_dir().'/test-'.uniqid().'.cbz';
        $this->tempFiles[] = $path;
        file_put_contents($path, random_bytes(100));

        $result = $this->service->getFirstImage($path);

        $this->assertNull($result);
    }

    public function testGetFirstImageReturnsNullWhenExtractPageFails(): void
    {
        $archivePath = $this->createTestCbz(['image.jpg']);

        $service = new class extends ArchiveService {
            public function extractPage(string $path, string $entryName): string
            {
                throw new \RuntimeException('Simulated failure');
            }
        };

        $result = $service->getFirstImage($archivePath);
        $this->assertNull($result);
    }

    private function createTestCbz(array $files, array $contents = []): string
    {
        $path = sys_get_temp_dir().'/test-'.uniqid().'.cbz';
        $this->tempFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $file) {
            $zip->addFromString($file, $contents[$file] ?? 'fake-image-data');
        }
        $zip->close();

        return $path;
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
