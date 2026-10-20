<?php

namespace App\Service;

use App\Entity\Library;

class LibraryService
{
    private const ALLOWED_EXTENSIONS = ['cbz', 'cbr', 'pdf', 'epub'];
    private const COVERABLE_EXTENSIONS = ['cbz', 'cbr', 'pdf'];

    /** @return array{name: string, path: string, type: string, extension: string|null}[] */
    public function getEntries(Library $library, string $subPath): array
    {
        $fullPath = $this->getFullPath($library, $subPath);
        if (!is_dir($fullPath)) {
            throw new \InvalidArgumentException("Path not found: {$fullPath}");
        }

        $entries = [];
        $items = scandir($fullPath);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $itemPath = $fullPath.'/'.$item;
            $relativePath = $subPath ? $subPath.'/'.$item : $item;

            if (is_dir($itemPath)) {
                $entries[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'type' => 'dir',
                    'extension' => null,
                ];
            } elseif (is_file($itemPath)) {
                $ext = strtolower(pathinfo($item, \PATHINFO_EXTENSION));
                if (\in_array($ext, self::ALLOWED_EXTENSIONS)) {
                    $entries[] = [
                        'name' => $item,
                        'path' => $relativePath,
                        'type' => 'file',
                        'extension' => $ext,
                    ];
                }
            }
        }

        usort($entries, static fn ($a, $b) => $a['type'] === $b['type']
            ? strnatcasecmp($a['name'], $b['name'])
            : ('dir' === $a['type'] ? -1 : 1));

        return $entries;
    }

    /**
     * Relative path of the first comic or PDF found in a folder, looking in the folder
     * itself before its subfolders. The search stops at $maxDepth levels, so a huge
     * tree cannot slow the explorer down.
     */
    public function findFirstCoverable(Library $library, string $subPath, int $maxDepth = 3): ?string
    {
        try {
            $entries = $this->getEntries($library, $subPath);
        } catch (\InvalidArgumentException) {
            return null;
        }

        foreach ($entries as $entry) {
            if ('file' === $entry['type'] && \in_array($entry['extension'], self::COVERABLE_EXTENSIONS, true)) {
                return $entry['path'];
            }
        }

        if ($maxDepth <= 1) {
            return null;
        }

        foreach ($entries as $entry) {
            if ('dir' === $entry['type']) {
                $found = $this->findFirstCoverable($library, $entry['path'], $maxDepth - 1);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** Number of comics and PDFs in a folder and all its subfolders. */
    public function countCoverable(Library $library, string $subPath): int
    {
        try {
            $entries = $this->getEntries($library, $subPath);
        } catch (\InvalidArgumentException) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if ('file' === $entry['type'] && \in_array($entry['extension'], self::COVERABLE_EXTENSIONS, true)) {
                ++$count;
            } elseif ('dir' === $entry['type']) {
                $count += $this->countCoverable($library, $entry['path']);
            }
        }

        return $count;
    }

    public function getFullPath(Library $library, string $subPath): string
    {
        $libraryRoot = $this->getLibraryRoot($library);
        $resolved = realpath($libraryRoot.'/'.ltrim($subPath, '/'));
        if (false === $resolved || !$this->isWithin($resolved, $libraryRoot)) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        return $resolved;
    }

    /**
     * Validates an absolute destination path (such as a rename target) and returns it
     * unchanged. The deepest existing ancestor must resolve inside the library, so
     * directories that do not exist yet are allowed without escaping the library.
     */
    public function assertDestinationWithin(Library $library, string $absolutePath): string
    {
        $libraryRoot = $this->getLibraryRoot($library);
        if (!str_starts_with($absolutePath, '/') || preg_match('#(^|/)\.\.(/|$)#', $absolutePath)) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        $existing = $absolutePath;
        while (!file_exists($existing) && $existing !== \dirname($existing)) {
            $existing = \dirname($existing);
        }

        $resolved = realpath($existing);
        if (false === $resolved || !$this->isWithin($resolved, $libraryRoot)) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        return $absolutePath;
    }

    private function getLibraryRoot(Library $library): string
    {
        $root = realpath($library->getPath());
        if (false === $root) {
            throw new \InvalidArgumentException('Library path not found');
        }

        return $root;
    }

    private function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }
}
