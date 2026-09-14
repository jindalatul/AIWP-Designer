---
id: header-footer
version: 3
category: design
description: Designing the shared header and footer.
---

The header and footer are built once and shared by every page that sets
`header_footer: "site"`. Build them before, or just after, the first page — otherwise
every page repeats its own navigation and they drift apart.

Use the same field model as a page. Sections might be `brand`, `nav` and `footer`;
the navigation links are an `aiwp_repeater` so the owner can add a page later
without touching markup.

## Navigation uses WordPress menus

Do not build the link list as a repeater. Navigation belongs in WordPress's own
menu system, so the site owner can change it under Appearance → Menus without
asking anyone.

1. Build the menu with `site_set_menu`, passing `page_id` for internal pages so
   the links follow if a slug changes.
2. Place it in the template:

```html
<nav class="site-nav" data-aiwp-menu="primary" aria-label="Primary"></nav>
```

3. Style `.aiwp-menu`, `.aiwp-menu__item`, `.aiwp-menu__link` and
   `.aiwp-menu__item.is-current` in your header and footer CSS. The current page is marked
   for you.

Locations are `primary` and `footer`. If nothing is assigned yet, the published
pages are listed instead, so a header is never empty.

## Header

- The brand and the primary action are the two things that matter. Everything
  else is secondary.
- Five or six top-level links is the limit. More is a sign the site needs
  grouping, not a smaller font.
- Give it a real sticky behaviour if the pages are long:
  `data-aiwp-behavior="sticky"` plus an `is-stuck` rule for the border or shadow.
- At narrow widths the desktop nav should not simply disappear with nothing in
  its place. Either keep a short row of the most important links, or keep the one
  action that matters.
- Mark the navigation `<nav aria-label="Primary">`.

## Footer

- It is the second navigation, not a graveyard. Group links under real headings.
- Include the things people actually look for: how to make contact, where the
  business is, and the legal lines.
- A single line of type at the bottom — company name, registration, year — is
  enough to finish it.

## CSS

Your CSS is scoped to the header and footer, so plain class names are safe. Two hooks are
provided for the header and footer elements themselves:

```css
.aiwp-chrome--header { position: sticky; top: 0; }
.aiwp-chrome--footer { background: var(--aiwp-color-text); color: #fff; }
``` Use the design
tokens so the header and footer sit with every page rather than on top of them.

Keep it light: the header and footer load on every page, so this is the one place
where a few extra kilobytes are paid for over and over.

## Where the footer meets the page

The footer does not need a margin above it. Every page already ends with its own
padding, and a margin adds to that rather than replacing it — the two stack, and
a visitor sees a band of nothing between the last words and the footer. If the
footer needs separating, a rule or a change of background does it in 1px.

The same goes for the header: the page's first section brings its own top
padding, so a header with a bottom margin pushes the page away from its own
opening.

## Do not repeat the page's call to action

If the header or footer carries a button, a page that ends with the same button
asks the same question twice, often within one screen, and both of them stop
reading as an instruction. `page_look` reports this as `asked_twice`. Keep
whichever is better placed, or give the page one that says something the shared
one cannot.
