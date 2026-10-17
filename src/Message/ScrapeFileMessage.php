<?php

namespace App\Message;

class ScrapeFileMessage
{
    public function __construct(
        private readonly int $libraryId,
        private readonly string $path,
        private readonly string $scraper,
        private readonly string $identifier,
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

    public function getScraper(): string
    {
        return $this->scraper;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
