#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-$(pwd)}"
PHP_BIN="${PHP_BIN:-php}"
cd "${PROJECT_DIR}"

echo "== Connection and config checks =="
"${PHP_BIN}" bin/sync.php status

echo "== Dry-run stock sync check =="
DRY_RUN=true "${PHP_BIN}" bin/sync.php stock

echo "Health checks passed."
