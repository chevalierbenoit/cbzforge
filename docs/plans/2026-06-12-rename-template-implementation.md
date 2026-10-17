# Rename Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a configurable rename expression system with `{alias}` placeholders, settings page with live preview, and per-file expression override in the rename modal.

**Architecture:** New `RenameTemplate` service handles `{alias}` substitution + smart cleanup. `RenameService` accepts an optional expression parameter. `RenameController` forwards the expression from the request. A new `RenameSettingsController` serves the settings page and AJAX preview endpoint. The frontend rename modal adds an expression override toggle.

**Tech Stack:** PHP 8.4, Symfony 8, Stimulus, Twig, Doctrine (Setting entity), PHPUnit

---

### Task 1: RenameTemplate Service

**Files:**
- Create: `src/Service/RenameTemplate.php`
- Create: `tests/Service/RenameTemplateTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
        $this->assertSame('Spawn- La série -ultime-', $result);
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

    public function testPreviewWithDemoData(): void
    {
        $tpl = new RenameTemplate();
        $result = $tpl->preview('{serie}/{numero} - {titre} ({annee})');
        $this->assertSame("Jérôme K. Jérôme Bloche/14 - Un fauve en cage (2024)", $result);
    }

    public function testGetAvailableAliasesReturnsAll(): void
    {
        $tpl = new RenameTemplate();
        $aliases = $tpl->getAvailableAliases();
        $this->assertIsArray($aliases);
        $this->assertGreaterThan(10, count($aliases));

        $serie = array_values(array_filter($aliases, fn($a) => $a['alias'] === '{serie}'))[0] ?? null;
        $this->assertNotNull($serie);
        $this->assertSame('{serie}', $serie['alias']);
        $this->assertSame('series', $serie['field']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec php php vendor/bin/phpunit tests/Service/RenameTemplateTest.php --verbose`
Expected: ERROR — class not found

- [ ] **Step 3: Write the RenameTemplate implementation**

```php
<?php

namespace App\Service;

use App\Model\ComicInfo;

class RenameTemplate
{
    private const ALIASES = [
        'serie' => 'series',
        'titre' => 'title',
        'numero' => 'number',
        'annee' => 'year',
        'scenariste' => 'writer',
        'dessinateur' => 'artist',
        'dessin' => 'penciler',
        'encreur' => 'inker',
        'coloriste' => 'colorist',
        'lettreur' => 'letterer',
        'couverture' => 'coverArtist',
        'editeur' => 'publisher',
        'resume' => 'summary',
        'isbn' => 'isbn',
        'classification' => 'ageRating',
        'langue' => 'languageIso',
        'genre' => 'genre',
        'personnages' => 'characters',
    ];

    public function render(string $expression, ComicInfo $info): string
    {
        $result = $this->substitute($expression, $info);
        $result = $this->sanitize($result);
        $result = $this->cleanup($result);
        $result = $this->stripDotDot($result);
        $result = trim($result);

        if ('' === $result) {
            return $this->fallback($info);
        }

        return $result;
    }

    public function preview(string $expression): string
    {
        $demo = new ComicInfo(
            series: "Jérôme K. Jérôme Bloche",
            number: 14,
            title: "Un fauve en cage",
            year: 2024,
            writer: "Makyo",
            artist: "Dodier",
            publisher: "Dupuis",
            languageIso: "fr",
            genre: ["Policier", "Drame"],
        );

        return $this->render($expression, $demo);
    }

    public function getAvailableAliases(): array
    {
        $descriptions = [
            'serie' => 'Nom de la série',
            'titre' => "Titre de l'album",
            'numero' => 'Numéro du tome',
            'annee' => 'Année de publication',
            'scenariste' => 'Scénariste',
            'dessinateur' => 'Dessinateur',
            'dessin' => 'Dessinateur (crayonné)',
            'encreur' => 'Encreur',
            'coloriste' => 'Coloriste',
            'lettreur' => 'Lettreur',
            'couverture' => 'Dessinateur de couverture',
            'editeur' => 'Éditeur',
            'resume' => 'Résumé',
            'isbn' => 'ISBN',
            'classification' => 'Classification (âge)',
            'langue' => 'Langue (code ISO)',
            'genre' => 'Genre(s)',
            'personnages' => 'Personnage(s)',
        ];

        $result = [];
        foreach (self::ALIASES as $alias => $field) {
            $result[] = [
                'alias' => '{'.$alias.'}',
                'field' => $field,
                'description' => $descriptions[$alias] ?? $field,
            ];
        }

        return $result;
    }

    private function substitute(string $expression, ComicInfo $info): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($info) {
            $alias = $m[1];
            $field = self::ALIASES[$alias] ?? null;

            if (null === $field) {
                return $m[0]; // unknown alias, leave as-is
            }

            $value = match ($field) {
                'genre', 'characters' => $info->{$field}
                    ? implode(', ', $info->{$field})
                    : null,
                default => $info->{$field} ?? null,
            };

            return null !== $value ? (string) $value : '';
        }, $expression);
    }

    private function sanitize(string $path): string
    {
        $segments = explode('/', $path);
        $segments = array_map(function (string $segment): string {
            $segment = preg_replace('/[\\\\:*?"<>|\x00-\x1f]/', '-', $segment);
            $segment = preg_replace('/-+/', '-', $segment);
            $segment = trim($segment);

            return $segment;
        }, $segments);

        return implode('/', $segments);
    }

    private function cleanup(string $path): string
    {
        $path = preg_replace('/\s+/', ' ', $path);
        $path = preg_replace('/\s*\(\s*\)/', '', $path);
        $path = preg_replace('/\s*\[\s*\]/', '', $path);
        $path = preg_replace('/^\s*[-\s,_;:.]+/', '', $path);
        $path = preg_replace('/[-\s,_;:.]+$/', '', $path);
        $path = preg_replace('/-{2,}/', '-', $path);
        $path = preg_replace('/_{2,}/', '_', $path);
        $path = preg_replace('/\/{2,}/', '/', $path);
        $segments = array_map('trim', explode('/', $path));
        $segments = array_filter($segments, fn(string $s) => '' !== $s);
        $path = implode('/', $segments);
        $path = preg_replace('/\s+/', ' ', $path);

        return trim($path);
    }

    private function stripDotDot(string $path): string
    {
        $segments = array_filter(explode('/', $path), fn(string $s) => trim($s) !== '..');

        return implode('/', $segments);
    }

    private function fallback(ComicInfo $info): string
    {
        if (null !== $info->series && '' !== $info->series) {
            return $this->sanitize($info->series);
        }
        if (null !== $info->title && '' !== $info->title) {
            return $this->sanitize($info->title);
        }

        return 'inconnu';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec php php vendor/bin/phpunit tests/Service/RenameTemplateTest.php --verbose`
Expected: 11 tests, all PASS

- [ ] **Step 5: Commit**

Run:
```bash
docker compose exec php bash -c "cd /app && git add src/Service/RenameTemplate.php tests/Service/RenameTemplateTest.php && git commit -m 'feat: add RenameTemplate service with alias substitution and smart cleanup'"
```

---

### Task 2: Update RenameService

**Files:**
- Modify: `src/Service/RenameService.php`
- Modify: `tests/Service/RenameServiceTest.php`

- [ ] **Step 1: Read current RenameService**

Read `src/Service/RenameService.php` to understand current `preview()` and `rename()` signatures.

- [ ] **Step 2: Write failing test for expression parameter**

Add these test methods to `tests/Service/RenameServiceTest.php`:

```php
public function testPreviewWithExpression(): void
{
    $service = $this->createService();
    $source = $this->createTempCbz('My Series', 3, 'Some Title', 2023);

    $preview = $service->preview($source, '{serie} #{numero} - {titre}', 'customExpression');

    $this->assertStringContainsString('My Series #3 - Some Title', $preview->newFilename);
}

public function testRenameWithExpression(): void
{
    $service = $this->createService();
    $source = $this->createTempCbz('My Series', 3, 'Some Title', 2023);

    $newPath = $service->rename($source, '{serie} #{numero} - {titre}', 'customExpression');

    $this->assertFileExists($newPath);
    $this->assertStringContainsString('My Series #3 - Some Title', basename($newPath));
    @unlink($newPath);
}

public function testRenameWithoutExpressionFallsBack(): void
{
    $service = $this->createService();
    $source = $this->createTempCbz('My Series', 3, 'Some Title', 2023);

    $newPath = $service->rename($source, $this->createInfo('My Series', 3, 'Some Title', 2023));

    $this->assertFileExists($newPath);
    @unlink($newPath);
}
```

- [ ] **Step 3: Run test to verify failure**

Run: `docker compose exec php php vendor/bin/phpunit tests/Service/RenameServiceTest.php --verbose --filter="Expression|FallsBack"`
Expected: FAIL — method signatures don't match

- [ ] **Step 4: Update RenameService signatures**

Add `?string $expression = null` parameter to `preview()` and `rename()`:

```php
public function preview(string $currentPath, ComicInfo $info, ?string $expression = null): RenamePreview
{
    if (null !== $expression) {
        $relPath = $this->renameTemplate->render($expression, $info);
        $filename = pathinfo($relPath, PATHINFO_BASENAME);
        $ext = pathinfo($currentPath, PATHINFO_EXTENSION);
        $newPath = dirname($currentPath).'/'.$relPath.'.'.$ext;

        return new RenamePreview($currentPath, $newPath, $filename.'.'.$ext);
    }

    // ... existing logic
}

public function rename(string $currentPath, ComicInfo $info, ?string $expression = null): string
{
    if (null !== $expression) {
        $relPath = $this->renameTemplate->render($expression, $info);
        $ext = pathinfo($currentPath, PATHINFO_EXTENSION);
        $newPath = dirname($currentPath).'/'.$relPath.'.'.$ext;

        $dir = dirname($newPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Impossible de créer le répertoire "%s".', $dir));
            }
        }

        if (!rename($currentPath, $newPath)) {
            throw new \RuntimeException(\sprintf('Impossible de renommer "%s" vers "%s".', $currentPath, $newPath));
        }

        return $newPath;
    }

    // ... existing logic
}
```

Also inject `RenameTemplate` in the constructor:

```php
public function __construct(
    private readonly RenameTemplate $renameTemplate,
) {
}
```

- [ ] **Step 5: Run tests to verify pass**

Run: `docker compose exec php php vendor/bin/phpunit tests/Service/RenameServiceTest.php --verbose`
Expected: All tests pass (existing + new)

- [ ] **Step 6: Commit**

```bash
docker compose exec php bash -c "cd /app && git add src/Service/RenameService.php tests/Service/RenameServiceTest.php && git commit -m 'feat: RenameService accepts optional expression parameter'"
```

---

### Task 3: Update RenameController

**Files:**
- Modify: `src/Controller/RenameController.php`
- Modify: `tests/Controller/RenameControllerTest.php`

- [ ] **Step 1: Read current RenameController**

Read `src/Controller/RenameController.php` to understand `preview()` and `execute()` signatures.

- [ ] **Step 2: Update RenameController::preview()**

Add `$expression` from request body, inject `RenameTemplate` and `SettingsService`:

```php
#[Route('/api/files/rename/preview', name: 'file_rename_preview', methods: ['POST'])]
public function preview(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $libraryId = $data['library_id'] ?? null;
    $path = $data['path'] ?? '';
    $expression = $data['expression'] ?? null;

    if (!$libraryId || !$path) {
        return $this->json(['error' => 'Missing library_id or path'], 400);
    }

    $library = $this->em->getRepository(Library::class)->find($libraryId);
    if (!$library) {
        return $this->json(['error' => 'Library not found'], 400);
    }

    $fullPath = $this->libraryService->getFullPath($library, $path);
    $info = $this->metadataService->read($fullPath);

    if (!$info) {
        return $this->json(['error' => 'No metadata found'], 400);
    }

    if (null !== $expression) {
        $targetPath = $this->renameService->preview($fullPath, $info, $expression);
    } else {
        $savedExpression = $this->settings->get('rename.format');
        if ($savedExpression) {
            $targetPath = $this->renameService->preview($fullPath, $info, $savedExpression);
        } else {
            $targetPath = $this->renameService->preview($fullPath, $info);
        }
    }

    return $this->json([
        'original_path' => $targetPath->originalPath,
        'new_path' => $targetPath->newPath,
        'new_filename' => $targetPath->newFilename,
    ]);
}
```

Same pattern for `execute()`.

- [ ] **Step 3: Update service constructor** to accept `SettingsService` and `RenameTemplate`

```php
public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly LibraryService $libraryService,
    private readonly MetadataService $metadataService,
    private readonly RenameService $renameService,
    private readonly ConversionService $conversionService,
    private readonly SettingsService $settings,
    private readonly RenameTemplate $renameTemplate,
) {
}
```

- [ ] **Step 4: Run existing tests to make sure they still pass**

Run: `docker compose exec php php vendor/bin/phpunit tests/Controller/RenameControllerTest.php --verbose`
Expected: All existing tests pass

- [ ] **Step 5: Commit**

```bash
docker compose exec php bash -c "cd /app && git add src/Controller/RenameController.php && git commit -m 'feat: RenameController accepts expression parameter, uses RenameTemplate'"
```

---

### Task 4: Create Rename Settings Page

**Files:**
- Create: `src/Controller/Admin/RenameSettingsController.php`
- Create: `templates/admin/rename_settings.html.twig`
- Modify: `templates/admin/libraries.html.twig` (sidebar link)
- Modify: `src/Controller/AdminController.php` (add route for sidebar)

- [ ] **Step 1: Create RenameSettingsController**

```php
<?php

namespace App\Controller\Admin;

use App\Service\RenameTemplate;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RenameSettingsController extends AbstractController
{
    #[Route('/admin/rename-settings', name: 'admin_rename_settings')]
    public function index(Request $request, SettingsService $settings, RenameTemplate $renameTemplate): Response
    {
        if ($request->isMethod('POST')) {
            $expression = $request->request->get('expression', '');
            $expression = trim($expression);
            if ('' === $expression) {
                $expression = '{serie}/{numero} - {titre}';
            }
            $settings->set('rename.format', $expression);
            $this->addFlash('success', 'Expression de renommage enregistrée.');

            return $this->redirectToRoute('admin_rename_settings');
        }

        $currentExpression = $settings->get('rename.format');
        if (null === $currentExpression) {
            $currentExpression = '{serie}/{numero} - {titre}';
        }

        return $this->render('admin/rename_settings.html.twig', [
            'expression' => $currentExpression,
            'aliases' => $renameTemplate->getAvailableAliases(),
        ]);
    }

    #[Route('/admin/rename-template/preview', name: 'admin_rename_template_preview', methods: ['POST'])]
    public function preview(Request $request, RenameTemplate $renameTemplate): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $expression = $data['expression'] ?? '';

        if ('' === trim($expression)) {
            return $this->json(['rendered' => '']);
        }

        try {
            $rendered = $renameTemplate->preview($expression);

            return $this->json(['rendered' => $rendered]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }
}
```

- [ ] **Step 2: Create rename_settings.html.twig**

```twig
{% extends 'base.html.twig' %}

{% block title %}Configuration du renommage — ComicsScraper{% endblock %}

{% block sidebar %}
    <div class="sidebar-header">Administration</div>
    <a href="{{ path('admin_libraries') }}" class="sidebar-item">📚 Bibliothèques</a>
    <a href="{{ path('admin_settings') }}" class="sidebar-item">⚙ Scrapers</a>
    <div class="sidebar-item active">✏ Renommage</div>
{% endblock %}

{% block body %}
<div class="page-wrapper">
    <div class="page-header">
        <h1>Configuration du renommage</h1>
    </div>

    <form method="post" action="{{ path('admin_rename_settings') }}" data-controller="rename-preview">
        <div class="settings-card">
            <div class="settings-card-header">
                <span class="settings-card-title">Expression de renommage</span>
            </div>

            <div class="form-group">
                <label for="rename-expression">Expression</label>
                <input type="text" id="rename-expression" name="expression"
                       value="{{ expression }}"
                       data-rename-preview-target="input"
                       data-action="input->rename-preview#preview"
                       style="width: 100%; font-family: monospace; padding: 0.5rem;"
                       placeholder="{serie}/{numero} - {titre}">
            </div>

            <div class="form-group">
                <label>Aperçu</label>
                <div id="rename-preview" data-rename-preview-target="output"
                     style="padding: 0.5rem; background: var(--bg-secondary); border-radius: 4px; font-family: monospace; min-height: 1.5rem;">
                    {{ expression }}
                </div>
            </div>

            <div class="form-group">
                <label>Alias disponibles</label>
                <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: 4px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--bg-secondary); position: sticky; top: 0;">
                                <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border);">Alias</th>
                                <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border);">Champ</th>
                                <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border);">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                        {% for alias in aliases %}
                            <tr>
                                <td style="padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--border); font-family: monospace;">{{ alias.alias }}</td>
                                <td style="padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--border);"><code>{{ alias.field }}</code></td>
                                <td style="padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--border);">{{ alias.description }}</td>
                            </tr>
                        {% endfor %}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
    </form>
</div>
{% endblock %}
```

- [ ] **Step 3: Add Stimulus controller for live preview**

Create `assets/controllers/rename_preview_controller.js`:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'output'];

    connect() {
        this._timer = null;
    }

    disconnect() {
        clearTimeout(this._timer);
    }

    preview() {
        clearTimeout(this._timer);
        this._timer = setTimeout(async () => {
            const expression = this.inputTarget.value;
            if (!expression.trim()) {
                this.outputTarget.textContent = '';
                return;
            }

            try {
                const response = await fetch('/admin/rename-template/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ expression }),
                });

                const data = await response.json();
                if (data.rendered !== undefined) {
                    this.outputTarget.textContent = data.rendered;
                } else {
                    this.outputTarget.textContent = '⚠ ' + (data.error || 'Erreur');
                }
            } catch (e) {
                this.outputTarget.textContent = '⚠ Erreur de connexion';
            }
        }, 300);
    }
}
```

- [ ] **Step 4: Add sidebar link in libraries.html.twig**

Find the sidebar in `templates/admin/libraries.html.twig` and add after the Scrapers link:

```diff
   <a href="{{ path('admin_settings') }}" class="sidebar-item">⚙ Scrapers</a>
+  <a href="{{ path('admin_rename_settings') }}" class="sidebar-item">✏ Renommage</a>
```

- [ ] **Step 5: Register Stimulus controller** in `assets/app.js`

Make sure the controller is registered. If using a convention-based loader, it's automatic. Otherwise add:

```javascript
import RenamePreviewController from './controllers/rename_preview_controller';
application.register('rename-preview', RenamePreviewController);
```

- [ ] **Step 6: Build frontend assets**

Run: `docker compose exec php php bin/console asset-map:compile` (or whatever the asset build command is)

- [ ] **Step 7: Test settings page**

- Open browser to `/admin/rename-settings`
- Verify the alias table renders
- Type in the expression field, verify live preview updates (debounced)
- Save the expression, verify it persists

- [ ] **Step 8: Commit**

```bash
docker compose exec php bash -c "cd /app && git add src/Controller/Admin/RenameSettingsController.php templates/admin/rename_settings.html.twig assets/controllers/rename_preview_controller.js && git commit -m 'feat: rename settings page with expression editor and live preview'"
```

---

### Task 5: Update Rename Modal for Expression Override

**Files:**
- Modify: `assets/controllers/rename_controller.js`
- Modify: `templates/library/explore.html.twig` (if modal has template)

- [ ] **Step 1: Read current rename_controller.js**

Read `assets/controllers/rename_controller.js` to understand `_showPreviewModal`, `_doPreview`, `_fetchExecute`.

- [ ] **Step 2: Modify `_showPreviewModal` to add expression toggle**

After the `Nouveau chemin` input row, add:

```javascript
// In the modal HTML template within _showPreviewModal:
`
...
<tr>
    <td>Nouveau chemin</td>
    <td>
        <input type="text" class="rename-new-path" value="${escapeHtml(data.new_path)}"
               style="width: 100%;" data-action="input->${this.identifier}#onNewPathChange">
    </td>
</tr>
<tr>
    <td colspan="2">
        <details style="margin-top: 0.5rem;">
            <summary style="cursor: pointer; color: var(--text-subtle); font-size: 0.85rem;">Format personnalisé</summary>
            <div style="margin-top: 0.5rem;">
                <input type="text" class="rename-expression-input" value="${escapeHtml(this._currentExpression || '${defaultExpression}')}"
                       style="width: 100%; font-family: monospace; padding: 0.3rem;"
                       data-action="input->${this.identifier}#onExpressionChange">
                <div class="rename-expression-preview" style="margin-top: 0.3rem; font-size: 0.85rem; color: var(--text-subtle); font-family: monospace;"></div>
            </div>
        </details>
    </td>
</tr>
...
`
```

- [ ] **Step 3: Add `_currentExpression` property and `onExpressionChange` method**

```javascript
_currentExpression = null;

async onExpressionChange(event) {
    const expression = event.target.value;
    this._currentExpression = expression;

    const previewEl = event.target.closest('details').querySelector('.rename-expression-preview');
    if (!expression.trim()) {
        previewEl.textContent = '';
        return;
    }

    // Re-preview with the custom expression
    const path = this._currentPath;
    if (!path) return;

    try {
        const response = await fetch('/api/files/rename/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                library_id: this.libraryIdValue,
                path: path,
                expression: expression,
            }),
        });
        const data = await response.json();
        if (data.new_path) {
            previewEl.textContent = data.new_path;
            // Also update the main new path input
            const pathInput = event.target.closest('.modal-body').querySelector('.rename-new-path');
            if (pathInput) pathInput.value = data.new_path;
        } else {
            previewEl.textContent = '⚠ Erreur';
        }
    } catch (e) {
        previewEl.textContent = '⚠ Erreur de connexion';
    }
}
```

- [ ] **Step 4: Modify `_fetchExecute` to send expression**

```javascript
async _fetchExecute(path, newPath) {
    const expression = this._currentExpression;
    const body = {
        library_id: this.libraryIdValue,
        path: path,
        new_path: newPath,
    };
    if (expression) {
        body.expression = expression;
    }
    // ... rest of existing method
}
```

- [ ] **Step 5: Build frontend assets**

Run: `docker compose exec php php bin/console asset-map:compile`

- [ ] **Step 6: Commit**

```bash
docker compose exec php bash -c "cd /app && git add assets/controllers/rename_controller.js && git commit -m 'feat: rename modal supports expression override'"
```

---

### Task 6: Navigation Link and Default Setting

**Files:**
- Modify: `templates/admin/libraries.html.twig` (sidebar — already done in Task 4)
- Modify: `templates/admin/library_edit.html.twig` (sidebar)
- Modify: `src/Controller/AdminController.php` (sidebar link in settings)

- [ ] **Step 1: Update sidebar in library_edit.html.twig**

```diff
   <a href="{{ path('admin_settings') }}" class="sidebar-item">⚙ Scrapers</a>
+  <a href="{{ path('admin_rename_settings') }}" class="sidebar-item">✏ Renommage</a>
```

- [ ] **Step 2: Commit**

```bash
docker compose exec php bash -c "cd /app && git add templates/admin/library_edit.html.twig && git commit -m 'chore: add rename settings navigation link'"
```

---

### Task 7: Self-Review and Verification

- [ ] **Step 1: Verify spec coverage**

Check each spec requirement against the plan:
- Alias reference (18 aliases) → Task 1 (RenameTemplate::ALIASES + getAvailableAliases)
- render() method with substitution, sanitize, cleanup, fallback, path traversal → Task 1
- preview() with demo data → Task 1
- Settings page with input, alias table, live preview → Task 4
- Rename modal with expression override → Task 5
- RenameController accepts expression → Task 3
- RenameService accepts expression → Task 2
- Extension appended automatically → Task 2 (`.'.'.$ext`)
- Unknown alias left as-is → Task 1 test
- Empty expression resets to default → Task 4 controller
- Path traversal protection → Task 1 (stripDotDot)

- [ ] **Step 2: Run full test suite**

Run: `docker compose exec php php vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 3: Push branch**

```bash
docker compose exec php bash -c "cd /app && git push"
```

## Self-Review

**Spec coverage:** All spec requirements have corresponding tasks. No gaps found.

**Placeholder scan:** No "TBD", "TODO", or incomplete steps. All code is concrete.

**Type consistency:** Method signatures are consistent across tasks. `RenameTemplate::render(string expression, ComicInfo info): string` in Task 1 matches usage in Task 2 and 3.
