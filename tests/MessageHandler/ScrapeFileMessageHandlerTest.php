<?php

namespace App\Tests\MessageHandler;

use App\Entity\Library;
use App\Entity\Task;
use App\Message\ScrapeFileMessage;
use App\MessageHandler\ScrapeFileMessageHandler;
use App\Model\ComicInfo;
use App\Scraper\ScraperInterface;
use App\Service\LibraryService;
use App\Service\MetadataService;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class ScrapeFileMessageHandlerTest extends TestCase
{
    public function testPicksUpProcessingTaskAndCompletesIt(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_SCRAPE);
        $task->setStatus(Task::STATUS_PROCESSING);
        $task->setLibraryId(1);
        $task->setPath('test.cbz');

        $criteria = null;
        $taskRepo = $this->createStub(EntityRepository::class);
        $taskRepo->method('findOneBy')->willReturnCallback(static function (array $c) use (&$criteria, $task) {
            $criteria = $c;

            return $task;
        });

        $libRepo = $this->createStub(EntityRepository::class);
        $libRepo->method('find')->willReturn(new Library());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => Task::class === $class ? $taskRepo : $libRepo,
        );

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn('/tmp/test.cbz');

        $metadata = $this->createMock(MetadataService::class);
        $metadata->expects($this->once())->method('write');

        $scraper = $this->createStub(ScraperInterface::class);
        $scraper->method('getName')->willReturn('Fake');
        $scraper->method('scrape')->willReturn(new ComicInfo(series: 'Spawn'));

        $chain = $this->createStub(ScraperChain::class);
        $chain->method('getScrapers')->willReturn([$scraper]);

        $settings = $this->createStub(SettingsService::class);
        $settings->method('get')->willReturn('1');

        $handler = new ScrapeFileMessageHandler($em, $libraryService, $metadata, $chain, $settings);
        $handler(new ScrapeFileMessage(1, 'test.cbz', 'Fake', 'fake:spawn'));

        $this->assertSame([Task::STATUS_PENDING, Task::STATUS_PROCESSING], $criteria['status']);
        $this->assertSame(Task::STATUS_COMPLETED, $task->getStatus());
    }

    public function testNoopsWhenNoMatchingTask(): void
    {
        $taskRepo = $this->createStub(EntityRepository::class);
        $taskRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($taskRepo);
        $em->expects($this->never())->method('flush');

        $handler = new ScrapeFileMessageHandler(
            $em,
            $this->createStub(LibraryService::class),
            $this->createStub(MetadataService::class),
            $this->createStub(ScraperChain::class),
            $this->createStub(SettingsService::class),
        );
        $handler(new ScrapeFileMessage(1, 'test.cbz', 'Fake', 'fake:spawn'));
    }
}
