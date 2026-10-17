<?php

namespace App\Controller;

use App\Entity\Library;
use App\Entity\Task;
use App\Message\ConvertFileMessage;
use App\Service\ConversionService;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
class ConvertController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly ConversionService $conversionService,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/convert', name: 'file_convert', methods: ['POST'])]
    public function convert(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';

        if (!$libraryId) {
            return new JsonResponse(['error' => $this->translator->trans('error.library_id_required')], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return new JsonResponse(['error' => $this->translator->trans('error.library_not_found')], 400);
        }

        $fullPath = $this->libraryService->getFullPath($library, $path);

        if (!$this->conversionService->needsConversion($fullPath)) {
            return new JsonResponse(['error' => $this->translator->trans('error.file_already_cbz')], 400);
        }

        try {
            $cbzPath = $this->conversionService->convert($fullPath);
            unlink($fullPath);

            $pathInfo = pathinfo($path);
            $relativePath = ('.' !== $pathInfo['dirname'] ? $pathInfo['dirname'].'/' : '').$pathInfo['filename'].'.cbz';

            return new JsonResponse(['success' => true, 'path' => $relativePath]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/api/files/convert/batch', name: 'file_convert_batch', methods: ['POST'])]
    public function batch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $paths = $data['paths'] ?? [];

        if (!$libraryId) {
            return new JsonResponse(['error' => 'library_id required'], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return new JsonResponse(['error' => 'Library not found'], 400);
        }

        if (!\is_array($paths) || [] === $paths) {
            return new JsonResponse(['error' => 'paths required'], 400);
        }

        $count = 0;
        foreach ($paths as $path) {
            $fullPath = $this->libraryService->getFullPath($library, $path);
            if (!$this->conversionService->needsConversion($fullPath)) {
                continue;
            }

            $task = new Task();
            $task->setType(Task::TYPE_CONVERT);
            $task->setLibraryId($libraryId);
            $task->setPath($path);
            $this->em->persist($task);
            $this->em->flush();

            $this->bus->dispatch(new ConvertFileMessage($libraryId, $path));
            ++$count;
        }

        return new JsonResponse(['task_count' => $count]);
    }
}
