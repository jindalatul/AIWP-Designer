#!/usr/bin/env bash
# Build a release ZIP containing production files only.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION=$(grep -m1 "Version:" aiwp-designer.php | awk '{print $3}')
OUT="../aiwp-designer-${VERSION}.zip"
STAGE=$(mktemp -d)/aiwp-designer

mkdir -p "$STAGE"
rsync -a --exclude-from=- . "$STAGE/" <<'EXCLUDES'
tests/
scripts/
node_modules/
vendor/
docs/
.git/
.gitignore
DOCUMENT.md
IMPLEMENTATION_STATUS.md
TEST_RESULTS.md
composer.lock
phpunit.xml
.phpunit.result.cache
*.zip
EXCLUDES

rm -f "$OUT"
( cd "$(dirname "$STAGE")" && zip -rq "$(cd "$OLDPWD/.." && pwd)/aiwp-designer-${VERSION}.zip" aiwp-designer )
echo "Built $(cd .. && pwd)/aiwp-designer-${VERSION}.zip"
