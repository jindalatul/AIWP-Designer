# Development

## Local site

```bash
cd aiwp
./setup.sh      # boots WordPress + MariaDB, installs wp-cli and ACF, prints an MCP token
./reset.sh      # wipes everything and starts over
```

- Site: <http://localhost:8090>
- Admin: <http://localhost:8090/wp-admin> (`admin` / `admin`)
- MCP: `http://localhost:8090/wp-json/aiwp-designer/v1/mcp`

The plugin directory is bind-mounted, so an edit on your machine is live
immediately. No rebuild, no reinstall.

## Tests

```bash
./aiwp-designer/scripts/test.sh          # 92 unit tests, no WordPress needed
./aiwp-designer/scripts/test.sh --testdox # readable output
./aiwp-designer/scripts/lint.sh          # PHP syntax across the plugin
python3 tests/smoke.py                   # 64 end-to-end checks against the running site
```

Everything runs in Docker. No local PHP, Composer or Node required.

## Commands the specification asks for

| Spec command | Here |
|---|---|
| `composer install` | Not needed — a PSR-4 autoloader ships with the plugin |
| `composer test` | `./scripts/test.sh` |
| `composer test:all` | `./scripts/test.sh && python3 ../tests/smoke.py` |
| `npm install` / `npm run build` | Not needed — assets in `assets/dist/` are plain ES5 and CSS, no build step |

## Useful

```bash
# wp-cli
docker compose exec -u www-data wordpress wp plugin list

# read a field
docker compose exec -u www-data wordpress wp eval 'echo get_field("hero_headline", 7);'

# generated files
docker compose exec wordpress ls -R wp-content/uploads/aiwp-designer

# PHP errors
docker compose exec wordpress tail -f wp-content/debug.log
```

## Adding a behavior

1. Add the implementation to `assets/dist/behaviors.js`.
2. Add its name to `AssetManager::BEHAVIORS`.
3. Document its markup hooks in `prompts/core/template-language.md`.

The validator reads the same list, so an unknown behavior is rejected
automatically.

## Adding an MCP tool

Add an entry to `ToolRegistry::catalog()` with its description, `inputSchema`,
required capability and workflow type, then write the handler. `tools/list`
picks it up from there.

## Changing design quality

Edit the markdown in `prompts/`. That is the dial. The plugin only decides what
is *allowed*; the prompts decide what is *good*.
