<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\LibraryService;
use App\Service\MetadataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ScrapeUpdateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
    ) {
    }

    #[Route('/api/files/scrape/update', name: 'file_scrape_update', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';

        if (!$libraryId) {
            return $this->json(['error' => 'library_id required'], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return $this->json(['error' => 'Library not found'], 400);
        }

        $fullPath = $this->libraryService->getFullPath($library, $path);
        $existing = $this->metadataService->read($fullPath);

        $info = new \App\Model\ComicInfo(
            series: $data['series'] ?? $existing?->series,
            title: $data['title'] ?? $existing?->title,
            number: isset($data['number']) && '' !== $data['number'] ? (int) $data['number'] : $existing?->number,
            writer: $data['writer'] ?? $existing?->writer,
            artist: $data['artist'] ?? $data['penciler'] ?? $existing?->artist,
            penciler: $data['penciler'] ?? $existing?->penciler,
            inker: $data['inker'] ?? $existing?->inker,
            colorist: $data['colorist'] ?? $existing?->colorist,
            letterer: $data['letterer'] ?? $existing?->letterer,
            coverArtist: $data['cover_artist'] ?? $existing?->coverArtist,
            publisher: $data['publisher'] ?? $existing?->publisher,
            year: isset($data['year']) && '' !== $data['year'] ? (int) $data['year'] : $existing?->year,
            summary: $data['summary'] ?? $existing?->summary,
            isbn: $data['isbn'] ?? $existing?->isbn,
            ageRating: $data['age_rating'] ?? $existing?->ageRating,
            languageIso: $data['language_iso'] ?? $existing?->languageIso,
            genre: isset($data['genre']) && '' !== $data['genre'] ? array_map('trim', explode(',', $data['genre'])) : $existing?->genre,
            characters: isset($data['characters']) && '' !== $data['characters'] ? array_map('trim', explode(',', $data['characters'])) : $existing?->characters,
        );

        $this->metadataService->write($fullPath, $info);

        return $this->json(['written' => true]);
    }
}
