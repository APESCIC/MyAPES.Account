# Herd profile: MyAPES Account

Laravel Herd (Pro) on Windows is the only supported local development setup for MyAPES Account. These are the values this project expects. [`profile.json`](profile.json) holds them for the scripts (`scripts/herd-setup.ps1`, `scripts/preview.ps1`, `scripts/herd-env.ps1`), and `.env.example` uses the same values; if you change one, change both.

| Setting | Value |
| --- | --- |
| Herd site | `myapes-account`, linked from the clone (`herd link myapes-account`); no copy or junction anywhere else |
| Hostname | `http://myapes-account.test` (optional `https://myapes-account.test` after `herd secure myapes-account`) |
| Document root | `public/` (Herd serves it automatically) |
| Web server | Herd's nginx on ports 80 and 443 |
| PHP | 8.4, pinned to the site with `herd isolate 8.4 --site=myapes-account`. Herd runs it through FastCGI (`php-cgi`, so `/healthz` reports `php_sapi` `cgi-fcgi`), and the command line uses the same build (`%USERPROFILE%\.config\herd\bin\php84`); production and CI also run 8.4 |
| PHP settings | Upload limits in `public/.user.ini` (read by Herd's PHP) and in the `<IfModule php_module>` block of `public/.htaccess` (read on Cloudron) |
| PHP extensions | `bcmath`, `curl`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sodium`, `zip` (all on in Herd's PHP 8.4) |
| Database engine | Herd's MySQL 8.4 service on port 3306 |
| Database name | `myapes_account_local` (`utf8mb4`, `utf8mb4_unicode_ci`) |
| Database user | `root` with an empty password (Herd default, local only) |
| Node | 22 from Herd's nvm (`%USERPROFILE%\.config\herd\bin\nvm\v22.*`) with npm 11.10 or newer. Herd's global Node default can be another version; the scripts pick Node 22 themselves |
| Vite dev server port | `5175` (unique to this project) |
| Vite dev server | `http://myapes-account.test:5175`, started with no window by `scripts\preview.ps1` (stop with `-StopDevServer`), output in `storage/logs/vite-dev.log` |
| Mail | Herd's mail catcher: SMTP on `127.0.0.1:2525`, read in Herd > Mail. Nothing is delivered |
| Tests | In-memory SQLite (`phpunit.xml`); they never touch `myapes_account_local` |

## Ports used by APESCIC projects

Herd's nginx (80 and 443) and MySQL (3306) are shared by every project. Each project has its own hostname, database and Vite port, so they can run side by side.

| Project | Hostname | Database | Vite port |
| --- | --- | --- | --- |
| MyAPES Account | `myapes-account.test` | `myapes_account_local` | 5175 |

Other APESCIC repositories keep their own values in their own profile docs. Pick an unused Vite port when you add a project, and never reuse `myapes_account_local`.

## Command line

Put Herd's PHP 8.4, Composer, MySQL client and Node 22 first on `PATH` in a PowerShell session with:

```powershell
. .\scripts\herd-env.ps1
```

Or use `herd php ...` and `herd composer ...`, which pick the PHP version the site is pinned to.
