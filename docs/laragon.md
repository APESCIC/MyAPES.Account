# Local development with Laragon

Laragon 8 on Windows is the **only supported local development setup** for MyAPES Account. The repository runs as its own Laragon project, with its own hostname, database and Vite port, so it can run alongside other APESCIC projects.

The project's settings (PHP, database, Node, hostname and port) are in [`laragon/profile.md`](laragon/profile.md). The demo data and test logins are in [`laragon/demo-data.md`](laragon/demo-data.md).

## Prerequisites

- Windows 10 or 11 with [Laragon 8 (Full)](https://laragon.app/) installed at the default `C:\laragon`, with **Auto Virtual Hosts** on (Menu > Preferences) and the default `{name}.test` hostname pattern.
- Laragon Full includes everything else: Apache 2.4, PHP 8.4, MySQL 8.4, Node 22, Composer, Git and Mailpit. Do not install separate copies of PHP, MySQL or Node for this project.
- npm 11.10 or newer. Laragon's Node 22 ships an older npm; upgrade it once from a Laragon terminal (Menu > Terminal) with `npm install -g npm@11`.
- Nothing else may use port 80, 443 or 3306 (see [Common fixes](#common-fixes)).

## 1. Clone into the project's own folder

Clone the repository into its own folder under Laragon's document root. The folder name becomes the hostname:

```powershell
cd C:\laragon\www
git clone https://github.com/APESCIC/MyAPES.Account.git myapes-account
cd myapes-account
```

Laragon serves it at `http://myapes-account.test` from the `public/` folder; it detects `public/` automatically. A directory junction from `C:\laragon\www\myapes-account` to an existing clone elsewhere works the same way.

## 2. Apply the Laragon profile

Follow [Applying the profile](laragon/profile.md#applying-the-profile): copy `docs\laragon\profile\myapes-account.ini` into `C:\laragon\usr\profile\`, or set **PHP 8.4**, **MySQL 8.4** and **Node 22** by hand from Laragon's menu.

## 3. Start Laragon

Click **Start All**. Apache and MySQL start, and Laragon writes `C:\laragon\etc\apache2\sites-enabled\auto.myapes-account.test.conf` and the hosts entry for `myapes-account.test`. If the site is missing, use **Menu > Apache > Reload**.

## 4. Run the setup script

From the project folder (a Laragon terminal or PowerShell):

```powershell
composer setup
# or: powershell -ExecutionPolicy Bypass -File .\scripts\laragon-setup.ps1
```

[`scripts/laragon-setup.ps1`](../scripts/laragon-setup.ps1):

1. checks that Laragon's PHP, Composer, Node, npm and MySQL match the profile, and warns clearly if Laragon's Apache is set to the wrong PHP version
2. copies `.env.example` to `.env` if `.env` is missing (it never overwrites an existing `.env`) and generates `APP_KEY`
3. runs `composer install` and `npm ci`
4. creates the `myapes_account_local` database if it does not exist, then runs the migrations
5. asks whether to load the demo data (`-Demo` loads it without asking, `-NoDemo` skips it)
6. builds the frontend and checks `http://myapes-account.test/healthz`

It is safe to run again at any time. It only touches this folder and the `myapes_account_local` database, and refuses to continue if `APP_ENV` is not `local` or the database name does not end in `_local`.

## 5. Load or reset the demo data

```powershell
composer demo:seed    # add or refresh the demo data
composer demo:reset   # wipe myapes_account_local, migrate and reload the demo data
```

Both commands give the same data every time. They refuse to run unless `APP_ENV=local`. See [`laragon/demo-data.md`](laragon/demo-data.md) for what is included and the test logins.

## 6. Live preview

```powershell
composer run dev
```

This starts the Vite dev server on port **5175**, the queue listener and a log tail. Laragon's Apache serves the pages, so keep Laragon running. Open `http://myapes-account.test`: saving a Blade template, CSS or JavaScript file, or a route or app file, reloads the browser or swaps the change in without a rebuild. Stop with `Ctrl+C`.

`npm run build` writes the production assets to `public/build`; the site uses them whenever the dev server is not running.

## Tests

```powershell
composer test          # PHPUnit on in-memory SQLite; never touches myapes_account_local
npm run test:frontend  # Node tests
composer pre-merge     # release metadata, build and tests, as CI runs them
composer pre-pr-verify # pre-merge, then the Laragon setup; -Fresh also runs demo:reset
```

## Several APESCIC projects side by side

Every APESCIC repository is its own Laragon project:

- its own folder under `C:\laragon\www` and so its own `.test` hostname
- its own database (this one is `myapes_account_local`)
- its own Vite port (this one is 5175, set with `VITE_PORT` in `.env`)

Apache (80 and 443) and MySQL (3306) are shared, and all projects run on the PHP version Laragon has selected. Start each project's `composer run dev` in its own terminal.

## Common fixes

- **Wrong PHP version:** the setup script prints a red warning. Use **Menu > PHP > Version > php-8.4.x**, then **Menu > Apache > Reload**.
- **Port 80 or 443 in use:** another web server is running (IIS, Skype or another local server). Stop it, or find it with `netstat -ano | findstr :80`, then click **Start All** again.
- **Port 3306 in use:** another MySQL is running. Stop it, then start Laragon's MySQL.
- **Port 5175 in use:** Vite stops with "Port 5175 is already in use" instead of picking another port. Close the other `composer run dev`, or set a different free `VITE_PORT` in `.env` (and tell the team, so the port stays unique).
- **`myapes-account.test` does not resolve:** use **Menu > Apache > Reload** so Laragon rewrites the hosts file. If it still fails, make sure Laragon can edit `C:\Windows\System32\drivers\etc\hosts` (run Laragon as administrator once).
- **Unstyled pages:** run `composer run dev`, or `npm run build`.
- **File permission errors** under `storage` or `bootstrap/cache`: close anything that locks the files (an editor search, antivirus or a running `composer run dev`), then run `php artisan optimize:clear`.
- **Passkeys:** browsers need a secure origin. Turn on Laragon SSL (**Menu > Apache > SSL > Enabled**), browse `https://myapes-account.test`, and set `APP_URL` to that origin and `SESSION_SECURE_COOKIE=true` in `.env`.
- **Mail:** every email goes to Laragon's Mailpit at `http://127.0.0.1:8025`; nothing is delivered.

## Cursor cloud agents

Cursor cloud and Linux agents have no Laragon. They use an agent-only path (`bash scripts/local/bootstrap.sh` and `bash scripts/local/dev.sh`, SQLite and `php artisan serve` on `http://127.0.0.1:8000`). It is not a developer setup and is not supported for local development.
