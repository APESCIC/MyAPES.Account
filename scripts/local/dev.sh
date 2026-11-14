#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f artisan ]]; then
  echo "artisan was not found in $ROOT_DIR. Run this inside the Laravel project root."
  exit 1
fi

if [[ ! -f composer.json ]]; then
  echo "composer.json was not found in $ROOT_DIR. Run this inside the Laravel project root."
  exit 1
fi

if [[ ! -f scripts/local/dev-runner.mjs ]]; then
  echo "scripts/local/dev-runner.mjs was not found in $ROOT_DIR."
  exit 1
fi

# Agent-only path for Cursor cloud and Linux agents without Herd: artisan serves on APP_PORT.
export APP_SERVE=1
exec composer run dev
