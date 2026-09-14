---
id: redesign-page
version: 1
category: workflow
description: Replace the design of an existing page without losing its content.
---

1. `page.get` for the current schema, content, template, CSS and version.
2. Open the current preview and look at it. Name what is wrong before changing anything.
3. Keep every field `id` that already holds content. Reuse the schema where you can; adding fields is cheap, moving ids is a migration.
4. Write the new template and CSS.
5. `page.update` with `expected_version` set to the version you read in step 1. If you get `AIWP_VERSION_CONFLICT`, re-read the page — someone else changed it.
6. Preview, critique, refine.
7. Do not publish unless the user asks.

If the content should change too, send `content` as well. If only the words change, use `page.update_content` instead — it does not create a new template version.
