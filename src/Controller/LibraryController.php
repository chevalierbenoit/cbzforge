<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\ArchiveService;
use App\Service\LibraryService;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LibraryController extends AbstractController
{
    public function __construct(
        private readonly LibraryService $libraryService,
        private readonly ArchiveService $archiveService,
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('/library/{id}', name: 'library_explore')]
    public function explore(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $library = $em->getRepository(Library::class)->find($id);
        if (!$library) {
            throw $this->createNotFoundException('Library not found');
        }

        $subPath = $request->query->get('path', '');
        $entries = $this->libraryService->getEntries($library, $subPath);

        foreach ($entries as &$entry) {
            if ('file' === $entry['type'] && 'cbz' === $entry['extension']) {
                $fullPath = $this->libraryService->getFullPath($library, $entry['path']);
                $entry['has_metadata'] = $this->archiveService->hasMetadata($fullPath);
            } else {
                $entry['has_metadata'] = null;
            }

            $entry['volume_count'] = 'dir' === $entry['type']
                ? $this->libraryService->countCoverable($library, $entry['path'])
                : null;
        }
        unset($entry);

        if ('' !== $subPath) {
            $pos = strrpos($subPath, '/');
            $parentPath = false !== $pos ? substr($subPath, 0, $pos) : '';
        } else {
            $parentPath = null;
        }

        $scraperNames = array_values(array_map(
            static fn ($s) => $s->getName(),
            $this->scraperChain->getAvailableScrapers($this->settings),
        ));

        return $this->render('library/explore.html.twig', [
            'library' => $library,
            'entries' => $entries,
            'currentPath' => $subPath,
            'parentPath' => $parentPath,
            'scrapers' => $scraperNames,
            'folderTree' => $this->folderTree($library, $subPath),
        ]);
    }

    /**
     * Folder tree as an explorer shows it: the folders of the current path are
     * expanded level by level, the siblings of each level stay visible, and
     * folders off the path are not read.
     *
     * @return list<array{name: string, path: string, active: bool, expanded: bool, children: list<mixed>}>
     */
    private function folderTree(Library $library, string $currentPath): array
    {
        $activeSegments = '' === $currentPath ? [] : explode('/', $currentPath);

        return $this->treeLevel($library, '', $activeSegments, 0, $currentPath);
    }

    /**
     * @param list<string> $activeSegments
     *
     * @return list<array{name: string, path: string, active: bool, expanded: bool, children: list<mixed>}>
     */
    private function treeLevel(Library $library, string $parentPath, array $activeSegments, int $depth, string $currentPath): array
    {
        $nodes = [];
        foreach ($this->libraryService->getEntries($library, $parentPath) as $entry) {
            if ('dir' !== $entry['type']) {
                continue;
            }

            $expanded = $depth < \count($activeSegments) && $activeSegments[$depth] === $entry['name'];
            $nodes[] = [
                'name' => $entry['name'],
                'path' => $entry['path'],
                'active' => $entry['path'] === $currentPath,
                'expanded' => $expanded,
                'children' => $expanded
                    ? $this->treeLevel($library, $entry['path'], $activeSegments, $depth + 1, $currentPath)
                    : [],
            ];
        }

        return $nodes;
    }
}
