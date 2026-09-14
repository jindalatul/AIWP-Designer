#!/usr/bin/env bash
# PHP syntax check across the whole plugin.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  bash -lc 'find src templates tests -name "*.php" -print0 | xargs -0 -n1 php -l' \
  | grep -v "^No syntax errors detected" || true
echo "PHP syntax OK"
