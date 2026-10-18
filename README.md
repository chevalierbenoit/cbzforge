# cbzforge

A Symfony web app to tidy up a comic library:

- scrape album metadata and write **ComicInfo.xml** into CBZ archives,
- convert **CBR, PDF and EPUB** files to CBZ,
- rename and organize files into a `Series/TXX - Title.ext` structure.

## Features

- **Metadata scraping**: search and scrape album metadata from Bedetheque and Google Books. More sources can be added through `ScraperInterface`.
- **ComicInfo.xml injection**: write ComicRack-compatible metadata directly into CBZ archives.
- **Format conversion**: convert CBR, PDF and EPUB to CBZ, one file or in batch. Jobs run in the background.
- **Renaming and organization**: rename files with a template and move them into series folders.
- **Library browser**: browse your libraries from the dashboard, and manage them in the admin.

## Requirements

Docker is the recommended setup. Without Docker you need:

- PHP 8.4 or newer, with the `ctype`, `iconv`, `pdo_sqlite`, `zip` and `gd` extensions
- Composer
- For CBR (RAR) files: the `unrar` command line tool (the Docker image builds it from RARLAB's source)
- For PDF files: `pdftoppm` (from poppler-utils); the Docker image includes it

## Quick start

### Docker (recommended)

```bash
git clone https://github.com/chevalierbenoit/cbzforge.git
cd cbzforge
docker compose up -d
```

Open <http://localhost:8080>.

On first start, the container runs `composer install`, creates the database, runs the migrations and installs the importmap assets.

```bash
# Follow the logs
docker compose logs -f

# Run a console command inside the container
docker compose exec app php bin/console <command>
```

The container runs PHP-FPM, Caddy and the background worker (`messenger:consume`), supervised by supervisord.

The image runs as UID/GID 568 by default, the user used by TrueNAS apps. The volume and mounted libraries must be writable by that user.

When started as root (the docker compose setup in this repository), the entrypoint instead aligns `www-data` on your host user through `HOST_UID` and `HOST_GID`, so the mounted files stay accessible.

### Without Docker

```bash
git clone https://github.com/chevalierbenoit/cbzforge.git
cd cbzforge
composer install
cp .env .env.local           # then set APP_SECRET, see "Security" below
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
symfony serve                # or: php -S 127.0.0.1:8000 -t public
```

Open <http://localhost:8000>.

Scraping and conversion jobs run in the background. Start a worker in a second terminal:

```bash
php bin/console messenger:consume async -vv
```

## Usage

0. On first start, create the administrator account on `/setup`. It is the only account, and the setup page closes after it is created.
1. An assistant then adds your first library and lets you choose the metadata sources. You can skip it and configure everything in the admin (`/admin/libraries`).
   The library can also be added through the REST API (`/api/libraries`).
2. Browse your libraries from the dashboard (`/`).
3. Select one or more files, then scrape their metadata.
4. Confirm the metadata to write ComicInfo.xml into the archives.
5. Preview and run renames. A CBR or PDF is converted to CBZ when needed.

## Web pages

| Path | Description |
|------|-------------|
| `/` | Dashboard, list of libraries |
| `/library/{id}` | Browse a library folder |
| `/admin/libraries` | Manage libraries |
| `/admin/conversion-settings` | PDF conversion settings |
| `/admin/rename-settings` | Rename template settings |
| `/admin/settings` | General settings |
| `/admin/tasks` | Background task history |

## API

The OpenAPI documentation is served at `/api/docs`. The full route list is available with `php bin/console debug:router`.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/libraries` | List libraries |
| `POST` | `/api/libraries` | Create a library |
| `GET` | `/api/libraries/{id}` | Get a library |
| `PUT` | `/api/libraries/{id}` | Update a library |
| `DELETE` | `/api/libraries/{id}` | Delete a library |
| `GET` | `/api/files/cover` | Cover image of an archive |
| `GET` | `/api/files/scrape/read` | Read the existing ComicInfo.xml |
| `POST` | `/api/files/scrape/search` | Search the scrapers |
| `POST` | `/api/files/scrape/url` | Scrape an album from a URL |
| `POST` | `/api/files/scrape/confirm` | Write confirmed metadata into an archive |
| `POST` | `/api/files/scrape/update` | Update existing metadata |
| `POST` | `/api/files/scrape/delete` | Remove ComicInfo.xml |
| `POST` | `/api/files/convert` | Convert one file to CBZ |
| `POST` | `/api/files/convert/batch` | Convert several files in the background |
| `POST` | `/api/files/rename/preview` | Preview a rename |
| `POST` | `/api/files/rename/execute` | Rename, converting first if needed |
| `POST` | `/api/files/rename-file` | Rename a single file |
| `GET` | `/api/tasks/list` | List background tasks |
| `GET` | `/api/tasks/status` | Summary of background tasks |
| `POST` | `/api/tasks/{id}/retry` | Requeue a failed task |
| `POST` | `/api/tasks/{id}/delete` | Delete a task |
| `POST` | `/api/tasks/purge` | Delete finished and failed tasks |
| `GET` | `/api/worker/status` | Worker heartbeat |
| `POST` | `/api/worker/restart` | Restart the worker |

## Configuration

| Variable | Description |
|----------|-------------|
| `APP_SECRET` | Application secret. Required. |
| `DATABASE_URL` | Database URL. Defaults to SQLite in `var/data.db`. |
| `MESSENGER_TRANSPORT_DSN` | Queue transport. Defaults to the Doctrine transport. |

## Security

- **Sign-in required.** Every page and API route needs the administrator's account; the sign-in page is rate limited (5 attempts, then a 15 minute wait). There is no default password.
- **Single account.** Version 1 has one administrator and no API key. Multiple users and an API key are not implemented.
- **Requests are checked.** Every request that changes data needs the session's CSRF token (a hidden `_token` field in forms, the `X-CSRF-Token` header for scripts) and must not come from another site (`Origin` check). Session cookies are `SameSite=Lax` as a second layer. Behind a reverse proxy, the host and scheme seen by the app must match the browser's (configure trusted proxies).
- **Set your own `APP_SECRET`.** The value in `.env` is a placeholder. Put a real secret in `.env.local`, which is git-ignored:

  ```bash
  echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local
  ```

- **Paths are restricted.** Files are only read and written inside the libraries you configured. Rename destinations must stay inside the library the file belongs to.

## Development

Run the tests and the checks inside the container, as the project does:

```bash
docker compose exec app php vendor/bin/phpunit
docker compose exec app php vendor/bin/phpstan analyse --level=6
docker compose exec app php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec app php vendor/bin/deptrac
```

Without Docker, drop the `docker compose exec app` prefix.

### Adding a scraper

Create a class in `src/Scraper/` that implements `ScraperInterface`. It is registered automatically with the `app.scraper` tag.

## Architecture

- **Backend:** Symfony 8, API Platform 4, Doctrine ORM, SQLite, Symfony Messenger
- **Frontend:** Twig templates and vanilla JavaScript (Stimulus, Turbo)
- **Packaging:** a single Docker image with Caddy, PHP-FPM and a worker

## License

MIT. See [LICENSE](LICENSE).
