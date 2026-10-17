<?php

namespace App\Tests\MessageHandler;

use App\Entity\Library;
use App\Entity\Task;
use App\Message\ConvertFileMessage;
use App\MessageHandler\ConvertFileMessageHandler;
use App\Service\ConversionService;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class ConvertFileMessageHandlerTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'handler_test_').'.cbr';
        touch($this->tmpFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testPicksUpProcessingTaskAndCompletesIt(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_CONVERT);
        $task->setStatus(Task::STATUS_PROCESSING);
        $task->setLibraryId(1);
        $task->setPath('test.cbr');

        $library = new Library();

        $criteria = null;
        $taskRepo = $this->createStub(EntityRepository::class);
        $taskRepo->method('findOneBy')->willReturnCallback(static function (array $c) use (&$criteria, $task) {
            $criteria = $c;

            return $task;
        });

        $libRepo = $this->createStub(EntityRepository::class);
        $libRepo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => Task::class === $class ? $taskRepo : $libRepo,
        );

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($this->tmpFile);

        $conversion = $this->createStub(ConversionService::class);
        $conversion->method('convert')->willReturn(preg_replace('/\.cbr$/', '.cbz', $this->tmpFile));

        $handler = new ConvertFileMessageHandler($em, $libraryService, $conversion);
        $handler(new ConvertFileMessage(1, 'test.cbr'));

        $this->assertSame([Task::STATUS_PENDING, Task::STATUS_PROCESSING], $criteria['status']);
        $this->assertSame(Task::STATUS_COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getCompletedAt());
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function testNoopsWhenNoMatchingTask(): void
    {
        $taskRepo = $this->createStub(EntityRepository::class);
        $taskRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($taskRepo);
        $em->expects($this->never())->method('flush');

        $handler = new ConvertFileMessageHandler(
            $em,
            $this->createStub(LibraryService::class),
            $this->createStub(ConversionService::class),
        );
        $handler(new ConvertFileMessage(1, 'test.cbr'));
    }

    public function testFailsTaskOnConversionError(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_CONVERT);
        $task->setStatus(Task::STATUS_PENDING);

        $taskRepo = $this->createStub(EntityRepository::class);
        $taskRepo->method('findOneBy')->willReturn($task);

        $libRepo = $this->createStub(EntityRepository::class);
        $libRepo->method('find')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => Task::class === $class ? $taskRepo : $libRepo,
        );

        $handler = new ConvertFileMessageHandler(
            $em,
            $this->createStub(LibraryService::class),
            $this->createStub(ConversionService::class),
        );
        $handler(new ConvertFileMessage(1, 'test.cbr'));

        $this->assertSame(Task::STATUS_FAILED, $task->getStatus());
        $this->assertSame('Library not found', $task->getError());
    }
}
