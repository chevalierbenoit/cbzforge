<?php

namespace App\Model;

class ScrapeResult
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly ?int $year = null,
        public readonly ?string $publisher = null,
        public readonly ?string $coverUrl = null,
        public readonly ?string $pageUrl = null,
    ) {
    }
}
