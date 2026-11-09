# Laragon profile: MyAPES Account

Laragon 8 on Windows is the only supported local development setup for MyAPES Account. These are the values this project expects. `.env.example` and `scripts/laragon-setup.ps1` use the same values; if you change one, change all three.

| Setting | Value |
| --- | --- |
| Laragon project folder | `C:\laragon\www\myapes-account` |
| Hostname | `http://myapes-account.test` (optional `https://myapes-account.test` with Laragon SSL) |
| Document root | `public/` (Laragon Auto Virtual Hosts picks it up automatically) |
| Web server | Apache 2.4 (`httpd-2.4.68-260617-Win64-VS18`) on ports 80 and 443 |
| PHP | 8.4, NTS build (`php-8.4.27-1-nts-Win32-vs17-x64`), run by Apache through FastCGI (mod_fcgid) with separate PHP processes per site, and the same build on the command line; production and CI also run 8.4 |
| PHP settings | Upload limits in `public/.user.ini` (read under FastCGI) and in the `<IfModule php_module>` block of `public/.htaccess` (read on Cloudron) |
| PHP extensions | `bcmath`, `curl`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sodium`, `zip` (all on by default in Laragon's PHP 8.4) |
| Database engine | MySQL 8.4 (`mysql-8.4.3-winx64`) on `127.0.0.1:3306` |
| Database name | `myapes_account_local` (`utf8mb4`, `utf8mb4_unicode_ci`) |
| Database user | `root` with an empty password (Laragon default, local only) |
| Node | 22 (`node-v22`) with npm 11.10 or newer |
| Vite dev server port | `5175` (unique to this project) |
| Mail | Laragon Mailpit: SMTP `127.0.0.1:1025`, inbox `http://127.0.0.1:8025` |
| Tests | In-memory SQLite (`phpunit.xml`); they never touch `myapes_account_local` |

## Applying the profile

Laragon 8 keeps service versions in profile files under `C:\laragon\usr\profile\`. The file [`profile/myapes-account.ini`](profile/myapes-account.ini) lists the versions above in the same format as Laragon's own `default.ini`.

1. Copy `docs\laragon\profile\myapes-account.ini` to `C:\laragon\usr\profile\myapes-account.ini`.
2. Pick the profile in Laragon, then **Stop** and **Start All**.

If your Laragon build does not list the profile, set the versions by hand: **Menu > PHP > Version > php-8.4.x**, **Menu > MySQL > Version > mysql-8.4.x** and **Menu > Node.js > Version > node-v22**. Add a missing version with **Menu > Tools > Quick add**.

Either way, `scripts/laragon-setup.ps1` checks the versions Laragon actually has in use and warns if they do not match.

## Ports used by APESCIC projects

Laragon's Apache (80 and 443) and MySQL (3306) are shared by every project. Each project has its own hostname, database and Vite port, so they can run side by side.

| Project | Hostname | Database | Vite port |
| --- | --- | --- | --- |
| MyAPES Account | `myapes-account.test` | `myapes_account_local` | 5175 |

Other APESCIC repositories keep their own values in their own profile docs. Pick an unused Vite port when you add a project, and never reuse `myapes_account_local`.

## Notes

- Laragon profiles are global: every project runs on the PHP version Laragon has selected. With an NTS build selected, Laragon runs PHP through FastCGI (`C:\laragon\etc\apache2\fcgid.conf`), which starts separate `php-cgi.exe` processes per site, so another project's `.env` values cannot leak into this one (they could under the thread-safe build's mod_php). The setup script checks Apache uses FastCGI and the same build as the command line.
- If Auto Virtual Hosts is turned off, copy [`myapes-account.test.conf`](myapes-account.test.conf) to `C:\laragon\etc\apache2\sites-enabled\` (without the `auto.` prefix, so Laragon leaves it as written) and reload Apache.
