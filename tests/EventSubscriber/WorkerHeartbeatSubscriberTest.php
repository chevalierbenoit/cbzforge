<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\WorkerHeartbeatSubscriber;
use App\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

class WorkerHeartbeatSubscriberTest extends TestCase
{
    public function testSubscribesToWorkerRunningEvent(): void
    {
        $events = WorkerHeartbeatSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(WorkerRunningEvent::class, $events);
    }

    public function testWritesHeartbeatOnFirstEvent(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('worker.last_heartbeat', '1000');

        $subscriber = $this->createSubscriber($settings, 1000);
        $subscriber->onWorkerRunning($this->createEvent());
    }

    public function testThrottlesWritesWithinWindow(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->once())
            ->method('set')
            ->with('worker.last_heartbeat', '1000');

        $subscriber = $this->createSubscriber($settings);
        $subscriber->currentTime = 1000;
        $subscriber->onWorkerRunning($this->createEvent());
        $subscriber->currentTime = 1015;
        $subscriber->onWorkerRunning($this->createEvent());
        $subscriber->currentTime = 1029;
        $subscriber->onWorkerRunning($this->createEvent());
    }

    public function testWritesAgainAfterThrottleWindow(): void
    {
        $writes = [];
        $settings = $this->createMock(SettingsService::class);
        $settings->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(static function (string $key, string $value) use (&$writes): void {
                $writes[] = $value;
            });

        $subscriber = $this->createSubscriber($settings);
        $subscriber->currentTime = 1000;
        $subscriber->onWorkerRunning($this->createEvent());
        $subscriber->currentTime = 1031;
        $subscriber->onWorkerRunning($this->createEvent());

        $this->assertSame(['1000', '1031'], $writes);
    }

    private function createSubscriber(SettingsService $settings, int $currentTime = 1000): WorkerHeartbeatSubscriber
    {
        $subscriber = new class($settings) extends WorkerHeartbeatSubscriber {
            public int $currentTime = 1000;

            protected function now(): int
            {
                return $this->currentTime;
            }
        };
        $subscriber->currentTime = $currentTime;

        return $subscriber;
    }

    private function createEvent(): WorkerRunningEvent
    {
        return new WorkerRunningEvent(
            $this->createStub(\Symfony\Component\Messenger\Worker::class),
            true,
        );
    }
}
