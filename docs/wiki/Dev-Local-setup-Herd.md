> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Local setup (Herd)

**Audience:** developers  
**Purpose:** run MyAPES Account locally at `https://myapes-account.test`.

Laravel Herd (Pro) on Windows is the only supported local setup. The full, maintained instructions are in [docs/herd.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd.md); this page is the short version.

## You need
- Laravel Herd for Windows with a Pro licence (MySQL service and mail catcher)
- The project's Herd profile: PHP 8.4, a MySQL 8.4 service on port 3306, Node 22 with npm 11.10 or newer ([profile](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd/profile.md))
- Ports 80, 443 and 3306 free for Herd, and 5175 for this project's Vite server

## First run
```powershell
git clone https://github.com/APESCIC/MyAPES.Account.git
cd MyAPES.Account
composer setup
```
Open Herd first. `composer setup` runs `scripts/herd-setup.ps1`: it checks the versions, links the site as `myapes-account.test` on PHP 8.4, creates `.env` from `.env.example`, installs dependencies, creates the `myapes_account_local` database, migrates and offers to load the demo data. It is safe to run again.

## Daily loop
1. Open Herd
2. Run `scripts\preview.ps1` for hot reload: Vite runs at `https://myapes-account.test:5175` with no window, its output in `storage/logs/vite-dev.log`; `scripts\preview.ps1 -StopDevServer` stops it
3. Open `https://myapes-account.test` and sign in with a demo login ([demo data](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd/demo-data.md); never use real accounts or data)
4. Mail the app sends lands in **Herd > Mail**; nothing is delivered. For replies by email, set `INBOUND_MAIL_DRIVER=herd` and `MAIL_USERNAME=myapes-account`, then use **Admin → Emails → Inbound** local test replies and `php artisan tickets:inbound-loop-check` (see [docs/herd.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd.md#replies-by-email))

## Demo data
- `composer demo:seed` loads it; `composer demo:reset` wipes the local database and reloads it
- Both refuse to run unless `APP_ENV=local`

## Before a pull request
- `composer test` (or `php artisan test`)
- `composer pre-merge`

Never commit `.env` or any key, token or password.
