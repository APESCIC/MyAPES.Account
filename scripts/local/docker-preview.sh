#!/usr/bin/env bash
# Start or reuse the MyAPES Account Docker preview. Always checks for existing containers first.
# Usage: bash scripts/local/docker-preview.sh [--build] [--migrate] [--fresh]
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

HOST_NAME="${MYAPES_DOCKER_HOST:-myaccount.myapes.test}"
PORT=8081
VITE_PORT=5175
HEALTH_TIMEOUT_SECONDS="${HEALTH_TIMEOUT_SECONDS:-240}"
SERVICES=(app web node)

build=false
migrate=false
fresh=false
for argument in "$@"; do
  case "$argument" in
    --build) build=true ;;
    --migrate) migrate=true ;;
    --fresh) fresh=true ;;
    *)
      echo "Unknown option: $argument (expected --build, --migrate or --fresh)" >&2
      exit 2
      ;;
  esac
done

if ! docker info >/dev/null 2>&1; then
  echo "Docker is not running. Start Docker, then run this script again." >&2
  exit 1
fi

if ! grep -Eq "^[[:space:]]*127\.0\.0\.1[[:space:]]+(.*[[:space:]])?${HOST_NAME//./\\.}([[:space:]]|$)" /etc/hosts; then
  echo "Warning: /etc/hosts has no entry for $HOST_NAME. Add it with:" >&2
  echo "  echo '127.0.0.1 $HOST_NAME' | sudo tee -a /etc/hosts" >&2
fi

contains() {
  local needle="$1"
  shift
  local item
  for item in "$@"; do
    [[ "$item" == "$needle" ]] && return 0
  done
  return 1
}

mapfile -t existing < <(docker compose ps -a --services)
mapfile -t running < <(docker compose ps --services --status running)

missing=()
stopped=()
for service in "${SERVICES[@]}"; do
  if ! contains "$service" "${existing[@]}"; then
    missing+=("$service")
  elif ! contains "$service" "${running[@]}"; then
    stopped+=("$service")
  fi
done

changed=()
while read -r service expected_hash; do
  [[ -z "$service" ]] && continue
  actual_hash="$(docker compose ps -a --format '{{.Service}} {{.Labels}}' \
    | awk -v s="$service" '$1 == s' \
    | grep -o 'com\.docker\.compose\.config-hash=[0-9a-f]*' | cut -d= -f2 || true)"
  if [[ -n "$actual_hash" && "$actual_hash" != "$expected_hash" ]]; then
    changed+=("$service")
  fi
done < <(docker compose config --hash '*')

if [[ "$build" == true ]]; then
  echo "Rebuilding images; docker compose keeps any container whose image and config are unchanged."
  docker compose up -d --build
  container_state="rebuilt"
elif [[ ${#changed[@]} -gt 0 ]]; then
  echo "compose.yaml changed for: ${changed[*]}. Recreating only those containers."
  docker compose up -d
  container_state="updated"
elif [[ ${#missing[@]} -eq 0 && ${#stopped[@]} -eq 0 ]]; then
  echo "Reusing running containers: ${SERVICES[*]}."
  container_state="reused"
elif [[ ${#missing[@]} -eq 0 ]]; then
  echo "Starting existing stopped containers: ${stopped[*]}."
  docker compose start
  container_state="started"
elif [[ ${#existing[@]} -eq 0 ]]; then
  echo "No MyAPES Account containers found. Creating them."
  docker compose up -d
  container_state="created"
else
  echo "Creating missing containers (${missing[*]}) and keeping the existing ones."
  docker compose up -d
  container_state="completed"
fi

if [[ ! -f .env ]]; then
  cp .env.docker.example .env
  echo "Created .env from .env.docker.example."
fi

app_environment="$(grep -E '^APP_ENV=' .env | head -n 1 | cut -d= -f2- | tr -d "\"'\r" || true)"
if [[ "$app_environment" != "local" && "$app_environment" != "testing" ]]; then
  echo "Refusing to prepare the app because APP_ENV is '$app_environment'. The Docker preview only accepts local or testing." >&2
  exit 1
fi

set_env_value() {
  local key="$1" value="$2"
  if grep -Eq "^${key}=" .env; then
    if [[ "$(grep -E "^${key}=" .env | head -n 1)" != "${key}=${value}" ]]; then
      sed -i.bak "s#^${key}=.*#${key}=${value}#" .env && rm -f .env.bak
      echo "Set ${key} in .env."
    fi
  else
    printf '\n%s=%s\n' "$key" "$value" >> .env
    echo "Added ${key} to .env."
  fi
}

set_env_value APP_URL "http://${HOST_NAME}:${PORT}"
set_env_value OIDC_REDIRECT_URI "http://${HOST_NAME}:${PORT}/staff/auth/callback"
set_env_value DB_CONNECTION sqlite
set_env_value DB_DATABASE /app/database/database.sqlite
set_env_value VITE_DEV_SERVER_URL "http://${HOST_NAME}:${VITE_PORT}"

database_created=false
if [[ ! -f database/database.sqlite ]]; then
  touch database/database.sqlite
  database_created=true
  echo "Created database/database.sqlite."
fi

# vendor/ lives in a container volume; reinstall when it is empty or composer.lock has changed.
lock_hash="$(sha256sum composer.lock | cut -d' ' -f1)"
installed_hash="$(docker compose exec -T app sh -c 'cat vendor/.composer-lock.sha256 2>/dev/null' | tr -d '[:space:]' || true)"
if [[ "$installed_hash" != "$lock_hash" ]]; then
  echo "Installing Composer packages in the app container."
  docker compose exec -T app composer install --no-interaction --prefer-dist
  docker compose exec -T app sh -c "echo $lock_hash > vendor/.composer-lock.sha256"
fi

if grep -Eq '^APP_KEY=[[:space:]]*$' .env; then
  docker compose exec -T app php artisan key:generate --force
fi

if [[ "$fresh" == true ]]; then
  echo "Running destructive local QA reset (migrate:fresh --seed)."
  docker compose exec -T app php artisan migrate:fresh --seed --force
elif [[ "$migrate" == true || "$database_created" == true ]]; then
  docker compose exec -T app php artisan migrate --force
elif docker compose exec -T app php artisan migrate:status --pending 2>/dev/null | grep -qw 'Pending'; then
  echo "Warning: there are pending migrations. Run this script again with --migrate to apply them." >&2
fi

health_url="http://127.0.0.1:${PORT}/healthz"
deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))
until curl -fsS -o /dev/null "$health_url"; do
  if (( SECONDS >= deadline )); then
    echo "The preview did not answer $health_url within $HEALTH_TIMEOUT_SECONDS seconds. Check 'docker compose logs app web'." >&2
    exit 1
  fi
  sleep 2
done

deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))
until [[ -f public/hot ]] || (( SECONDS >= deadline )); do
  sleep 2
done
if [[ ! -f public/hot ]]; then
  echo "Warning: Vite has not started yet (public/hot is missing). Check 'docker compose logs node'." >&2
fi

echo
echo "Containers: $container_state"
echo "Preview: http://${HOST_NAME}:${PORT}"
echo "Vite HMR: http://${HOST_NAME}:${VITE_PORT}"
