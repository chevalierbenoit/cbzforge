<?php

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Service\WorkerManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class WorkerManagerTest extends TestCase
{
    private function createManager(?Setting $setting, array $stopCommand = [\PHP_BINARY, '-r', 'exit(0);']): WorkerManager
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($setting);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new WorkerManager($em, '/tmp', $stopCommand);
    }

    public function testStatusIsDeadWithoutHeartbeat(): void
    {
        $manager = $this->createManager(null);

        $status = $manager->getStatus();

        $this->assertFalse($status['alive']);
        $this->assertNull($status['last_heartbeat']);
    }

    public function testStatusIsAliveWithRecentHeartbeat(): void
    {
        $setting = new Setting();
        $setting->setKey(WorkerManager::HEARTBEAT_KEY);
        $setting->setValue((string) time());

        $status = $this->createManager($setting)->getStatus();

        $this->assertTrue($status['alive']);
        $this->assertNotNull($status['last_heartbeat']);
    }

    public function testStatusIsDeadWithStaleHeartbeat(): void
    {
        $setting = new Setting();
        $setting->setKey(WorkerManager::HEARTBEAT_KEY);
        $setting->setValue((string) (time() - 3600));

        $status = $this->createManager($setting)->getStatus();

        $this->assertFalse($status['alive']);
    }

    public function testRestartReturnsTrueOnSuccess(): void
    {
        $manager = $this->createManager(null, [\PHP_BINARY, '-r', 'exit(0);']);

        $this->assertTrue($manager->restart());
    }

    public function testRestartReturnsFalseOnFailure(): void
    {
        $manager = $this->createManager(null, [\PHP_BINARY, '-r', 'exit(1);']);

        $this->assertFalse($manager->restart());
    }
}
