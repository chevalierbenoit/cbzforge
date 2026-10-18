<?php

namespace App\Tests\Scraper;

use App\Model\ComicInfo;
use App\Scraper\BdgestScraper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BdgestScraperTest extends TestCase
{
    /**
     * An album page in the markup the site serves now: microdata for the name, number, year,
     * publisher and description, an authors list with one role per author, and the EAN in a hidden input.
     *
     * @param list<array{0: string, 1: string}> $authors [name, role] pairs
     */
    private function albumPage(
        string $series = 'Spawn',
        string $name = 'Spawn - Origines',
        string $headline = 'Tome 1',
        array $authors = [['McFarlane, Todd', 'Scénario'], ['McFarlane, Todd', 'Dessin']],
        string $publisher = 'Image Comics',
        string $date = '1992-01-01',
        string $ean = '9780000000000',
        string $pages = '112',
        string $description = 'Première aventure de Spawn.',
    ): string {
        $authorSpans = '';
        foreach ($authors as [$author, $role]) {
            $authorSpans .= '<span><a href="https://www.bedetheque.com/auteur-1.html">'.$author.'</a> <small>('.$role.')</small></span>';
        }

        return <<<HTML
<html>
<body>
<div>
    <h1><a href="https://www.bedetheque.com/serie-123-BD-{$series}.html" title="{$series}">{$series}</a></h1>
    <meta itemprop="name" content="{$name}">
    <meta itemprop="alternativeheadline" content="{$headline}">
    <div class="bdt-ah-auteurs liste-auteurs">{$authorSpans}</div>
    <meta itemprop="datePublished" content="{$date}">
    <span itemprop="publisher">{$publisher}</span>
    <input type="hidden" id="EAN" value="{$ean}">
    <b itemprop="numberOfPages">{$pages}</b> pages
    <span itemprop="description">{$description}</span>
</div>
</body>
</html>
HTML;
    }

    public function testGetName(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $this->assertSame('BDGest', $scraper->getName());
    }

    public function testSupportsBdgestUrl(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $this->assertTrue($scraper->supports('https://www.bdgest.com/album/123'));
        $this->assertTrue($scraper->supports('https://bdgest.com/album/456'));
        $this->assertFalse($scraper->supports('https://comicvine.com/issue/789'));
    }

    public function testSearchReturnsScrapeResults(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li>
        <a href="https://www.bedetheque.com/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a>
    </li>
    <li>
        <a href="https://www.bedetheque.com/BD-Largo-Winch-Tome-2-Le-Groupe-W-213.html">Le Groupe W</a>
    </li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(2, $results);
        $this->assertStringContainsString('/BD-Largo-Winch-Tome-1-L-heritier-212', $results[0]->identifier);
        $this->assertSame('Largo Winch #1 - L\'héritier', $results[0]->title);
        $this->assertSame('1', $results[0]->subtitle);
    }

    public function testScrapeParsesAlbumPage(): void
    {
        $html = $this->albumPage();
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('/BD-Spawn-Tome-1-Origines-1234.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
        $this->assertSame(1, $info->number);
        $this->assertSame('Todd McFarlane', $info->writer);
    }

    public function testScrapeExtractsAllFields(): void
    {
        $html = $this->albumPage('Pourpre-Sang', 'Pourpre-Sang - Le Cercle de Nimuée', 'Tome 1', [['Chérel, Léo', 'Scénario'], ['Chérel, Léo', 'Dessin']], 'Astrolabe', '2026-05-09', '9782488807067', '112', 'La profession de Pourpre-Sang n\'est pas de tout repos.');
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('/BD-Pourpre-Sang-Tome-1-Le-Cercle-de-Nimuee-538892.html');

        $this->assertSame('Pourpre-Sang', $info->series);
        $this->assertSame('Le Cercle de Nimuée', $info->title);
        $this->assertSame(1, $info->number);
        $this->assertSame('Léo Chérel', $info->writer);
        $this->assertSame('Léo Chérel', $info->artist);
        $this->assertSame('Astrolabe', $info->publisher);
        $this->assertSame(2026, $info->year);
        $this->assertSame('9782488807067', $info->isbn);
        $this->assertSame('La profession de Pourpre-Sang n\'est pas de tout repos.', $info->summary);
    }

    public function testSearchWithTrailingNumberRetriesStrippedQuery(): void
    {
        // First call (with "4") returns empty, second call (stripped) returns results
        $ajaxJsonEmpty = '[]';
        $ajaxJsonFound = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li><a href="https://www.bedetheque.com/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a></li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJsonEmpty, ['http_code' => 200]),
            new MockResponse($ajaxJsonFound, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch 4');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Largo Winch #1', $results[0]->title);
    }

    public function testSearchWithTomeSuffixRetriesStrippedQuery(): void
    {
        $ajaxJsonEmpty = '[]';
        $ajaxJsonFound = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li><a href="https://www.bedetheque.com/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a></li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJsonEmpty, ['http_code' => 200]),
            new MockResponse($ajaxJsonFound, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch Tome 4');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Largo Winch #1', $results[0]->title);
    }

    public function testSearchWithLeadingArticleFallsBackToInvertedForm(): void
    {
        $ajaxJsonEmpty = '[]';
        $ajaxJsonFound = '[{"id":"14","label":"Crépuscule des dieux (Le)","value":"Crépuscule des dieux (Le)","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li><a href="https://www.bedetheque.com/BD-Crepuscule-des-dieux-Tome-1-Le-Crepuscule-212.html">Le Crépuscule</a></li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJsonEmpty, ['http_code' => 200]),
            new MockResponse($ajaxJsonFound, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Le crépuscule des dieux');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Crépuscule des dieux (Le)', $results[0]->title);
    }

    public function testSearchNoResults(): void
    {
        $ajaxJson = '[]';
        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('XyzzyNonexistent');

        $this->assertCount(0, $results);
    }

    public function testSearchTransportExceptionReturnsEmpty(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Spawn');

        $this->assertCount(0, $results);
    }

    public function testSearchSkipsNonAlbumLinks(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li>
        <a href="https://www.bedetheque.com/album/creation">Ajouter</a>
    </li>
    <li>
        <a href="https://www.bedetheque.com/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a>
    </li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('L\'héritier', $results[0]->title);
    }

    public function testScrapeWithSeriesUrl(): void
    {
        $seriesHtml = <<<HTML
<html>
<body>
<div class="bandeau-info">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
</div>
<ul class="bdt-box-albums-liste">
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = $this->albumPage();

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('https://www.bedetheque.com/serie-123-BD-Spawn.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
    }

    public function testScrapeWithSeriesUrlNoLinks(): void
    {
        $seriesHtml = <<<HTML
<html>
<body>
<div class="bandeau-info">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
</div>
<ul class="bdt-box-albums-liste">
    <li>Just text, no link</li>
    <li>Another item with no link</li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('https://www.bedetheque.com/serie-123-BD-Spawn.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertNull($info->title);
    }

    public function testScrapeWithSeriesUrlCreationLink(): void
    {
        $seriesHtml = <<<HTML
<html>
<body>
<div class="bandeau-info">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
</div>
<ul class="bdt-box-albums-liste">
    <li><a href="https://www.bedetheque.com/album/creation">Ajouter</a></li>
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = $this->albumPage();

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('https://www.bedetheque.com/serie-123-BD-Spawn.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
    }

    public function testScrapeWithSeriesUrlEmptyHref(): void
    {
        $seriesHtml = <<<HTML
<html>
<body>
<div class="bandeau-info">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
</div>
<ul class="bdt-box-albums-liste">
    <li><a href="">Empty href</a></li>
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = $this->albumPage();

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('https://www.bedetheque.com/serie-123-BD-Spawn.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
    }

    public function testParseAlbumPageNumberOnly(): void
    {
        $html = $this->albumPage('Spawn', 'Spawn', '42', [['McFarlane, Todd', 'Scénario']]);

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame(42, $info->number);
        $this->assertNull($info->title);
    }

    public function testParseAlbumPageTitleOnly(): void
    {
        $html = $this->albumPage('Spawn', 'Spawn - Titre seul', 'Hors série', [['McFarlane, Todd', 'Scénario']]);

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertNull($info->number);
        $this->assertSame('Titre seul', $info->title);
    }

    public function testParseAlbumPageAuthorsWithAllRoles(): void
    {
        $html = $this->albumPage('Spawn', 'Spawn - Test', 'Tome 1', [['Writer, The', 'Scénario'], ['Artist, The', 'Dessin'], ['Inker, The', 'Encre'], ['Colorist, The', 'Couleur']]);

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame('The Writer', $info->writer);
        $this->assertSame('The Artist', $info->artist);
        $this->assertSame('The Artist', $info->penciler);
        $this->assertSame('The Inker', $info->inker);
        $this->assertSame('The Colorist', $info->colorist);
    }

    public function testGetConfigFields(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $this->assertSame([], $scraper->getConfigFields());
    }

    public function testNormalizeAuthorNameNonMatching(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('normalizeAuthorName');
        $result = $method->invoke($scraper, 'John Doe');
        $this->assertSame('John Doe', $result);
    }

    public function testInvertArticle(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('invertArticle');

        $this->assertSame('crépuscule des dieux (Le)', $method->invoke(null, 'Le crépuscule des dieux'));
        $this->assertSame('Écho du sabre (L\')', $method->invoke(null, 'L\'Écho du sabre'));
        $this->assertSame('vie de famille (Une)', $method->invoke(null, 'Une vie de famille'));
        $this->assertSame('Largo Winch', $method->invoke(null, 'Largo Winch'));
    }

    public function testSearchNoSeriesAlbums(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li>Only one UL - not enough</li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(0, $results);
    }

    public function testParseSeriesAlbumsEmptyLinks(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li>No link here - just text</li>
    <li><a href="/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a></li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(1, $results);
        $this->assertStringContainsString("L'héritier", $results[0]->title);
    }

    public function testSearchSkipsEmptyHref(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="bdt-box-albums-liste">
    <li><a href="">Empty href</a></li>
    <li><a href="/BD-Largo-Winch-Tome-1-L-heritier-212.html">L'héritier</a></li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BdgestScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(1, $results);
        $this->assertStringContainsString("L'héritier", $results[0]->title);
    }

    public function testGetXpathTextWithContext(): void
    {
        $scraper = new BdgestScraper(new MockHttpClient());
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('getXpathText');

        $html = '<html><body><div><span class="foo">bar</span></div></body></html>';
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);
        $context = $xpath->query('//div')->item(0);

        $result = $method->invokeArgs($scraper, [$xpath, './/span[@class="foo"]', $context]);
        $this->assertSame('bar', $result);
    }
}
