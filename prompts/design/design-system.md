---
id: design-system
version: 3
category: design
description: Decide this site's visual language, then write it down so every page obeys it.
---

You are designing the visual language for **this** business. Not a template, not
a house style, not the look of the last site you built. Two sites should never
come out alike, even two plumbers.

Work in this order. Each step depends on the one above it.

## 1. Decide what this site should feel like

Before any value, answer in one sentence each:

- **personality** — what should someone feel in the first two seconds? Name a
  real feeling: exact, warm, expensive, cheap and cheerful, serious, playful,
  quiet, loud.
- **layout** — how are pages built? A strict grid. A wide centre column. Two
  columns with an asymmetric split. Full-bleed bands. Something else.
- **surface** — how is one thing separated from another? Hairlines. Filled
  cards. Shadow and lift. Blocks of flat colour. Nothing but space.
- **motion** — how much does this site move? None at all. A little on hover.
  Things that arrive as you scroll. Whatever you decide, put the numbers in the
  `motion` tokens below and write them nowhere else, or page twelve will move
  at a different speed from page one.
- **buttons** — shape, weight, and what happens on hover.
- **imagery** — what images do here, and what they are. Photography. Drawings.
  Diagrams. Screenshots. None.
- **signature** — the one thing a visitor would remember. An oversized number.
  A rule that runs through every section. A colour used once per page. A
  headline that breaks the grid.

These go in `style`. They are read back by every page you build afterwards, so
write them as instructions to your future self, not as marketing words.

There is no correct answer to any of them. There is only a choice, made once,
and then kept.

## 2. Tokens

```json
{
  "colors": { "primary": "", "secondary": "", "accent": "", "text": "", "muted": "", "surface": "", "border": "" },
  "typography": { "font_url": "", "heading_font": "", "body_font": "", "base_size": "17px", "scale": "1.333" },
  "spacing": { "3xs": "4px", "2xs": "8px", "xs": "12px", "sm": "18px", "md": "28px", "lg": "44px", "xl": "72px", "2xl": "112px", "3xl": "176px" },
  "radius": { "small": "", "medium": "", "large": "", "full": "999px" },
  "container": { "max": "1240px", "text": "68ch" },
  "motion": { "fast": "", "base": "", "slow": "", "ease": "", "travel": "" },
  "style": { "personality": "", "layout": "", "surface": "", "motion": "", "buttons": "", "imagery": "", "signature": "" }
}
```

### motion

Five values, decided once, used by every page.

- `fast`, `base`, `slow` — durations, like `120ms` or `.4s`. `fast` is a colour
  changing under a cursor; `base` is most things; `slow` is something arriving
  on its own.
- `ease` — the curve. A keyword (`ease-out`), or `cubic-bezier(.2,0,0,1)`, or
  `steps(4)`. This is the part that makes movement feel like *this* site.
- `travel` — how far something moves when it arrives, like `16px`.

They come out as `--aiwp-motion-fast`, `--aiwp-motion-base`,
`--aiwp-motion-slow`, `--aiwp-motion-ease` and `--aiwp-motion-travel`. Write
`transition: color var(--aiwp-motion-fast) var(--aiwp-motion-ease)`, never a
number typed into the page. A site that decides `none` writes `0ms` and is done.

`data-aiwp-behavior="reveal"` makes an element fade and rise into place as it
scrolls in. It uses `slow`, `ease` and `travel`, and it stops dead for anyone
whose machine asks for less movement. You do not write the JavaScript for it.

**The spacing scale needs nine steps, not five.** Five is the single most common
reason a site looks slightly wrong: pages need 20px and 24px and 40px, the scale
does not have them, so every page invents its own and no two pages match. Build
a real ladder, each step roughly 1.4 to 1.6 times the last.

**`scale` is the type ratio, and it is a real decision.** 1.2 is quiet and
editorial. 1.333 is a comfortable middle. 1.5 and up is loud and confident. Pick
it on purpose, because every heading size on the site comes from it.

The plugin turns `base_size` and `scale` into real variables —
`--aiwp-text-xs` through `--aiwp-text-5xl` — so pages size type from the scale
instead of guessing in `rem`. `rem` is 16px whatever you set here, so a page
that writes `1.02rem` on an 18px site gets text smaller than its own body copy.

**Radius is a decision, not a default.** Zero everywhere is a look. 2px is a
look. Fully round is a look. Pick one posture and hold it.

## 3. Fonts

Typography is the biggest single lever on how a site feels, so choose real
typefaces rather than settling for system defaults.

`font_url` may point at a stylesheet on `fonts.googleapis.com` or
`fonts.bunny.net`. Any other host is dropped. Name families with fallbacks, and
request only the weights you will use — two or three, not the whole family.

Page CSS cannot load anything remote. This is a site-wide decision, made once,
here.

## 4. Global CSS

Foundations only:

- heading and body type rules
- container and layout helpers
- accessibility helpers (focus ring, visually-hidden)
- reduced-motion handling

Not components — those go in the component library, next. Not page design.
The plugin ships a small baseline; add to it rather than restating it.

## Rules that are not style

These hold whatever you choose:

- text on primary and text on surface must both pass 4.5:1 contrast
- the accent is for action; if it appears everywhere it stops meaning anything
- `container.max` above 1440px needs a reason
- every interactive thing needs a visible keyboard focus state
