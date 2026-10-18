<?php

namespace App\Service;

use App\Model\ParsedFileName;

class FilenameParser
{
    public function parse(string $filename): ParsedFileName
    {
        $cleaned = $this->normalize($filename);

        $patterns = [
            // 1. "Series - T(\d+) - Title" or "Series - (\d+) - Title"
            '/^(.+?)\s*-\s*(?:T)?(\d+)\s*-\s*(.+)$/i',
            // 2. "Series T(\d+) Title" (space-separated with T prefix)
            '/^(.+?)\s+T(\d+)\s+(.+)$/i',
            // 2b. "Series -01" or "Series -01 - Title" (number glued to the dash)
            '/^(.+?)\s*-\s*(?:T|Tome\s*)?(\d+)(?:\s*-\s*(.+))?$/i',
            // 3. "Series #(\d+)" with optional " - Title"
            '/^(.+?)\s+#(\d+)(?:\s*-\s*(.+))?$/i',
            // 4. "Series v(\d+)"
            '/^(.+?)\s+v(\d+)$/i',
            // 5. "Series Vol(\d+)" or "Series Vol. (\d+)"
            '/^(.+?)\s+Vol\.?\s*(\d+)$/i',
            // 6. "Series T(\d+)" (no title)
            '/^(.+?)\s+T(\d+)$/i',
            // 7. "Series (\d+)" (just number at end)
            '/^(.+?)\s+(\d+)$/',
            // 8. "Series (\d+) Title" (broad, last resort)
            '/^(.+?)\s+(\d+)\s+(.+)$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleaned, $m)) {
                return new ParsedFileName(
                    seriesName: trim($m[1]),
                    number: (int) $m[2],
                    title: isset($m[3]) ? trim($m[3]) : null,
                );
            }
        }

        return new ParsedFileName(seriesName: $cleaned);
    }

    private function normalize(string $filename): string
    {
        $name = preg_replace('/\.[^.]+$/', '', $filename);
        // Release and scan annotations: "(Best of Fusion Comics 03.2019)", "[HQ]", "@9-art-BD".
        $name = (string) preg_replace(['/\([^)]*\)/', '/\[[^\]]*\]/', '/@\S+/'], ' ', $name);
        $name = str_replace('_', ' ', $name);
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }
}
