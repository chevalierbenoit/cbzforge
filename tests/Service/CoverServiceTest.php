<?php

namespace App\Tests\Service;

use App\Service\ArchiveService;
use App\Service\CoverService;
use App\Service\FileSystemHelper;
use App\Service\PdfReader;
use App\Service\RarReader;
use PHPUnit\Framework\TestCase;

class CoverServiceTest extends TestCase
{
    private string $tmpDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/cover-test-'.uniqid();
        $this->cacheDir = $this->tmpDir.'/cache';
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        FileSystemHelper::rrmdir($this->tmpDir);
    }

    public function testCbrCoverComesFromRarEntries(): void
    {
        $unrar = $this->fakeUnrar("b.jpg\na.jpg\ncover.png\n");
        $cbr = $this->tmpDir.'/comic.cbr';
        touch($cbr);

        $cover = $this->service(new RarReader($unrar))->getCover($cbr);

        // cover.png is a conventional cover name, so it wins over the first image.
        $this->assertSame(['content' => 'IMG:cover.png', 'mime' => 'image/png'], $cover);
    }

    public function testCbrWithoutImagesHasNoCover(): void
    {
        $unrar = $this->fakeUnrar("notes.txt\n");
        $cbr = $this->tmpDir.'/comic.cbr';
        touch($cbr);

        $this->assertNull($this->service(new RarReader($unrar))->getCover($cbr));
    }

    public function testPdfCoverIsRenderedAsJpegAndCached(): void
    {
        if (!shell_exec('which pdftoppm 2>/dev/null')) {
            $this->markTestSkipped('pdftoppm (poppler-utils) not available');
        }

        $pdf = $this->tmpDir.'/book.pdf';
        file_put_contents($pdf, $this->minimalPdf());
        $service = $this->service(new RarReader('unrar'));

        $cover = $service->getCover($pdf);

        $this->assertNotNull($cover);
        $this->assertSame('image/jpeg', $cover['mime']);
        $this->assertStringStartsWith("\xFF\xD8", $cover['content']);
        $this->assertCount(2, glob($this->cacheDir.'/*'), 'the cover is stored in the cache');

        // Second request is served from the cache: the source is no longer needed.
        unlink($pdf);
        file_put_contents($pdf, 'changed content, same size is not enough to pass the check');
        // The changed file has a new cache key, so it is rendered again (and fails here) instead of reusing the old cover.
        $this->assertNull($service->getCover($pdf));
    }

    public function testUnknownFormatHasNoCover(): void
    {
        $epub = $this->tmpDir.'/book.epub';
        touch($epub);

        $this->assertNull($this->service(new RarReader('unrar'))->getCover($epub));
    }

    public function testMissingFileHasNoCover(): void
    {
        $this->assertNull($this->service(new RarReader('unrar'))->getCover($this->tmpDir.'/missing.cbr'));
    }

    private function service(RarReader $rar): CoverService
    {
        return new CoverService(new ArchiveService(), $rar, new PdfReader(), $this->cacheDir);
    }

    /** A stand-in for unrar: lists the given entries and prints a recognisable payload. */
    private function fakeUnrar(string $list): string
    {
        $script = $this->tmpDir.'/fake-unrar';
        file_put_contents($script, <<<SH
#!/bin/sh
case "\$1" in
  lb) printf '%s' '{$list}' ;;
  p) printf 'IMG:%s' "\$5" ;;
esac
SH);
        chmod($script, 0755);

        return $script;
    }

    private function minimalPdf(): string
    {
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj',
        ];
        $pdf = "%PDF-1.4\n".implode("\n", $objects)."\ntrailer << /Root 1 0 R /Size 4 >>\n%%EOF\n";

        return $pdf;
    }
}
