---
id: build-site
version: 1
category: workflow
description: The order a whole site is built in, and why it is that order.
---

Every other workflow here describes one job. This is the order they go in.

`site_get_context` reports `stage`, which names where this site has got to and
what it needs next. Read it before deciding what to do. Nothing refuses an
order that suits a particular site — but if you are unsure, this is the order.

## 1. The business and the brand

Who this is for, what they sell, what makes them not interchangeable with the
next firm in the same trade. Ask if you do not know. Record it.

Everything after this refers back to it. Designing first and describing the
business afterwards produces a site that could belong to anyone.

## 2. What pages this site needs

From the brief, or from the business. Decide before building, because a menu, a
homepage and a component library all assume a shape.

## 3. The design system

`design_create_system`. Colours, type, spacing, radius, **motion**, and the
seven style decisions written in words.

Motion belongs here. Added page by page at the end, one site ends up moving at
three different speeds, which nobody can name and everybody feels.

## 4. The homepage

Build it, and give it more rounds than any other page. It decides what the rest
of the site looks like.

A design system settled in the abstract does not survive contact with a real
page. Expect to change tokens while building this one.

## 5. The component library

`design_extract_components` on the homepage, then `design_set_components` to
keep what is worth reusing. Name each one and say when to use it.

Without this, every page writes its own button and its own card, and the site
stops matching itself somewhere around page four.

## 6. The header and footer

`site_set_header_footer`, then `design_review` with no `page_id`.

They are on every page, so a fault here is a fault everywhere. Build them after
the homepage: the look is settled by then, and there is a real page to see them
against.

## 7. The rest of the pages

Each one composed from the library, each one a different shape from the last.
Two pages with the same run of sections make a site feel like a template.

## 8. The menu

`site_set_menu` once the pages exist, passing `page_id` for internal links so
they follow a slug change. Deciding it earlier means writing it twice.

## 9. Articles and archives

Last, because they inherit everything above.

## At every step

`design_review` on what you just made, and `page_look` before publishing a page.
`page_publish` refuses a page nobody has read back, and refuses one carrying a
high-severity design fault. Those are the floor, not the target.
