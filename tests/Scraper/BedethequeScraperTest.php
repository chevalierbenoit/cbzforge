<?php

namespace App\Tests\Scraper;

use App\Model\ComicInfo;
use App\Scraper\BedethequeScraper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BedethequeScraperTest extends TestCase
{
    public function testGetName(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
        $this->assertSame('Bedetheque', $scraper->getName());
    }

    public function testSupportsBedethequeUrl(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
        $this->assertTrue($scraper->supports('https://www.bedetheque.com/album/123'));
        $this->assertTrue($scraper->supports('https://www.bedetheque.com/album/456'));
        $this->assertFalse($scraper->supports('https://comicvine.com/issue/789'));
        $this->assertFalse($scraper->supports('https://www.bdgest.com/album/789'));
    }

    public function testSearchReturnsScrapeResults(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="liste-albums">
    <li>nav item</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(2, $results);
        $this->assertStringContainsString('/BD-Largo-Winch-Tome-1-L-heritier-212', $results[0]->identifier);
        $this->assertSame('Largo Winch #1 - L\'héritier', $results[0]->title);
        $this->assertSame('1', $results[0]->subtitle);
    }

    public function testScrapeParsesAlbumPage(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1>
        <a href="https://www.bedetheque.com/serie-123-BD-Spawn.html" title="Spawn">Spawn</a>
    </h1>
    <h2>1<span class="numa"></span>. Origines</h2>
    <meta itemprop="name" content="Spawn - Origines">
    <meta itemprop="alternativeheadline" content="Tome 1">
    <h3>
        Une BD de
        <a href="https://www.bedetheque.com/auteur-456-BD-Todd-McFarlane.html">
            <span itemprop="author">Todd McFarlane</span>
        </a>
        - <span itemprop="publisher" class='editeur'>Image Comics</span>
        <span class='collection'>(Spawn)</span>
        - <span class='annee'>1992</span>
        <div class='liste-auteurs'>
            <a href="https://www.bedetheque.com/auteur-456-BD-McFarlane-Todd.html">McFarlane, Todd</a>
            <span class='metier'>(Scénario)</span>
            <a href="https://www.bedetheque.com/auteur-456-BD-McFarlane-Todd.html">McFarlane, Todd</a>
            <span class='metier'>(Dessin)</span>
        </div>
    </h3>
    <span itemprop="description">Première aventure de Spawn.</span>
    <span itemprop="isbn">9780000000000</span>
    <i class="icon-book espace"></i> <span itemprop="numberOfPages">112</span> pages
    <br/>
</div>
</body>
</html>
HTML;
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Spawn-Tome-1-Origines-1234.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
        $this->assertSame(1, $info->number);
        $this->assertSame('Todd McFarlane', $info->writer);
    }

    public function testScrapeExtractsAllFields(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1>
        <a href="https://www.bedetheque.com/serie-97940-BD-Pourpre-Sang.html" title="Pourpre-Sang">Pourpre-Sang</a>
    </h1>
    <h2>1<span class="numa"></span>. Le Cercle de Nimuée</h2>
    <h3>
        Une BD de
        <a href="https://www.bedetheque.com/auteur-53663-BD-Cherel-Leo.html">
            <span itemprop="author">Léo Chérel</span>
        </a>
        - <span itemprop="publisher" class='editeur'>Astrolabe</span>
        <span class='collection'>(Charybde)</span>
        - <span class='annee'>2026</span>
        <div class='liste-auteurs'>
            <a href="https://www.bedetheque.com/auteur-53663-BD-Cherel-Leo.html">Chérel, Léo</a>
            <span class='metier'>(Scénario)</span>
            <a href="https://www.bedetheque.com/auteur-53663-BD-Cherel-Leo.html">Chérel, Léo</a>
            <span class='metier'>(Dessin)</span>
        </div>
    </h3>
    <span class="parution">(09 mai 2026)</span>
    <span itemprop="numberOfPages">112</span> pages
    <span itemprop="isbn">9782488807067</span>
    <span itemprop="description">La profession de Pourpre-Sang n'est pas de tout repos.</span>
</div>
</body>
</html>
HTML;
        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
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

        $scraper = new BedethequeScraper($client);
        $results = $scraper->search('XyzzyNonexistent');

        $this->assertCount(0, $results);
    }

    public function testSearchTransportExceptionReturnsEmpty(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);

        $scraper = new BedethequeScraper($client);
        $results = $scraper->search('Spawn');

        $this->assertCount(0, $results);
    }

    public function testSearchSkipsNonAlbumLinks(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch","desc":"skin\/flags\/France.png"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1<span class="numa"></span>. Origines</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Scénario)</span>
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Dessin)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
        <span class="annee">1992</span>
    </h3>
    <span itemprop="isbn">9780000000000</span>
    <span itemprop="description">Première aventure de Spawn.</span>
    <span itemprop="numberOfPages">112</span>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
    <li>Just text, no link</li>
    <li>Another item with no link</li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
    <li><a href="https://www.bedetheque.com/album/creation">Ajouter</a></li>
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1<span class="numa"></span>. Origines</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Scénario)</span>
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Dessin)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
        <span class="annee">1992</span>
    </h3>
    <span itemprop="isbn">9780000000000</span>
    <span itemprop="description">Première aventure de Spawn.</span>
    <span itemprop="numberOfPages">112</span>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
    <li><a href="">Empty href</a></li>
    <li><a href="/BD-Spawn-Tome-1-Origines-1234.html">Origines</a></li>
</ul>
</body>
</html>
HTML;

        $albumHtml = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1<span class="numa"></span>. Origines</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Scénario)</span>
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Dessin)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
        <span class="annee">1992</span>
    </h3>
    <span itemprop="isbn">9780000000000</span>
    <span itemprop="description">Première aventure de Spawn.</span>
    <span itemprop="numberOfPages">112</span>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($seriesHtml, ['http_code' => 200]),
            new MockResponse($albumHtml, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('https://www.bedetheque.com/serie-123-BD-Spawn.html');

        $this->assertInstanceOf(ComicInfo::class, $info);
        $this->assertSame('Spawn', $info->series);
        $this->assertSame('Origines', $info->title);
    }

    public function testParseAlbumPageNumberOnly(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>42</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Scénario)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
        <span class="annee">1992</span>
    </h3>
    <span itemprop="isbn">9780000000000</span>
    <span itemprop="description">Desc.</span>
    <span itemprop="numberOfPages">112</span>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame(42, $info->number);
        $this->assertNull($info->title);
    }

    public function testParseAlbumPageTitleOnly(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>Titre seul</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">McFarlane, Todd</a>
            <span class="metier">(Scénario)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
    </h3>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertNull($info->number);
        $this->assertSame('Titre seul', $info->title);
    }

    public function testParseAlbumPageAuthorsWithAllRoles(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1. Test</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">Writer, The</a>
            <span class="metier">(Scénario)</span>
            <a href="...">Artist, The</a>
            <span class="metier">(Dessin)</span>
            <a href="...">Inker, The</a>
            <span class="metier">(Encre)</span>
            <a href="...">Colorist, The</a>
            <span class="metier">(Couleur)</span>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
    </h3>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame('The Writer', $info->writer);
        $this->assertSame('The Artist', $info->artist);
        $this->assertSame('The Artist', $info->penciler);
        $this->assertSame('The Inker', $info->inker);
        $this->assertSame('The Colorist', $info->colorist);
    }

    public function testParseAlbumPageFallbackWriter(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1. Test</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">Doe, John</a>
        </div>
        <span itemprop="publisher" class="editeur">Image Comics</span>
    </h3>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame('John Doe', $info->writer);
    }

    public function testParseAlbumPageFallbackPublisher(): void
    {
        $html = <<<HTML
<html>
<body>
<div class="bandeau-info album panier">
    <h1><a href="https://www.bedetheque.com/serie-123-BD-Spawn.html">Spawn</a></h1>
    <h2>1. Test</h2>
    <h3>
        <div class="liste-auteurs">
            <a href="...">Doe, John</a>
            <span class="metier">(Scénario)</span>
        </div>
        <span class="editeur">Fallback Publisher</span>
    </h3>
</div>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($html, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $info = $scraper->scrape('/BD-Test-123.html');

        $this->assertSame('Fallback Publisher', $info->publisher);
    }

    public function testGetConfigFields(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
        $this->assertSame([], $scraper->getConfigFields());
    }

    public function testNormalizeAuthorNameNonMatching(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('normalizeAuthorName');
        $result = $method->invoke($scraper, 'John Doe');
        $this->assertSame('John Doe', $result);
    }

    public function testInvertArticle(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
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
<ul class="liste-albums">
    <li>Only one UL - not enough</li>
</ul>
</body>
</html>
HTML;

        $client = new MockHttpClient([
            new MockResponse($ajaxJson, ['http_code' => 200]),
            new MockResponse($seriesPageHtml, ['http_code' => 200]),
        ]);

        $scraper = new BedethequeScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(0, $results);
    }

    public function testParseSeriesAlbumsEmptyLinks(): void
    {
        $ajaxJson = '[{"id":"14","label":"Largo Winch","value":"Largo Winch"}]';
        $seriesPageHtml = <<<HTML
<html>
<body>
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
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
<ul class="liste-albums">
    <li>nav</li>
</ul>
<ul class="liste-albums">
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

        $scraper = new BedethequeScraper($client);
        $results = $scraper->search('Largo Winch');

        $this->assertCount(1, $results);
        $this->assertStringContainsString("L'héritier", $results[0]->title);
    }

    public function testGetXpathTextWithContext(): void
    {
        $scraper = new BedethequeScraper(new MockHttpClient());
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
