<?php

namespace App\Service;

class ConversionService
{
    private const FORMATS = ['cbr', 'pdf', 'epub'];

    private const PDF_FORMAT_DEFAULT = 'jpeg';
    private const PDF_DPI_DEFAULT = '150';

    public function __construct(
        private readonly ?SettingsService $settings = null,
    ) {
    }

    public function needsConversion(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        return \in_array($ext, self::FORMATS);
    }

    public function getTargetPath(string $path): string
    {
        $info = pathinfo($path);

        return $info['dirname'].'/'.$info['filename'].'.cbz';
    }

    public function convert(string $sourcePath): string
    {
        if (!$this->needsConversion($sourcePath)) {
            throw new \InvalidArgumentException("No conversion needed for: {$sourcePath}");
        }

        $targetPath = $this->getTargetPath($sourcePath);
        $tmpDir = sys_get_temp_dir().'/convert-'.uniqid();
        mkdir($tmpDir, 0777, true);

        $ext = strtolower(pathinfo($sourcePath, \PATHINFO_EXTENSION));

        match ($ext) {
            'cbr' => $this->extractRar($sourcePath, $tmpDir),
            'epub' => $this->extractEpub($sourcePath, $tmpDir),
            'pdf' => $this->extractPdf($sourcePath, $tmpDir),
            default => throw new \LogicException(\sprintf('Unsupported archive extension "%s".', $ext)),
        };

        $this->recompressToCbz($tmpDir, $targetPath);
        FileSystemHelper::rrmdir($tmpDir);

        return $targetPath;
    }

    private function extractRar(string $path, string $dest): void
    {
        $header = '';
        if (is_readable($path)) {
            $header = (string) file_get_contents($path, false, null, 0, 8);
        }
        if (str_starts_with($header, "PK\x03\x04")) {
            $this->extractZip($path, $dest);

            return;
        }

        $escapedPath = escapeshellarg($path);
        $escapedDest = escapeshellarg($dest);
        exec("unrar x -o+ {$escapedPath} {$escapedDest} 2>&1", $output, $exitCode);
        if (0 !== $exitCode) {
            $detail = trim(implode("\n", \array_slice($output, -2)));
            throw new \RuntimeException("CBR extraction failed (unrar exit code {$exitCode}): {$detail}");
        }
    }

    private function extractZip(string $path, string $dest): void
    {
        $zip = new \ZipArchive();
        if (true === $zip->open($path)) {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $dest.'/'.$name;
                if (!is_dir(\dirname($target))) {
                    mkdir(\dirname($target), 0777, true);
                }
                file_put_contents($target, $zip->getFromIndex($i));
            }
            $zip->close();
        } else {
            throw new \RuntimeException("Failed to open ZIP: {$path}");
        }
    }

    private function extractEpub(string $path, string $dest): void
    {
        $zip = new \ZipArchive();
        if (true === $zip->open($path)) {
            $found = false;
            for ($i = 0; $i < \count($zip); ++$i) {
                $name = $zip->statIndex($i)['name'];
                $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
                if (\in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $content = $zip->getFromIndex($i);
                    $cleanName = basename($name);
                    file_put_contents($dest.'/'.$cleanName, $content);
                    $found = true;
                }
            }
            $zip->close();
            if (!$found) {
                throw new \RuntimeException('No images found in EPUB');
            }
        } else {
            throw new \RuntimeException("Failed to open EPUB: {$path}");
        }
    }

    private function extractPdf(string $path, string $dest): void
    {
        $format = $this->settings?->get('conversion.pdf.format') ?? self::PDF_FORMAT_DEFAULT;
        $dpi = $this->settings?->get('conversion.pdf.dpi') ?? self::PDF_DPI_DEFAULT;

        $fmtFlag = match ($format) {
            'png' => '-png',
            default => '-jpeg',
        };

        $prefix = $dest.'/page';
        $escapedPath = escapeshellarg($path);
        $escapedPrefix = escapeshellarg($prefix);

        exec("pdftoppm {$fmtFlag} -r {$dpi} {$escapedPath} {$escapedPrefix} 2>/dev/null", $output, $exitCode);

        if (0 !== $exitCode) {
            throw new \RuntimeException('PDF conversion requires `pdftoppm` (poppler-utils). Install poppler-utils or check the PDF file.');
        }
    }

    private function recompressToCbz(string $sourceDir, string $targetPath): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($targetPath, \ZipArchive::CREATE)) {
            throw new \RuntimeException("Unable to create archive: {$targetPath}");
        }
        FileSystemHelper::addDirToZip($zip, $sourceDir);
        $zip->close();
    }
}
