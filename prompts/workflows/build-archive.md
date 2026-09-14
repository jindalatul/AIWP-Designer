---
id: build-archive
version: 1
category: workflow
description: One design for every list of articles on the site.
---

A site with articles and no page listing them is a site where the only way to
reach an article is to already know its address.

One design covers all of it: the blog index, a category, a tag, an author's
posts, a search result. They differ in what the list is of and what it is
called, and both of those arrive as values. Building five is how a site ends up
with five slightly different ones.

## What arrives without you declaring it

`archive_get_template` prints the full list. The ones that matter:

- `archive.title` — what this list is called, already correct for each kind
- `archive.kind` — `blog`, `category`, `tag`, `author`, `search` or `date`
- `archive.term` on a category or tag, `archive.query` on a search
- `archive.count`, `archive.page`, `archive.pages`
- `archive.newer_url` and `archive.older_url`, empty when there is no such page
- `archive.empty` — true when there is nothing to show
- `archive.paginated` — true only when there is more than one page. Wrap the
  pager in this, not in `archive.pages`, which is 1 on a short list
- `archive.items` — the articles, each with title, url, excerpt, date, author,
  image, reading time and its first category
- `archive.categories` — every category on the site, with `is_current`

## Order

1. `design_get_system` and `design_get_components`. A listing is built from the
   site's own components, like everything else.
2. `archive_get_template` for the field paths.
3. Design it, then `archive_set_template`.
4. Open the blog index and look. Then open a category, and a search that finds
   nothing.

## What a listing has to get right

- **One thing per row, scannable.** Somebody is deciding what to read, not
  reading. Title, a line of context, and how long it takes.
- **`archive.empty` must be handled.** A search that finds nothing is a page
  somebody is definitely going to see, and a blank column is not an answer.
  Say what happened and offer a way on.
- **Use `archive.kind` to change the words, not the layout.** A category page
  can open differently from a search result without being a second design.
- **Paginate honestly.** Show both links only when both exist. `newer_url` and
  `older_url` are empty strings at the ends, so wrap them in `{{#if}}`.
- **Reading time helps somebody choose.** It is already calculated.
- **Do not print the whole article.** An excerpt is there for a reason.

## Afterwards

Tell the user to create a page for the blog and set it under **Settings >
Reading**, unless the site root is meant to be the article list. Without that,
WordPress shows the list at the root and the homepage never appears.
