<?php

namespace App\Model;

class ComicInfo
{
    public function __construct(
        public readonly ?string $series = null,
        public readonly ?string $title = null,
        public readonly ?int $number = null,
        public readonly ?string $writer = null,
        public readonly ?string $artist = null,
        public readonly ?string $publisher = null,
        public readonly ?int $year = null,
        public readonly ?string $summary = null,
        public readonly ?string $isbn = null,
        public readonly ?string $ageRating = null,
        public readonly ?string $languageIso = 'fr',
        /** @var string[] */
        public readonly ?array $genre = null,
        /** @var string[] */
        public readonly ?array $characters = null,
        public readonly ?string $penciler = null,
        public readonly ?string $inker = null,
        public readonly ?string $colorist = null,
        public readonly ?string $letterer = null,
        public readonly ?string $coverArtist = null,
    ) {
    }

    public function toXml(): string
    {
        $xml = new \SimpleXMLElement('<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" />');

        $this->addXmlField($xml, 'Series', $this->series);
        $this->addXmlField($xml, 'Title', $this->title);
        $this->addXmlField($xml, 'Number', $this->number);
        $this->addXmlField($xml, 'Writer', $this->writer);
        $this->addXmlField($xml, 'Artist', $this->artist);
        $this->addXmlField($xml, 'Penciller', $this->penciler);
        $this->addXmlField($xml, 'Inker', $this->inker);
        $this->addXmlField($xml, 'Colorist', $this->colorist);
        $this->addXmlField($xml, 'Letterer', $this->letterer);
        $this->addXmlField($xml, 'CoverArtist', $this->coverArtist);
        $this->addXmlField($xml, 'Publisher', $this->publisher);
        $this->addXmlField($xml, 'Year', $this->year);
        $this->addXmlField($xml, 'Summary', $this->summary);
        $this->addXmlField($xml, 'ISBN', $this->isbn);
        $this->addXmlField($xml, 'AgeRating', $this->ageRating);
        $this->addXmlField($xml, 'LanguageISO', $this->languageIso);
        if ($this->genre) {
            $this->addXmlField($xml, 'Genre', implode(', ', $this->genre));
        }
        if ($this->characters) {
            $this->addXmlField($xml, 'Characters', implode(', ', $this->characters));
        }

        return $xml->asXML();
    }

    public static function fromXml(string $xml): self
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$loaded) {
            throw new \RuntimeException('Invalid ComicInfo XML');
        }
        $xpath = new \DOMXPath($doc);

        $series = ($node = $xpath->query('//Series')->item(0)) ? trim($node->textContent) : null;
        $title = ($node = $xpath->query('//Title')->item(0)) ? trim($node->textContent) : null;
        $numberRaw = ($node = $xpath->query('//Number')->item(0)) ? trim($node->textContent) : null;
        $writer = ($node = $xpath->query('//Writer')->item(0)) ? trim($node->textContent) : null;
        $artist = ($node = $xpath->query('//Artist')->item(0)) ? trim($node->textContent) : null;
        $penciler = ($node = $xpath->query('//Penciller')->item(0)) ? trim($node->textContent) : null;
        $inker = ($node = $xpath->query('//Inker')->item(0)) ? trim($node->textContent) : null;
        $colorist = ($node = $xpath->query('//Colorist')->item(0)) ? trim($node->textContent) : null;
        $letterer = ($node = $xpath->query('//Letterer')->item(0)) ? trim($node->textContent) : null;
        $coverArtist = ($node = $xpath->query('//CoverArtist')->item(0)) ? trim($node->textContent) : null;
        $publisher = ($node = $xpath->query('//Publisher')->item(0)) ? trim($node->textContent) : null;
        $yearRaw = ($node = $xpath->query('//Year')->item(0)) ? trim($node->textContent) : null;
        $summary = ($node = $xpath->query('//Summary')->item(0)) ? trim($node->textContent) : null;
        $isbn = ($node = $xpath->query('//ISBN')->item(0)) ? trim($node->textContent) : null;
        $ageRating = ($node = $xpath->query('//AgeRating')->item(0)) ? trim($node->textContent) : null;
        $languageIso = ($node = $xpath->query('//LanguageISO')->item(0)) ? trim($node->textContent) : null;
        $genreRaw = ($node = $xpath->query('//Genre')->item(0)) ? trim($node->textContent) : null;
        $charactersRaw = ($node = $xpath->query('//Characters')->item(0)) ? trim($node->textContent) : null;

        return new self(
            series: $series,
            title: $title,
            number: null !== $numberRaw ? (int) $numberRaw : null,
            writer: $writer,
            artist: $artist,
            publisher: $publisher,
            year: null !== $yearRaw ? (int) $yearRaw : null,
            summary: $summary,
            isbn: $isbn,
            ageRating: $ageRating,
            languageIso: $languageIso,
            genre: null !== $genreRaw ? array_map('trim', explode(',', $genreRaw)) : null,
            characters: null !== $charactersRaw ? array_map('trim', explode(',', $charactersRaw)) : null,
            penciler: $penciler,
            inker: $inker,
            colorist: $colorist,
            letterer: $letterer,
            coverArtist: $coverArtist,
        );
    }

    private function addXmlField(\SimpleXMLElement $xml, string $name, string|int|null $value): void
    {
        if (null !== $value) {
            $xml->addChild($name, (string) $value);
        }
    }
}
