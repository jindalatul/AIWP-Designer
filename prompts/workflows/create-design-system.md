---
id: create-design-system
version: 2
category: workflow
description: Decide the site's visual language before any page is built.
---

Work in this order. Each step needs the one above it.

1. `site_get_context` — name, description, existing theme, homepage URL, onboarding answers.
2. If a real site exists, open its homepage in your browser and read its visual language (see brand-analysis). If it does not, work from the onboarding answers and what the business actually is.
3. **Decide, in words, before any value.** The seven `style` decisions: personality, layout, surface, motion, buttons, imagery, signature. Write them as instructions to your future self — every page reads them back. There is no correct answer to any of them, only a choice that then gets kept.
4. **Derive the values from the decisions.** Tokens, a nine-step spacing ladder, a type ratio you picked on purpose. Not defaults you did not think about.
5. `design_create_system` with `design_system` (including `style`) and `global_css`. Foundations only in the CSS — not components.
6. Report the decisions and the palette in a few lines, and say what you decided *against*. A choice with no rejected alternative was not a choice.

Then either:

- **design the components now** (see component-library) with `design_set_components`, or
- **build the first page, then lift the components out of it** with
  `design_extract_components`. Often better: you find out what the site actually
  needs by building something real, rather than guessing a library up front.

Either way the library exists before page two. A site whose second page invents
its own button already has two buttons.

This replaces the active design system for the whole site. Existing pages keep
rendering; they pick up the new tokens because they reference variables.
