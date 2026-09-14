# AIWP Designer

An AI-native website design system for WordPress.

Your own AI — Claude, ChatGPT, any MCP client — is the designer. This plugin is
the trusted place where that design is checked, stored, versioned and rendered.
There is no LLM inside the plugin and no hosted service behind it.

## Install

Download **aiwp-designer-0.12.0.zip** from
[Releases](https://github.com/jindalatul/AIWP-Designer/releases/latest), then in
WordPress go to **Plugins → Add New → Upload Plugin** and choose that file.

That ZIP holds the plugin and nothing else. Downloading the repository instead
gives you the test harness as well, which does not belong in a live site.

You also need [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/).
The free one. ACF Pro is not required.

Requires WordPress 6.6+ and PHP 8.1+.

## What it is not

Not a page builder. There is no fixed set of sections, no drag-and-drop canvas,
and no "hero / features / testimonials / FAQ" skeleton the AI has to fill in.
The AI decides the structure of each page.

## How it works

```
Claude / ChatGPT  ──MCP──▶  AIWP Designer  ──▶  ACF fields + safe template + page CSS
                                   │
                                   ▼
                            WordPress page
                                   │
                                   ▼
                            preview URL  ──▶  the AI opens it, looks, improves
```

The AI writes four things: a field schema, template markup, page CSS and the
starting content. It never writes PHP or JavaScript, and the plugin refuses
anything that tries.

## Requirements

- WordPress 6.6+
- PHP 8.1+
- Advanced Custom Fields **free** 6.x (ACF Pro is not required and not used)

## Install

1. Install WordPress.
2. Install and activate Advanced Custom Fields (free).
3. Copy `aiwp-designer/` into `wp-content/plugins/` and activate it.

No build step. No `composer install` needed.

## Connect your AI

1. Open **AIWP Designer → MCP Connection**.
2. Generate a token. It is shown once.
3. Connect your client:

```bash
claude mcp add --transport http aiwp https://yoursite.com/wp-json/aiwp-designer/v1/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

A token acts as one WordPress user and gets exactly that user's capabilities.

## Use it

Fill in **AIWP Designer → Brand & Business** first, then tell your AI:

> Analyse my website and create a Services page.

The AI will call `workflow_prepare`, read the site and its capabilities, plan the
page, create it as a draft, open the preview, critique its own work, improve it,
and hand it back. Publishing is yours to ask for.

Then edit the content any way you like:

- **Edit fields** — normal WordPress admin, normal ACF fields
- **Ask the AI** — "change the headline to …"
- **Your own code** — `get_field()` or the REST API

## For developers

Content is plain ACF. Nothing is locked into the plugin's renderer.

```php
$headline = get_field( 'hero_headline' );

foreach ( get_field( 'benefits_cards' ) as $card ) {
    echo esc_html( $card['title'] );
}
```

Headless:

```
GET /wp-json/aiwp-designer/v1/pages/142/data
```

A custom theme can ignore AIWP's rendering entirely and use the fields directly.

## Documentation

| Document | What is in it |
|---|---|
| [docs/building-websites.md](docs/building-websites.md) | **Start here** — how to actually build a site with it |
| [docs/architecture.md](docs/architecture.md) | How the pieces fit together |
| [docs/template-language.md](docs/template-language.md) | The complete `.aiwp` language |
| [docs/acf-repeater.md](docs/acf-repeater.md) | `aiwp_repeater` field and its storage |
| [docs/mcp.md](docs/mcp.md) | Every MCP tool, resource and prompt |
| [docs/forms.md](docs/forms.md) | Forms: declaring, styling, storage, spam |
| [docs/chrome.md](docs/chrome.md) | The shared header and footer |
| [docs/prompts.md](docs/prompts.md) | The prompt library and how it compiles |
| [docs/rest-api.md](docs/rest-api.md) | REST endpoints |
| [docs/security.md](docs/security.md) | What is rejected, and why |
| [docs/development.md](docs/development.md) | Local setup and tests |
| [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) | What is built and what is not |
| [docs/ADR.md](docs/ADR.md) | Every departure from the specification |

## Licence

GPL-2.0-or-later.

## Running it yourself

The repository carries a throwaway WordPress to test against.

```bash
./setup.sh
```

That boots WordPress on http://localhost:8090, installs ACF and this plugin,
creates an MCP token and prints the line that connects your AI client to it.
`./reset.sh` puts it back to empty.

The tests:

```bash
# unit tests, no WordPress needed
curl -sSL -o tests/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
docker run --rm -v "$PWD:/w" -w /w php:8.3-cli php tests/phpunit.phar

# end to end, against a running site
AIWP_SITE=http://localhost:8090 python3 tests/smoke.py
```

`RUNBOOK.md` has the longer version.

## Licence

GPL-2.0-or-later, the same as WordPress.
