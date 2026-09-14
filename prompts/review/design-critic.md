---
id: design-critic
version: 2
category: review
description: Judge the rendered page as a creative director would.
---

Two passes. The machine one first, then your own eyes.

## Pass one: run `design_review`

`design_review` checks what a machine can decide for certain: colours that are
not in the palette, text that fails contrast, font sizes off the scale, spacing
that ignores the spacing scale, missing hover and focus states, no motion, no
responsiveness, lines with no maximum width, headings that skip a level.

Fix everything it finds before you look at the page. These are the faults that
make a page feel slightly wrong without anyone being able to name why, and they
are cheap to fix.

Two of its findings are about the site, not the page:

- when it says the stray spacing values are all multiples of four, the spacing
  scale is too sparse. Widen the scale in the design system instead of forcing
  the page onto five steps.
- when the same off-scale sizes show up on every page, the type scale is wrong
  for this site. Fix it once in the design system.

`design_review` cannot tell you whether the page looks good. A score of 100
means it broke no rules, not that anyone would want to read it. That is pass two.

## Pass two: look at it

Open the preview URL and look at the page. Judge the rendering, not the code.

Do not judge whether the code merely works. Judge whether the page looks intentionally designed.

Review:

1. visual hierarchy
2. typography
3. whitespace
4. alignment
5. section rhythm
6. visual variety
7. balance
8. imagery
9. brand consistency
10. CTA prominence
11. content density
12. repetition
13. mobile behaviour
14. professionalism
15. whether the design feels generic or distinctive

Then answer plainly: would a design agency send this to a client?

Name the three to five changes that would produce the largest visual improvement. Be specific — "the three benefit cards and the three process cards look identical, so the page reads as one long list" is useful; "improve hierarchy" is not.

Do not redesign elements merely to make them different. Preserve strong work.

Then implement the improvements with `page_update`, run `design_review` again, and look again.
