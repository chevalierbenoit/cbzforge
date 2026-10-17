<?php

namespace App\Controller;

use App\Entity\Library;
use App\Entity\Task;
use App\Message\ScrapeFileMessage;
use App\Scraper\ScraperInterface;
use App\Service\LibraryService;
use App\Service\MetadataService;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ScrapeConfirmController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/files/scrape/confirm', name: 'file_scrape_confirm', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $libraryId = $data['library_id'] ?? null;
        $path = $data['path'] ?? '';
        $scraperName = $data['scraper'] ?? null;
        $confirmIdentifier = $data['confirm_identifier'] ?? null;
        $batch = !empty($data['batch']);

        if (!$confirmIdentifier || !$scraperName || !$libraryId) {
            return $this->json(['error' => $this->translator->trans('error.confirm_fields_required')], 400);
        }

        $library = $this->em->getRepository(Library::class)->find($libraryId);
        if (!$library) {
            return $this->json(['error' => $this->translator->trans('error.library_not_found')], 400);
        }

        $scraper = $this->findScraper($scraperName);
        if (!$scraper) {
            return $this->json(['error' => $this->translator->trans('error.scraper_not_found', ['%name%' => $scraperName])], 400);
        }

        if ($batch) {
            $task = new Task();
            $task->setType(Task::TYPE_SCRAPE);
            $task->setLibraryId($libraryId);
            $task->setPath($path);
            $task->setPayload(json_encode([
                'scraper' => $scraperName,
                'confirm_identifier' => $confirmIdentifier,
            ]) ?: null);
            $this->em->persist($task);
            $this->em->flush();

            $this->bus->dispatch(new ScrapeFileMessage($libraryId, $path, $scraperName, $confirmIdentifier));

            return $this->json(['queued' => true, 'task_id' => $task->getId()]);
        }

        try {
            $fullPath = $this->libraryService->getFullPath($library, $path);
            $info = $scraper->scrape($confirmIdentifier);
            $this->metadataService->write($fullPath, $info);

            return $this->json(['comic_info' => $this->serializeInfo($info), 'written' => true]);
        } catch (TransportExceptionInterface $e) {
            return $this->json(['error' => $this->translator->trans('error.connection_error', ['%message%' => $e->getMessage()])], 502);
        }
    }

    private function findScraper(string $name): ?ScraperInterface
    {
        foreach ($this->scraperChain->getScrapers() as $scraper) {
            if ($scraper->getName() === $name) {
                if ('0' === $this->settings->get('scraper.'.$name.'.enabled')) {
                    return null;
                }

                return $scraper;
            }
        }

        return null;
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
