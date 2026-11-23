#!/usr/bin/env bash
# Prepare SQLite app + ThemeE2eSeeder for Playwright theme snapshots (#908).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f .env ]]; then
  cp .env.local.example .env
fi

SQLITE_PATH="$ROOT_DIR/database/database.sqlite"
mkdir -p "$(dirname "$SQLITE_PATH")"
touch "$SQLITE_PATH"

LOCAL_DB_PATH="$SQLITE_PATH" php -r '
$path = ".env";
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Unable to read .env\n");
    exit(1);
}

$values = [
    "APP_ENV" => "local",
    "APP_DEBUG" => "true",
    "APP_URL" => "http://127.0.0.1:8000",
    "DB_CONNECTION" => "sqlite",
    "DB_DATABASE" => str_replace("\\", "/", getenv("LOCAL_DB_PATH")),
    "CACHE_STORE" => "file",
    "SESSION_DRIVER" => "file",
    "SESSION_SECURE_COOKIE" => "false",
    "QUEUE_CONNECTION" => "sync",
    "MAIL_MAILER" => "log",
];

foreach ($values as $key => $value) {
    $pattern = "/^".preg_quote($key, "/")."=.*$/m";
    $replacement = $key."=".$value;
    $updated = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($updated === null) {
        fwrite(STDERR, "Unable to update ".$key." in .env\n");
        exit(1);
    }
    $content = $count === 0
        ? rtrim($updated).PHP_EOL.$replacement.PHP_EOL
        : $updated;
}

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write .env\n");
    exit(1);
}
'

if grep -qE '^APP_KEY=[[:space:]]*$' .env; then
  php artisan key:generate --force
fi

php artisan migrate:fresh --force --no-interaction
php artisan db:seed --class=Database\\Seeders\\ThemeE2eSeeder --force --no-interaction

echo "Theme E2E database ready (staff: theme-e2e@example.test)."