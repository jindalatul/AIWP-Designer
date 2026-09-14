# REST API

Namespace: `aiwp-designer/v1`.

| Method | Route | Who | Does |
|---|---|---|---|
| GET | `/pages` | `aiwp_edit_pages` | Every AIWP page |
| GET | `/pages/{id}` | `aiwp_edit_pages` | Schema, content, template, CSS, metadata |
| GET | `/pages/{id}/data` | public if published | Normalised content for headless use |
| PATCH | `/pages/{id}/fields` | `aiwp_edit_pages` | Update field values |
| GET | `/pages/{id}/versions` | `aiwp_edit_pages` | Version history |
| POST | `/pages/{id}/rollback` | `aiwp_edit_pages` | Restore a version |
| GET | `/pages/{id}/audit` | `aiwp_edit_pages` | Static performance audit |
| GET | `/design-system` | `aiwp_edit_pages` | Tokens and global CSS |
| POST | `/mcp` | bearer token | The MCP endpoint |

## Headless

```
GET /wp-json/aiwp-designer/v1/pages/142/data
```

```json
{
  "id": 142,
  "slug": "first-time-home-buyers",
  "title": "First-Time Home Buyers",
  "status": "publish",
  "fields": {
    "hero": { "headline": "Buy your first home", "intro": "…" },
    "benefits": {
      "cards": [ { "title": "Low down payment", "body": "…", "image": 145 } ]
    }
  }
}
```

Draft pages need `aiwp_edit_pages`. Published pages are public, like the page
itself.

Generated field groups also set `show_in_rest`, so the standard WordPress REST
response carries ACF values too.

## Updating fields

```
PATCH /wp-json/aiwp-designer/v1/pages/142/fields
X-WP-Nonce: <wp_rest nonce>

{ "updates": [ { "field_path": "hero.headline", "value": "A better headline" } ] }
```

Browser requests need the standard `wp_rest` nonce. A nonce is CSRF protection,
not authentication — the capability check is what decides access.

Unknown field paths are rejected rather than silently written.
