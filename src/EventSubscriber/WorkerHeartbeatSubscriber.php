<?php

namespace App\EventSubscriber;

use App\Service\SettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private const HEARTBEAT_KEY = 'worker.last_heartbeat';
    private const THROTTLE_SECONDS = 30;

    private ?int $lastWrite = null;

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $now = $this->now();

        if (null !== $this->lastWrite && ($now - $this->lastWrite) < self::THROTTLE_SECONDS) {
            return;
        }

        $this->lastWrite = $now;
        $this->settings->set(self::HEARTBEAT_KEY, (string) $now);
    }

    protected function now(): int
    {
        return time();
    }
}
