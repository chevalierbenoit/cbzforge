<?php

namespace App\Dto;

class RenamePreview
{
    public function __construct(
        public readonly string $originalPath,
        public readonly string $newPath,
        public readonly string $newFilename,
    ) {
    }
}
