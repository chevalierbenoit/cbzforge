<?php

namespace App\Tests\Service;

use App\Model\ComicInfo;
use App\Service\RenameTemplate;
use PHPUnit\Framework\TestCase;

class RenameTemplateTest extends TestCase
{
    private function makeInfo(array $overrides = []): ComicInfo
    {
        return new ComicInfo(...$overrides);
    }

    public function testRenderSubstitutesSimpleAlias(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn', 'number' => 5, 'title' => 'Retour en enfer', 'year' => 2024]);
        $result = $tpl->render('{serie} #{numero} - {titre} ({annee})', $info);
        $this->assertSame('Spawn #5 - Retour en enfer (2024)', $result);
    }

    public function testRenderHandlesNullFields(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn', 'number' => 5, 'title' => null, 'year' => null]);
        $result = $tpl->render('{serie} #{numero} - {titre} ({annee})', $info);
        $this->assertSame('Spawn #5', $result);
    }

    public function testRenderStripsEmptyBrackets(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn', 'year' => null]);
        $result = $tpl->render('{serie} ({annee})', $info);
        $this->assertSame('Spawn', $result);
    }

    public function testRenderFallbackToSeries(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn']);
        $result = $tpl->render('{numero} - {titre}', $info);
        $this->assertSame('Spawn', $result);
    }

    public function testRenderFallbackToTitle(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => null, 'title' => 'Un fauve en cage']);
        $result = $tpl->render('{numero} - {titre}', $info);
        $this->assertSame('Un fauve en cage', $result);
    }

    public function testRenderFallbackToInconnu(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => null, 'title' => null]);
        $result = $tpl->render('{numero} - {titre}', $info);
        $this->assertSame('inconnu', $result);
    }

    public function testRenderStripsPathTraversal(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => '../../etc/passwd']);
        $result = $tpl->render('{serie}', $info);
        $this->assertSame('etc/passwd', $result);
    }

    public function testRenderSanitizesBadChars(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn: La série "ultime"']);
        $result = $tpl->render('{serie}', $info);
        $this->assertSame('Spawn- La série -ultime', $result);
    }

    public function testRenderLeavesUnknownAliasAsIs(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn']);
        $result = $tpl->render('{serie} - {foo}', $info);
        $this->assertStringContainsString('{foo}', $result);
    }

    public function testRenderWithDirectorySeparator(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['series' => 'Spawn', 'number' => 5, 'title' => 'Retour']);
        $result = $tpl->render('{serie}/{numero} - {titre}', $info);
        $this->assertSame('Spawn/5 - Retour', $result);
    }

    public function testRenderArrayFields(): void
    {
        $tpl = new RenameTemplate();
        $info = $this->makeInfo(['genre' => ['Policier', 'Drame'], 'characters' => ['Jérôme', 'Babette']]);
        $result = $tpl->render('{genre} - {personnages}', $info);
        $this->assertSame('Policier, Drame - Jérôme, Babette', $result);
    }

    public function testPreviewWithDemoData(): void
    {
        $tpl = new RenameTemplate();
        $result = $tpl->preview('{serie}/{numero} - {titre} ({annee})');
        $this->assertSame('Jérôme K. Jérôme Bloche/14 - Un fauve en cage (2024)', $result);
    }

    public function testGetAvailableAliasesReturnsAll(): void
    {
        $tpl = new RenameTemplate();
        $aliases = $tpl->getAvailableAliases();
        $this->assertIsArray($aliases);
        $this->assertGreaterThan(10, \count($aliases));

        $serie = array_values(array_filter($aliases, static fn ($a) => '{serie}' === $a['alias']))[0] ?? null;
        $this->assertNotNull($serie);
        $this->assertSame('{serie}', $serie['alias']);
        $this->assertSame('series', $serie['field']);
    }
}
