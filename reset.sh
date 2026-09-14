#!/usr/bin/env bash
# Wipe the test site completely and start again.
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker compose down -v
rm -f .mcp-token
./setup.sh
