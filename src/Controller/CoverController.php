<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\CoverService;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CoverController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly CoverService $coverService,
    ) {
    }

    #[Route('/api/files/cover', name: 'file_cover', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $libraryId = $request->query->get('library_id');
        $path = $request->query->get('path', '');

        if (!$libraryId || !$path) {
            throw $this->createNotFoundException('Missing library_id or path');
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            throw $this->createNotFoundException('Library not found');
        }

        try {
            $fullPath = $this->libraryService->getFullPath($library, $path);
            $image = $this->coverService->getCover($fullPath);

            if (!$image) {
                return $this->renderErrorCover();
            }

            return new Response($image['content'], 200, [
                'Content-Type' => $image['mime'],
                'Cache-Control' => 'private, max-age=3600',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->renderErrorCover();
        }
    }

    /** Cover of the first comic in a folder, shown as the folder's thumbnail. */
    #[Route('/api/folders/cover', name: 'folder_cover', methods: ['GET'])]
    public function folder(Request $request): Response
    {
        $libraryId = $request->query->get('library_id');
        $path = $request->query->get('path', '');

        $library = $libraryId ? $this->em->getRepository(Library::class)->find($libraryId) : null;
        if (!$library) {
            throw $this->createNotFoundException('Library not found');
        }

        try {
            $coverPath = $this->libraryService->findFirstCoverable($library, $path);
            $image = null !== $coverPath
                ? $this->coverService->getCover($this->libraryService->getFullPath($library, $coverPath))
                : null;
        } catch (\InvalidArgumentException) {
            $image = null;
        }

        if (!$image) {
            throw $this->createNotFoundException('No cover in this folder');
        }

        return new Response($image['content'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function renderErrorCover(): Response
    {
        $svg = $this->renderView('scrape/error_cover.svg.twig');

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
