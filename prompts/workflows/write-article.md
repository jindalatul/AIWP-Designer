---
id: write-article
version: 1
category: workflow
description: Put a finished article on the site as a blog post that opens in the block editor.
---

You are putting an article on the site as a **blog post**. Not a page: a post is
what a person writing a blog would make in WordPress. It shows in the blog list,
its category and tag pages, the feed and search. It opens in the block editor,
where anyone can change it by hand. It renders through the site's article design,
so it looks like the rest of the site.

The design is not your job here. One article design serves every post; if it does
not exist yet, say so and suggest `build_article_template` first. A post without
it still works, but uses the theme's look.

## Order

1. `article_get_template` — does a design exist, and which extra fields does it
   have (deck, takeaways, FAQ, call to action…)? You will fill those.
2. `terms_list` — pick an existing category. Do not make a new category for each
   article; a blog with forty one-post categories is harder to read.
3. Images: anything in the body must already be in the media library. Use
   `media_search`, or `media_import` with real alt text, and use the library
   address. An image from elsewhere is left out, and the answer says so.
4. `post_create` — always a draft. Read its `warnings`: they say when the site
   is missing something an article needs (an article design, a blog page, the
   block editor).
5. `post_get_preview_url` — open it and read the whole article. Long articles
   show problems short ones hide.
6. Fix with `post_update`. Send only what changes.
7. **Do not publish on your own.** Publishing is the person's decision. Call
   `post_publish` with `confirm_publish: true` only when they ask, and give them
   the live url it returns.

## The body

- Send the article **without the title**. The design prints the title as the
  h1. An h1 in the body is turned into an h2.
- Use h2 for sections and h3 inside them, in order. Do not skip levels.
- Write HTML or Markdown (`body_format`). Both become real blocks: headings,
  paragraphs, lists, quotes, tables, images, code, separators.
- Keep paragraphs short. Use lists for steps and options, a table to compare.
- Links: use full addresses for other sites, and the site's own addresses for
  internal links.
- No inline styles, classes, scripts or embeds. The design styles the body; the
  cleaner removes anything else.

## Fields around the body

Fill only the fields `article_get_template` lists, in `fields` as
`{ section_id: { field_name: value } }`. A field the design does not have is
refused, with the list of the ones it has.

If the design has an FAQ field, put the questions and answers **there**, not in
the body. The design shows them in their own block and the page gets FAQPage
schema. If it has a takeaways field, write 3 to 5 short lines a skimmer can read
instead of the article.

## Search

Send `meta_title` (under about 60 characters), `meta_description` (around 150),
`primary_keyword`, the `questions` the article answers, and `schema_types`
(`Article`, and `FAQPage` when there is an FAQ). They go into the post's brief,
which the meta tags, the schema and `seo_audit` read. When an SEO plugin is
active, its own fields are filled instead, and nothing typed there by hand is
overwritten.

## Facts

Never invent numbers, quotes, customers or results. If the person gave you their
own results, use them as they gave them. If something needs checking, ask the
person before you create the post, rather than putting a guess on their site.
