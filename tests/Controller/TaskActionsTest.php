<?php

namespace App\Tests\Controller;

use App\Controller\TaskController;
use App\Entity\Task;
use App\Message\ConvertFileMessage;
use App\Message\ScrapeFileMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class TaskActionsTest extends TestCase
{
    private MessageBusInterface $bus;
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->dispatched = [];
    }

    private function createController(?Task $task = null): TaskController
    {
        $this->dispatched = [];
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function ($message) {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($task);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new TaskController($em, $this->bus);
    }

    public function testDeleteReturns404ForUnknownTask(): void
    {
        $response = $this->createController()->delete(999);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteRemovesTask(): void
    {
        $task = new Task();
        $task->setStatus(Task::STATUS_FAILED);

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($task);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('remove')->with($task);
        $em->expects($this->once())->method('flush');

        $controller = new TaskController($em, $this->bus);
        $response = $controller->delete(1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['deleted']);
    }

    public function testRetryRejectsPendingTask(): void
    {
        $task = new Task();
        $task->setStatus(Task::STATUS_PENDING);

        $response = $this->createController($task)->retry(1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRetryFailedConvertTaskRedispatches(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_CONVERT);
        $task->setStatus(Task::STATUS_FAILED);
        $task->setLibraryId(3);
        $task->setPath('a.cbr');
        $task->setError('boom');
        $task->setCompletedAt(new \DateTimeImmutable());

        $response = $this->createController($task)->retry(1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ConvertFileMessage::class, $this->dispatched[0]);
        $this->assertSame(Task::STATUS_PENDING, $task->getStatus());
        $this->assertNull($task->getError());
        $this->assertNull($task->getCompletedAt());
    }

    public function testRetryFailedScrapeTaskUsesPayload(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_SCRAPE);
        $task->setStatus(Task::STATUS_FAILED);
        $task->setLibraryId(3);
        $task->setPath('a.cbz');
        $task->setPayload(json_encode(['scraper' => 'Dummy', 'confirm_identifier' => 'dummy:x']));

        $this->createController($task)->retry(1);

        $message = $this->dispatched[0];
        $this->assertInstanceOf(ScrapeFileMessage::class, $message);
        $this->assertSame('Dummy', $message->getScraper());
        $this->assertSame('dummy:x', $message->getIdentifier());
    }

    public function testRetryScrapeTaskWithoutPayloadFails(): void
    {
        $task = new Task();
        $task->setType(Task::TYPE_SCRAPE);
        $task->setStatus(Task::STATUS_FAILED);
        $task->setLibraryId(3);
        $task->setPath('a.cbz');

        $response = $this->createController($task)->retry(1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertCount(0, $this->dispatched);
    }

    public function testPurgeDeletesCompletedAndFailed(): void
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('execute')->willReturn(5);

        $qb = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $response = (new TaskController($em, $this->bus))->purge();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(5, json_decode($response->getContent(), true)['purged']);
    }
}
