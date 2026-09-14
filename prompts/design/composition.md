---
id: composition
version: 1
category: design
description: Concrete layout patterns that hold up, with the CSS that makes them.
---

Patterns worth reaching for. Each is scoped to your page automatically, so plain
class names are fine.

## Asymmetric hero

Display type on the left, standfirst dropped to the baseline on the right, a rule
under both, then a full-width image.

```css
.hero__type { display: grid; grid-template-columns: minmax(0,7fr) minmax(0,4fr); gap: 48px; align-items: end; }
.hero__display { font-size: clamp(2.8rem, 8.4vw, 7.6rem); line-height: .94; letter-spacing: -.03em; }
.hero__meta { display: flex; justify-content: space-between; border-top: 1px solid var(--aiwp-color-border); padding-top: 22px; }
```

## Index rows instead of cards

For projects, services, case studies, plans, releases. Rows with hairlines read
as editorial; the same content as cards reads as a template.

```css
.index__row a { display: grid; grid-template-columns: minmax(0,5fr) minmax(0,3fr) auto; gap: 16px; align-items: baseline; padding: 26px 0; text-decoration: none; transition: padding-left .28s ease; }
.index__row { border-bottom: 1px solid var(--aiwp-color-border); }
.index__row a:hover { padding-left: 14px; }
```

## Staggered sequence

A three-step process where the columns step down the page instead of sitting in a
row.

```css
.steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 48px; }
.step:nth-child(2) { margin-top: 48px; }
.step:nth-child(3) { margin-top: 96px; }
.step::before { content: counter(step, decimal-leading-zero); }
```

## Figure strip

```css
.figures { display: grid; grid-template-columns: repeat(4, 1fr); gap: 32px; }
.figure { border-top: 1px solid var(--aiwp-color-text); padding-top: 18px; }
.figure dt { font-size: clamp(2.4rem, 5vw, 4.2rem); line-height: 1; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
```

Pair it with `data-aiwp-behavior="counter"` and `data-aiwp-counter-to`.

## Statement block

One idea, set large, with a lot of air and a small attribution.

```css
.statement { display: grid; grid-template-columns: minmax(0,3fr) minmax(0,9fr); gap: 48px; padding-block: clamp(80px,12vw,180px); }
.statement p { font-size: clamp(1.35rem, 2.7vw, 2.15rem); line-height: 1.34; max-width: 26ch; }
```

## Closing band

One dark full-width section, used once, at the end.

```css
.close { background: var(--aiwp-color-primary); color: #fff; }
.close__in { padding-block: clamp(80px,12vw,170px); }
```

## Micro-label

The small uppercase word above a section heading. Cheap, and it makes a page look
edited.

```css
.label { font-size: .74rem; letter-spacing: .14em; text-transform: uppercase; color: var(--aiwp-color-muted); }
```

## Sticky header that wakes up on scroll

```html
<header class="bar" data-aiwp-behavior="sticky">
```

```css
.bar { background: rgba(255,255,255,.82); backdrop-filter: blur(10px); border-bottom: 1px solid transparent; transition: border-color .3s; }
.bar.is-stuck { border-bottom-color: var(--aiwp-color-border); }
```

The `sticky` behavior adds `is-stuck` once the header leaves the top.

## Responsive rule of thumb

Two breakpoints usually carry a page: `64rem` collapses multi-column grids to
one, `40rem` drops padding, hides the desktop nav and unstacks flex rows. Reset
any `nth-child` offsets at the first breakpoint or the stagger becomes a mess.
