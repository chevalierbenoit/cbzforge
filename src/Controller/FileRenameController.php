<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
class FileRenameController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/rename-file', name: 'file_rename_file', methods: ['POST'])]
    public function renameFile(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';
        $newName = $data['new_name'] ?? '';

        if (!$libraryId) {
            return new JsonResponse(['error' => $this->translator->trans('error.library_id_required')], 400);
        }

        if (empty($newName)) {
            return new JsonResponse(['error' => $this->translator->trans('error.new_name_required')], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return new JsonResponse(['error' => $this->translator->trans('error.library_not_found')], 400);
        }

        $fullPath = $this->libraryService->getFullPath($library, $path);
        $pathInfo = pathinfo($fullPath);
        $extension = $pathInfo['extension'] ?? '';
        $newFullPath = $pathInfo['dirname'].'/'.$newName.'.'.$extension;

        if (file_exists($newFullPath)) {
            return new JsonResponse(['error' => $this->translator->trans('error.file_already_exists')], 409);
        }

        if (!is_file($fullPath) || !@rename($fullPath, $newFullPath)) {
            return new JsonResponse(['error' => $this->translator->trans('error.rename_failed')], 500);
        }

        $pathInfoRelative = pathinfo($path);
        $newRelativePath = ('.' !== $pathInfoRelative['dirname'] ? $pathInfoRelative['dirname'].'/' : '')
            .$newName.'.'.$extension;

        return new JsonResponse(['success' => true, 'path' => $newRelativePath]);
    }
}
