> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Local setup (Laragon)

**Audience:** developers  
**Purpose:** run MyAPES Account locally at `http://myapes-account.test`.

Laragon 8 on Windows is the only supported local setup. The full, maintained instructions are in [docs/laragon.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/laragon.md); this page is the short version.

## You need
- Laragon 8 (Full) at `C:\laragon` with Auto Virtual Hosts on
- The project's Laragon profile: PHP 8.4, MySQL 8.4, Node 22 with npm 11.10 or newer ([profile](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/laragon/profile.md))
- Ports 80, 443 and 3306 free for Laragon, and 5175 for this project's Vite server

## First run
```powershell
cd C:\laragon\www
git clone https://github.com/APESCIC/MyAPES.Account.git myapes-account
cd myapes-account
composer setup
```
Start Laragon (**Start All**) first. `composer setup` runs `scripts/laragon-setup.ps1`: it checks the versions, creates `.env` from `.env.example`, installs dependencies, creates the `myapes_account_local` database, migrates and offers to load the demo data. It is safe to run again.

## Daily loop
1. Start Laragon
2. Run `scripts\preview.ps1` for hot reload: Vite runs at `http://myapes-account.test:5175` with no window, its output in `storage/logs/vite-dev.log`; `scripts\preview.ps1 -StopDevServer` stops it
3. Open `http://myapes-account.test` and sign in with a demo login ([demo data](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/laragon/demo-data.md); never use real accounts or data)

## Demo data
- `composer demo:seed` loads it; `composer demo:reset` wipes the local database and reloads it
- Both refuse to run unless `APP_ENV=local`

## Before a pull request
- `composer test` (or `php artisan test`)
- `composer pre-merge`

Never commit `.env` or any key, token or password.
