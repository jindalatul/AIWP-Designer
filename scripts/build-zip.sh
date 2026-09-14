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
.github/
.gitignore
DOCUMENT.md
IMPLEMENTATION_STATUS.md
TEST_RESULTS.md
composer.lock
phpunit.xml
.phpunit.result.cache
*.zip
.DS_Store
__pycache__/
# The test harness. It belongs in the repository, never in somebody's
# wp-content/plugins folder.
docker-compose.yml
setup.sh
reset.sh
RUNBOOK.md
EXCLUDES

rm -f "$OUT"
( cd "$(dirname "$STAGE")" && zip -rq "$(cd "$OLDPWD/.." && pwd)/aiwp-designer-${VERSION}.zip" aiwp-designer )
echo "Built $(cd .. && pwd)/aiwp-designer-${VERSION}.zip"
