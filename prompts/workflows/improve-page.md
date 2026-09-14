---
id: improve-page
version: 1
category: workflow
description: A critique-and-fix pass on a page that already exists.
---

1. `page.get`, then open the preview.
2. Look at it at desktop width and at roughly 390px wide.
3. Critique it (see design-critic), then run `performance.static_audit`.
4. Pick the few changes with the largest visual payoff. Do not rewrite what already works.
5. `page.update` with `expected_version`.
6. Preview again and confirm the change actually improved it. If it did not, say so.
