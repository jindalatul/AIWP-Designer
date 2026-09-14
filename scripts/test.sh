#!/usr/bin/env bash
# Unit tests for the parts that do not need WordPress: parser, validators,
# CSS scoper, schema, prompts, preview tokens.
#
# No local PHP needed — everything runs in a throwaway php:8.3 container.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f tests/phpunit.phar ]; then
  echo "==> Downloading PHPUnit"
  curl -sSL -o tests/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
  chmod +x tests/phpunit.phar
fi

docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  php tests/phpunit.phar --configuration phpunit.xml "$@"
