<?php

namespace App\Service;

class FileSystemHelper
{
    public static function rrmdir(string $dir): void
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

    public static function addDirToZip(\ZipArchive $zip, string $dir, string $prefix = ''): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $entries = [];
        foreach ($files as $file) {
            $entries[($prefix ? $prefix.'/' : '').$files->getSubPathname()] = $file->getPathname();
        }

        // Directory order depends on the filesystem; sort so CBZ pages keep their reading order.
        uksort($entries, strnatcasecmp(...));
        foreach ($entries as $localPath => $path) {
            $zip->addFile($path, $localPath);
        }
    }
}
