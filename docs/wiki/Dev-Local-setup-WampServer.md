> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Local setup (WampServer)

**Audience:** developers  
**Purpose:** run MyAPES Account locally at `http://myapes-account.test`.

The full, maintained instructions are in the [README: Local environment setup](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#local-environment-setup). This page is the short version.

## You need
- WampServer 3.4 (64-bit) with Apache's PHP set to 8.4 (8.3 minimum; CI uses 8.4) and the usual Laravel extensions (`pdo_sqlite`, `openssl`, `mbstring`, `curl`, `fileinfo`)
- `php`, `composer`, `node` and `npm` on your PATH
- No MySQL or Redis: local development uses SQLite and file-backed cache and sessions
- Laragon stopped, because it also uses port 80

## Virtual host
- Add a `<VirtualHost *:80>` block for `myapes-account.test` to WampServer's `httpd-vhosts.conf`, with the document root set to the repository's `public/` folder (see the README for the block)
- Add `127.0.0.1 myapes-account.test` to the Windows hosts file (needs admin)
- Restart WampServer's Apache after vhost changes

## First run
```powershell
cd C:\Users\<you>\Documents\GitHub\MyAPES.Account
powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Wamp -Fresh
```
This creates `.env` from `.env.wamp.example`, generates the app key, migrates, seeds test data and builds the frontend. Use `-AppUrl http://your-host.test` if your host name differs. `-Fresh` wipes local data.

## Daily loop
1. Make sure WampServer's services are running (green tray icon)
2. Run `composer run dev:wamp`
3. Open `http://myapes-account.test` and sign in with a seeded QA account (see the README; never use real accounts or data)

## Before a pull request
- `composer test` (or `php artisan test`)
- `composer pre-merge`

Never commit `.env` or any key, token or password.
