<?php

namespace App\Controller;

use App\Entity\Task;
use App\Message\ConvertFileMessage;
use App\Message\ScrapeFileMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class TaskController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/tasks/status', name: 'tasks_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $repo = $this->em->getRepository(Task::class);

        $statuses = ['pending', 'processing', 'completed', 'failed'];
        $counts = [];
        foreach ($statuses as $s) {
            $counts[$s] = $repo->count(['status' => $s]);
        }

        return new JsonResponse($counts);
    }

    #[Route('/api/tasks/list', name: 'tasks_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $tasks = $this->em->getRepository(Task::class)->findBy([], ['createdAt' => 'DESC'], 20);

        $data = array_map(static function (Task $task) {
            return [
                'id' => $task->getId(),
                'type' => $task->getType(),
                'status' => $task->getStatus(),
                'path' => $task->getPath(),
                'error' => $task->getError(),
                'createdAt' => $task->getCreatedAt()->format('H:i:s'),
            ];
        }, $tasks);

        return new JsonResponse($data);
    }

    #[Route('/admin/tasks', name: 'admin_tasks')]
    public function index(): Response
    {
        $tasks = $this->em->getRepository(Task::class)->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->render('admin/tasks.html.twig', [
            'tasks' => $tasks,
        ]);
    }

    #[Route('/api/tasks/purge', name: 'tasks_purge', methods: ['POST'], priority: 2)]
    public function purge(): JsonResponse
    {
        $count = $this->em->createQueryBuilder()
            ->delete(Task::class, 't')
            ->where('t.status IN (:statuses)')
            ->setParameter('statuses', [Task::STATUS_COMPLETED, Task::STATUS_FAILED])
            ->getQuery()
            ->execute();

        return new JsonResponse(['purged' => $count]);
    }

    #[Route('/api/tasks/{id}/delete', name: 'tasks_delete', methods: ['POST'], requirements: ['id' => '\d+'], priority: 1)]
    public function delete(int $id): JsonResponse
    {
        $task = $this->em->getRepository(Task::class)->find($id);

        if (!$task) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        $this->em->remove($task);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/api/tasks/{id}/retry', name: 'tasks_retry', methods: ['POST'], requirements: ['id' => '\d+'], priority: 1)]
    public function retry(int $id): JsonResponse
    {
        $task = $this->em->getRepository(Task::class)->find($id);

        if (!$task) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        if (!\in_array($task->getStatus(), [Task::STATUS_FAILED, Task::STATUS_PROCESSING], true)) {
            return new JsonResponse(['error' => 'Only failed or processing tasks can be retried'], 400);
        }

        try {
            $this->dispatchMessage($task);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $task->setStatus(Task::STATUS_PENDING);
        $task->setError(null);
        $task->setCompletedAt(null);
        $this->em->flush();

        return new JsonResponse(['retried' => true]);
    }

    private function dispatchMessage(Task $task): void
    {
        if (Task::TYPE_CONVERT === $task->getType()) {
            $this->bus->dispatch(new ConvertFileMessage($task->getLibraryId(), $task->getPath()));

            return;
        }

        if (Task::TYPE_SCRAPE === $task->getType()) {
            $payload = json_decode($task->getPayload() ?? '', true);

            if (!\is_array($payload) || !isset($payload['scraper'], $payload['confirm_identifier'])) {
                throw new \InvalidArgumentException('Missing scrape payload for retry');
            }

            $this->bus->dispatch(new ScrapeFileMessage(
                $task->getLibraryId(),
                $task->getPath(),
                $payload['scraper'],
                $payload['confirm_identifier'],
            ));

            return;
        }

        throw new \InvalidArgumentException('Unknown task type');
    }
}
