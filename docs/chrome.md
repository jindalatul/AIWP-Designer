# Shared header and footer

## Where the values come from

Everything in the header and footer comes from one of four places, and each is
edited somewhere different.

| In the template | Comes from | Edited in |
|---|---|---|
| `{{text:brand.name}}` and every other `{{...}}` | ACF fields on the chrome holder page | AIWP Designer → Header & Footer |
| `<nav data-aiwp-menu="primary">` | A WordPress navigation menu | Appearance → Menus |
| The markup and the CSS | `uploads/aiwp-designer/chrome/current/` | The AI, through `site_set_chrome` |
| `var(--aiwp-color-*)` and the fonts | The design system tokens | The AI, through `design_create_system` |

A field path maps to its ACF field name by turning the dot into an underscore:
`{{text:foot.email}}` is the field `foot_email`, so `get_field( 'foot_email', $chrome_page_id )`
works in a theme like any other field.

The header and footer are built once and shared by every page that sets
`chrome: "site"`. Build them before, or just after, the first page — otherwise
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
   `.aiwp-menu__item.is-current` in your chrome CSS. The current page is marked
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

Your CSS is scoped to the chrome, so plain class names are safe. Two hooks are
provided for the header and footer elements themselves:

```css
.aiwp-chrome--header { position: sticky; top: 0; }
.aiwp-chrome--footer { background: var(--aiwp-color-text); color: #fff; }
``` Use the design
tokens so the header and footer sit with every page rather than on top of them.

Keep it light: the header and footer load on every page, so this is the one place
where a few extra kilobytes are paid for over and over.
