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
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1                 # start
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1 -StopDevServer  # stop
```

[`scripts/preview.ps1`](../scripts/preview.ps1) is the live preview gate. It loads the latest code, checks Laragon serves it, then starts the Vite dev server on `http://myapes-account.test:5175` in the background, with no window on the taskbar. Vite's output goes to `storage/logs/vite-dev.log` (git-ignored). Running it again reuses the Vite that is already running, so there is never a second copy. `-StopDevServer` stops this project's Vite only and removes `public/hot`, so the pages fall back to the built assets.

Laragon's Apache serves the pages, so keep Laragon running. Open `http://myapes-account.test`: saving a Blade template, CSS or JavaScript file, or a route or app file, reloads the browser or swaps the change in without a rebuild.

Vite, its live-reload connection and `public/hot` all use the host name from `APP_URL` (`myapes-account.test`), never an IP address. Laragon's hosts entry makes the name resolve; nothing else needs setting up.

For the queue listener and a log tail as well, run `composer run dev` in a terminal. It reuses the Vite the gate started; stop it with `Ctrl+C`.

`npm run build` writes the production assets to `public/build`; the site uses them whenever the dev server is not running.

## Tests

```powershell
composer test          # PHPUnit on in-memory SQLite; never touches myapes_account_local
npm run test:frontend  # Node tests
composer pre-merge     # release metadata, build and tests, as CI runs them
composer pre-pr-verify # pre-merge, then the Laragon setup; -Fresh also runs demo:reset
```

## Replies by email

Ticket and case replies by email ([`mail.md`](mail.md#replies-by-email)) work locally with Laragon's Mailpit standing in for the `communications@` mailbox: the app sends ticket emails to Mailpit, you reply as the client into Mailpit, and the normal poller fetches the reply from Mailpit and posts it.

### Set up once

```powershell
php artisan inbound-mail:key --enable     # writes INBOUND_MAIL_TOKEN_KEY and turns replies on in .env
```

Then add these two lines to `.env` and clear the config:

```dotenv
INBOUND_MAIL_DRIVER=mailpit
INBOUND_MAIL_MAILPIT_URL=http://127.0.0.1:8025
```

```powershell
php artisan config:clear
```

`mailpit` only works with `APP_ENV=local` (or `testing`) and a `127.0.0.1` or `localhost` URL. The Cloudron deploy refuses any driver but `imap` (`myapes:inbound-mail:preflight`), so never set it anywhere else. Leave the `INBOUND_MAIL_IMAP_*` settings empty locally.

### The reply loop in the browser

1. Sign in with the QA Admin account and, as staff, add a public message to a demo ticket or case. Its emails land in Mailpit (<http://localhost:8025>) with a `communications+t…` Reply-To, a `[MYAPES-T-12]` subject tag and the "Reply above this line" marker.
2. Open **Admin → Emails → Inbound**. The **Local test replies** panel (only on this local install) lists the latest 20 ticket and case emails in Mailpit, each with **Open in Mailpit**.
3. Under **Reply as client**, choose the email, type the reply, and optionally another account to reply from or a variant (no subject tag, no reply headers, changed subject, HTML only, one attachment). Press **Send reply**: the reply goes to Mailpit through the normal mail settings.
4. Press **Fetch replies now**. It runs the poller once and shows how many replies were fetched and stored, with links to each one and to its ticket or case. The reply appears on the ticket marked **by email**; anything not posted stays on the Inbound list with the reason, where **Attach to ticket or case** can post it.

### The same from the command line

```powershell
php artisan tickets:inbound-reply-as --latest=T12 --body="It works now."   # reply to the latest T12 email in Mailpit
php artisan tickets:inbound-reply-as <mailpit-id> --as=other@example.test --variant=no-tag
php artisan tickets:inbound-poll                                           # fetch from Mailpit and post
```

Without the Mailpit driver you can still write a reply to a file and feed it in:

```powershell
php artisan tickets:inbound-sample T12 owner@example.org --body="It works now."
php artisan tickets:inbound-ingest storage\app\inbound-samples\reply-T12-<time>.eml
```

You can also save a real reply from Gmail or Outlook as `.eml` and ingest that. Set `INBOUND_MAIL_REQUIRE_AUTH=false` in `.env` if the saved file has no `Authentication-Results` header.

## Several APESCIC projects side by side

Every APESCIC repository is its own Laragon project:

- its own folder under `C:\laragon\www` and so its own `.test` hostname
- its own database (this one is `myapes_account_local`)
- its own Vite port (this one is 5175, set with `VITE_PORT` in `.env`)

Apache (80 and 443) and MySQL (3306) are shared, and all projects run on the PHP version Laragon has selected. Each project starts and stops its own Vite; this project's `-StopDevServer` never touches another project's dev server.

## Common fixes

- **Wrong PHP version:** the setup script prints a red warning. Use **Menu > PHP > Version > php-8.4.x**, then **Menu > Apache > Reload**.
- **Port 80 or 443 in use:** another web server is running (IIS, Skype or another local server). Stop it, or find it with `netstat -ano | findstr :80`, then click **Start All** again.
- **Port 3306 in use:** another MySQL is running. Stop it, then start Laragon's MySQL.
- **Port 5175 in use:** if it is this project's Vite, the preview gate reuses it. If another program holds the port, the gate says which one; close it, or set a different free `VITE_PORT` in `.env` (and tell the team, so the port stays unique).
- **Vite did not start:** the gate shows the end of `storage/logs/vite-dev.log`; the whole file has Vite's output.
- **`myapes-account.test` does not resolve:** use **Menu > Apache > Reload** so Laragon rewrites the hosts file. If it still fails, make sure Laragon can edit `C:\Windows\System32\drivers\etc\hosts` (run Laragon as administrator once).
- **Unstyled pages:** start Vite with `scripts\preview.ps1`, or run `npm run build`. A `public/hot` file left by a Vite that was closed some other way also unstyles the pages; `scripts\preview.ps1 -StopDevServer` removes it.
- **File permission errors** under `storage` or `bootstrap/cache`: close anything that locks the files (an editor search, antivirus or a running dev server; `scripts\preview.ps1 -StopDevServer` stops Vite), then run `php artisan optimize:clear`.
- **Passkeys:** browsers need a secure origin. Turn on Laragon SSL (**Menu > Apache > SSL > Enabled**), browse `https://myapes-account.test`, and set `APP_URL` to that origin and `SESSION_SECURE_COOKIE=true` in `.env`.
- **Mail:** every email goes to Laragon's Mailpit at `http://localhost:8025`; nothing is delivered.

## Cursor cloud agents

Cursor cloud and Linux agents have no Laragon. They use an agent-only path (`bash scripts/local/bootstrap.sh` and `bash scripts/local/dev.sh`, SQLite and `php artisan serve` on `http://127.0.0.1:8000`). It is not a developer setup and is not supported for local development.
