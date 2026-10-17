# Rename Template — Configurable File Naming Expression

## Summary

Allow users to define a custom rename expression (template) using `{alias}` placeholders
that map to ComicInfo metadata fields. The expression generates the full relative path
(including subdirectories via `/`). It is configurable in the settings page, with a live
preview, and overridable per-file in the rename modal.

## Motivation

The current rename uses a hardcoded filename format (`{serie} #{numero} - {titre}` with
`Series/` directory prefix). Users cannot customize the naming pattern. This spec adds a
flexible, user-configurable template system.

## Alias Reference

| Alias | Champ ComicInfo | Type | Exemple |
|-------|-----------------|------|---------|
| `{serie}` | series | string | Jérôme K. Jérôme Bloche |
| `{titre}` | title | string | Un fauve en cage |
| `{numero}` | number | int | 14 |
| `{annee}` | year | int | 2024 |
| `{scenariste}` | writer | string | Makyo |
| `{dessinateur}` | artist | string | Dodier |
| `{dessin}` | penciler | string | Dodier |
| `{encreur}` | inker | string | Dodier |
| `{coloriste}` | colorist | string | Cerise |
| `{lettreur}` | letterer | string | ? |
| `{couverture}` | coverArtist | string | Dodier |
| `{editeur}` | publisher | string | Dupuis |
| `{resume}` | summary | string | (long text) |
| `{isbn}` | isbn | string | 9782800123456 |
| `{classification}` | ageRating | string | 12+ |
| `{langue}` | languageIso | string | fr |
| `{genre}` | genre | string[] | Policier, Drame |
| `{personnages}` | characters | string[] | Jérôme, Babette |

Listes (`genre`, `personnages`) : rendues comme `"Policier, Drame"`.

## Service: RenameTemplate

New class `App\Service\RenameTemplate`.

### `render(string $expression, ComicInfo $info): string`

The full relative path WITHOUT extension.

1. **Substitution**: Replace each `{alias}` with its value from ComicInfo (null → empty string)
2. **Sanitize**: Replace filesystem-unsafe characters with `-` in each path segment.
   Do NOT sanitize `/` (directory separator) or `.` (kept for future extension handling).
3. **Smart cleanup**: Applied after substitution:
   - Collapse multiple spaces: `\s+` → ` `
   - Remove empty brackets: `\s*\(\s*\)` → ``, `\s*\[\s*\]` → ``
   - Remove orphaned separator at start/end: `^\s*[-\s,_;:.]+\s*` → ``, same at end
   - Collapse repeated separators: `-{2,}` → `-`, `_{2,}` → `_`
   - Collapse repeated `/`: `/{2,}` → `/`
   - Trim each path segment
   - Remove empty path segments (e.g. double `/` after cleanup)
4. **Fallback**: If the result is empty after cleanup, use `{serie}` or `{titre}` or `'inconnu'`
5. **Path traversal protection**: Remove any path segment equal to `..`

### `preview(string $expression): string`

Same as `render()` but with hardcoded demo data from "Jérôme K. Jérôme Bloche #14 - Un fauve en cage":
- series: "Jérôme K. Jérôme Bloche"
- number: 14
- title: "Un fauve en cage"
- year: 2024
- writer: "Makyo"
- artist: "Dodier"
- publisher: "Dupuis"
- languageIso: "fr"
- genre: ["Policier", "Drame"]

Example: `{serie}/{numero} - {titre}` → `Jérôme K. Jérôme Bloche/14 - Un fauve en cage`

### `getAvailableAliases(): array`

Returns the alias map for frontend display:
```php
[
    ['alias' => '{serie}', 'field' => 'series', 'description' => 'Nom de la série'],
    ...
]
```

## Settings

- **Key**: `rename.format`
- **Storage**: `Setting` entity (existing key-value)
- **Default value**: `{serie}/{numero} - {titre}`
- **Migration**: Insert default on first access if not set

### Settings Page UI

New section "Renommage" (accessible from admin sidebar).

Contains:
1. **Input field** — text input for the expression, wide enough for long patterns
2. **Live preview** — AJAX call on input change (debounced 300ms) to
   `POST /admin/rename-template/preview {expression}`
   Returns `{rendered: "..."}`. Shows preview below the input.
   If error (invalid expression), shows error message in red.
3. **Alias table** — scrollable table listing all aliases with their description and type
4. **Save button** — saves to `Setting` entity

### Route

- `GET /admin/rename-settings` — renders the settings page
- `POST /admin/rename-settings` — saves the expression
- `POST /admin/rename-template/preview` — AJAX preview endpoint

## Rename Modal Changes

### Current modal

Shows `Nouveau chemin` with editable full path input.

### New modal

1. The rename preview now uses the `rename.format` expression by default
2. A "Format personnalisé" toggle reveals a secondary input showing the current expression
3. When the user changes the expression, the preview updates via AJAX
4. The custom expression is sent with the preview/execute requests
5. If no custom expression is provided, the backend uses the saved setting
6. The custom expression is NOT persisted — it's per-file only

### Backend changes in RenameController

- `preview()` and `execute()` accept optional field `expression`
- If provided, `RenameTemplate::render(expression, info)` generates the relative path
- If not provided, loads `rename.format` from settings
- The extension is always appended automatically by the system (`.cbz` for converted files)

### Backend changes in RenameService

- `preview(path, info, ?expression)` — uses RenameTemplate when expression is available
- `rename(path, info, ?expression)` — same, then creates directories and renames
- `getNewPath()` and `buildFilename()` kept for backward compatibility (used as fallback
  when no expression is configured)
- Directory creation uses the `/`-separated parts of the rendered path

## Filesystem Safety

- `..` path components are stripped to prevent path traversal
- Each path segment is sanitized independently (bad chars → `-`)
- `/` is preserved as directory separator
- The library base path is always verified via `LibraryService::getFullPath()` (existing)

## Error Handling

- If the expression contains an unknown alias like `{foo}`, it is left as-is in the output
  (user can see it and fix it)
- If the rendered path is empty → fallback to `{serie}` or `inconnu`
- If the save expression is empty/blank → reset to default `{serie}/{numero} - {titre}`
- Preview endpoint returns `{error: "..."}` on invalid input

## Test Plan

- `RenameTemplateTest`:
  - Basic substitution: one alias, multiple aliases
  - Smart cleanup: empty value removes separator, empty brackets cleaned
  - Fallback: all null fields
  - Path traversal: `..` segments stripped
  - Sanitize: bad chars in values
  - Preview with demo data
  - Unknown alias left as-is
- `RenameServiceTest`:
  - Update existing tests to cover expression parameter
  - Expression overrides default format
  - No expression falls back to existing behavior
- `RenameControllerTest`:
  - Preview with expression parameter
  - Execute with expression parameter
- Frontend: Live preview AJAX call on input change

## Future Considerations

- i18n: Alias names currently in French; translate when app is internationalized
- Conditional blocks: more advanced syntax (`{?numero} #{numero}`) could be added later
- Multiple profiles: ability to save multiple named formats (not needed yet)
