<?php

namespace App\Service;

use App\Dto\RenamePreview;
use App\Model\ComicInfo;

class RenameService
{
    public function __construct(
        private readonly RenameTemplate $renameTemplate,
    ) {
    }

    public function preview(string $currentPath, ComicInfo $info, ?string $expression = null, ?string $libraryRoot = null): RenamePreview
    {
        [$newPath, $newFilename] = $this->resolveNewPath($currentPath, $info, $expression, $libraryRoot);

        return new RenamePreview(
            originalPath: $currentPath,
            newPath: $newPath,
            newFilename: $newFilename,
        );
    }

    /** @return array{string, string} */
    private function resolveNewPath(string $currentPath, ComicInfo $info, ?string $expression, ?string $libraryRoot): array
    {
        $baseDir = $libraryRoot ?? \dirname($currentPath);

        if (null !== $expression) {
            $relPath = $this->renameTemplate->render($expression, $info);
            $ext = pathinfo($currentPath, \PATHINFO_EXTENSION);
            $newPath = $baseDir.'/'.$relPath.'.'.$ext;

            return [$newPath, basename($newPath)];
        }

        $filename = basename($currentPath);
        $newPath = $this->getNewPath($baseDir, $filename, $info);

        return [$newPath, basename($newPath)];
    }

    public function getNewPath(string $baseDir, string $filename, ComicInfo $info): string
    {
        $extension = pathinfo($filename, \PATHINFO_EXTENSION);
        $newFilename = $this->buildFilename($info, $extension);

        if ($newFilename === 'Unknown.'.$extension) {
            $newFilename = $filename;
        }

        if (!empty($info->series)) {
            $series = $this->sanitizeFilename($info->series);

            return $baseDir.'/'.$series.'/'.$newFilename;
        }

        return $baseDir.'/'.$newFilename;
    }

    public function rename(string $currentPath, ComicInfo $info, ?string $expression = null, ?string $libraryRoot = null): string
    {
        [$newPath] = $this->resolveNewPath($currentPath, $info, $expression, $libraryRoot);

        $newDir = \dirname($newPath);
        $dirOk = is_dir($newDir) || @mkdir($newDir, 0777, true) || is_dir($newDir);

        if (!$dirOk || !is_file($currentPath) || !@rename($currentPath, $newPath)) {
            throw new \RuntimeException("Failed to rename: {$currentPath} -> {$newPath}");
        }

        return $newPath;
    }

    private function buildFilename(ComicInfo $info, string $extension): string
    {
        if (null !== $info->series && null !== $info->number) {
            $series = $this->sanitizeFilename($info->series);
            $filename = $series.' #'.$info->number;
            if (null !== $info->year) {
                $filename .= ' ('.$info->year.')';
            }

            return $filename.'.'.$extension;
        }

        $parts = [];

        if (null !== $info->number) {
            $parts[] = 'T'.str_pad((string) $info->number, 2, '0', \STR_PAD_LEFT);
        }

        if (null !== $info->title) {
            $parts[] = $this->sanitizeFilename($info->title);
        }

        $filename = !empty($parts) ? implode(' - ', $parts) : 'Unknown';

        return $filename.'.'.$extension;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
        $name = preg_replace('/[\s\-]+/', ' ', $name);

        return trim($name);
    }
}
