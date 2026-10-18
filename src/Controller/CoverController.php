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

    private function renderErrorCover(): Response
    {
        $svg = $this->renderView('scrape/error_cover.svg.twig');

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
