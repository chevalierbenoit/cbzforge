<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/** Renders the first page of a PDF as a JPEG, through poppler's pdftoppm. */
class PdfReader
{
    private const RESOLUTION_DPI = '96';
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        #[Autowire('pdftoppm')]
        private readonly string $binary = 'pdftoppm',
    ) {
    }

    /** @return array{content: string, mime: string}|null */
    public function renderFirstPage(string $path): ?array
    {
        $dir = sys_get_temp_dir().'/pdf-cover-'.uniqid();
        mkdir($dir, 0777, true);

        try {
            $prefix = $dir.'/cover';
            $process = new Process([
                $this->binary, '-jpeg', '-r', self::RESOLUTION_DPI, '-f', '1', '-l', '1', '-singlefile', $path, $prefix,
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            $file = $prefix.'.jpg';
            if (!$process->isSuccessful() || !is_file($file)) {
                return null;
            }

            $content = file_get_contents($file);

            return false === $content ? null : ['content' => $content, 'mime' => 'image/jpeg'];
        } finally {
            FileSystemHelper::rrmdir($dir);
        }
    }
}
