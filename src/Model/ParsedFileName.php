<?php

namespace App\Model;

class ParsedFileName
{
    public function __construct(
        public readonly ?string $seriesName = null,
        public readonly ?int $number = null,
        public readonly ?string $title = null,
    ) {
    }
}
