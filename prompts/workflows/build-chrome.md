---
id: build-chrome
version: 1
category: workflow
description: Order of operations for the shared header and footer.
---

1. `site_get_context` and `site_get_capabilities`.
2. `design_get_system` — the header and footer must use the same tokens as the
   pages.
3. `site_get_chrome` — if one already exists, read it and change it rather than
   starting again. Keep field ids that already hold content.
4. Decide the navigation from the pages that exist, not from a guess. The site
   context lists them.
5. `site_set_chrome` with `sections`, `content`, `header`, `footer` and `css`.
6. Open a page that uses `chrome: "site"` and look at it. If no page uses it yet,
   set `chrome: "site"` on one with `page_update` first.
7. Check it at a phone width. The header is the part most likely to break.

Existing pages do not switch automatically. A page uses the shared chrome only
when its own `chrome` is set to `site`.
