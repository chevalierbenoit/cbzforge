<?php

namespace App\Service;

use App\Model\ComicInfo;

class MetadataService
{
    public function __construct(
        private readonly ArchiveService $archiveService,
    ) {
    }

    public function read(string $path): ?ComicInfo
    {
        if (!$this->archiveService->hasMetadata($path)) {
            return null;
        }

        $xmlContent = $this->archiveService->extractPage($path, 'ComicInfo.xml');

        return ComicInfo::fromXml($xmlContent);
    }

    public function createEmpty(): ComicInfo
    {
        return new ComicInfo();
    }

    public function write(string $path, ComicInfo $info): void
    {
        $this->archiveService->assertNotCbr($path);

        $tmpDir = sys_get_temp_dir().'/comicscraper-'.uniqid();
        mkdir($tmpDir);

        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->extractTo($tmpDir);
        $zip->close();

        file_put_contents($tmpDir.'/ComicInfo.xml', $info->toXml());

        // Write to a temp file in the same directory for atomic rename
        $dir = \dirname($path);
        $tmpPath = $dir.'/.tmp-'.uniqid().'.cbz';
        $newZip = new \ZipArchive();
        $newZip->open($tmpPath, \ZipArchive::CREATE);
        FileSystemHelper::addDirToZip($newZip, $tmpDir);
        $newZip->close();

        if (!rename($tmpPath, $path)) {
            unlink($tmpPath);
            throw new \RuntimeException("Failed to write metadata to: {$path}");
        }

        FileSystemHelper::rrmdir($tmpDir);
    }

    public function delete(string $path): void
    {
        $this->archiveService->assertNotCbr($path);
        $tmpDir = sys_get_temp_dir().'/comicscraper-'.uniqid();
        mkdir($tmpDir);

        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->extractTo($tmpDir);
        $zip->close();

        $comicInfoPath = $tmpDir.'/ComicInfo.xml';
        if (file_exists($comicInfoPath)) {
            unlink($comicInfoPath);
        }

        $dir = \dirname($path);
        $tmpPath = $dir.'/.tmp-'.uniqid().'.cbz';
        $newZip = new \ZipArchive();
        $newZip->open($tmpPath, \ZipArchive::CREATE);
        FileSystemHelper::addDirToZip($newZip, $tmpDir);
        $newZip->close();

        if (!rename($tmpPath, $path)) {
            unlink($tmpPath);
            throw new \RuntimeException("Failed to delete metadata from: {$path}");
        }

        FileSystemHelper::rrmdir($tmpDir);
    }
}
