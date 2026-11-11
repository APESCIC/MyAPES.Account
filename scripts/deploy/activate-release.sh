#!/usr/bin/env bash
set -euo pipefail

deployment_control_paths=(
  scripts/deploy/activate-release.sh
  scripts/deploy/rollback-release.sh
  scripts/deploy/cloudron-app.conf
  scripts/deploy/cloudron-run.sh
)

verify_deployment_controls() {
  local source_root="${1:-}"
  local expected_manifest_sha256="${2:-}"
  local manifest_path="${source_root}/DEPLOYMENT-CONTROLS.sha256"
  local actual_manifest_sha256=""
  local index=0
  local line=""
  local digest=""
  local manifest_control_path=""
  local expected_path=""
  local -a manifest_lines=()

  if [[ -z "$source_root" || ! "$expected_manifest_sha256" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Deployment control verification requires a source root and exact manifest SHA-256."
    return 1
  fi
  if [[ ! -f "$manifest_path" || -L "$manifest_path" ]]; then
    echo "Deployment control manifest is missing or unsafe."
    return 1
  fi

  actual_manifest_sha256="$(sha256sum "$manifest_path" | awk '{print $1}')"
  if [[ "$actual_manifest_sha256" != "$expected_manifest_sha256" ]]; then
    echo "Deployment control manifest digest does not match the tested artifact."
    return 1
  fi

  mapfile -t manifest_lines <"$manifest_path"
  if [[ "${#manifest_lines[@]}" -ne "${#deployment_control_paths[@]}" ]]; then
    echo "Deployment control manifest has an unexpected entry count."
    return 1
  fi

  for index in "${!deployment_control_paths[@]}"; do
    expected_path="${deployment_control_paths[$index]}"
    line="${manifest_lines[$index]}"
    digest="${line%%  *}"
    manifest_control_path="${line#*  }"
    if [[ ! "$digest" =~ ^[0-9a-f]{64}$ \
      || "$manifest_control_path" != "$expected_path" \
      || "$line" != "$digest  $expected_path" ]]; then
      echo "Deployment control manifest contains an unexpected entry."
      return 1
    fi
    if [[ ! -f "${source_root}/${expected_path}" || -L "${source_root}/${expected_path}" ]]; then
      echo "Deployment control path is missing or unsafe: $expected_path"
      return 1
    fi
  done

  if ! (cd "$source_root" && sha256sum --check --strict --status DEPLOYMENT-CONTROLS.sha256); then
    echo "Deployment control content authentication failed."
    return 1
  fi
}

assert_hardened_control_directory() {
  local source_root="${1:-}"
  local path=""
  local expected_mode=""

  if [[ ! -d "$source_root" || -L "$source_root" \
    || "$(stat -c '%U:%G' "$source_root")" != "root:root" \
    || "$(stat -c '%a' "$source_root")" != "700" ]]; then
    echo "Deployment control directory is not root-owned and private."
    return 1
  fi

  while IFS=':' read -r path expected_mode; do
    if [[ ! -f "${source_root}/${path}" \
      || -L "${source_root}/${path}" \
      || "$(stat -c '%U:%G' "${source_root}/${path}")" != "root:root" \
      || "$(stat -c '%a' "${source_root}/${path}")" != "$expected_mode" ]]; then
      echo "Deployment control path is not root-owned and immutable: $path"
      return 1
    fi
  done <<'CONTROL_MODES'
DEPLOYMENT-CONTROLS.sha256:600
scripts/deploy/activate-release.sh:700
scripts/deploy/rollback-release.sh:700
scripts/deploy/cloudron-app.conf:600
scripts/deploy/cloudron-run.sh:700
CONTROL_MODES

  if sudo -u www-data test -w "$source_root"; then
    echo "Application user can replace authenticated deployment controls."
    return 1
  fi
}

classify_activation_targets() {
  local release_sha="${1:-}"
  local prior_release_sha="${2:-}"
  local previous_available="${3:-}"
  local same_release="${4:-}"
  local current_target="${5:-}"
  local previous_target="${6:-}"
  local releases_dir="${7:-}"
  local target=""

  if [[ ! "$release_sha" =~ ^[0-9a-f]{40}$ \
    || ! "$previous_available" =~ ^(true|false)$ \
    || ! "$same_release" =~ ^(true|false)$ \
    || -z "$releases_dir" ]]; then
    echo "Activation classification inputs are invalid." >&2
    return 1
  fi
  if [[ "$previous_available" == true ]]; then
    if [[ ! "$prior_release_sha" =~ ^[0-9a-f]{40}$ ]]; then
      echo "Captured prior release identity is invalid." >&2
      return 1
    fi
  elif [[ -n "$prior_release_sha" ]]; then
    echo "Unavailable prior release identity must be empty." >&2
    return 1
  fi
  if [[ "$same_release" == true \
    && ("$previous_available" != true || "$prior_release_sha" != "$release_sha") ]]; then
    echo "Same-release classification does not match the captured identity." >&2
    return 1
  fi

  for target in "$current_target" "$previous_target"; do
    [[ -n "$target" ]] || continue
    if [[ ! "$target" =~ ^${releases_dir}/[0-9a-f]{40}$ ]]; then
      echo "Activation classification found an unsafe release target." >&2
      return 1
    fi
  done

  if [[ "$same_release" == true ]]; then
    if [[ "$current_target" != "${releases_dir}/${release_sha}" ]]; then
      echo "Same-release activation no longer points at the captured release." >&2
      return 1
    fi
    echo "same-release"
    return 0
  fi

  if [[ -z "$current_target" ]]; then
    if [[ "$previous_available" == false && -z "$previous_target" ]]; then
      echo "first-deployment"
      return 0
    fi
    echo "Activation left an ambiguous empty current release." >&2
    return 1
  fi

  if [[ "$current_target" == "${releases_dir}/${release_sha}" ]]; then
    if [[ "$previous_available" == false ]]; then
      if [[ -n "$previous_target" ]]; then
        echo "Activation switched without an authenticated prior release." >&2
        return 1
      fi
      echo "unavailable-rollback"
      return 0
    fi
    if [[ "$previous_target" != "${releases_dir}/${prior_release_sha}" ]]; then
      echo "Post-switch previous release does not match the captured identity." >&2
      return 1
    fi
    echo "post-switch"
    return 0
  fi

  if [[ "$previous_available" == true \
    && "$current_target" == "${releases_dir}/${prior_release_sha}" ]]; then
    echo "pre-switch"
    return 0
  fi

  echo "Activation links do not match a safe recovery state." >&2
  return 1
}

classify_activation_state() {
  local release_sha="${1:-}"
  local prior_release_sha="${2:-}"
  local previous_available="${3:-}"
  local same_release="${4:-}"
  local data_dir="${5:-/app/data}"
  local releases_dir="${data_dir}/releases"
  local current_link="${data_dir}/current"
  local previous_link="${data_dir}/previous"
  local current_target=""
  local previous_target=""

  for release_link in "$current_link" "$previous_link"; do
    if [[ -e "$release_link" && ! -L "$release_link" ]]; then
      echo "Activation classification requires authoritative release symlinks." >&2
      return 1
    fi
  done
  if [[ -L "$current_link" ]]; then
    current_target="$(readlink -f "$current_link" 2>/dev/null || true)"
    if [[ -z "$current_target" || ! -d "$current_target" ]]; then
      echo "Current release link is dangling during activation classification." >&2
      return 1
    fi
  fi
  if [[ -L "$previous_link" ]]; then
    previous_target="$(readlink -f "$previous_link" 2>/dev/null || true)"
    if [[ -z "$previous_target" || ! -d "$previous_target" ]]; then
      echo "Previous release link is dangling during activation classification." >&2
      return 1
    fi
  fi

  classify_activation_targets \
    "$release_sha" "$prior_release_sha" \
    "$previous_available" "$same_release" \
    "$current_target" "$previous_target" "$releases_dir"
}

install_authenticated_controls() {
  local source_root="${1:-}"
  local destination_root="${2:-}"
  local temporary_root="${destination_root}.installing"

  verify_deployment_controls "$source_root" "$EXPECTED_CONTROLS_SHA256"
  install -d -o root -g root -m 0700 "$CONTROL_RELEASES_DIR"

  if [[ -e "$destination_root" || -L "$destination_root" ]]; then
    if [[ -d "$destination_root" && ! -L "$destination_root" ]] \
      && verify_deployment_controls \
        "$destination_root" "$EXPECTED_CONTROLS_SHA256" \
      && assert_hardened_control_directory "$destination_root"; then
      return
    fi

    case "$destination_root" in
      "${CONTROL_RELEASES_DIR}/"*) rm -rf -- "$destination_root" ;;
      *) echo "Unsafe deployment control path: $destination_root"; return 1 ;;
    esac
  fi

  if [[ -e "$temporary_root" || -L "$temporary_root" ]]; then
    case "$temporary_root" in
      "${CONTROL_RELEASES_DIR}/"*.installing) rm -rf -- "$temporary_root" ;;
      *) echo "Unsafe temporary control path: $temporary_root"; return 1 ;;
    esac
  fi

  install -d -o root -g root -m 0700 \
    "$temporary_root" "$temporary_root/scripts" \
    "$temporary_root/scripts/deploy"
  install -o root -g root -m 0600 \
    "$source_root/DEPLOYMENT-CONTROLS.sha256" \
    "$temporary_root/DEPLOYMENT-CONTROLS.sha256"
  install -o root -g root -m 0700 \
    "$source_root/scripts/deploy/activate-release.sh" \
    "$temporary_root/scripts/deploy/activate-release.sh"
  install -o root -g root -m 0700 \
    "$source_root/scripts/deploy/rollback-release.sh" \
    "$temporary_root/scripts/deploy/rollback-release.sh"
  install -o root -g root -m 0600 \
    "$source_root/scripts/deploy/cloudron-app.conf" \
    "$temporary_root/scripts/deploy/cloudron-app.conf"
  install -o root -g root -m 0700 \
    "$source_root/scripts/deploy/cloudron-run.sh" \
    "$temporary_root/scripts/deploy/cloudron-run.sh"
  verify_deployment_controls "$temporary_root" "$EXPECTED_CONTROLS_SHA256"
  assert_hardened_control_directory "$temporary_root"
  mv "$temporary_root" "$destination_root"
}

if [[ "${1:-}" == "--classify-activation-state" ]]; then
  classify_activation_state \
    "${2:-}" "${3:-}" "${4:-}" "${5:-}" "${6:-/app/data}"
  exit 0
fi

if [[ "${1:-}" == "--verify-controls" ]]; then
  verify_deployment_controls "${2:-}" "${3:-}"
  exit 0
fi

RELEASE_SHA="${1:-}"
EXPECTED_CURRENT_SHA="${2:-}"
EXPECTED_CONTROLS_SHA256="${3:-}"
DATA_DIR="/app/data"
DEPLOY_DIR="${DATA_DIR}/.deploy/${RELEASE_SHA}"
ARCHIVE_PATH="${DEPLOY_DIR}/release.tar.gz"
RELEASES_DIR="${DATA_DIR}/releases"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_SHA}"
TEMP_RELEASE_DIR="${RELEASE_DIR}.extracting"
CONTROL_RELEASES_DIR="/run/myapes-deployment-controls"
CONTROL_RELEASE_DIR="${CONTROL_RELEASES_DIR}/${RELEASE_SHA}"
SHARED_DIR="${DATA_DIR}/shared"
CURRENT_LINK="${DATA_DIR}/current"
PREVIOUS_LINK="${DATA_DIR}/previous"
PHP_BIN="/usr/bin/php8.4"
SELECTIVE_MEDIA_MARKER_NAME=".myapes-selective-media"
SELECTIVE_MEDIA_MARKER_CONTENT="myapes-selective-media:v1"

assert_canonical_deployment_directory() {
  local path="${1:-}"
  local description="${2:-deployment directory}"
  local required="${3:-false}"
  local canonical_path=""

  if [[ -z "$path" || -L "$path" ]]; then
    echo "Unsafe canonical deployment path for ${description}: ${path}"
    return 1
  fi
  if [[ -e "$path" ]]; then
    if [[ ! -d "$path" ]]; then
      echo "Unsafe canonical deployment path for ${description}: ${path}"
      return 1
    fi
    canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
  else
    if [[ "$required" == true ]]; then
      echo "Required canonical deployment path is missing for ${description}: ${path}"
      return 1
    fi
    canonical_path="$(readlink -m -- "$path" 2>/dev/null || true)"
  fi
  if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
    echo "Unsafe canonical deployment path for ${description}: ${path}"
    return 1
  fi
}

assert_canonical_deployment_file() {
  local path="${1:-}"
  local description="${2:-deployment file}"
  local required="${3:-false}"
  local canonical_path=""

  if [[ -z "$path" || -L "$path" ]]; then
    echo "Unsafe canonical deployment file for ${description}: ${path}"
    return 1
  fi
  if [[ -e "$path" ]]; then
    if [[ ! -f "$path" ]]; then
      echo "Unsafe canonical deployment file for ${description}: ${path}"
      return 1
    fi
    canonical_path="$(readlink -f -- "$path" 2>/dev/null || true)"
  else
    if [[ "$required" == true ]]; then
      echo "Required canonical deployment file is missing for ${description}: ${path}"
      return 1
    fi
    canonical_path="$(readlink -m -- "$path" 2>/dev/null || true)"
  fi
  if [[ -z "$canonical_path" || "$canonical_path" != "$path" ]]; then
    echo "Unsafe canonical deployment file for ${description}: ${path}"
    return 1
  fi
}

assert_shared_runtime_path_boundaries() {
  local required="${1:-false}"

  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage" "shared storage" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/app" "shared storage app" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/app/public" "shared public storage" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/app/public/avatars" "shared avatars storage" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/framework" "shared framework storage" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/framework/cache" "shared framework cache" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/framework/cache/data" "shared framework cache data" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/framework/sessions" "shared framework sessions" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/framework/views" "shared framework views" "$required"
  assert_canonical_deployment_directory \
    "${SHARED_DIR}/storage/logs" "shared log storage" "$required"
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

assert_shared_environment_path() {
  local environment_path="${SHARED_DIR}/.env"
  local canonical_path=""

  if [[ -L "$environment_path" ]]; then
    echo "Unsafe shared environment path: $environment_path"
    return 1
  fi
  if [[ ! -e "$environment_path" ]]; then
    return 0
  fi
  if [[ ! -f "$environment_path" ]]; then
    echo "Unsafe shared environment path: $environment_path"
    return 1
  fi
  canonical_path="$(readlink -f -- "$environment_path" 2>/dev/null || true)"
  if [[ -z "$canonical_path" || "$canonical_path" != "$environment_path" ]]; then
    echo "Unsafe shared environment path: $environment_path"
    return 1
  fi
}

upsert_shared_env_key() {
  local key="${1:-}"
  local value="${2:-}"
  local environment_path="${SHARED_DIR}/.env"

  if [[ ! "$key" =~ ^[A-Z][A-Z0-9_]*$ || -z "$value" ]]; then
    echo "Invalid shared environment assignment: ${key}=${value}"
    return 1
  fi
  assert_shared_environment_path
  if [[ ! -f "$environment_path" ]]; then
    echo "Shared environment is missing."
    return 1
  fi
  if grep -Eq "^${key}=" "$environment_path"; then
    sed -i "s|^${key}=.*$|${key}=${value}|" "$environment_path"
  else
    printf '%s=%s\n' "$key" "$value" >>"$environment_path"
  fi
}

ensure_cloudron_redis_runtime() {
  if [[ -z "${CLOUDRON_REDIS_HOST:-}" ]]; then
    echo "Cloudron Redis is required."
    return 1
  fi

  upsert_shared_env_key CACHE_STORE redis
  upsert_shared_env_key QUEUE_CONNECTION redis
  upsert_shared_env_key SESSION_DRIVER redis
  upsert_shared_env_key APP_MAINTENANCE_STORE redis
  upsert_shared_env_key REDIS_CLIENT phpredis

  assert_shared_environment_path
  chown root:www-data "${SHARED_DIR}/.env"
  chmod 0640 "${SHARED_DIR}/.env"
}

ensure_cloudron_locale_runtime() {
  upsert_shared_env_key APP_LOCALE en_GB
  upsert_shared_env_key APP_FALLBACK_LOCALE en
  upsert_shared_env_key APP_FAKER_LOCALE en_GB

  assert_shared_environment_path
  chown root:www-data "${SHARED_DIR}/.env"
  chmod 0640 "${SHARED_DIR}/.env"
}

assert_activation_path_boundaries() {
  assert_canonical_deployment_directory "$DATA_DIR" "application data root" true
  assert_canonical_deployment_directory "${DATA_DIR}/apache" "Apache runtime parent" true
  assert_canonical_deployment_file \
    "${DATA_DIR}/apache/app.conf" "Apache runtime configuration"
  assert_canonical_deployment_file "${DATA_DIR}/run.sh" "Cloudron runtime launcher"
  assert_canonical_deployment_directory "$RELEASES_DIR" "release parent"
  assert_canonical_deployment_directory "$SHARED_DIR" "shared parent"
  assert_shared_runtime_path_boundaries false
  assert_shared_environment_path
  assert_canonical_deployment_directory "$DEPLOY_DIR" "deployment staging root"
  assert_canonical_deployment_directory "$RELEASE_DIR" "release root"
  assert_canonical_deployment_directory "$TEMP_RELEASE_DIR" "temporary release root"
}

hydrate_shared_runtime_directories() {
  local shared_storage="${SHARED_DIR}/storage"

  if [[ ! -e "$SHARED_DIR" && ! -L "$SHARED_DIR" ]]; then
    install -d -o root -g root -m 0755 "$SHARED_DIR"
  fi
  assert_canonical_deployment_directory "$SHARED_DIR" "shared parent" true
  chown -h root:root "$SHARED_DIR"
  chmod 0555 "$SHARED_DIR"
  if [[ "$(stat -Lc '%U:%G' "$SHARED_DIR")" != "root:root" ]] \
    || sudo -u www-data test -w "$SHARED_DIR"; then
    echo "Application user can mutate the shared structural parent."
    return 1
  fi

  if [[ ! -e "$shared_storage" && ! -L "$shared_storage" ]]; then
    install -d -o www-data -g www-data -m 0770 "$shared_storage"
  fi
  assert_canonical_deployment_directory "$shared_storage" "shared storage" true
  chown -h www-data:www-data "$shared_storage"
  chmod 0770 "$shared_storage"

  sudo -u www-data install -d -m 0770 \
    "${shared_storage}/app/public/avatars" \
    "${shared_storage}/framework/cache/data" \
    "${shared_storage}/framework/sessions" \
    "${shared_storage}/framework/views" \
    "${shared_storage}/logs"

  assert_shared_runtime_path_boundaries true
}

install_public_storage_boundary() {
  local release_root="${1:-}"
  local public_root="${release_root}/public"
  local public_storage="${public_root}/storage"
  local shared_public_storage="${SHARED_DIR}/storage/app/public"
  local marker="${public_storage}/${SELECTIVE_MEDIA_MARKER_NAME}"
  local avatars_link="${public_storage}/avatars"
  local shared_avatars="${shared_public_storage}/avatars"
  local unexpected_entry=""
  local version=""
  local major=""
  local minor=""
  local patch=""
  local selective_required=false

  if [[ -z "$release_root" \
    || "$release_root" != "${RELEASES_DIR}/"* \
    || ! -d "$release_root" \
    || ! -d "$public_root" \
    || -L "$public_root" ]]; then
    echo "Public storage boundary requires a valid immutable release directory."
    return 1
  fi

  version="$(tr -d '\r\n' <"${release_root}/VERSION" 2>/dev/null || true)"
  if [[ ! "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    echo "Public storage boundary requires a valid release VERSION."
    return 1
  fi
  major="${BASH_REMATCH[1]}"
  minor="${BASH_REMATCH[2]}"
  patch="${BASH_REMATCH[3]}"
  if [[ "$major" != 0 \
    || ${#minor} -gt 2 \
    || (${#minor} -eq 2 && "$minor" > 13) ]]; then
    selective_required=true
  fi
  if [[ ! -d "$shared_public_storage" || -L "$shared_public_storage" ]]; then
    echo "Shared public storage target is missing or unsafe."
    return 1
  fi
  shared_public_storage="$(readlink -f "$shared_public_storage")"
  shared_avatars="${shared_public_storage}/avatars"

  if [[ -L "$public_storage" ]]; then
    if [[ "$selective_required" == true ]]; then
      echo "Selective-media release cannot use the legacy public storage link."
      return 1
    fi
    if [[ "$(readlink -f "$public_storage" 2>/dev/null || true)" != "$shared_public_storage" ]]; then
      echo "Legacy public storage link targets an unexpected path."
      return 1
    fi
  elif [[ -d "$public_storage" ]]; then
    if [[ "$selective_required" != true ]]; then
      echo "Legacy release cannot use the selective public storage directory."
      return 1
    fi
    if [[ ! -f "$marker" || -L "$marker" ]] \
      || ! cmp -s "$marker" <(printf '%s\n' "$SELECTIVE_MEDIA_MARKER_CONTENT"); then
      echo "Selective-media marker is missing, unsafe, or tampered."
      return 1
    fi
    unexpected_entry="$(find "$public_storage" -mindepth 1 -maxdepth 1 \
      ! -name "$SELECTIVE_MEDIA_MARKER_NAME" ! -name avatars -print -quit)"
    if [[ -n "$unexpected_entry" ]]; then
      echo "Unexpected public storage entry: $unexpected_entry"
      return 1
    fi
    if [[ ! -d "$shared_avatars" || -L "$shared_avatars" ]]; then
      echo "Shared avatars target is missing or unsafe."
      return 1
    fi
    if [[ -L "$avatars_link" ]]; then
      if [[ "$(readlink -f "$avatars_link" 2>/dev/null || true)" != "$shared_avatars" ]]; then
        echo "Avatar storage link targets an unexpected path."
        return 1
      fi
    elif [[ -e "$avatars_link" ]]; then
      echo "Avatar storage path exists and is not the expected symlink."
      return 1
    else
      ln -s "$shared_avatars" "$avatars_link"
    fi
  elif [[ -e "$public_storage" ]]; then
    echo "Public storage boundary has an unsupported filesystem type."
    return 1
  elif [[ "$selective_required" == true ]]; then
    echo "Selective-media release is missing its tracked public storage boundary."
    return 1
  else
    ln -s "$shared_public_storage" "$public_storage"
  fi

  if [[ -d "$public_storage" && ! -L "$public_storage" ]] \
    && [[ ! -L "$avatars_link" \
      || "$(readlink -f "$avatars_link" 2>/dev/null || true)" != "$shared_avatars" ]]; then
    echo "Selective avatar storage link verification failed."
    return 1
  fi

  if sudo -u www-data test -w "$public_root" \
    || { [[ ! -L "$public_storage" ]] && sudo -u www-data test -w "$public_storage"; }; then
    echo "Application user can mutate the immutable public storage boundary."
    return 1
  fi
  if [[ -e "$marker" ]] && sudo -u www-data test -w "$marker"; then
    echo "Application user can mutate the immutable public storage boundary."
    return 1
  fi
}

restore_data_root_ownership() {
  local cache_root=""
  local unexpected_owner=""

  find "$DATA_DIR" -xdev \
    -path "${SHARED_DIR}/storage" -prune -o \
    -path "${RELEASES_DIR}/*/bootstrap/cache" -prune -o \
    -exec chown -h root:root {} +
  chown -hR www-data:www-data "${SHARED_DIR}/storage"
  chown root:www-data "${SHARED_DIR}/.env"

  for cache_root in "${RELEASES_DIR}"/*/bootstrap/cache; do
    [[ -d "$cache_root" ]] || continue
    chown -hR www-data:www-data "$cache_root"
  done

  unexpected_owner="$(find "$DATA_DIR" -xdev \
    -path "${SHARED_DIR}/storage" -prune -o \
    -path "${RELEASES_DIR}/*/bootstrap/cache" -prune -o \
    ! -user root -print -quit)"
  if [[ -n "$unexpected_owner" ]]; then
    echo "Application data contains an unexpected application-owned path."
    return 1
  fi
}

harden_release_write_boundaries() {
  local release_root="${1:-}"
  local unexpected_owner=""

  if [[ -z "$release_root" \
    || "$release_root" != "${RELEASES_DIR}/"* \
    || ! -d "$release_root" ]]; then
    echo "Release hardening requires a valid immutable release directory."
    return 1
  fi
  assert_release_runtime_path_boundaries "$release_root" false

  chown -hR root:root "$release_root"
  chmod -R a-w -- "$release_root"
  install -d -o www-data -g www-data -m 0770 "${release_root}/bootstrap/cache"
  assert_release_runtime_path_boundaries "$release_root" true
  chown -hR www-data:www-data "${release_root}/bootstrap/cache"
  chmod -R u+rwX,g+rwX,o-rwx "${release_root}/bootstrap/cache"
  chmod 0555 "$DATA_DIR" "$RELEASES_DIR" "$release_root" "${release_root}/public"

  unexpected_owner="$(find "$release_root" -xdev \
    ! -user root \
    ! -path "${release_root}/bootstrap/cache" \
    ! -path "${release_root}/bootstrap/cache/*" \
    -print -quit)"
  if [[ -n "$unexpected_owner" ]]; then
    echo "Immutable release contains an application-owned path."
    return 1
  fi

  for protected_path in \
    "$DATA_DIR" \
    "$RELEASES_DIR" \
    "$release_root" \
    "${release_root}/public"; do
    if [[ "$(stat -Lc '%U:%G' "$protected_path")" != "root:root" ]] \
      || sudo -u www-data test -w "$protected_path"; then
      echo "Application user can mutate protected runtime path: $protected_path"
      return 1
    fi
  done

  if ! sudo -u www-data test -w "${release_root}/bootstrap/cache" \
    || ! sudo -u www-data test -w "${SHARED_DIR}/storage"; then
    echo "Required Laravel runtime paths are not writable by the application user."
    return 1
  fi
}

harden_shared_runtime_boundaries() {
  if [[ ! -d "$SHARED_DIR" || ! -d "${SHARED_DIR}/storage" \
    || ! -f "${SHARED_DIR}/.env" || -L "${SHARED_DIR}/.env" ]]; then
    echo "Shared Laravel runtime paths are incomplete or unsafe."
    return 1
  fi

  chown root:root "$SHARED_DIR"
  chmod 0555 "$SHARED_DIR"
  chown root:www-data "${SHARED_DIR}/.env"
  chmod 0640 "${SHARED_DIR}/.env"
  chown -hR www-data:www-data "${SHARED_DIR}/storage"

  if [[ "$(stat -Lc '%U:%G' "$SHARED_DIR")" != "root:root" \
    || "$(stat -Lc '%U:%G' "${SHARED_DIR}/.env")" != "root:www-data" ]] \
    || sudo -u www-data test -w "$SHARED_DIR" \
    || sudo -u www-data test -w "${SHARED_DIR}/.env" \
    || ! sudo -u www-data test -w "${SHARED_DIR}/storage"; then
    echo "Shared Laravel runtime ownership is unsafe."
    return 1
  fi
}

assert_published_runtime_controls() {
  local protected_path=""
  local -a protected_paths=(
    "$DATA_DIR"
    "${DATA_DIR}/apache"
    "${DATA_DIR}/apache/app.conf"
    "${DATA_DIR}/run.sh"
    "$RELEASES_DIR"
    "$SHARED_DIR"
    "$RELEASE_DIR"
    "${RELEASE_DIR}/public"
  )

  if [[ -n "${CURRENT_TARGET_BEFORE:-}" \
    && "$CURRENT_TARGET_BEFORE" != "$RELEASE_DIR" ]]; then
    protected_paths+=(
      "$CURRENT_TARGET_BEFORE"
      "${CURRENT_TARGET_BEFORE}/public"
    )
  fi

  for protected_path in "${protected_paths[@]}"; do
    if [[ "$(stat -Lc '%U:%G' "$protected_path")" != "root:root" ]] \
      || sudo -u www-data test -w "$protected_path"; then
      echo "Application user can replace protected release control: $protected_path"
      return 1
    fi
  done

  if [[ "$(stat -Lc '%U:%G' "${SHARED_DIR}/.env")" != "root:www-data" ]] \
    || sudo -u www-data test -w "${SHARED_DIR}/.env"; then
    echo "Application user can replace the shared production environment."
    return 1
  fi

  for release_link in "$CURRENT_LINK" "$PREVIOUS_LINK"; do
    if [[ -L "$release_link" \
      && "$(stat -c '%U:%G' "$release_link")" != "root:root" ]]; then
      echo "Release link is not root-owned: $release_link"
      return 1
    fi
  done
}

if [[ ! "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Release SHA must be a full 40-character Git commit SHA."
  exit 1
fi

if [[ ! "$EXPECTED_CONTROLS_SHA256" =~ ^[0-9a-f]{64}$ ]]; then
  echo "Release activation requires the tested deployment-control manifest SHA-256."
  exit 1
fi

if [[ -n "$EXPECTED_CURRENT_SHA" && ! "$EXPECTED_CURRENT_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Expected current release must be a full 40-character Git commit SHA."
  exit 1
fi

assert_activation_path_boundaries

if [[ ! -f "$ARCHIVE_PATH" ]]; then
  echo "Release archive is missing: $ARCHIVE_PATH"
  exit 1
fi

for release_link in "$CURRENT_LINK" "$PREVIOUS_LINK"; do
  if [[ -e "$release_link" && ! -L "$release_link" ]]; then
    echo "Refusing activation because $release_link exists and is not a symlink."
    exit 1
  fi
done

CURRENT_TARGET_BEFORE=""
PREVIOUS_TARGET_BEFORE=""

if [[ -L "$CURRENT_LINK" ]]; then
  CURRENT_TARGET_BEFORE="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"
  if [[ -z "$CURRENT_TARGET_BEFORE" ]]; then
    echo "Current release symlink is dangling; refusing activation."
    exit 1
  fi
fi

if [[ -n "$CURRENT_TARGET_BEFORE" ]]; then
  CURRENT_SHA_BEFORE="$(basename "$CURRENT_TARGET_BEFORE")"
  if [[ -z "$EXPECTED_CURRENT_SHA" || "$CURRENT_SHA_BEFORE" != "$EXPECTED_CURRENT_SHA" ]]; then
    echo "Current release does not match the pre-activation release identity."
    exit 1
  fi
elif [[ -n "$EXPECTED_CURRENT_SHA" ]]; then
  echo "A pre-activation release identity was supplied but no current release exists."
  exit 1
fi

if [[ -L "$PREVIOUS_LINK" ]]; then
  PREVIOUS_TARGET_BEFORE="$(readlink -f "$PREVIOUS_LINK" 2>/dev/null || true)"
  if [[ -z "$PREVIOUS_TARGET_BEFORE" ]]; then
    echo "Previous release symlink is dangling; refusing activation."
    exit 1
  fi
fi

for release_target in "$CURRENT_TARGET_BEFORE" "$PREVIOUS_TARGET_BEFORE"; do
  [[ -n "$release_target" ]] || continue
  if [[ ! "$release_target" =~ ^${RELEASES_DIR}/[0-9a-f]{40}$ ]]; then
    echo "Release symlink does not identify one canonical deployment path: $release_target"
    exit 1
  fi
  assert_canonical_deployment_directory "$release_target" "linked release root" true
done

install_authenticated_controls "$DEPLOY_DIR" "$CONTROL_RELEASE_DIR"

assert_canonical_deployment_directory "$RELEASES_DIR" "release parent"
if [[ ! -e "$RELEASES_DIR" && ! -L "$RELEASES_DIR" ]]; then
  install -d -o root -g root -m 0755 "$RELEASES_DIR"
fi
assert_canonical_deployment_directory "$RELEASES_DIR" "release parent" true
hydrate_shared_runtime_directories

assert_shared_environment_path
if [[ ! -e "${SHARED_DIR}/.env" && ! -L "${SHARED_DIR}/.env" ]]; then
  tar --extract --gzip --to-stdout --file "$ARCHIVE_PATH" \
    ./scripts/deploy/production.env.example >"${SHARED_DIR}/.env"
  assert_shared_environment_path
  APP_KEY_VALUE="$("$PHP_BIN" -r 'echo "base64:".base64_encode(random_bytes(32));')"
  sed -i "s|^APP_KEY=.*$|APP_KEY=${APP_KEY_VALUE}|" "${SHARED_DIR}/.env"
  chown root:www-data "${SHARED_DIR}/.env"
  chmod 0640 "${SHARED_DIR}/.env"
  echo "Created the shared production environment. Configure OIDC values in the Cloudron app environment and rerun deployment if the authentication gate fails."
elif ! grep -Eq '^APP_ENV=production$' "${SHARED_DIR}/.env"; then
  echo "Refusing deployment because ${SHARED_DIR}/.env is not marked APP_ENV=production."
  exit 1
fi
ensure_cloudron_redis_runtime
ensure_cloudron_locale_runtime

harden_shared_runtime_boundaries
restore_data_root_ownership
chown root:root "$DATA_DIR" "$RELEASES_DIR"
for release_link in "$CURRENT_LINK" "$PREVIOUS_LINK"; do
  if [[ -L "$release_link" ]]; then
    chown -h root:root "$release_link"
  fi
done
chmod 0555 "$DATA_DIR" "$RELEASES_DIR" "$SHARED_DIR"

required_paths=(
  VERSION
  REVISION
  artisan
  public/index.php
  public/storage/.myapes-selective-media
  vendor/autoload.php
  public/build/manifest.json
  resources/data/releases.json
  resources/data/module-runtime-contract.json
  config/modules.php
  config/permission.php
  database/migrations/2026_07_28_000000_create_permission_tables.php
  database/migrations/2026_07_28_000100_cut_over_authorization_domain.php
  database/migrations/2026_08_06_000000_create_module_installations_table.php
  app/Console/Commands/AuthorizationPreflight.php
  app/Console/Commands/DirectorySync.php
  app/Console/Commands/AuthorizationSync.php
  app/Console/Commands/AuthorizationCheck.php
  app/Console/Commands/ModulesPreflight.php
  app/Console/Commands/ModulesSync.php
  app/Console/Commands/ModulesCheck.php
  app/Console/Commands/ModulesRollbackCheck.php
  app/Services/ModuleRollbackCompatibilityChecker.php
  scripts/deploy/activate-release.sh
  scripts/deploy/rollback-release.sh
  scripts/deploy/cloudron-app.conf
  scripts/deploy/cloudron-run.sh
  scripts/deploy/production.env.example
  DEPLOYMENT-CONTROLS.sha256
)

reuse_existing_release=false
if [[ -d "$RELEASE_DIR" ]]; then
  packaged_revision="$(tr -d '\r\n' <"${RELEASE_DIR}/REVISION" 2>/dev/null || true)"
  existing_release_valid=true

  for required_path in "${required_paths[@]}"; do
    if [[ ! -e "${RELEASE_DIR}/${required_path}" ]]; then
      existing_release_valid=false
      break
    fi
  done

  if [[ "$existing_release_valid" == true && "$packaged_revision" == "$RELEASE_SHA" ]] \
    && assert_release_runtime_path_boundaries "$RELEASE_DIR" false \
    && verify_deployment_controls "$RELEASE_DIR" "$EXPECTED_CONTROLS_SHA256"; then
    reuse_existing_release=true
    echo "Reusing existing immutable release ${RELEASE_SHA}."
  elif [[ "$RELEASE_DIR" == "$CURRENT_TARGET_BEFORE" || "$RELEASE_DIR" == "$PREVIOUS_TARGET_BEFORE" ]]; then
    echo "Referenced release ${RELEASE_DIR} is incomplete; refusing to remove it."
    exit 1
  else
    case "$RELEASE_DIR" in
      "${RELEASES_DIR}/"*) rm -rf -- "$RELEASE_DIR" ;;
      *) echo "Unsafe release path: $RELEASE_DIR"; exit 1 ;;
    esac
  fi
fi

if [[ "$reuse_existing_release" == false ]]; then
  if [[ -e "$TEMP_RELEASE_DIR" ]]; then
    case "$TEMP_RELEASE_DIR" in
      "${RELEASES_DIR}/"*.extracting) rm -rf -- "$TEMP_RELEASE_DIR" ;;
      *) echo "Unsafe temporary release path: $TEMP_RELEASE_DIR"; exit 1 ;;
    esac
  fi

  install -d -m 0755 "$TEMP_RELEASE_DIR"
  tar --extract --gzip --file "$ARCHIVE_PATH" --directory "$TEMP_RELEASE_DIR" --no-same-owner
  assert_release_runtime_path_boundaries "$TEMP_RELEASE_DIR" false

  for required_path in "${required_paths[@]}"; do
    if [[ ! -e "${TEMP_RELEASE_DIR}/${required_path}" ]]; then
      echo "Packaged release is missing required path: $required_path"
      exit 1
    fi
  done

  if [[ "$(tr -d '\r\n' <"${TEMP_RELEASE_DIR}/REVISION")" != "$RELEASE_SHA" ]]; then
    echo "Packaged REVISION does not match requested release SHA."
    exit 1
  fi

  verify_deployment_controls "$TEMP_RELEASE_DIR" "$EXPECTED_CONTROLS_SHA256"

  rm -rf -- "${TEMP_RELEASE_DIR}/storage"
  ln -s "${SHARED_DIR}/storage" "${TEMP_RELEASE_DIR}/storage"
  ln -s "${SHARED_DIR}/.env" "${TEMP_RELEASE_DIR}/.env"
  assert_release_runtime_path_boundaries "$TEMP_RELEASE_DIR" false
  install -d -o www-data -g www-data -m 0775 "${TEMP_RELEASE_DIR}/bootstrap/cache"
  assert_release_runtime_path_boundaries "$TEMP_RELEASE_DIR" true
  chown -hR www-data:www-data "${TEMP_RELEASE_DIR}/bootstrap/cache"
  mv "$TEMP_RELEASE_DIR" "$RELEASE_DIR"
fi

verify_deployment_controls "$RELEASE_DIR" "$EXPECTED_CONTROLS_SHA256"
verify_deployment_controls "$CONTROL_RELEASE_DIR" "$EXPECTED_CONTROLS_SHA256"
assert_hardened_control_directory "$CONTROL_RELEASE_DIR"

rm -rf -- "${RELEASE_DIR}/storage"
rm -f -- "${RELEASE_DIR}/.env"
ln -s "${SHARED_DIR}/storage" "${RELEASE_DIR}/storage"
ln -s "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"
assert_release_runtime_path_boundaries "$RELEASE_DIR" false
install -d -o www-data -g www-data -m 0775 "${RELEASE_DIR}/bootstrap/cache"
assert_release_runtime_path_boundaries "$RELEASE_DIR" true
install_public_storage_boundary "$RELEASE_DIR"
harden_release_write_boundaries "$RELEASE_DIR"
if [[ -n "$CURRENT_TARGET_BEFORE" && "$CURRENT_TARGET_BEFORE" != "$RELEASE_DIR" ]]; then
  harden_release_write_boundaries "$CURRENT_TARGET_BEFORE"
  install_public_storage_boundary "$CURRENT_TARGET_BEFORE"
fi

run_artisan() {
  sudo -E -u www-data env APP_ENV=production \
    "$PHP_BIN" "${RELEASE_DIR}/artisan" "$@"
}

run_current_artisan() {
  sudo -E -u www-data env APP_ENV=production \
    "$PHP_BIN" "${CURRENT_TARGET_BEFORE}/artisan" "$@"
}

maintenance_state_for_release() {
  local release_root="$1"
  local state=""

  if ! state="$(sudo -E -u www-data env APP_ENV=production \
      "$PHP_BIN" -r '
$root = $argv[1] ?? "";
require $root."/vendor/autoload.php";
$app = require $root."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fwrite(STDOUT, $app->maintenanceMode()->active() ? "active" : "inactive");
' "$release_root")"; then
    echo "Unable to determine Laravel maintenance state." >&2
    return 1
  fi

  case "$state" in
    active|inactive)
      printf '%s\n' "$state"
      ;;
    *)
      echo "Laravel maintenance state returned an invalid result." >&2
      return 1
      ;;
  esac
}

PRE_SWITCH_MAINTENANCE_ACTIVE=false
PREEXISTING_MAINTENANCE=false
DEPLOYMENT_MAINTENANCE_ACTIVE=false
PRE_SWITCH_DATABASE_MUTATED=false
ACTIVATION_SWITCHED=false

restore_current_authorization_after_failure() {
  local exit_code=$?
  local authorization_restored=false
  local current_reopened=false

  trap - EXIT

  if [[ "$exit_code" -eq 0 \
    || "$ACTIVATION_SWITCHED" == true \
    || "$PRE_SWITCH_MAINTENANCE_ACTIVE" != true \
    || -z "$CURRENT_TARGET_BEFORE" \
    || "$CURRENT_TARGET_BEFORE" == "$RELEASE_DIR" ]]; then
    exit "$exit_code"
  fi

  set +e
  if [[ "$PRE_SWITCH_DATABASE_MUTATED" != true ]]; then
    if [[ "${DEPLOYMENT_MAINTENANCE_ACTIVE:-true}" != true ]]; then
      current_reopened=true
    elif run_current_artisan up --no-interaction --no-ansi; then
      current_reopened=true
    fi
  elif ! run_artisan myapes:modules:rollback-check \
    --target-release="$CURRENT_TARGET_BEFORE" \
    --no-interaction --no-ansi; then
    echo "Current release cannot represent the migrated module state; maintenance mode remains active."
  elif run_current_artisan permission:cache-reset --no-interaction --no-ansi \
    && run_current_artisan myapes:authorization-sync --no-interaction --no-ansi \
    && run_current_artisan permission:cache-reset --no-interaction --no-ansi \
    && run_current_artisan myapes:authorization-check --no-interaction --no-ansi; then
    authorization_restored=true
    if [[ "${DEPLOYMENT_MAINTENANCE_ACTIVE:-true}" != true ]]; then
      current_reopened=true
    elif run_current_artisan up --no-interaction --no-ansi; then
      current_reopened=true
    fi
  fi

  if [[ "$PRE_SWITCH_DATABASE_MUTATED" == true && "$authorization_restored" != true ]]; then
    echo "Current authorization could not be restored; maintenance mode remains active."
  elif [[ "$current_reopened" != true ]]; then
    echo "Current release could not leave maintenance mode after activation failure."
  fi

  exit "$exit_code"
}

run_artisan_with_retry_for_directory_readiness() {
  local attempt=1
  local max_attempts=3
  local delay_seconds=5
  local output=""
  local status=0

  while (( attempt <= max_attempts )); do
    set +e
    output="$(run_artisan myapes:authorization-preflight --no-interaction --no-ansi 2>&1)"
    status=$?
    set -e
    printf '%s\n' "$output"

    if (( status == 0 )); then
      return 0
    fi

    if [[ "$output" != *"failed (directory_readiness"* ]]; then
      return "$status"
    fi

    if (( attempt == max_attempts )); then
      return "$status"
    fi

    echo "Authorization preflight reported directory_readiness; retrying (${attempt}/${max_attempts})..."
    sleep "$delay_seconds"
    attempt=$((attempt + 1))
  done
}

run_artisan optimize:clear
run_artisan_with_retry_for_directory_readiness
run_artisan myapes:modules:preflight --no-interaction --no-ansi
run_artisan myapes:accounts:preflight --no-interaction --no-ansi
run_artisan myapes:inbound-mail:preflight --no-interaction --no-ansi
trap restore_current_authorization_after_failure EXIT
if [[ -n "$CURRENT_TARGET_BEFORE" && "$CURRENT_TARGET_BEFORE" != "$RELEASE_DIR" ]]; then
  CURRENT_MAINTENANCE_STATE="$(maintenance_state_for_release "$CURRENT_TARGET_BEFORE")"
  if [[ "$CURRENT_MAINTENANCE_STATE" == active ]]; then
    PREEXISTING_MAINTENANCE=true
  else
    run_current_artisan down --retry=60 --no-interaction --no-ansi
    DEPLOYMENT_MAINTENANCE_ACTIVE=true
  fi
  PRE_SWITCH_MAINTENANCE_ACTIVE=true
fi
PRE_SWITCH_DATABASE_MUTATED=true
run_artisan migrate --force
run_artisan myapes:modules:sync --no-interaction --no-ansi
run_artisan config:cache
run_artisan route:cache
run_artisan view:cache
run_artisan permission:cache-reset --no-interaction --no-ansi
run_artisan myapes:directory-sync --source=manual --no-interaction --no-ansi
run_artisan myapes:authorization-sync --no-interaction --no-ansi
run_artisan permission:cache-reset --no-interaction --no-ansi
run_artisan myapes:modules:check --no-interaction --no-ansi
run_artisan myapes:accounts:check --no-interaction --no-ansi
run_artisan myapes:authorization-check --no-interaction --no-ansi

if ! run_artisan env --no-ansi | grep -Eq 'production'; then
  echo "Effective Laravel environment verification failed."
  exit 1
fi

assert_canonical_deployment_directory "${DATA_DIR}/apache" "Apache runtime parent" true
assert_canonical_deployment_file \
  "${DATA_DIR}/apache/app.conf" "Apache runtime configuration"
assert_canonical_deployment_file "${DATA_DIR}/run.sh" "Cloudron runtime launcher"
install -m 0444 "${CONTROL_RELEASE_DIR}/scripts/deploy/cloudron-app.conf" "${DATA_DIR}/apache/app.conf"
install -m 0555 "${CONTROL_RELEASE_DIR}/scripts/deploy/cloudron-run.sh" "${DATA_DIR}/run.sh"
assert_canonical_deployment_file \
  "${DATA_DIR}/apache/app.conf" "Apache runtime configuration" true
assert_canonical_deployment_file "${DATA_DIR}/run.sh" "Cloudron runtime launcher" true
chown root:root "$DATA_DIR" "${DATA_DIR}/apache" "$RELEASES_DIR" "$SHARED_DIR" \
  "${DATA_DIR}/apache/app.conf" "${DATA_DIR}/run.sh"
for release_link in "$CURRENT_LINK" "$PREVIOUS_LINK"; do
  if [[ -L "$release_link" ]]; then
    chown -h root:root "$release_link"
  fi
done
chmod 0555 "$DATA_DIR" "${DATA_DIR}/apache" "$RELEASES_DIR" "$SHARED_DIR"
assert_published_runtime_controls

if [[ "$CURRENT_TARGET_BEFORE" == "$RELEASE_DIR" ]]; then
  echo "MyAPES release ${RELEASE_SHA} is already active."
  exit 0
fi

rm -f -- "${CURRENT_LINK}.next" "${PREVIOUS_LINK}.next"
ln -s "$RELEASE_DIR" "${CURRENT_LINK}.next"

NEXT_PREVIOUS_TARGET=""
if [[ -n "$CURRENT_TARGET_BEFORE" && "$CURRENT_TARGET_BEFORE" != "$RELEASE_DIR" ]]; then
  NEXT_PREVIOUS_TARGET="$CURRENT_TARGET_BEFORE"
fi

if [[ -n "$NEXT_PREVIOUS_TARGET" ]]; then
  ln -s "$NEXT_PREVIOUS_TARGET" "${PREVIOUS_LINK}.next"
  mv -Tf "${PREVIOUS_LINK}.next" "$PREVIOUS_LINK"
else
  rm -f -- "$PREVIOUS_LINK"
fi

# This is the commit point: all fallible preparation happens before the atomic switch.
mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"
ACTIVATION_SWITCHED=true
if [[ "$DEPLOYMENT_MAINTENANCE_ACTIVE" == true ]]; then
  run_artisan up --no-interaction --no-ansi
fi

CURRENT_TARGET="$(readlink -f "$CURRENT_LINK")"
PREVIOUS_TARGET="$(readlink -f "$PREVIOUS_LINK" 2>/dev/null || true)"
for candidate in "${RELEASES_DIR}"/*; do
  [[ -d "$candidate" ]] || continue
  CANDIDATE_TARGET="$(readlink -f "$candidate")"
  if [[ "$CANDIDATE_TARGET" != "$CURRENT_TARGET" && "$CANDIDATE_TARGET" != "$PREVIOUS_TARGET" ]]; then
    case "$CANDIDATE_TARGET" in
      "${RELEASES_DIR}/"*)
        if ! rm -rf -- "$CANDIDATE_TARGET"; then
          echo "Warning: unable to prune old release $CANDIDATE_TARGET."
        fi
        ;;
      *) echo "Skipping unsafe release cleanup target: $CANDIDATE_TARGET" ;;
    esac
  fi
done

echo "Activated MyAPES release ${RELEASE_SHA}."
