<?php

namespace App\MessageHandler;

use App\Entity\Task;
use App\Message\ConvertFileMessage;
use App\Service\ConversionService;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ConvertFileMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryService $libraryService,
        private readonly ConversionService $conversionService,
    ) {
    }

    public function __invoke(ConvertFileMessage $message): void
    {
        $task = $this->em->getRepository(Task::class)->findOneBy([
            'libraryId' => $message->getLibraryId(),
            'path' => $message->getPath(),
            'type' => Task::TYPE_CONVERT,
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

            $fullPath = $this->libraryService->getFullPath($library, $message->getPath());
            $this->conversionService->convert($fullPath);
            unlink($fullPath);

            $task->setStatus(Task::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $task->setStatus(Task::STATUS_FAILED);
            $task->setError($e->getMessage());
        }

        $task->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
