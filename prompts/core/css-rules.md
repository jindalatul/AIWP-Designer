---
id: css-rules
version: 1
category: core
description: How to write page CSS that stays inside the page.
---

Write plain, modern CSS. The plugin scopes every selector to this page, so `.hero` really means "this page's `.hero`".

Use the design tokens rather than hard-coded values whenever the token is right:

```css
var(--aiwp-color-primary)
var(--aiwp-color-accent)
var(--aiwp-color-text)
var(--aiwp-color-muted)
var(--aiwp-color-surface)
var(--aiwp-space-3xs) … var(--aiwp-space-3xl)
var(--aiwp-radius-small|medium|large|full)
var(--aiwp-font-heading)
var(--aiwp-font-body)
var(--aiwp-container-max)
var(--aiwp-text-xs|sm|base|lg|xl|2xl|3xl|4xl|5xl)
```

## Do not size type in rem

`rem` is relative to the browser root, which is 16px. It is **not** this site's
base size. On a site with an 18px base, `font-size: 1.02rem` renders at 16.3px —
smaller than the body text, which is never what was meant. This is the single
most common reason article text comes out too small.

Use the scale instead. It is computed from this site's own base size and ratio:

```css
font-size: var(--aiwp-text-base);   /* body copy */
font-size: var(--aiwp-text-3xl);    /* a section heading */
font-size: clamp(var(--aiwp-text-3xl), 5vw, var(--aiwp-text-5xl));  /* fluid */
```

`em` is fine where you mean "relative to this element's own size" — spacing
inside a button, for instance.

A hard-coded value is fine when the design genuinely needs one (a specific gradient stop, an optical nudge). It is not fine as a substitute for reading the tokens.

Rules:

- prefix your class names per page or per section (`.svc-hero`, not `.hero`) so two AIWP pages never fight
- prefer `clamp()` for fluid type instead of many breakpoints
- prefer CSS grid and flexbox; do not use floats
- do not write a reset — the global CSS already has one
- do not restate the baseline (`img{max-width:100%}` etc.); it is already there
- no `!important` unless you can name the rule you are beating
- no remote stylesheets or web fonts; use the fonts in the design system
- keep the whole page under roughly 20 KB of CSS

Layout helper you can rely on: `.aiwp-container` centres content at `--aiwp-container-max` with sensible side padding.
