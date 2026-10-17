<?php

namespace App\Tests\Service;

use App\Service\ConversionService;
use App\Service\FileSystemHelper;
use PHPUnit\Framework\TestCase;

class ConversionServiceTest extends TestCase
{
    private ConversionService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new ConversionService();
        $this->tmpDir = sys_get_temp_dir().'/test_conv_'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    public function testNeedsConversionReturnsTrueForCbr(): void
    {
        $this->assertTrue($this->service->needsConversion('file.cbr'));
    }

    public function testNeedsConversionReturnsTrueForPdf(): void
    {
        $this->assertTrue($this->service->needsConversion('file.pdf'));
    }

    public function testNeedsConversionReturnsTrueForEpub(): void
    {
        $this->assertTrue($this->service->needsConversion('file.epub'));
    }

    public function testNeedsConversionReturnsFalseForCbz(): void
    {
        $this->assertFalse($this->service->needsConversion('file.cbz'));
    }

    public function testGetTargetPathReplacesExtension(): void
    {
        $this->assertStringEndsWith('.cbz', $this->service->getTargetPath('file.cbr'));
        $this->assertStringEndsWith('.cbz', $this->service->getTargetPath('file.pdf'));
        $this->assertStringEndsWith('.cbz', $this->service->getTargetPath('file.epub'));
        $this->assertStringEndsWith('.cbz', $this->service->getTargetPath('file.cbz'));
    }

    public function testConvertThrowsForNoConversionNeeded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->convert('file.cbz');
    }

    public function testConvertEpubToCbz(): void
    {
        $epubPath = $this->tmpDir.'/test.epub';
        $this->createEpub($epubPath, ['OEBPS/images/page_001.jpg' => $this->getTestImage()]);

        $result = $this->service->convert($epubPath);

        $this->assertStringEndsWith('.cbz', $result);
        $this->assertFileExists($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result));
        $this->assertGreaterThan(0, $zip->count());
        $names = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $names[] = $zip->statIndex($i)['name'];
        }
        $this->assertContains('page_001.jpg', $names);
        $zip->close();

        unlink($epubPath);
        unlink($result);
    }

    public function testConvertEpubWithMultipleImages(): void
    {
        $epubPath = $this->tmpDir.'/test.epub';
        $this->createEpub($epubPath, [
            'OEBPS/images/page_001.jpg' => $this->getTestImage(),
            'OEBPS/images/page_002.png' => $this->getTestImage(),
            'OEBPS/images/page_003.webp' => $this->getTestImage(),
        ]);

        $result = $this->service->convert($epubPath);
        $this->assertStringEndsWith('.cbz', $result);
        $this->assertFileExists($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result));
        $this->assertEquals(3, $zip->count());
        $zip->close();

        unlink($epubPath);
        unlink($result);
    }

    public function testConvertEpubNoImagesThrowsException(): void
    {
        $epubPath = $this->tmpDir.'/test.epub';
        $this->createEpub($epubPath, ['mimetype' => 'application/epub+zip']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No images found in EPUB');

        try {
            $this->service->convert($epubPath);
        } finally {
            $this->cleanupConvertTempDirs();
        }
    }

    public function testConvertEpubFailedToOpen(): void
    {
        $epubPath = $this->tmpDir.'/test.epub';
        file_put_contents($epubPath, 'not a zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open EPUB');

        try {
            $this->service->convert($epubPath);
        } finally {
            $this->cleanupConvertTempDirs();
        }
    }

    public function testConvertPdfToCbz(): void
    {
        if (!shell_exec('which pdftoppm 2>/dev/null')) {
            $this->markTestSkipped('pdftoppm (poppler-utils) not available');
        }

        $pdfPath = $this->tmpDir.'/test.pdf';
        $this->createMinimalPdf($pdfPath);

        $result = $this->service->convert($pdfPath);

        $this->assertStringEndsWith('.cbz', $result);
        $this->assertFileExists($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result));
        $this->assertGreaterThan(0, $zip->count());
        $zip->close();

        unlink($pdfPath);
        unlink($result);
    }

    public function testConvertPdfInvalidFileThrows(): void
    {
        $pdfPath = $this->tmpDir.'/test.pdf';
        file_put_contents($pdfPath, 'not a valid pdf');

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->convert($pdfPath);
        } finally {
            $this->cleanupConvertTempDirs();
        }
    }

    public function testConvertCbrFallsBackToUnrarOnGarbage(): void
    {
        if (\extension_loaded('rar')) {
            $this->markTestSkipped('RAR extension loaded, tests fallback path only');
        }

        $cbrPath = $this->tmpDir.'/test.cbr';
        file_put_contents($cbrPath, 'not a valid rar archive');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CBR extraction failed');

        try {
            $this->service->convert($cbrPath);
        } finally {
            $this->cleanupConvertTempDirs();
        }
    }

    public function testConvertCbrThatIsActuallyZipConvertsToCbz(): void
    {
        $cbrPath = $this->tmpDir.'/mislabeled.cbr';
        $this->createEpub($cbrPath, [
            'Kill La Kill T01/001.jpg' => $this->getTestImage(),
            'Kill La Kill T01/002.jpg' => $this->getTestImage(),
        ]);

        $result = $this->service->convert($cbrPath);

        $this->assertStringEndsWith('.cbz', $result);
        $this->assertFileExists($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result));
        $names = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $names[] = $zip->statIndex($i)['name'];
        }
        $this->assertContains('Kill La Kill T01/001.jpg', $names);
        $this->assertContains('Kill La Kill T01/002.jpg', $names);
        $zip->close();

        unlink($cbrPath);
        unlink($result);
    }

    public function testConvertCbrUsesRarExtensionWhenAvailable(): void
    {
        if (!\extension_loaded('rar')) {
            $this->markTestSkipped('RAR extension not available');
        }

        $cbrPath = __DIR__.'/test.cbr';
        if (!file_exists($cbrPath)) {
            $this->markTestSkipped('Test RAR file not found');
        }

        $result = $this->service->convert($cbrPath);

        $this->assertStringEndsWith('.cbz', $result);
        $this->assertFileExists($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result));
        $names = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $names[] = $zip->statIndex($i)['name'];
        }
        $this->assertContains('page_001.jpg', $names);
        $zip->close();

        unlink($result);
    }

    public function testExtractRarReflectionFallback(): void
    {
        if (\extension_loaded('rar')) {
            $this->markTestSkipped('RAR extension loaded, tests fallback path only');
        }

        $method = new \ReflectionMethod(ConversionService::class, 'extractRar');

        $destDir = $this->tmpDir.'/extract_dest';
        mkdir($destDir, 0777, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CBR extraction failed');

        $method->invoke($this->service, $this->tmpDir.'/nonexistent.cbr', $destDir);
    }

    public function testRrmdirWithNonExistentDir(): void
    {
        FileSystemHelper::rrmdir($this->tmpDir.'/does/not/exist');
        $this->assertTrue(true);
    }

    public function testRrmdirRemovesDirectoryWithFiles(): void
    {
        $testDir = $this->tmpDir.'/rrmdir_test';
        mkdir($testDir, 0777, true);
        file_put_contents($testDir.'/file1.txt', 'content');
        mkdir($testDir.'/subdir', 0777, true);
        file_put_contents($testDir.'/subdir/file2.txt', 'content');

        $this->assertDirectoryExists($testDir);

        FileSystemHelper::rrmdir($testDir);

        $this->assertDirectoryDoesNotExist($testDir);
    }

    public function testRecompressToCbzCreatesValidZip(): void
    {
        $sourceDir = $this->tmpDir.'/recompress_src';
        mkdir($sourceDir, 0777, true);
        file_put_contents($sourceDir.'/page_001.jpg', $this->getTestImage());
        file_put_contents($sourceDir.'/page_002.jpg', $this->getTestImage());

        $targetPath = $this->tmpDir.'/output.cbz';

        $method = new \ReflectionMethod(ConversionService::class, 'recompressToCbz');
        $method->invoke($this->service, $sourceDir, $targetPath);

        $this->assertFileExists($targetPath);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($targetPath));
        $this->assertEquals(2, $zip->count());
        $this->assertEquals('page_001.jpg', $zip->statIndex(0)['name']);
        $this->assertEquals('page_002.jpg', $zip->statIndex(1)['name']);
        $zip->close();

        unlink($targetPath);
    }

    public function testAddDirToZipWithSubdirectories(): void
    {
        $sourceDir = $this->tmpDir.'/adddir_src';
        mkdir($sourceDir, 0777, true);
        mkdir($sourceDir.'/sub', 0777, true);
        file_put_contents($sourceDir.'/page_001.jpg', $this->getTestImage());
        file_put_contents($sourceDir.'/sub/page_002.jpg', $this->getTestImage());

        $targetPath = $this->tmpDir.'/output.cbz';

        $recompress = new \ReflectionMethod(ConversionService::class, 'recompressToCbz');
        $recompress->invoke($this->service, $sourceDir, $targetPath);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($targetPath));
        $names = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $names[] = $zip->statIndex($i)['name'];
        }
        $this->assertContains('page_001.jpg', $names);
        $this->assertContains('sub/page_002.jpg', $names);
        $zip->close();

        unlink($targetPath);
    }

    protected function tearDown(): void
    {
        $this->cleanupConvertTempDirs();
        if (is_dir($this->tmpDir)) {
            $this->cleanupDir($this->tmpDir);
        }
        unset($this->service);
    }

    private function cleanupConvertTempDirs(): void
    {
        foreach (glob(sys_get_temp_dir().'/convert-*') as $dir) {
            if (is_dir($dir)) {
                $this->cleanupDir($dir);
            }
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    private function createEpub(string $path, array $images = []): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE)) {
            throw new \RuntimeException("Failed to create EPUB: {$path}");
        }
        foreach ($images as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    private function getTestImage(): string
    {
        return hex2bin('ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffc400141101000000000000000000000000000000ffc4001a1100030101010100000000000000000000000000010203110405ffda000c03020002110311003f00f8000000003fffd9');
    }

    private function createMinimalPdf(string $path): void
    {
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
        $pdf .= "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n";
        $pdf .= "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n";
        $pdf .= "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n";
        $pdf .= "trailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
        file_put_contents($path, $pdf);
    }
}
