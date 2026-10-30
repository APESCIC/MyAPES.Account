> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Local setup (Laragon)

**Audience:** developers  
**Purpose:** run MyAPES Account locally at `http://myapes-account.test`.

The full, maintained instructions are in the [README: Local environment setup](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#local-environment-setup). This page is the short version.

## You need
- Laragon with PHP 8.4 (8.3 minimum; CI uses 8.4) and the usual Laravel extensions (`pdo_sqlite`, `openssl`, `mbstring`, `curl`, `fileinfo`)
- `php`, `composer`, `node` and `npm` in the Laragon terminal
- No MySQL or Redis: local development uses SQLite and file-backed cache and sessions

## Virtual host
- Clone to `C:\laragon\www\myapes-account`; Laragon serves it as `http://myapes-account.test`
- Document root must be `public/`; restart Apache after vhost changes

## First run
```powershell
cd C:\laragon\www\myapes-account
powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Laragon -Fresh
```
This creates `.env` from `.env.laragon.example`, generates the app key, migrates, seeds test data and builds the frontend. Use `-AppUrl http://your-host.test` if your host name differs. `-Fresh` wipes local data.

## Daily loop
1. Start Laragon (Apache)
2. Run `composer run dev:laragon`
3. Open `http://myapes-account.test` and sign in with a seeded QA account (see the README; never use real accounts or data)

## Before a pull request
- `composer test` (or `php artisan test`)
- `composer pre-merge`

Never commit `.env` or any key, token or password.
