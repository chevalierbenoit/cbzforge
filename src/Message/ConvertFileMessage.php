<?php

namespace App\Message;

class ConvertFileMessage
{
    public function __construct(
        private readonly int $libraryId,
        private readonly string $path,
    ) {
    }

    public function getLibraryId(): int
    {
        return $this->libraryId;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
