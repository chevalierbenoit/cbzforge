<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Reads RAR archives (CBR) through the unrar command-line tool.
 *
 * Arguments are passed as an array, so file names never reach a shell.
 */
class RarReader
{
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        #[Autowire('unrar')]
        private readonly string $binary = 'unrar',
    ) {
    }

    /**
     * Names of the files in the archive, directories excluded.
     *
     * @return list<string>
     */
    public function listEntries(string $path): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $this->run(['lb', '-p-', $path])) ?: [] as $line) {
            if ('' !== $line && !str_ends_with($line, '/')) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /** Content of one entry of the archive. */
    public function readEntry(string $path, string $entry): string
    {
        return $this->run(['p', '-inul', '-p-', $path, $entry]);
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = new Process([$this->binary, ...$arguments]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new \RuntimeException("unrar failed: {$detail}");
        }

        return $process->getOutput();
    }
}
