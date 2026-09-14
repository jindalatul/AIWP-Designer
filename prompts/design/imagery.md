---
id: imagery
version: 1
category: design
description: Where images come from, when to draw one yourself, and how to place it.
---

A page made only of text, boxes and colour reads as a template. Images are what
make it look like a real site. You can put them there yourself.

## Getting an image onto the site

`media_search` finds what is already in the library. Use it first — never upload
the same thing twice.

`media_import` adds a new one. Give exactly one source:

| Source | Use it for |
|---|---|
| `svg` | anything you can draw: hero backgrounds, patterns, icons, diagrams, charts, abstract shapes, logos |
| `url` | a real photo at a public https address, or an image from the client's existing site |
| `data` | base64 of a file the person gave you |

`alt` is required every time. Write what the image shows, for someone who
cannot see it. Not "hero image". Not the file name.

The tool returns an attachment `id`. Put that id in an `image` field. Then the
template prints it:

```
<img src="{{image_url:hero.image}}"
     srcset="{{image_srcset:hero.image}}"
     sizes="(max-width: 720px) 100vw, 600px"
     alt="{{image_alt:hero.image}}"
     width="{{image_width:hero.image}}"
     height="{{image_height:hero.image}}"
     loading="lazy" decoding="async">
```

Never put an external URL straight into a template. It breaks when that site
changes, and it leaks your visitors to someone else's server.

## Draw it yourself first

SVG is the one you will reach for most. It is sharp at any size, it is a few
kilobytes, it matches the design system exactly because you pick the colours,
and it needs nobody's permission.

Good SVG jobs:

- a hero background: a soft gradient, a grid, a blurred blob, a diagonal band
- section dividers and corner flourishes
- a simple diagram of a three-step process
- icons for a feature list, all drawn in one consistent style
- a bar or line chart of a real number you were given
- an abstract stand-in where a screenshot would go

Rules for SVG you write:

- give the root a `viewBox`, and no fixed `width` or `height`
- use the design system's colour values, not new ones
- keep it under about 200 shapes; a huge path list is a slow page
- no `<script>`, no `<style>`, no `<a>`, no external references — they are
  stripped out before the file is saved, and the tool tells you what it dropped
- real words belong in HTML, not in `<text>`. Text inside an SVG cannot be
  selected, searched, translated or read well by a screen reader. Use `<text>`
  for a chart label, never for a headline.

## When to use a photo

A photo earns its place when the subject is a real thing: a person, a place, a
product, a team, a screenshot of the actual software. For anything conceptual,
a drawing you made will look more deliberate than a stock photo of a handshake.

If you use a photo:

- ask for one, or use an image the client already has, before reaching for stock
- check you are allowed to use it — say so when you are not sure
- crop and size it for the slot: a hero needs about 2400px wide, a card 800px,
  an avatar 200px. A 6000px photo in a 400px card is the most common reason a
  page feels slow.

## How many

Most pages need fewer than you think.

- hero: one strong image, or none and a confident type layout instead
- every third or fourth section: something to break the rhythm of text blocks
- never an image in every section — that reads as a slideshow, not a page

An empty image field must still look right. Design the section so it works with
the picture missing, then add the picture.
