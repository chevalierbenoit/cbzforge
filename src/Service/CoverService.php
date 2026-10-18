<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cover image of a comic or PDF file, picked by format and cached on disk.
 *
 * The cache key includes the file's mtime and size, so an edited or replaced file
 * gets a fresh cover.
 */
class CoverService
{
    public function __construct(
        private readonly ArchiveService $archives,
        private readonly RarReader $rar,
        private readonly PdfReader $pdf,
        #[Autowire('%kernel.cache_dir%/covers')]
        private readonly string $cacheDir,
    ) {
    }

    /** @return array{content: string, mime: string}|null */
    public function getCover(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $key = sha1($path.'|'.filemtime($path).'|'.filesize($path));
        $cached = $this->readCache($key);
        if (null !== $cached) {
            return $cached;
        }

        $cover = match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'cbz' => $this->archives->getFirstImage($path),
            'cbr' => $this->firstRarImage($path),
            'pdf' => $this->pdf->renderFirstPage($path),
            default => null,
        };

        if (null !== $cover) {
            $this->writeCache($key, $cover);
        }

        return $cover;
    }

    /** @return array{content: string, mime: string}|null */
    private function firstRarImage(string $path): ?array
    {
        try {
            $entry = ArchiveService::selectCoverEntry($this->rar->listEntries($path));
            if (null === $entry) {
                return null;
            }

            $mime = ArchiveService::imageMime($entry);
            if (null === $mime) {
                return null;
            }

            return ['content' => $this->rar->readEntry($path, $entry), 'mime' => $mime];
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return array{content: string, mime: string}|null */
    private function readCache(string $key): ?array
    {
        $content = @file_get_contents($this->cacheDir.'/'.$key.'.bin');
        $mime = @file_get_contents($this->cacheDir.'/'.$key.'.mime');

        if (false === $content || false === $mime) {
            return null;
        }

        return ['content' => $content, 'mime' => $mime];
    }

    /** @param array{content: string, mime: string} $cover */
    private function writeCache(string $key, array $cover): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            return;
        }

        file_put_contents($this->cacheDir.'/'.$key.'.bin', $cover['content']);
        file_put_contents($this->cacheDir.'/'.$key.'.mime', $cover['mime']);
    }
}
