---
id: performance
version: 1
category: review
description: Keep the output closer to hand-written code than to a page builder.
---

Prefer custom-code-like frontend output.

Avoid:

- unnecessary DOM wrappers (a `div` that only holds one `div`)
- decorative markup with no purpose
- duplicated CSS — if two sections share a card, share the class
- large libraries
- unnecessary animation
- layout shift: images without dimensions, fonts that swap late
- oversized images

Prefer:

- semantic HTML
- design tokens
- page-specific CSS
- WordPress responsive images
- native browser capability (`details`, `scroll-snap`, CSS grid) over a behavior
- plugin-provided behavior modules when interaction is genuinely needed

Run `performance.static_audit` on the page and fix what it reports.
