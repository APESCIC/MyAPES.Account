> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Local setup (Docker)

**Audience:** developers  
**Purpose:** run MyAPES Account locally at `http://myaccount.myapes.test:8081`.

The full, maintained instructions are in the [README: Local development with Docker](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#local-development-with-docker). This page is the short version.

## You need
- Docker Desktop, running
- A hosts entry: `127.0.0.1 myaccount.myapes.test`
- No host PHP, Composer, Node, MySQL or Redis: the containers run PHP 8.4, Composer and Vite, and local development uses SQLite with file-backed cache and sessions

## Containers
| Service | What it runs |
| --- | --- |
| `web` | Caddy on port `8081` |
| `app` | PHP 8.4 FPM and Composer |
| `node` | Vite hot reload on port `5175` |

## First run
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\local\docker-preview.ps1 -Build -Fresh
```
This builds the images, creates `.env` from `.env.docker.example`, generates the app key, migrates and seeds test data. `-Fresh` wipes local data.

## Daily loop
1. Start Docker Desktop
2. Run `composer run dev:docker`. It checks for existing containers first: it reuses running ones, starts stopped ones, and only creates containers when none exist
3. Open `http://myaccount.myapes.test:8081` and sign in with a seeded QA account (see the README; never use real accounts or data)

## Commands
- Artisan: `docker compose exec app php artisan <command>`
- Tests: `docker compose exec app php artisan test`
- npm: `docker compose exec node npm <command>`

## Before a pull request
- `docker compose exec app php artisan test`
- `composer pre-merge`

Never commit `.env` or any key, token or password.
