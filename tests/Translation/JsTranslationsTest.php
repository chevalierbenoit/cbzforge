<?php

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;

/**
 * The JS translator reads public/translations/{locale}.json, not the YAML catalogues.
 * Every static js.* key used by the Stimulus controllers must exist in both locales,
 * otherwise the UI shows the raw key.
 */
class JsTranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testEveryStaticJsKeyExistsInEveryLocale(): void
    {
        $used = $this->usedKeys();
        $this->assertNotEmpty($used);

        foreach (['en', 'fr'] as $locale) {
            $catalogue = $this->catalogue($locale);
            $missing = array_values(array_diff($used, array_keys($catalogue)));

            $this->assertSame([], $missing, "Missing js.* keys in {$locale}.json");
        }
    }

    public function testLocalesHaveTheSameKeys(): void
    {
        $this->assertSame(
            array_keys($this->catalogue('en')),
            array_keys($this->catalogue('fr')),
        );
    }

    /** @return list<string> */
    private function usedKeys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/assets', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('js' !== $file->getExtension() || str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }
            preg_match_all("/trans\(\s*['\"`]([^'\"`$]+)/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                // Dynamic keys (js.tasks.status.${status}) are built at runtime and checked by their static parts.
                if (str_starts_with($key, 'js.') && !str_ends_with($key, '.')) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $data = json_decode((string) file_get_contents(self::ROOT."/public/translations/{$locale}.json"), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
