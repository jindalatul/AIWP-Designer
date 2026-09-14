---
id: build-article-template
version: 1
category: workflow
description: One design that every article on the site renders through.
---

A landing page is one design for one page. An article is the opposite: one
design, every article. You are not designing *an* article — you are designing
the thing two hundred articles will be poured into.

## What is already there

The body stays in the WordPress editor, where the writer works. You do not
declare a field for it. Print it with `{{post_body:post.body}}`.

Everything else WordPress knows arrives under `post.` without you declaring it:
`post.title`, `post.excerpt`, `post.date`, `post.author`, `post.author_bio`,
`post.author_avatar`, `post.image`, `post.reading_time`, `post.word_count`,
`post.categories`, `post.tags`, and the machine-readable dates. Call
`article_get_template` to see the full list.

## What you declare

The furniture around the body — the parts a writer fills in per article that
WordPress has no field for. Choose what this publication actually needs, not a
standard set:

- a deck or standfirst, if the site uses one
- key takeaways, if the audience skims before reading
- a pull quote
- an FAQ block, if the articles answer questions
- a call to action at the end, aimed at what this business sells

Every one of these is optional. An article design with nothing but a body and
good typography is a real choice, and often the right one.

## Order

1. `design_get_system` — the tokens and the seven `style` decisions. An article
   is part of the same site; it does not get its own look.
2. `design_get_components` — build out of the library, the same as a page. The
   pull quote, the callout, the author box: those are components, and other
   pages will want them too.
3. `article_get_template` — what exists now, and every `post.` path.
4. Design it. Declare the fields, write the markup, write the CSS.
5. `article_set_template`.
6. **Open a real article and read it.** Not the shortest one. A design that
   works with two paragraphs falls apart with twenty.
7. `design_review` on it once a page uses the same components.

## What makes a long read work

- **One column, and let it be narrow.** 65 to 75 characters. This is the single
  biggest thing, and the easiest to get wrong on a wide screen.
- **Body type larger than you think.** 18 to 21px. People read articles, they do
  not scan them.
- **Line height around 1.6 to 1.75**, and space between paragraphs rather than
  indents.
- **Style what the writer will actually produce**: `h2`, `h3`, `ul`, `ol`,
  `blockquote`, `figure`, `figcaption`, `code`, `pre`, `table`, and images that
  break out of the column. If you style only the wrapper, every article will
  look unstyled the moment somebody adds a list.
- **Headings need space above, not below.** A heading belongs to what follows it.
- **Give the reader a sense of length** near the top — reading time, or a
  contents list — so they know what they are agreeing to.

Style those with a descendant rule from the article wrapper, because the body
HTML comes from the editor and carries no classes of yours.

## Afterwards

Tell the user this now governs every post, and that changing it changes all of
them at once. That is the point, and it is also the risk.
