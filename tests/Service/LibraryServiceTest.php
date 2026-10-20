<?php

namespace App\Tests\Service;

use App\Entity\Library;
use App\Service\LibraryService;
use PHPUnit\Framework\TestCase;

class LibraryServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/libtest-'.uniqid();
        mkdir($this->tmpDir.'/Serie1', 0777, true);
        touch($this->tmpDir.'/Serie1/test.cbz');
        touch($this->tmpDir.'/test2.cbr');
        touch($this->tmpDir.'/readme.txt');
    }

    public function testGetEntriesReturnsFilesAndDirs(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();
        $entries = $service->getEntries($library, '');

        $dirs = array_filter($entries, static fn ($e) => 'dir' === $e['type']);
        $files = array_filter($entries, static fn ($e) => 'file' === $e['type']);

        $this->assertCount(1, $dirs);
        $this->assertSame('Serie1', $dirs[0]['name']);
        $this->assertGreaterThanOrEqual(1, \count($files));
        $extensions = array_map(static fn ($f) => $f['extension'], $files);
        $this->assertContains('cbr', $extensions);
    }

    public function testGetEntriesWithSubdirectory(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();
        $entries = $service->getEntries($library, 'Serie1');

        $this->assertCount(1, $entries);
        $this->assertSame('test.cbz', $entries[0]['name']);
    }

    public function testGetEntriesThrowsForFilePath(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getEntries($library, 'readme.txt');
    }

    public function testGetEntriesThrowsForNonexistentPath(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getEntries($library, 'nonexistent');
    }

    public function testGetFullPathThrowsForPathTraversal(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getFullPath($library, '../');
    }

    public function testGetEntriesSortsDirectoriesFirst(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();
        $entries = $service->getEntries($library, '');

        $types = array_map(static fn ($e) => $e['type'], $entries);
        $dirIndices = array_keys($types, 'dir', true);
        $fileIndices = array_keys($types, 'file', true);

        if ([] !== $dirIndices && [] !== $fileIndices) {
            $this->assertGreaterThan(max($dirIndices), min($fileIndices));
        }
    }

    public function testGetEntriesSortsAlphabeticallyWithinType(): void
    {
        touch($this->tmpDir.'/zeta.cbz');
        touch($this->tmpDir.'/alpha.cbz');
        touch($this->tmpDir.'/beta.cbz');

        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();
        $entries = $service->getEntries($library, '');

        $files = array_values(array_filter($entries, static fn ($e) => 'file' === $e['type']));

        $this->assertCount(4, $files);
        $names = array_map(static fn ($f) => $f['name'], $files);
        $this->assertSame(['alpha.cbz', 'beta.cbz', 'test2.cbr', 'zeta.cbz'], $names);
    }

    public function testFindFirstCoverablePrefersFilesOfTheFolderItself(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertSame('test2.cbr', $service->findFirstCoverable($library, ''));
    }

    public function testFindFirstCoverableLooksInSubfolders(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertSame('Serie1/test.cbz', $service->findFirstCoverable($library, 'Serie1'));
    }

    public function testFindFirstCoverableIgnoresFilesWithoutCover(): void
    {
        mkdir($this->tmpDir.'/Epub');
        touch($this->tmpDir.'/Epub/book.epub');

        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertNull($service->findFirstCoverable($library, 'Epub'));
    }

    public function testFindFirstCoverableStopsAtMaxDepth(): void
    {
        mkdir($this->tmpDir.'/a/b/c/d', 0777, true);
        touch($this->tmpDir.'/a/b/c/d/deep.cbz');

        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertNull($service->findFirstCoverable($library, 'a'));
        $this->assertSame('a/b/c/d/deep.cbz', $service->findFirstCoverable($library, 'a', 5));
    }

    public function testCountCoverableIncludesSubfolders(): void
    {
        touch($this->tmpDir.'/Serie1/second.cbz');
        mkdir($this->tmpDir.'/Serie1/Tome 2/sub', 0777, true);
        touch($this->tmpDir.'/Serie1/Tome 2/sub/third.pdf');
        touch($this->tmpDir.'/Serie1/Tome 2/notes.txt');
        touch($this->tmpDir.'/Serie1/Tome 2/book.epub');

        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertSame(3, $service->countCoverable($library, 'Serie1'));
        $this->assertSame(0, $service->countCoverable($library, 'nonexistent'));
    }

    public function testFindFirstCoverableReturnsNullForMissingFolder(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        $this->assertNull($service->findFirstCoverable($library, 'nonexistent'));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testGetFullPathRejectsSiblingWithSamePrefix(): void
    {
        mkdir($this->tmpDir.'/Serie1bis');

        $library = new Library();
        $library->setPath($this->tmpDir.'/Serie1');

        $service = new LibraryService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getFullPath($library, '../Serie1bis');
    }

    public function testAssertDestinationWithinAcceptsNewSubdirectory(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();
        $destination = $this->tmpDir.'/Nouvelle/renamed.cbz';

        $this->assertSame($destination, $service->assertDestinationWithin($library, $destination));
    }

    public function testAssertDestinationWithinRejectsPathOutsideLibrary(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir.'/Serie1');

        $service = new LibraryService();

        $this->expectException(\InvalidArgumentException::class);
        $service->assertDestinationWithin($library, $this->tmpDir.'/test2.cbr');
    }

    public function testAssertDestinationWithinRejectsRelativeAndTraversalPaths(): void
    {
        $library = new Library();
        $library->setPath($this->tmpDir);

        $service = new LibraryService();

        foreach (['relative.cbz', $this->tmpDir.'/../escape.cbz', $this->tmpDir.'/Serie1/../../escape.cbz'] as $path) {
            try {
                $service->assertDestinationWithin($library, $path);
                $this->fail("Path should be rejected: {$path}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
