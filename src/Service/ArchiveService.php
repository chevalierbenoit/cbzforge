<?php

namespace App\Service;

class ArchiveService
{
    /**
     * @return list<string>
     */
    public function listContents(string $path): array
    {
        $this->assertNotCbr($path);
        if (!is_file($path) || 0 === filesize($path)) {
            throw new \RuntimeException("Failed to open archive: {$path}");
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new \RuntimeException("Failed to open archive: {$path}");
        }
        $files = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $stat = $zip->statIndex($i);
            if (false !== $stat && $stat['size'] > 0) {
                $files[] = $stat['name'];
            }
        }
        $zip->close();

        return $files;
    }

    public function extractPage(string $path, string $entryName): string
    {
        $this->assertNotCbr($path);
        if (!is_file($path) || 0 === filesize($path)) {
            throw new \RuntimeException("Failed to open archive: {$path}");
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new \RuntimeException("Failed to open archive: {$path}");
        }
        $content = $zip->getFromName($entryName);
        $zip->close();
        if (false === $content) {
            throw new \RuntimeException("Entry '{$entryName}' not found in archive");
        }

        return $content;
    }

    public function hasMetadata(string $path): bool
    {
        try {
            $contents = $this->listContents($path);

            return \in_array('ComicInfo.xml', $contents);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{content: string, mime: string}|null
     */
    public function getFirstImage(string $path): ?array
    {
        try {
            $contents = $this->listContents($path);
        } catch (\RuntimeException) {
            return null;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        ];

        $images = [];
        foreach ($contents as $entry) {
            $ext = strtolower(pathinfo($entry, \PATHINFO_EXTENSION));
            if (\in_array($ext, $imageExtensions, true)) {
                $images[] = $entry;
            }
        }

        if (empty($images)) {
            return null;
        }

        natsort($images);
        $images = array_values($images);

        $coverCandidates = ['cover.jpg', 'cover.png', '000.jpg', '000.png'];
        $candidates = array_intersect($coverCandidates, $images);
        $best = $candidates ? reset($candidates) : $images[0];

        try {
            $content = $this->extractPage($path, $best);
        } catch (\RuntimeException) {
            return null;
        }

        $ext = strtolower(pathinfo($best, \PATHINFO_EXTENSION));

        return [
            'content' => $content,
            'mime' => $mimeMap[$ext],
        ];
    }

    public function assertNotCbr(string $path): void
    {
        if (str_ends_with(strtolower($path), '.cbr')) {
            throw new \InvalidArgumentException('CBR files not supported. Convert to CBZ first.');
        }
    }
}
