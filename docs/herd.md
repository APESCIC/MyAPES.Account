# Local development with Laravel Herd

Laravel Herd (Pro) on Windows is the **only supported local development setup** for MyAPES Account. The repository runs as its own Herd site, with its own hostname, database and Vite port, so it can run alongside other APESCIC projects.

The project's settings (PHP, database, Node, hostname and port) are in [`herd/profile.md`](herd/profile.md). The demo data and test logins are in [`herd/demo-data.md`](herd/demo-data.md).

## Prerequisites

- Windows 10 or 11 with [Laravel Herd for Windows](https://herd.laravel.com/windows) and a Herd Pro licence (for the MySQL service and the mail catcher).
- In Herd: PHP 8.4 installed (`herd php:install 8.4` if it is missing), a **MySQL 8.4** service on port 3306 (Herd > Services), and Node.js turned on with Node 22 (`herd node:install 22`). Herd's own PHP, Composer, MySQL and Node are all you need; do not install separate copies for this project.
- npm 11.10 or newer in Herd's Node 22, which ships an older npm. Upgrade it once from PowerShell:

  ```powershell
  . .\scripts\herd-env.ps1     # Herd's PHP 8.4, Composer, MySQL client and Node 22 first on PATH
  npm install -g npm@11
  ```

- Nothing else may use port 80, 443 or 3306 (see [Common fixes](#common-fixes)).

## 1. Clone and link

Clone the repository anywhere, for example under `Documents\GitHub`, and link it to Herd from the clone:

```powershell
git clone https://github.com/APESCIC/MyAPES.Account.git
cd MyAPES.Account
herd link myapes-account
herd isolate 8.4 --site=myapes-account
```

Herd serves it at `https://myapes-account.test` from the `public/` folder, on PHP 8.4, with Herd's own trusted certificate (`herd secure myapes-account`); `http://` redirects to it. The setup script below does the link, the PHP pin and the certificate for you if they are missing.

## 2. Run the setup script

From the clone, in PowerShell:

```powershell
composer setup
# or: powershell -ExecutionPolicy Bypass -File .\scripts\herd-setup.ps1
```

[`scripts/herd-setup.ps1`](../scripts/herd-setup.ps1):

1. checks Herd is running and that its PHP, extensions, Composer, Node, npm and MySQL match the profile
2. links the site, pins it to PHP 8.4 and secures it for HTTPS if that is not done yet
3. copies `.env.example` to `.env` if `.env` is missing (it never overwrites an existing `.env`), warns about values that differ from the profile, and generates `APP_KEY`
4. runs `composer install` and `npm ci`
5. creates the `myapes_account_local` database if it does not exist, then runs the migrations
6. asks whether to load the demo data (`-Demo` loads it without asking, `-NoDemo` skips it)
7. builds the frontend (`-SkipBuild` skips it) and checks `https://myapes-account.test/healthz`

It is safe to run again at any time. It only touches this site link, this folder and the `myapes_account_local` database, and refuses to continue if `APP_ENV` is not `local` or the database name does not end in `_local`.

The first `composer install` with Herd's PHP can take several minutes while Windows scans the new PHP build and `vendor`; later runs are quick.

## 3. Load or reset the demo data

```powershell
composer demo:seed    # add or refresh the demo data
composer demo:reset   # wipe myapes_account_local, migrate and reload the demo data
```

Both commands give the same data every time. They refuse to run unless `APP_ENV=local`. See [`herd/demo-data.md`](herd/demo-data.md) for what is included and the test logins.

## 4. Live preview

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1                 # start
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1 -StopDevServer  # stop
```

[`scripts/preview.ps1`](../scripts/preview.ps1) is the live preview gate. It loads the latest code, checks Herd serves it on PHP 8.4, then starts the Vite dev server on `https://myapes-account.test:5175` (with the same Herd certificate) with Herd's Node 22, in the background with no window on the taskbar. Vite's output goes to `storage/logs/vite-dev.log` (git-ignored). Running it again reuses the Vite that is already running, so there is never a second copy. `-StopDevServer` stops this project's Vite only and removes `public/hot`, so the pages fall back to the built assets.

Herd's nginx serves the pages, so keep Herd running. Open `https://myapes-account.test`: saving a Blade template, CSS or JavaScript file, or a route or app file, reloads the browser or swaps the change in without a rebuild.

Vite, its live-reload connection and `public/hot` all use the host name from `APP_URL` (`myapes-account.test`), never an IP address. Herd's hosts entry makes the name resolve; nothing else needs setting up.

For the queue listener and a log tail as well, run `composer run dev` in a terminal. It reuses the Vite the gate started; stop it with `Ctrl+C`.

`npm run build` writes the production assets to `public/build`; the site uses them whenever the dev server is not running.

## Command line

Herd's global PHP and Node defaults can differ from this project's (PHP 8.4, Node 22). In a PowerShell session, dot-source [`scripts/herd-env.ps1`](../scripts/herd-env.ps1) to put the right ones first on `PATH`, or use `herd php ...` and `herd composer ...`, which follow the site's PHP pin.

## Tests

```powershell
composer test          # PHPUnit on in-memory SQLite; never touches myapes_account_local
npm run test:frontend  # Node tests
composer pre-merge     # release metadata, build and tests, as CI runs them
composer pre-pr-verify # pre-merge, then the Herd setup; -Fresh also runs demo:reset
```

## Mail

Every email goes to Herd's mail catcher (SMTP `127.0.0.1:2525`); read it in **Herd > Mail**. Nothing is delivered.

## Replies by email

Ticket and case replies by email ([`mail.md`](mail.md#replies-by-email)) can be tried locally in two ways.

### Without a mailbox

Write a reply to a file and feed it in:

```powershell
php artisan inbound-mail:key --enable     # once: writes INBOUND_MAIL_TOKEN_KEY and turns replies on in .env
php artisan tickets:inbound-sample T12 owner@example.org --body="It works now."
php artisan tickets:inbound-ingest storage\app\inbound-samples\reply-T12-<time>.eml
```

You can also save a real reply from Gmail or Outlook as `.eml` and ingest that. Set `INBOUND_MAIL_REQUIRE_AUTH=false` in `.env` if the saved file has no `Authentication-Results` header.

### The whole loop with an optional standalone Mailpit

Herd's mail catcher has no API the app can read replies from, so the full browser loop uses a standalone [Mailpit](https://mailpit.axllent.org/) standing in for the `communications@` mailbox: the app sends ticket emails to Mailpit, you reply as the client into Mailpit, and the normal poller fetches the reply from Mailpit and posts it. Mailpit is optional and not part of the Herd setup; install it yourself (for example `winget install axllent.mailpit`) and run it with its default ports (SMTP 1025, web 8025).

While you test, set these in `.env` and clear the config:

```dotenv
MAIL_PORT=1025
INBOUND_MAIL_DRIVER=mailpit
INBOUND_MAIL_MAILPIT_URL=http://127.0.0.1:8025
```

```powershell
php artisan config:clear
```

Set `MAIL_PORT=2525` and `INBOUND_MAIL_DRIVER=imap` again afterwards, so mail goes back to Herd.

`mailpit` only works with `APP_ENV=local` (or `testing`) and a `127.0.0.1` or `localhost` URL. The Cloudron deploy refuses any driver but `imap` (`myapes:inbound-mail:preflight`), so never set it anywhere else. Leave the `INBOUND_MAIL_IMAP_*` settings empty locally.

1. Sign in with the QA Admin account and, as staff, add a public message to a demo ticket or case. Its emails land in Mailpit (<http://localhost:8025>) with a `communications+t…` Reply-To, a `[MYAPES-T-12]` subject tag and the "Reply above this line" marker.
2. Open **Admin → Emails → Inbound**. The **Local test replies** panel (only on this local install) lists the latest 20 ticket and case emails in Mailpit, each with **Open in Mailpit**.
3. Under **Reply as client**, choose the email, type the reply, and optionally another account to reply from or a variant (no subject tag, no reply headers, changed subject, HTML only, one attachment). Press **Send reply**: the reply goes to Mailpit through the normal mail settings.
4. Press **Fetch replies now**. It runs the poller once and shows how many replies were fetched and stored, with links to each one and to its ticket or case. The reply appears on the ticket marked **by email**; anything not posted stays on the Inbound list with the reason, where **Attach to ticket or case** can post it.

The same from the command line:

```powershell
php artisan tickets:inbound-reply-as --latest=T12 --body="It works now."   # reply to the latest T12 email in Mailpit
php artisan tickets:inbound-reply-as <mailpit-id> --as=other@example.test --variant=no-tag
php artisan tickets:inbound-poll                                           # fetch from Mailpit and post
```

## Several APESCIC projects side by side

Every APESCIC repository is its own Herd site:

- its own link and so its own `.test` hostname
- its own database (this one is `myapes_account_local`)
- its own Vite port (this one is 5175, set with `VITE_PORT` in `.env`)
- its own PHP pin (this one is 8.4)

nginx (80 and 443) and MySQL (3306) are shared. Each project starts and stops its own Vite; this project's `-StopDevServer` never touches another project's dev server.

## Common fixes

- **Wrong PHP version:** the preview gate stops and says so. Pin the site with `herd isolate 8.4 --site=myapes-account`.
- **Port 80 or 443 in use:** another web server is running (IIS or another local stack). Stop it, or find it with `netstat -ano | findstr :80`, then restart Herd.
- **Port 3306 in use:** another MySQL is running. Stop it, then start Herd's MySQL service (Herd > Services).
- **Herd lists its MySQL service as stopped, but the site works:** Herd's status can lag after a restart. If the database misbehaves, restart the service in Herd > Services.
- **Port 5175 in use:** if it is this project's Vite, the preview gate reuses it. If another program holds the port, the gate says which one; close it, or set a different free `VITE_PORT` in `.env` (and tell the team, so the port stays unique).
- **Vite did not start:** the gate shows the end of `storage/logs/vite-dev.log`; the whole file has Vite's output.
- **`myapes-account.test` does not resolve:** run `herd status`, and check `herd links` lists `myapes-account`. Herd's helper service writes the hosts entry; restart Herd if it is missing.
- **`php` or `node` is the wrong version:** dot-source `scripts\herd-env.ps1`, or use `herd php` and `herd composer`.
- **Unstyled pages:** start Vite with `scripts\preview.ps1`, or run `npm run build`. A `public/hot` file left by a Vite that was closed some other way also unstyles the pages; `scripts\preview.ps1 -StopDevServer` removes it. If `public/hot` starts with `http://` while the site is on HTTPS, the browser blocks the styles as mixed content: check `APP_URL` is `https://myapes-account.test` and the site is secured, then restart Vite (`-StopDevServer`, then `scripts\preview.ps1`).
- **File permission errors** under `storage` or `bootstrap/cache`: close anything that locks the files (an editor search, antivirus or a running dev server; `scripts\preview.ps1 -StopDevServer` stops Vite), then run `php artisan optimize:clear`.
- **Passkeys:** browsers need a secure origin, which the HTTPS site already is (`APP_URL=https://myapes-account.test`, `SESSION_SECURE_COOKIE=true`).

## Cursor cloud agents

Cursor cloud and Linux agents have no Herd. They use an agent-only path (`bash scripts/local/bootstrap.sh` and `bash scripts/local/dev.sh`, SQLite and `php artisan serve` on `http://127.0.0.1:8000`). It is not a developer setup and is not supported for local development.
