---
id: redesign-section
version: 1
category: workflow
description: Rework one section and leave the rest of the page alone.
---

1. `page.get` and find the section in the schema and the template.
2. Change only that section's markup and only the CSS rules belonging to it.
3. Keep the surrounding sections byte-identical. Send the whole template back, with only that part different.
4. `page.update` with `expected_version`.
5. Preview and check the section still sits correctly against its neighbours — rhythm is a property of the whole page, not the section.
