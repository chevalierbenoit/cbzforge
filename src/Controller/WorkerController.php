<?php

namespace App\Controller;

use App\Service\WorkerManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class WorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerManager $workerManager,
    ) {
    }

    #[Route('/api/worker/status', name: 'worker_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->workerManager->getStatus());
    }

    #[Route('/api/worker/restart', name: 'worker_restart', methods: ['POST'])]
    public function restart(): JsonResponse
    {
        $ok = $this->workerManager->restart();

        return new JsonResponse(['success' => $ok], $ok ? 200 : 500);
    }
}
