<?php

namespace App\Tests\Controller;

use App\Controller\WorkerController;
use App\Entity\Setting;
use App\Service\WorkerManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class WorkerControllerTest extends TestCase
{
    private function createController(?Setting $setting = null, bool $restartOk = true): WorkerController
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($setting);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $manager = new WorkerManager(
            $em,
            '/tmp',
            [\PHP_BINARY, '-r', \sprintf('exit(%d);', $restartOk ? 0 : 1)],
        );

        return new WorkerController($manager);
    }

    public function testStatusReturnsDeadWhenNoHeartbeat(): void
    {
        $response = $this->createController()->status();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['alive']);
        $this->assertNull($data['last_heartbeat']);
    }

    public function testStatusReturnsAliveWithFreshHeartbeat(): void
    {
        $setting = new Setting();
        $setting->setKey(WorkerManager::HEARTBEAT_KEY);
        $setting->setValue((string) time());

        $data = json_decode($this->createController($setting)->status()->getContent(), true);

        $this->assertTrue($data['alive']);
    }

    public function testRestartReturnsSuccess(): void
    {
        $response = $this->createController(restartOk: true)->restart();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['success']);
    }

    public function testRestartReturnsErrorOnFailure(): void
    {
        $response = $this->createController(restartOk: false)->restart();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getContent(), true)['success']);
    }
}
