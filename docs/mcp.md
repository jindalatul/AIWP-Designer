# MCP

## Endpoint

```
POST /wp-json/aiwp-designer/v1/mcp
Authorization: Bearer <token>
Content-Type: application/json
```

JSON-RPC 2.0. Request/response only — there is no SSE stream, so `GET` returns
405.

Supported methods: `initialize`, `ping`, `server/discover`, `tools/list`,
`tools/call`, `prompts/list`, `prompts/get`, `resources/list`, `resources/read`,
`notifications/initialized`.

## Connecting

```bash
claude mcp add --transport http aiwp https://yoursite.com/wp-json/aiwp-designer/v1/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

If your host strips the `Authorization` header (common with Apache + mod_php),
add:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## The rule that shapes everything

**Every mutating tool needs a `workflow_id`.** Get one from `workflow_prepare`,
which also returns the instructions for that job. Without it you get
`AIWP_WORKFLOW_REQUIRED`.

## Tools

### Start here

| Tool | Does |
|---|---|
| `workflow_prepare` | Returns compiled instructions + `workflow_id`. Types: `build_page`, `redesign_page`, `redesign_section`, `create_design_system`, `improve_page`, `update_content` |

### Reading

| Tool | Does |
|---|---|
| `site_get_context` | Site, theme, homepage, brand answers, existing pages |
| `site_get_capabilities` | Field types, behaviors and template features that exist right now |
| `design_get_system` | Tokens, CSS variables, global CSS |
| `pages_list` | Every AIWP page |
| `page_get` | One page in full |
| `page_versions` | Version history |
| `page_get_preview_url` | A signed URL you can open in a browser |
| `media_search` | Media library images, by id |
| `page_validate` | Dry-run a package; stores nothing |
| `performance_static_audit` | Deterministic checks on the rendered page |

### Writing

| Tool | Workflow needed | Does |
|---|---|---|
| `page_create` | `build_page` | New draft page |
| `page_update` | `redesign_page` | New template/CSS/schema version |
| `page_update_content` | `update_content` | Field values only, no new version |
| `design_create_system` | `create_design_system` | New site-wide design system |
| `page_publish` | — | Makes it public. Needs `confirm_publish: true` |
| `page_rollback` | — | Restores a version, as a new version |

## `page_create` input

```json
{
  "workflow_id": "…",
  "page": { "title": "First-Time Home Buyers", "slug": "first-time-home-buyers" },
  "design_metadata": {
    "page_goal": "Lead generation",
    "audience": "First-time buyers",
    "visual_direction": "Premium and reassuring",
    "primary_conversion": "Start application"
  },
  "sections": [ { "id": "hero", "label": "Hero", "fields": [ … ] } ],
  "content":  { "hero": { "headline": "…" } },
  "template": "<main>…</main>",
  "css": "…",
  "behaviors": ["reveal", "accordion"],
  "chrome": "theme"
}
```

Response:

```json
{
  "success": true,
  "page_id": 142,
  "page_uuid": "…",
  "version": 1,
  "status": "draft",
  "preview_url": "https://…/?page_id=142&aiwp_preview=…",
  "validation": { "errors": [], "warnings": [] }
}
```

Success is never reported unless the page, the schema, the files, the content and
the version index all landed. On any failure everything is undone.

## Resources

```
aiwp://site/context
aiwp://site/capabilities
aiwp://design/system
aiwp://template-language
aiwp://behaviors
aiwp://page/{id}
```

No server filesystem path is ever exposed.

## Error codes

```
AIWP_ACF_MISSING              AIWP_TEMPLATE_INVALID
AIWP_CSS_INVALID              AIWP_FIELD_TYPE_UNAVAILABLE
AIWP_FIELD_REFERENCE_MISSING  AIWP_PERMISSION_DENIED
AIWP_WORKFLOW_REQUIRED        AIWP_WORKFLOW_EXPIRED
AIWP_WORKFLOW_TYPE_MISMATCH   AIWP_VERSION_CONFLICT
AIWP_FILE_WRITE_FAILED        AIWP_MCP_UNAUTHORIZED
AIWP_UNKNOWN_TOOL             AIWP_PAGE_INVALID
```

A failing tool call returns `isError: true` with the code and a message saying
what to do about it.

## Tool names

The specification writes these with dots (`page.create`). They are registered
with underscores because several clients pass tool names into APIs that reject
dots. Dotted names are still accepted. See ADR-001.
