<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\FilenameParser;
use App\Service\LibraryService;
use App\Service\MetadataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ScrapeReadController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
        private readonly FilenameParser $filenameParser,
    ) {
    }

    #[Route('/api/files/scrape/read', name: 'file_scrape_read', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = 'GET' === $request->getMethod()
            ? $request->query->all()
            : json_decode($request->getContent(), true);
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
        $existingInfo = $this->metadataService->read($fullPath);

        $result = ['comic_info' => $existingInfo ? $this->serializeInfo($existingInfo) : null];
        if (!$existingInfo) {
            $parsed = $this->filenameParser->parse(basename($path));
            $result['parsed_query'] = $parsed->seriesName;
        }

        return $this->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInfo(\App\Model\ComicInfo $info): array
    {
        return [
            'series' => $info->series,
            'title' => $info->title,
            'number' => $info->number,
            'writer' => $info->writer,
            'penciler' => $info->penciler ?? $info->artist,
            'inker' => $info->inker,
            'colorist' => $info->colorist,
            'letterer' => $info->letterer,
            'cover_artist' => $info->coverArtist,
            'artist' => $info->artist,
            'publisher' => $info->publisher,
            'year' => $info->year,
            'summary' => $info->summary,
            'isbn' => $info->isbn,
            'age_rating' => $info->ageRating,
            'language_iso' => $info->languageIso,
            'genre' => $info->genre,
            'characters' => $info->characters,
        ];
    }
}
