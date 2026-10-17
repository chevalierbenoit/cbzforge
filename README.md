# ComicsScraper

Symfony 8 web app for scraping comic metadata, writing **ComicInfo.xml** into CBZ archives, converting CBR/PDF/EPUB to CBZ, and renaming/organizing files by series.

## Features

- **Metadata scraping** — Search and scrape album metadata from BDGest (extensible via `ScraperInterface`)
- **ComicInfo.xml injection** — Write ComicRack-compatible metadata directly into CBZ archives
- **Format conversion** — Convert CBR, PDF, EPUB to CBZ
- **File renaming & organization** — Organize files into `Series/TXX - Title.ext` structure
- **Library browser** — Browse configured library paths via REST API
- **Docker support** — PHP-FPM + Caddy, ready to deploy

## Requirements

- PHP 8.4+
- SQLite
- [unrar](https://launchpad.net/ubuntu/+source/unrar) (for CBR conversion)
- Composer

## Quick Start

### Docker (recommended)

```bash
docker compose up -d
```

Then open `http://localhost:8080`.

Le premier démarrage exécute automatiquement `composer install`, la création de la base de données, les migrations et `importmap:install`.

```bash
# Suivre les logs
docker compose logs -f

# Exécuter des commandes dans le container
docker compose exec -u www-data app php bin/console ...
```

Le container s'exécute en tant que `www-data` avec son UID/GID automatiquement aligné sur ceux de l'hôte (via `HOST_UID`/`HOST_GID`), ce qui permet d'accéder aux fichiers montés sans conflit de permissions.

### Sans Docker

```bash
composer install
cp .env .env.local  # ajuster APP_ENV=dev si nécessaire
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
symfony serve
```

Puis ouvrir `http://localhost:8000`.

## Usage

1. Add a library path via the admin interface (`/admin/libraries`) or REST API (`/api/libraries`)
2. Browse your library from the dashboard (`/`)
3. Scrape metadata from BDGest via the scrape workflow
4. Confirm metadata write to inject ComicInfo.xml into archives
5. Preview and execute rename operations

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/libraries` | List libraries (auto CRUD) |
| `POST` | `/api/files/scrape/search` | Search scrapers for a query |
| `POST` | `/api/files/scrape/url` | Scrape metadata from a URL |
| `POST` | `/api/files/scrape/confirm` | Confirm and write scraped metadata |
| `GET` | `/api/files/scrape/read` | Read existing metadata |
| `POST` | `/api/files/scrape/update` | Update metadata |
| `POST` | `/api/files/scrape/delete` | Delete metadata |
| `GET` | `/api/files/cover` | Get cover image from archive |
| `POST` | `/api/files/rename/preview` | Preview rename path |
| `POST` | `/api/files/rename/execute` | Execute rename + convert if needed |

## Security

- **Local use only.** The app has no authentication and no CSRF protection. Do not expose it to a network you do not trust.
- **Set your own `APP_SECRET`.** The value in `.env` is a placeholder. Put a real secret in `.env.local` (git-ignored):

  ```bash
  echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local
  ```
- **Destination paths are restricted.** Renamed files must stay inside the library they belong to.

## Tests

```bash
php vendor/bin/phpunit
```

## Architecture

- **Backend:** Symfony 8 + API Platform 4 (REST) + Doctrine ORM + SQLite
- **Frontend:** Twig templates + vanilla JS
- **Scrapers:** Plugin system via `ScraperInterface` — implement and tag with `app.scraper`

## License

MIT
