---
id: responsive-design
version: 1
category: design
description: Make the small-screen page a design, not a collapse.
---

Responsive design must be intentional rather than desktop CSS that merely collapses.

Consider:

- heading scale — a 64px desktop headline is not a 64px phone headline; use `clamp()`
- text measure and horizontal padding
- section spacing, which usually needs to shrink faster than type
- image crop and aspect ratio
- content order — what should come first on a phone is often not the desktop order
- card stacking, and whether a 3-up grid should become 1-up or a scroll strip
- button width and touch target size (44px minimum)

Do not hide important content simply because space is limited.

Avoid horizontal overflow. Any wide element (table, code, diagram) scrolls inside its own container, never the page.

Use a small number of meaningful breakpoints. Two is usually enough: around 48rem and around 64rem.
