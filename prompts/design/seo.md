---
id: seo
version: 1
category: design
description: What to record about a page, and what the plugin checks for you.
---

Two separate jobs, and confusing them is how sites end up stuffed with keywords.

The plugin checks the things that need no opinion — a title of a sensible
length, one h1, headings that do not skip, alt text, internal links, whether
anything links to this page at all. Those run whether you record anything or
not, and you do not have to think about them.

What it cannot know is what the page was *for*. That is what a brief records.

## Record the brief before you build the page

`page_set_brief` takes whatever you know. Everything is optional, and a brief
with only a keyword and three questions is worth having.

- **primary_keyword** — the one phrase. Not five.
- **questions** — what this page has to answer. These get checked against what
  the page actually says, so put the real ones in.
- **entities** — concepts a credible page on this subject must cover. Not
  keywords: things the subject involves, that a reader would notice the absence
  of.
- **meta_title** — under about 60 characters. It is the whole title, not a
  fragment with the site name bolted on.
- **meta_description** — around 150. It is an argument for clicking, not a
  summary.
- **schema_types** — `Article`, `FAQPage`, `BreadcrumbList`. See below.

If a brief came from somewhere else, pass its fields straight through. The
plugin does not care where it came from.

## Then check the page against it

`seo_audit` reports both layers and tells you which checks it skipped. Act on
what it finds:

- **The keyword is absent.** Use the phrase where it belongs — in the title,
  and once early in the body. Once. A page that repeats a phrase fifteen times
  reads as written for a machine, and has done since about 2012.
- **Questions unanswered.** Answer them, ideally under a heading shaped like the
  question. That is also the shape an answer engine quotes.
- **Concepts missing.** Either cover the concept or take it off the brief. A
  brief that asks for something the page has no business covering is wrong, and
  saying so is a real answer.
- **Nothing links to this page.** Link to it from a page that already gets
  visits. A page nothing points at gets crawled rarely and found by nobody.

## Structured data is built for you

You do not write JSON-LD. The plugin builds it from the rendered page, because
schema is a set of claims a search engine treats as facts, and asking a model
for facts about a page produces ratings nobody measured and questions the page
does not answer.

Ask for a type and the plugin emits it only if the page can support it.
`FAQPage` needs real questions and answers in the markup — a question-shaped
heading with a real answer under it. Two or more, or nothing is emitted.

The practical consequence: **to get FAQ schema, write a proper FAQ section.**
That is the right order round.

## Titles and descriptions

On a site with no SEO plugin, the plugin prints them itself.

If Yoast, Rank Math, SEO Press or All in One is active, it stands down and
writes your values into that plugin's own fields instead, without ever
overwriting something a person typed there. Two plugins printing two titles is
worse than neither doing it. `seo_audit` reports `meta_owner` so you can see
which is in charge.

## What nobody can check here

How the page ranks. Nothing in this plugin talks to a search engine, and any
tool that claims to score your page against Google is guessing. What it can tell
you is whether the page is well formed, whether it says what it set out to say,
and whether anything points at it.
