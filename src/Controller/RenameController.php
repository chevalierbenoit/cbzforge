<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\ConversionService;
use App\Service\LibraryService;
use App\Service\MetadataService;
use App\Service\RenameService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
class RenameController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
        private readonly RenameService $renameService,
        private readonly ConversionService $conversionService,
        private readonly SettingsService $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/rename/preview', name: 'file_rename_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';

        if (!$libraryId) {
            return $this->json(['error' => $this->translator->trans('error.library_id_required')], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return $this->json(['error' => $this->translator->trans('error.library_not_found')], 400);
        }

        $fullPath = $this->libraryService->getFullPath($library, $path);
        $info = $this->metadataService->read($fullPath);

        if (!$info) {
            return $this->json(['error' => $this->translator->trans('error.no_metadata')], 400);
        }

        $targetPath = $fullPath;
        if ($this->conversionService->needsConversion($fullPath)) {
            $targetPath = $this->conversionService->getTargetPath($fullPath);
        }

        $expression = $data['expression'] ?? $this->settings->get('rename.format');
        $preview = $this->renameService->preview($targetPath, $info, $expression ?: null, $library->getPath());

        return $this->json([
            'original_path' => $preview->originalPath,
            'new_path' => $preview->newPath,
            'new_filename' => $preview->newFilename,
            'current_expression' => $expression,
        ]);
    }

    #[Route('/api/files/rename/execute', name: 'file_rename_execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';

        if (!$libraryId) {
            return $this->json(['error' => $this->translator->trans('error.library_id_required')], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return $this->json(['error' => $this->translator->trans('error.library_not_found')], 400);
        }

        $fullPath = $this->libraryService->getFullPath($library, $path);
        $info = $this->metadataService->read($fullPath);

        if (!$info) {
            return $this->json(['error' => $this->translator->trans('error.no_metadata')], 400);
        }

        $actualPath = $fullPath;
        if ($this->conversionService->needsConversion($fullPath)) {
            try {
                $actualPath = $this->conversionService->convert($fullPath);
            } catch (\RuntimeException $e) {
                return $this->json(['error' => $e->getMessage()], 400);
            }
        }

        $expression = $data['expression'] ?? null;
        $customPath = $data['new_path'] ?? null;

        try {
            if ($expression) {
                $newPath = $this->renameService->rename($actualPath, $info, $expression, $library->getPath());
            } elseif ($customPath) {
                try {
                    $this->libraryService->assertDestinationWithin($library, $customPath);
                } catch (\InvalidArgumentException) {
                    return $this->json(['error' => $this->translator->trans('error.rename_failed_to', ['%path%' => $customPath])], 400);
                }
                $newDir = \dirname($customPath);
                if (!is_dir($newDir) && !@mkdir($newDir, 0777, true) && !is_dir($newDir)) {
                    throw new \RuntimeException($this->translator->trans('error.rename_failed_to', ['%path%' => $customPath]));
                }
                if (!is_file($actualPath) || !@rename($actualPath, $customPath)) {
                    throw new \RuntimeException($this->translator->trans('error.rename_failed_to', ['%path%' => $customPath]));
                }
                $newPath = $customPath;
            } else {
                $newPath = $this->renameService->rename($actualPath, $info, null, $library->getPath());
            }

            return $this->json(['new_path' => $newPath]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
