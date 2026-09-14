# Security

Nothing arriving from MCP, REST, the browser or the database is trusted. Input is
validated, stored values are sanitised, and output is escaped again at render
time.

## Templates

Two layers. The first rejects obviously hostile strings. The second parses the
markup with `DOMDocument` and walks it against an allowlist, because substring
checks alone are not enough.

Rejected outright:

```
<?php   <?=   <script>   <style>   <link>   <meta>   <base>   <title>
<iframe>   <object>   <embed>   <applet>   <form>   <input>   <textarea>
on*="…"   javascript:   vbscript:   data:text/html   -moz-binding   expression(
```

Elements must be on the allowlist. Attributes must be on the allowlist, or start
with `aria-` or `data-aiwp-`. Every `{{...}}` must name a field the schema
defines. `@item` only works inside `{{#each}}`, and only for declared sub fields.

Templates are parsed to an AST and walked. There is no `eval` and no compilation
to PHP anywhere in the renderer.

## Escaping

One escaper per context, never one generic function:

| Filter | Uses |
|---|---|
| `text` | `esc_html()` |
| `attr` | `esc_attr()` |
| `url` | `esc_url()` with scheme validation |
| `html` | `wp_kses_post()` |
| `image_url` | resolves the attachment, then `esc_url()` |

## CSS

Rejected: `@import`, `expression(`, `javascript:`, `-moz-binding`, `behavior:`,
`url()` pointing at anything but an image, unbalanced braces, and anything over
the size limit.

Then every page selector is rewritten under `[data-aiwp-page="UUID"]`, so page
CSS cannot reach the theme even if the AI forgets. `html`, `body`, `:root` and
`*` collapse onto the page container. `@media`, `@supports` and `@container`
bodies are scoped recursively. `@keyframes` and `@font-face` are left alone.

## JavaScript

None is accepted. Interaction is declared as `data-aiwp-behavior="accordion"` and
implemented by the plugin's own library.

## Files

All generated files live under `uploads/aiwp-designer/`, never in a theme. Every
read and write goes through `FileStore`, which refuses `..`, null bytes and any
path outside its root. Filenames come from UUIDs the plugin generated, never from
AI or user input. The directory carries an `.htaccess` denying PHP execution and
directory listing. Writes go to a temp file and are renamed, so a half-written
template never goes live.

## Authorisation

Authentication is not authorisation. A bearer token says which WordPress user you
are; each tool then checks a capability:

| Capability | Gives |
|---|---|
| `aiwp_edit_pages` | Create and update pages and content |
| `aiwp_publish_pages` | Publish |
| `aiwp_manage_design` | Change the design system |
| `aiwp_manage_connections` | Manage MCP tokens |

Administrators get all four; editors get `aiwp_edit_pages` only.

Tokens are stored as a SHA-256 hash and shown in full exactly once. Tokens are
never logged, never returned by a tool, and never accepted in a query string.

## Workflow ids

A workflow id expires, belongs to one user and one workflow type, and grants
nothing by itself. It proves the AI read the current instructions. It is not a
credential.

## Preview tokens

Draft previews use a signed token carrying page uuid, version, expiry and random
entropy, verified with `hash_equals`. It contains no credentials and stops
working when it expires.

## Other limits

- Request bodies over 4 MB are refused.
- Templates and CSS over 500 KB are refused.
- A repeater accepts at most 200 rows per request; `{{#each}}` renders at most 500.
- `expected_version` stops one client overwriting another's newer work.
- The audit log records what happened, and scrubs anything token-shaped.
