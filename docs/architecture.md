# Architecture

## The one-sentence version

The AI decides what the page should be; the plugin decides what is allowed to
run.

## Layers

```
MCP client (Claude / ChatGPT)
        │  JSON-RPC 2.0 over HTTP
        ▼
MCPServer ──▶ TokenAuthProvider  (who are you?)
        │ ──▶ WorkflowManager    (have you read the instructions?)
        │ ──▶ CapabilityManager  (are you allowed?)
        ▼
ToolRegistry
        │
        ├── PromptCompiler      instructions for this workflow
        ├── PageManager         create / update / publish / rollback
        ├── DesignSystemRepo    tokens + global CSS
        └── StaticAuditor       deterministic page checks
                │
                ▼
        PageValidator
        ├── PageSchema          field model
        ├── TemplateValidator   parse HTML, allowlist, check field refs
        └── CSSValidator        reject, then scope to this page
                │
                ▼
        VersionManager ──▶ FileStore (atomic writes, UUID paths)
        FieldValueManager ──▶ ACF
                │
                ▼
        PageRenderer ──▶ TemplateParser ──▶ AST ──▶ TemplateEngine
                │
                ▼
        page-shell.php ──▶ theme chrome + rendered page + scoped CSS + behaviors
```

## Where things live

A page is an ordinary WordPress `page`. It is never a custom post type, so
permalinks, menus, SEO plugins and permissions all behave normally.

Post meta on that page:

| Key | Holds |
|---|---|
| `_aiwp_enabled` | `1` if this page is AIWP-designed |
| `_aiwp_page_uuid` | the id every file path is derived from |
| `_aiwp_schema` | the field model |
| `_aiwp_manifest` | design decisions worth keeping |
| `_aiwp_active_version` | which version is live |
| `_aiwp_design_version` | which design system it was built against |

Generated files:

```
wp-content/uploads/aiwp-designer/
├── design/
│   ├── current/{tokens.json, global.css}
│   └── versions/N/
├── pages/PAGE_UUID/
│   ├── current/{template.aiwp, page.css, schema.json, content.json, manifest.json}
│   └── versions/N/
└── cache/
```

Nothing is ever written to the theme. The directory denies PHP execution and
directory listing. Paths come from UUIDs the plugin generated; the AI never sees
or supplies a filesystem path.

The database table `{prefix}aiwp_versions` is only an index over those files.

## Content and presentation stay apart

The template holds structure. ACF holds words. Redesigning a page does not touch
content; rewriting content does not touch the template. That is why
`page_update` and `page_update_content` are separate tools — only the first
creates a new version.

## Why the AI never writes code

AI output arrives as data, not as anything executable:

- **Template** — parsed to an AST and walked. Never `eval`ed, never compiled to
  PHP.
- **CSS** — parsed, filtered, and rewritten so it cannot reach outside its page.
- **JavaScript** — not accepted at all. Interactivity is declared
  (`data-aiwp-behavior="accordion"`) and implemented by the plugin.

## The workflow gate

An MCP client is not guaranteed to read a server's prompts. So every mutating
tool requires a `workflow_id` that only `workflow_prepare` issues, and that call
returns the compiled instructions. The AI cannot change the site without first
being handed the rules.

A workflow id is not authentication. It expires, belongs to one user and one
workflow type, and grants nothing on its own.
