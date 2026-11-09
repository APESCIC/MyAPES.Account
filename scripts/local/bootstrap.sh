#!/usr/bin/env bash
# Agent-only path for Cursor cloud and Linux agents without Laragon (SQLite, artisan serve).
# It is not a supported developer setup; developers use scripts/laragon-setup.ps1.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

RUN_SEED=false
RUN_FRESH=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --seed)
      RUN_SEED=true
      shift
      ;;
    --fresh)
      RUN_FRESH=true
      RUN_SEED=true
      shift
      ;;
    *)
      echo "Unknown option: $1"
      echo "Usage: bash scripts/local/bootstrap.sh [--seed] [--fresh]"
      exit 1
      ;;
  esac
done

for tool in php composer npm; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "Missing required tool: $tool"
    exit 1
  fi
done

for required_file in artisan composer.json .env.local.example; do
  if [[ ! -f "$required_file" ]]; then
    echo "Required local bootstrap file is missing: $required_file"
    exit 1
  fi
done

if [[ ! -f .env ]]; then
  cp .env.local.example .env
  echo "Created .env from .env.local.example"
fi

APP_ENV_VALUE="$(sed -n 's/^APP_ENV=//p' .env | head -n 1 | tr -d "\"'[:space:]")"
if [[ "$APP_ENV_VALUE" != "local" && "$APP_ENV_VALUE" != "testing" ]]; then
  echo "Refusing to rewrite .env because APP_ENV is '$APP_ENV_VALUE'."
  echo "Local bootstrap only accepts local or testing environments."
  exit 1
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

composer install --no-interaction --prefer-dist
npm install --no-audit --no-fund

if grep -qE '^APP_KEY=[[:space:]]*$' .env; then
  php artisan key:generate --force
fi

if [[ "$RUN_FRESH" == true ]]; then
  echo "Running destructive local QA reset (migrate:fresh --seed)."
  php artisan migrate:fresh --seed --force
elif [[ "$RUN_SEED" == true ]]; then
  php artisan migrate --force
  php artisan db:seed --force
else
  php artisan migrate --force
fi

source scripts/local/selective-media-boundary.sh
ensure_selective_media_boundary "$(pwd)" true
npm run build

echo "Local bootstrap complete. Run 'composer run dev' to start MyAPES Account."
