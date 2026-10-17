<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

class WorkerManager
{
    public const HEARTBEAT_KEY = 'worker.last_heartbeat';
    private const MAX_AGE_SECONDS = 90;

    /**
     * @param list<string> $stopCommand
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
        private readonly array $stopCommand = [\PHP_BINARY, 'bin/console', 'messenger:stop-worker'],
    ) {
    }

    /**
     * @return array{alive: bool, last_heartbeat: ?string}
     */
    public function getStatus(): array
    {
        $heartbeat = $this->em->getRepository(\App\Entity\Setting::class)->find(self::HEARTBEAT_KEY)?->getValue();

        if (null === $heartbeat) {
            return ['alive' => false, 'last_heartbeat' => null];
        }

        return [
            'alive' => (time() - (int) $heartbeat) < self::MAX_AGE_SECONDS,
            'last_heartbeat' => $heartbeat,
        ];
    }

    public function restart(): bool
    {
        $process = new Process($this->stopCommand, $this->projectDir);
        $process->run();

        return $process->isSuccessful();
    }
}
