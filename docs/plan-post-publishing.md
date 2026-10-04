# Plan: write and publish posts (articles) through MCP

Status: built in 0.13.0 (2026-10-04). See ADR-037 and docs/mcp.md.

## Goal

An AI tool can put a finished article into WordPress as a **post**, in the **block editor (Gutenberg)**,
using the site's **article design**. The person reviews the draft and decides when it goes live.

## Must do (the point of this feature)

1. **Articles are blog posts on the website.** Normal WordPress posts (`post` type), not AIWP pages.
   They show in the blog list, in their category and tag pages, in the RSS feed and in WordPress search,
   like any post a person writes.
2. **Every article is editable in Gutenberg.** It opens in the block editor (Posts > All Posts > Edit)
   as normal blocks: headings, paragraphs, lists, quotes, tables, images. A person can change any of it by hand,
   with no AI and no code.
3. **It uses the site's article design** on the front end (header, footer, typography, FAQ box and so on).

Two checks make sure this works on every site:

- **Block editor on for posts:** `post_create` checks `use_block_editor_for_post_type( 'post' )`.
  If a plugin (for example "Classic Editor") or the theme turns Gutenberg off for posts, the post is still created,
  and the tool says clearly: "Gutenberg is off for posts on this site, so the post opens in the classic editor."
- **A blog list exists:** if the site has no blog page (Settings > Reading > Posts page) and no article list design,
  the tool says so, and suggests `build_archive` and setting a Posts page. The post still has its own URL.

`post_get` and `post_create` return the **Gutenberg edit link** (`wp-admin/post.php?post=ID&action=edit`),
so the person can open the article straight in the block editor.

## Typical flow with ConvertRank

1. ConvertRank MCP: plan the page, ask about real results, write the article, run the SEO / AEO / coverage checks.
2. AIWP MCP: `post_create` makes a draft post, the person opens the preview, then `post_publish`.
3. ConvertRank MCP: `mark_page_done` with the live URL, so new articles link to it.

## What already exists (do not rebuild)

- **Posts and pages are already separate** (ADR-032).
  - AIWP pages: the design is the whole page, the block editor is off.
  - Posts: the block editor stays on. The body lives in `post_content`.
- **One article design for all posts:** `article_get_template`, `article_set_template`, workflow `build_article_template`.
  - The body is printed with `{{post_body:post.body}}`.
  - Extra fields per article (deck, takeaways, FAQ, call to action) are declared in the design.
  - The workflow already reuses the site design: `design_get_system` and `design_get_components` come first.
- **The list design** for blog, category, tag and author pages: `archive_get_template`, `archive_set_template`.
- **`articles_list`:** the posts on the site.
- **SEO:** `MetaTags` writes meta title and description, or keeps Yoast / Rank Math / SEOPress in step (ADR-035).
  `SchemaGraph` already supports `Article` and `FAQPage`.
- **Previews** with signed tokens, and the **publish rule** (`confirm_publish: true`) for pages.

## What is missing

1. **No tool can create, change or publish a post.** The AI can design how articles look, but cannot add one.
2. **No conversion to real blocks.** Raw HTML saved in `post_content` opens as one "Classic" block, which is hard to edit.
3. **No workflow for writing an article** (only for designing the article template).

## New tools (posts only)

All new tools start with `post_`, so they never mix with the `page_` tools.

| Tool | Workflow needed | Does |
|---|---|---|
| `post_create` | `write_article` | New **draft** post (see input below) |
| `post_update` | `write_article` | Change a post: body, title, excerpt, fields, terms, image, meta |
| `post_get` | — | One post in full: title, status, body as blocks and as HTML, fields, terms, meta, URL |
| `post_get_preview_url` | — | A signed preview URL, the same as pages |
| `post_publish` | — | Make it public. Needs `confirm_publish: true`. Re-checks the post first |
| `terms_list` | — | Categories and tags, so the AI picks real ones |

Notes:
- `articles_list` stays as it is, for listing posts.
- No `post_delete` in the first version. Deleting stays a human action in WordPress.
- `post_update` on a **published** post saves a WordPress revision first, so the old text is never lost.

### `post_create` input

```json
{
  "workflow_id": "...",
  "title": "SEC EDGAR Data and API: How to Access Filings",
  "slug": "sec-edgar-api",
  "body": "<h2>What SEC EDGAR data is</h2><p>...</p>",
  "body_format": "html",
  "excerpt": "Short summary for lists and search.",
  "categories": [12],
  "tags": ["sec filings", "edgar"],
  "featured_image_id": 345,
  "fields": { "deck": "...", "takeaways": ["..."], "faq": [{ "question": "...", "answer": "..." }] },
  "meta_title": "SEC EDGAR API: How to Access Filings Programmatically",
  "meta_description": "...",
  "schema": ["Article", "FAQPage"],
  "kind": "default"
}
```

- `body_format`: `html` or `markdown`. Markdown is turned into HTML first.
- `fields` must match the article design's field schema (the same check as `page_update_content`).
  Unknown fields are refused with a clear error, like pages (`UnknownArgumentTest`).
- `categories`: ids from `terms_list`. `tags`: names; missing tags are created.
- `featured_image_id`: from `media_search`.
- The answer returns `post_id`, `status: draft`, the edit link in WordPress, and a preview URL.

## Turning the body into real Gutenberg blocks

New class `Articles/BlockConverter`. It turns clean HTML into core block markup, so the post opens
in the block editor as normal blocks a writer can edit.

| HTML | Block |
|---|---|
| `h2` to `h6` | `core/heading` with `level` |
| `p` | `core/paragraph` |
| `ul`, `ol` (with `li`) | `core/list` with `core/list-item` inside |
| `blockquote` | `core/quote` |
| `table` | `core/table` |
| `img` (from the media library) | `core/image` with the attachment `id` |
| `hr` | `core/separator` |
| `pre` / `code` | `core/code` |
| anything else | its text goes into a paragraph; scripts and styles are removed |

Rules:
- Clean the HTML first with `wp_kses_post` (the same rules as a person pasting into WordPress).
- Write each block **exactly** as the block editor saves it (same wrapper classes and attributes),
  or the editor shows "This block contains unexpected or invalid content".
- `h1` is not allowed in the body: the article design prints the title. An `h1` becomes an `h2`, and the tool says so.
- Links stay links. Internal links to the site's own posts are kept as they are.
- After converting, run `parse_blocks()` and check every block name is known.

Risk: block markup must match what the editor expects for the installed WordPress version.
Test it by opening converted posts in the editor (see Tests). If a block type causes trouble, fall back to
`core/paragraph` for that element rather than saving broken markup.

## The `write_article` workflow

New prompt file `prompts/workflows/write-article.md`, registered in `PromptRegistry::WORKFLOWS`.

What it tells the AI:
1. Call `article_get_template` to see the article design and its fields. If there is no article design yet,
   say so and suggest `build_article_template` first (a post still renders with the theme, but not with the site design).
2. Call `terms_list` and pick an existing category. Do not invent many new categories.
3. Put the body in `body`. Do not repeat the title as `h1`. Do not put the FAQ in the body when the design has an FAQ field:
   put it in `fields`, so it shows in the design and gets `FAQPage` schema.
4. Fill only the fields the design declares.
5. `post_create` as a draft, then open `post_get_preview_url` and look at it.
6. Never publish on your own. Publishing is the person's decision: call `post_publish` only when they ask.

## Article design from the existing design

Already covered by `build_article_template` (it starts with `design_get_system` and `design_get_components`).
One small addition to that workflow: when the site will get articles from ConvertRank, suggest these fields,
because ConvertRank briefs produce them:

- `faq` (question + answer repeater): for the brief's FAQ, and `FAQPage` schema
- `takeaways` (short list): optional
- a call to action at the end: from the Brand document

Still a suggestion, not a rule: the workflow keeps "choose what this publication actually needs".

## SEO and schema

- `meta_title` and `meta_description` go through `MetaTags`. When an SEO plugin is active, its own fields are filled (ADR-035).
- `schema: ["Article", "FAQPage"]` goes through `SchemaGraph`. `FAQPage` is only emitted when the article really has an FAQ (the existing rule).
- The slug must be unique. If it is taken, the tool says so instead of adding "-2" silently.

## Publishing rules

- `post_create` always makes a **draft**.
- `post_publish` needs `confirm_publish: true`, checks the post first (title, body not empty, fields valid), then publishes.
- Optional `publish_at` (a date) schedules the post instead of publishing now.
- The answer returns the live URL, so ConvertRank can call `mark_page_done` with it.

## Security

- Capabilities: `post_create` / `post_update` need `edit_posts`; `post_publish` needs `publish_posts`; editing someone else's post needs `edit_others_posts`. Checked through `CapabilityManager`, for the MCP token's user.
- Body cleaned with `wp_kses_post`; fields cleaned per field type, the same as pages.
- Every write goes to `AuditLogger`.
- Posts that are not of type `post` are refused. Pages stay with the `page_` tools.

## Tests

PHP (`tests/php`):
- `BlockConverterTest`: each HTML element becomes the right block; scripts removed; `h1` becomes `h2`; result parses with `parse_blocks()`.
- `PostCreateTest`: draft created; unknown field refused; slug taken refused; terms set; meta written.
- `PostPublishTest`: refused without `confirm_publish`; refused without `publish_posts`; publishes and returns the URL.

By hand (in `tests/smoke.py` or the runbook):
- Create a post with every block type, open it in the block editor: **no "invalid content" warnings**, and every part is a separate, editable block (no single "Classic" block).
- The new post shows in the blog list, its category page and the RSS feed.
- With the "Classic Editor" plugin on, `post_create` still works and shows the "Gutenberg is off" message.
- Edit a block in the editor, save, and check `post_get` still reads it.
- Check the front end uses the article design, and the FAQ shows with `FAQPage` schema.

## Docs to update

- `docs/mcp.md`: the new tools, and `post_create` input.
- `docs/ADR.md`: new ADR "Posts get their own tools, and the body is stored as core blocks".
- `CHANGELOG.md`, `readme.txt`.

## Build order (my guess: 4 to 6 days)

1. `BlockConverter` + its tests (the riskiest part, so first).
2. `post_create`, `post_get`, `terms_list`.
3. `post_get_preview_url`, `post_update` (with revision on published posts).
4. `post_publish` (+ `publish_at`).
5. `write_article` workflow and the small addition to `build_article_template`.
6. Docs, smoke test in the block editor, version bump.

## Open questions

1. **Featured images:** pick from the media library only (`media_search`), or also upload an image from a URL?
   First version: media library only.
2. **More than one article kind** (for example "guide" and "case study" with different designs)? The storage already allows `kind`; first version uses `default`.
3. **Repo visibility:** this repo is public on GitHub. If the plugin will be paid or private, make the repo private.
