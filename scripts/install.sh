#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-$(pwd)}"
PHP_BIN="${PHP_BIN:-php}"

echo "Installing dependencies in ${PROJECT_DIR}"
cd "${PROJECT_DIR}"

if [ ! -f ".env" ]; then
  cp .env.example .env
  echo ".env created from .env.example"
fi

mkdir -p logs
chmod 755 logs

composer install --no-interaction --prefer-dist
chmod +x bin/sync.php

echo "Running health check"
"${PHP_BIN}" bin/sync.php status

echo "Install finished"
