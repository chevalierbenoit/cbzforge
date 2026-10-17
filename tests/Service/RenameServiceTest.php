<?php

namespace App\Tests\Service;

use App\Dto\RenamePreview;
use App\Model\ComicInfo;
use App\Service\RenameService;
use App\Service\RenameTemplate;
use PHPUnit\Framework\TestCase;

class RenameServiceTest extends TestCase
{
    private RenameService $service;

    protected function setUp(): void
    {
        $this->service = new RenameService(new RenameTemplate());
    }

    public function testPreviewGeneratesCorrectPath(): void
    {
        $info = new ComicInfo(series: 'Spawn', title: 'La Naissance', number: 1);
        $preview = $this->service->preview('/library/old-name.cbz', $info);

        $this->assertInstanceOf(RenamePreview::class, $preview);
        $this->assertStringContainsString('Spawn', $preview->newPath);
        $this->assertStringContainsString('Spawn #1', $preview->newFilename);
        $this->assertStringNotContainsString('La Naissance', $preview->newFilename);
    }

    public function testPreviewUsesSeriesFromInfo(): void
    {
        $info = new ComicInfo(series: 'Batman', title: 'The Killing Joke', number: null);
        $preview = $this->service->preview('/lib/test.cbz', $info);

        $this->assertStringContainsString('Batman', $preview->newPath);
    }

    public function testGetNewPath(): void
    {
        $info = new ComicInfo(series: 'Spawn', title: 'La Naissance', number: 1);
        $path = $this->service->getNewPath('/library', 'old-name.cbz', $info);

        $this->assertStringContainsString('Spawn', $path);
        $this->assertStringContainsString('Spawn #1', $path);
        $this->assertStringNotContainsString('La Naissance', $path);
        $this->assertStringContainsString('.cbz', $path);
    }

    public function testGetNewPathWithYear(): void
    {
        $info = new ComicInfo(series: 'Chroniques de la Lune Noire', number: 23, year: 2026);
        $path = $this->service->getNewPath('/library', 'file.cbz', $info);

        $this->assertStringContainsString('Chroniques de la Lune Noire', $path);
        $this->assertStringContainsString('Chroniques de la Lune Noire #23 (2026)', $path);
    }

    public function testGetNewPathWithoutNumber(): void
    {
        $info = new ComicInfo(series: 'Batman', title: 'The Killing Joke');
        $path = $this->service->getNewPath('/library', 'file.cbz', $info);

        $this->assertStringContainsString('Batman', $path);
        $this->assertStringContainsString('The Killing Joke.cbz', $path);
    }

    public function testGetNewPathWithoutSeries(): void
    {
        $info = new ComicInfo(number: 1);
        $path = $this->service->getNewPath('/library', 'file.cbz', $info);

        $this->assertSame('/library/T01.cbz', $path);
    }

    public function testBuildFilenameWithOnlyNumber(): void
    {
        $info = new ComicInfo(number: 1);

        $reflection = new \ReflectionMethod(RenameService::class, 'buildFilename');
        $result = $reflection->invoke($this->service, $info, 'cbz');

        $this->assertSame('T01.cbz', $result);
    }

    public function testBuildFilenameUnknown(): void
    {
        $info = new ComicInfo();

        $reflection = new \ReflectionMethod(RenameService::class, 'buildFilename');
        $result = $reflection->invoke($this->service, $info, 'cbz');

        $this->assertSame('Unknown.cbz', $result);
    }

    public function testSanitizeFilename(): void
    {
        $reflection = new \ReflectionMethod(RenameService::class, 'sanitizeFilename');
        $result = $reflection->invoke($this->service, 'a/b\\c:d*e?f"g<h>i|j');

        $this->assertSame('a b c d e f g h i j', $result);
    }

    public function testGetNewPathReturnsOriginalWhenUnknown(): void
    {
        $info = new ComicInfo();
        $path = $this->service->getNewPath('/library', 'original-file.cbz', $info);

        $this->assertSame('/library/original-file.cbz', $path);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testRenameFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename_fail_'.uniqid();
        mkdir($tmpDir, 0777, true);
        $file = $tmpDir.'/nonexistent.cbz';

        $info = new ComicInfo(series: 'Test', number: 1);

        $this->expectException(\RuntimeException::class);
        $this->service->rename($file, $info);

        $newDir = $tmpDir.'/Test';
        if (is_dir($newDir)) {
            rmdir($newDir);
        }
        rmdir($tmpDir);
    }

    public function testRenameWithActualFile(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename_test_'.uniqid();
        mkdir($tmpDir, 0777, true);
        $file = $tmpDir.'/test.cbz';
        touch($file);

        $info = new ComicInfo(series: 'Test Series', number: 1);
        $newPath = $this->service->rename($file, $info);

        $this->assertStringContainsString('Test Series', $newPath);
        $this->assertStringContainsString('Test Series #1.cbz', $newPath);
        $this->assertFileExists($newPath);
        $this->assertFileDoesNotExist($file);

        unlink($newPath);
        rmdir(\dirname($newPath));
        rmdir($tmpDir);
    }

    public function testPreviewWithExpression(): void
    {
        $info = new ComicInfo(series: 'Spawn', title: 'La Naissance', number: 1);
        $preview = $this->service->preview('/library/old-name.cbz', $info, '{serie} #{numero} - {titre}');

        $this->assertInstanceOf(RenamePreview::class, $preview);
        $this->assertStringContainsString('Spawn #1 - La Naissance.cbz', $preview->newFilename);
    }

    public function testPreviewWithExpressionAndLibraryRoot(): void
    {
        $info = new ComicInfo(series: 'Spawn', title: 'La Naissance', number: 1);
        $preview = $this->service->preview(
            '/library/Spawn/old-name.cbz',
            $info,
            '{serie}/{numero} - {titre}',
            '/library',
        );

        $this->assertInstanceOf(RenamePreview::class, $preview);
        $this->assertSame('/library/Spawn/1 - La Naissance.cbz', $preview->newPath);
    }

    public function testPreviewWithLibraryRootAndFlatFile(): void
    {
        $info = new ComicInfo(series: 'Astérix', number: 41, title: 'Astérix en Lusitanie', year: 2025);
        $preview = $this->service->preview(
            '/mnt/Livres/file.cbz',
            $info,
            '{serie}/{numero} - {titre}',
            '/mnt/Livres',
        );

        $this->assertSame('/mnt/Livres/Astérix/41 - Astérix en Lusitanie.cbz', $preview->newPath);
    }

    public function testPreviewWithLibraryRootNoDuplication(): void
    {
        $info = new ComicInfo(series: 'Astérix', number: 41, title: 'Astérix en Lusitanie', year: 2025);
        $preview = $this->service->preview(
            '/mnt/Livres/Astérix/old.cbz',
            $info,
            '{serie}/{numero} - {titre}',
            '/mnt/Livres',
        );

        $this->assertSame('/mnt/Livres/Astérix/41 - Astérix en Lusitanie.cbz', $preview->newPath);
    }

    public function testRenameWithExpression(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename_expr_'.uniqid();
        mkdir($tmpDir, 0777, true);
        $file = $tmpDir.'/test.cbz';
        touch($file);

        $info = new ComicInfo(series: 'Test Series', number: 1, title: 'First');
        $newPath = $this->service->rename($file, $info, '{serie}/{numero} - {titre}');

        $this->assertStringContainsString('Test Series/1 - First.cbz', $newPath);
        $this->assertFileExists($newPath);

        unlink($newPath);
        rmdir(\dirname($newPath));
        rmdir($tmpDir);
    }

    protected function tearDown(): void
    {
        unset($this->service);
    }
}
