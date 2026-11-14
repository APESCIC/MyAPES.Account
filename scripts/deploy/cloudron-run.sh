#!/usr/bin/env bash
set -euo pipefail

CURRENT_DIR="/app/data/current"
PHP_BIN="/usr/bin/php8.4"
LOG_DIR="/app/data/shared/storage/logs"
export APP_ENV=production

# Developer sign-in must never be on in production (QA_LOGIN_ENABLED stays unset on Cloudron).
if [[ "${QA_LOGIN_ENABLED:-}" == "true" || "${QA_LOGIN_ENABLED:-}" == "1" ]]; then
  echo "Refusing to run with QA_LOGIN_ENABLED set while APP_ENV=production."
  exit 1
fi

run_artisan() {
  sudo -E -u www-data env APP_ENV=production \
    "$PHP_BIN" "${CURRENT_DIR}/artisan" "$@"
}

assert_release_runtime_path_boundaries() {
  local release_root="${1:-}"
  local cache_required="${2:-false}"
  local path=""
  local canonical_path=""
  local -a required_directories=(
    "$release_root"
    "${release_root}/public"
    "${release_root}/bootstrap"
  )

  for path in "${required_directories[@]}"; do
    if [[ -z "$release_root" || ! -d "$path" || -L "$path" ]]; then
      echo "Unsafe release runtime path: $path"
      return 1
    fi
    canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
    if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
      echo "Unsafe release runtime path: $path"
      return 1
    fi
  done

  path="${release_root}/bootstrap/cache"
  if [[ -L "$path" ]]; then
    echo "Unsafe release runtime path: $path"
    return 1
  fi
  if [[ -e "$path" ]]; then
    if [[ ! -d "$path" ]]; then
      echo "Unsafe release runtime path: $path"
      return 1
    fi
    canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
  else
    if [[ "$cache_required" == true ]]; then
      echo "Unsafe release runtime path: $path"
      return 1
    fi
    canonical_path="$(readlink -m -- "$path" 2>/dev/null || true)"
  fi
  if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
    echo "Unsafe release runtime path: $path"
    return 1
  fi
}

assert_ordinary_canonical_runtime_directory() {
  local path="${1:-}"
  local description="${2:-runtime directory}"
  local canonical_path=""

  if [[ -z "$path" || ! -d "$path" || -L "$path" ]]; then
    echo "Unsafe canonical runtime path for ${description}: ${path}"
    return 1
  fi
  canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
  if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
    echo "Unsafe canonical runtime path for ${description}: ${path}"
    return 1
  fi
}

assert_ordinary_canonical_runtime_file() {
  local path="${1:-}"
  local description="${2:-runtime file}"
  local canonical_path=""

  if [[ -z "$path" || ! -f "$path" || -L "$path" ]]; then
    echo "Unsafe canonical runtime path for ${description}: ${path}"
    return 1
  fi
  canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
  if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
    echo "Unsafe canonical runtime path for ${description}: ${path}"
    return 1
  fi
}

assert_runtime_control_paths() {
  local data_root="${1:-/app/data}"

  assert_ordinary_canonical_runtime_directory \
    "${data_root}/apache" "Apache runtime parent"
  assert_ordinary_canonical_runtime_file \
    "${data_root}/apache/app.conf" "Apache runtime configuration"
  assert_ordinary_canonical_runtime_file \
    "${data_root}/run.sh" "Cloudron runtime launcher"
}

assert_laravel_log_file() {
  local log_path="${1:-}"
  local canonical_path=""

  if [[ -z "$log_path" || -L "$log_path" \
    || ( -e "$log_path" && ! -f "$log_path" ) ]]; then
    echo "Unsafe Laravel log path: $log_path" >&2
    return 1
  fi
  if [[ ! -e "$log_path" ]]; then
    : >>"$log_path"
  fi
  canonical_path="$(readlink -f -- "$log_path" 2>/dev/null || true)"
  if [[ -z "$canonical_path" || "$canonical_path" != "$log_path" ]]; then
    echo "Unsafe Laravel log path: $log_path" >&2
    return 1
  fi
}

prepare_laravel_logs() {
  local log_dir="${1:-}"
  local canonical_path=""
  local log_name=""

  if [[ -z "$log_dir" || -L "$log_dir" \
    || ( -e "$log_dir" && ! -d "$log_dir" ) ]]; then
    echo "Unsafe Laravel log path: $log_dir" >&2
    return 1
  fi
  if [[ -e "$log_dir" ]]; then
    canonical_path="$(readlink -f -- "$log_dir" 2>/dev/null || true)"
  else
    canonical_path="$(readlink -m -- "$log_dir" 2>/dev/null || true)"
  fi
  if [[ -z "$canonical_path" || "$canonical_path" != "$log_dir" ]]; then
    echo "Unsafe Laravel log path: $log_dir" >&2
    return 1
  fi
  if [[ ! -e "$log_dir" ]]; then
    mkdir -- "$log_dir"
  fi
  chmod 0775 "$log_dir"

  for log_name in queue-worker.log scheduler.log; do
    assert_laravel_log_file "${log_dir}/${log_name}"
  done
}

start_laravel_worker() {
  local log_path="${1:-}"
  shift

  sudo -E -u www-data env APP_ENV=production \
    bash -c "$(declare -f assert_laravel_log_file); \
      log_path=\"\$1\"; shift; \
      assert_laravel_log_file \"\$log_path\"; \
      exec \"\$@\" >>\"\$log_path\" 2>&1" \
    myapes-laravel-worker "$log_path" "$@" &
}

if [[ ! -f "${CURRENT_DIR}/artisan" ]]; then
  echo "MyAPES release is not active yet; skipping Laravel workers."
  exit 0
fi

CURRENT_TARGET="$(readlink -f "$CURRENT_DIR" 2>/dev/null || true)"
if [[ ! "$CURRENT_TARGET" =~ ^/app/data/releases/[0-9a-f]{40}$ \
  || ! -d "$CURRENT_TARGET" ]]; then
  echo "Current release does not resolve to an immutable release directory."
  exit 1
fi

assert_runtime_ownership() {
  local protected_path=""
  local unexpected_owner=""

  assert_runtime_control_paths
  assert_release_runtime_path_boundaries "$CURRENT_TARGET" true

  unexpected_owner="$(find /app/data -xdev \
    -path /app/data/shared/storage -prune -o \
    -path '/app/data/releases/*/bootstrap/cache' -prune -o \
    ! -user root -print -quit)"
  if [[ -n "$unexpected_owner" ]]; then
    echo "Application runtime ownership is not root-controlled."
    return 1
  fi

  for protected_path in \
    /app/data \
    /app/data/releases \
    "$CURRENT_DIR" \
    "${CURRENT_DIR}/public" \
    /app/data/run.sh \
    /app/data/apache \
    /app/data/shared \
    /app/data/apache/app.conf; do
    if [[ ! -e "$protected_path" && ! -L "$protected_path" ]]; then
      echo "Protected runtime path is missing: $protected_path"
      return 1
    fi
    if [[ "$(stat -Lc '%U:%G' "$protected_path")" != "root:root" ]] \
      || sudo -u www-data test -w "$protected_path"; then
      echo "Application runtime ownership is not root-controlled."
      return 1
    fi
  done

  if [[ "$(stat -c '%U:%G' "$CURRENT_DIR")" != "root:root" \
    || ! -f /app/data/shared/.env \
    || -L /app/data/shared/.env \
    || "$(stat -Lc '%U:%G' /app/data/shared/.env)" != "root:www-data" ]] \
    || sudo -u www-data test -w /app/data/shared/.env; then
    echo "Application runtime ownership is not root-controlled."
    return 1
  fi

  if ! sudo -u www-data test -w "${CURRENT_DIR}/bootstrap/cache" \
    || ! sudo -u www-data test -w /app/data/shared/storage; then
    echo "Required Laravel runtime paths are not writable by the application user."
    return 1
  fi
}

repair_release_symlinks() {
  local release_root=""
  local link_path=""
  local current_target=""
  local previous_target=""

  # BusyBox/Cloudron chown -R rewrites symlink owners to www-data. chown -h
  # cannot repair those inodes here, so recreate the expected root-owned links.
  current_target="$(readlink -f /app/data/current 2>/dev/null || true)"
  previous_target="$(readlink -f /app/data/previous 2>/dev/null || true)"
  if [[ "$current_target" =~ ^/app/data/releases/[0-9a-f]{40}$ ]]; then
    rm -f /app/data/current
    ln -s "$current_target" /app/data/current
  fi
  if [[ "$previous_target" =~ ^/app/data/releases/[0-9a-f]{40}$ ]]; then
    rm -f /app/data/previous
    ln -s "$previous_target" /app/data/previous
  fi

  for release_root in /app/data/releases/[0-9a-f]*; do
    [[ -d "$release_root" ]] || continue
    if [[ -L "${release_root}/storage" || ! -e "${release_root}/storage" ]]; then
      rm -f "${release_root}/storage"
      ln -s /app/data/shared/storage "${release_root}/storage"
    fi
    if [[ -L "${release_root}/.env" || ! -e "${release_root}/.env" ]]; then
      rm -f "${release_root}/.env"
      ln -s /app/data/shared/.env "${release_root}/.env"
    fi
    link_path="${release_root}/public/storage/avatars"
    if [[ -L "$link_path" || ! -e "$link_path" ]]; then
      rm -f "$link_path"
      ln -s /app/data/shared/storage/app/public/avatars "$link_path"
    fi
  done
}

restore_runtime_ownership() {
  local release_root=""
  local previous_target=""
  local -a release_roots=("$CURRENT_TARGET")

  repair_release_symlinks
  CURRENT_TARGET="$(readlink -f "$CURRENT_DIR" 2>/dev/null || true)"
  release_roots=("$CURRENT_TARGET")

  assert_runtime_control_paths
  assert_release_runtime_path_boundaries "$CURRENT_TARGET" true

  find /app/data -xdev \
    -path /app/data/shared/storage -prune -o \
    -path '/app/data/releases/*/bootstrap/cache' -prune -o \
    -exec chown -h root:root {} +
  chown -hR www-data:www-data /app/data/shared/storage
  chown root:www-data /app/data/shared/.env
  chmod 0640 /app/data/shared/.env
  chmod 0555 /app/data /app/data/releases /app/data/shared /app/data/apache
  chmod 0555 /app/data/run.sh
  chmod 0444 /app/data/apache/app.conf

  if [[ -L /app/data/previous ]]; then
    previous_target="$(readlink -f /app/data/previous)"
    [[ "$previous_target" =~ ^/app/data/releases/[0-9a-f]{40}$ ]]
    [[ "$previous_target" != "$CURRENT_TARGET" ]]
    release_roots+=("$previous_target")
  fi

  for release_root in "${release_roots[@]}"; do
    [[ "$release_root" =~ ^/app/data/releases/[0-9a-f]{40}$ ]]
    assert_release_runtime_path_boundaries "$release_root" false
    chmod -R a-w "$release_root"
    install -d -o www-data -g www-data -m 0770 "$release_root/bootstrap/cache"
    assert_release_runtime_path_boundaries "$release_root" true
    chown -hR www-data:www-data "$release_root/bootstrap/cache"
    chmod -R u+rwX,g+rwX,o-rwx "$release_root/bootstrap/cache"
    chmod 0555 "$release_root" "$release_root/public"
  done

  assert_runtime_ownership
}

start_laravel_runtime() {
  sudo -E -u www-data env APP_ENV=production \
    bash -c "$(declare -f assert_laravel_log_file); \
      $(declare -f prepare_laravel_logs); \
      prepare_laravel_logs \"\$1\"" \
    myapes-log-preparation "$LOG_DIR"

  run_artisan config:clear
  run_artisan config:cache
  run_artisan route:cache
  run_artisan view:cache

  if ! run_artisan env --no-ansi | grep -Eq 'production'; then
    echo "Effective Laravel environment verification failed."
    return 1
  fi

  if ! pgrep -f "${CURRENT_DIR}/artisan queue:work" >/dev/null 2>&1; then
    start_laravel_worker "${LOG_DIR}/queue-worker.log" \
      "$PHP_BIN" "${CURRENT_DIR}/artisan" queue:work \
      --sleep=3 \
      --tries=3 \
      --timeout=60
  fi

  if ! pgrep -f "${CURRENT_DIR}/artisan schedule:work" >/dev/null 2>&1; then
    start_laravel_worker "${LOG_DIR}/scheduler.log" \
      "$PHP_BIN" "${CURRENT_DIR}/artisan" schedule:work
  fi
}

if ! assert_runtime_ownership; then
  echo "Ownership assert failed at boot; repairing release symlinks and restoring ownership."
  repair_release_symlinks
  CURRENT_TARGET="$(readlink -f "$CURRENT_DIR" 2>/dev/null || true)"
  restore_runtime_ownership
fi
assert_runtime_ownership
MYAPES_OWNERSHIP_RESTORED=false

chown() {
  local touches_app_data=false
  local argument=""

  for argument in "$@"; do
    if [[ "$argument" == "/app/data" ]]; then
      touches_app_data=true
      break
    fi
  done

  command chown "$@"
  if [[ "$touches_app_data" == false ]]; then
    return
  fi

  if [[ "$#" -ne 6 || "$1" != "-R" || "$2" != "www-data:www-data" \
    || "$3" != "/app/data" || "$4" != "/run/apache2" \
    || "$5" != "/run/app" || "$6" != "/tmp" ]]; then
    echo "Unsupported Cloudron ownership-normalization contract."
    return 1
  fi

  unset -f chown
  restore_runtime_ownership
  start_laravel_runtime
  MYAPES_OWNERSHIP_RESTORED=true
}

exec() {
  if [[ "$MYAPES_OWNERSHIP_RESTORED" != true ]]; then
    echo "Refusing process replacement before root ownership restoration."
    return 1
  fi

  unset -f exec
  builtin exec "$@"
}
