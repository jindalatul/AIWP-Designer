---
id: component-library
version: 1
category: design
description: Design the set of pieces this site is built from, before building any page.
---

A site looks like one site when every page is made of the same pieces. It looks
like several sites when each page invents its own.

So before the first page: design the components for **this** business, and write
them to the site with `design_set_components`.

## What you are making

Not a catalogue to pick from. There is no catalogue. You are designing these,
now, for this business, from the decisions you just recorded in `style`. A
funeral director and a trampoline park should not end up with the same card.

Each entry:

```json
{
  "id": "stat",
  "name": "Stat block",
  "use_when": "A single number that carries weight. Never more than four in a row.",
  "css": ".stat { ... } .stat__figure { ... } .stat__label { ... }",
  "markup": "<div class=\"stat\"><p class=\"stat__figure\">4.2x</p><p class=\"stat__label\">…</p></div>"
}
```

**`use_when` is the important field.** A library without it is a pile. The next
page — and the next person — chooses by reading that line, so write when to
reach for it *and* when not to.

## How many, and which

Ten to twenty is a working library. Under five and pages will still invent
things. Over forty and nothing gets reused because nothing can be found.

Think about what this site actually needs to say, then make the pieces that say
it. A law firm needs a way to show a case result and a person. A shop needs a
product and a price. A SaaS product needs a feature and a plan. Start there, not
from a list of generic blocks.

Whatever you build, a site needs at minimum a way to:

- start a page
- make one thing look more important than the things around it
- show several things of the same kind without three equal cards
- carry a number or a fact
- let someone say something in their own words
- ask for the click

How each of those looks is entirely yours.

## Rules

- style components with the design tokens, never raw values
- every component that can be hovered or focused defines both states
- a component is responsive on its own; a page should not have to fix it
- name classes for what the thing **is**, not what it looks like: `.quote`, not
  `.grey-box`
- keep them independent — a component must not need a particular parent

## Afterwards

Pages are then composed from this list. Page CSS is for arranging *that page*,
not for defining another card.

When a page needs something the site does not have, add it here with
`design_set_components` and use it. That is how the library grows: from real
pages that needed something, not from guessing up front. `design_review` flags a
page that builds its own pieces instead.
