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

        $best = self::selectCoverEntry($contents);
        if (null === $best) {
            return null;
        }

        try {
            $content = $this->extractPage($path, $best);
        } catch (\RuntimeException) {
            return null;
        }

        return [
            'content' => $content,
            'mime' => self::imageMime($best),
        ];
    }

    /**
     * Picks the cover among archive entries: a conventional cover name wins, otherwise the
     * first image in natural order. Returns null when there is no image.
     *
     * @param list<string> $entries
     */
    public static function selectCoverEntry(array $entries): ?string
    {
        $images = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => null !== self::imageMime($entry),
        ));

        if (empty($images)) {
            return null;
        }

        natsort($images);
        $images = array_values($images);

        $candidates = array_intersect(['cover.jpg', 'cover.png', '000.jpg', '000.png'], $images);

        return $candidates ? reset($candidates) : $images[0];
    }

    /** MIME type of an image entry, or null when the name is not a supported image. */
    public static function imageMime(string $entry): ?string
    {
        return match (strtolower(pathinfo($entry, \PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }

    public function assertNotCbr(string $path): void
    {
        if (str_ends_with(strtolower($path), '.cbr')) {
            throw new \InvalidArgumentException('CBR files not supported. Convert to CBZ first.');
        }
    }
}
