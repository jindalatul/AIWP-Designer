---
id: build-header-footer
version: 1
category: workflow
description: Order of operations for the shared header and footer.
---

1. `site_get_context` and `site_get_capabilities`.
2. `design_get_system` — the header and footer must use the same tokens as the
   pages.
3. `site_get_header_footer` — if one already exists, read it and change it rather than
   starting again. Keep field ids that already hold content.
4. Decide the navigation from the pages that exist, not from a guess. The site
   context lists them.
5. `site_set_header_footer` with `sections`, `content`, `header`, `footer` and `css`.
6. **`design_review` with no `page_id`.** That reviews the header and footer
   against the design system — off-palette colour, contrast, type scale,
   spacing scale, states. It is the same check a page gets, and a header and footer
   that nobody runs it on is how a site ends up with a careful body and a header
   that fails contrast.
7. Open a page that uses `header_footer: "site"` and look at it. If no page uses it
   yet, set `header_footer: "site"` on one with `page_update` first.
8. Check it at a phone width. The header is the part most likely to break.
9. Look at where the footer meets the page. Every page already ends with its
   own padding; a margin on top of the footer adds to it, and the result is a
   band of empty space that reads as a mistake rather than as breathing room.

Existing pages do not switch automatically. A page uses the shared header and footer only
when its own `header_footer` is set to `site`.
