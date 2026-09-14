---
id: accessibility
version: 1
category: review
description: Deterministic accessibility checks before finishing.
---

Check and fix:

- exactly one `h1`; no skipped heading levels
- every `img` has a meaningful `alt` (decorative images get `alt=""`)
- text contrast at least 4.5:1, and 3:1 for large text
- every interactive thing is a `button` or an `a` with a real `href`
- focus is visible on every interactive element
- link text makes sense alone — not "click here", not "read more" repeated eight times
- accordions and tabs use the declared behaviors so they get correct ARIA
- nothing important is conveyed by colour alone
- animation respects `prefers-reduced-motion` (the plugin baseline handles reveal; anything you add must too)
- tap targets at least 44×44px
