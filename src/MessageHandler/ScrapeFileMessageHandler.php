<?php

namespace App\MessageHandler;

use App\Entity\Task;
use App\Message\ScrapeFileMessage;
use App\Scraper\ScraperInterface;
use App\Service\LibraryService;
use App\Service\MetadataService;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ScrapeFileMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
        private readonly ScraperChain $scraperChain,
        private readonly SettingsService $settings,
    ) {
    }

    public function __invoke(ScrapeFileMessage $message): void
    {
        $task = $this->em->getRepository(Task::class)->findOneBy([
            'libraryId' => $message->getLibraryId(),
            'path' => $message->getPath(),
            'type' => Task::TYPE_SCRAPE,
            'status' => [Task::STATUS_PENDING, Task::STATUS_PROCESSING],
        ]);

        if (!$task) {
            return;
        }

        $task->setStatus(Task::STATUS_PROCESSING);
        $this->em->flush();

        try {
            $library = $this->em->getRepository(\App\Entity\Library::class)->find($message->getLibraryId());
            if (!$library) {
                throw new \RuntimeException('Library not found');
            }

            $scraper = $this->findScraper($message->getScraper());
            if (!$scraper) {
                throw new \RuntimeException("Scraper '{$message->getScraper()}' not found or disabled");
            }

            $fullPath = $this->libraryService->getFullPath($library, $message->getPath());
            $info = $scraper->scrape($message->getIdentifier());
            $this->metadataService->write($fullPath, $info);

            $task->setStatus(Task::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $task->setStatus(Task::STATUS_FAILED);
            $task->setError($e->getMessage());
        }

        $task->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function findScraper(string $name): ?ScraperInterface
    {
        foreach ($this->scraperChain->getScrapers() as $scraper) {
            if ($scraper->getName() === $name) {
                if (!$this->scraperChain->isAvailable($scraper, $this->settings)) {
                    return null;
                }

                return $scraper;
            }
        }

        return null;
    }
}
