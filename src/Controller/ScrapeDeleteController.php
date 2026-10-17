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

class ScrapeDeleteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
    ) {
    }

    #[Route('/api/files/scrape/delete', name: 'file_scrape_delete', methods: ['POST'])]
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
        $this->metadataService->delete($fullPath);

        return $this->json(['deleted' => true]);
    }
}
