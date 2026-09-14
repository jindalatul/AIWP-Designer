---
id: build-page
version: 3
category: workflow
description: The exact order of operations for creating a new page.
---

## Is this the site's first page?

It changes what you do.

**First page.** You are not just building a page. You are inventing the language
the rest of the site will speak: how it feels, how it separates things, what its
buttons do, what it uses images for. Design it properly, then lift that language
out of it so page two can speak it too:

1. run the `create_design_system` workflow first — tokens and the seven `style`
   decisions
2. build this page
3. `design_extract_components` on it — what you just invented is in its CSS
4. name the ones that are really components, write each a `use_when` line,
   store them with `design_set_components`
5. move the component CSS out of the page CSS, so it is not defined twice

Skipping step 3 is how a site ends up with a beautiful homepage and eleven pages
that look like a different site.

**Every page after that.** You are speaking a language that already exists.
Read it before you design: `design_get_system` for the tokens and `style`,
`design_get_components` for the pieces. Build out of them.

## Order

1. `workflow_prepare` with `workflow_type: "build_page"` — already done if you are reading this.
2. `site_get_context` — what site this is, what pages exist, and **`page_types`**.
3. **If a page of the same kind already exists, read it with `page_get` before you design anything.** Two service pages that look different is the most visible way a site falls apart. Match it. Only depart from it where this page genuinely has a different job.
4. `site_get_capabilities` — which field types and behaviors exist right now. Never assume.
5. `design_get_system` — tokens and the `style` decisions you must design inside.
6. `design_get_components` — the pieces you must build from.
7. If there is a brief, record it with `page_set_brief` before you design. Then plan the page: goal, audience, conversion, direction, story. In `design_metadata` set `page_type`, so the next page of this kind can find it, and `target_words` when a brief gives one, so `performance_static_audit` can say when the page is short.
8. Decide the field model, then the markup, then the CSS. Page CSS lays this page out; it does not redefine components.
9. `page_create` with the full package: `page`, `design_metadata`, `sections`, `content`, `template`, `css`, `behaviors`, `chrome`.
10. Read the response. Validation warnings are real; fix them.
11. `design_review` on the page. Fix what it finds — that is the machine pass.
12. Open `preview_url` in your browser and actually look at the page. Check a phone width too.
13. Critique it (see design-critic) and `page_update` with the improvements. One or two rounds is normal.
14. **`page_look`.** Read the page back. Everything before this checks the
    parts — the template parses, the CSS is scoped, the schema is sound — and
    all of that passes on a page with a band of empty space in the middle of
    it, because none of it looks at the page. `page_look` returns what the page
    actually says, in order, and the things that only exist once it has been
    rendered: a field the template prints that was left empty, a sentence said
    twice, standing placeholder text, links that go nowhere. Read the `reading`
    list as a visitor would, not as a checklist. Fix what it finds and look
    again. `page_publish` refuses until this has been done for the version
    being published.
15. `seo_audit`, then `performance_static_audit`. Fix what they report. If it says the page is short of its target, the missing words are whatever the brief asks for that the page does not yet cover. Find that, and write it. Do not pad what is already there.
16. If this is the site's homepage, make sure it is the front page. A page named `Home` claims the site root on its own while nothing else has; otherwise call `site_set_front_page`. `site_get_context` reports whether a front page is set and published. A site left showing the default blog listing at its root is not finished.
17. Tell the user what you built and what you would still improve. Do not publish. Publishing is the user's decision, through `page_publish` with `confirm_publish: true`.

The first generated design is not assumed to be final. A build that never read
its own page back is not finished — and `page_publish` will say so.

## When this page needs something new

Add it to the library with `design_set_components` and use it from there, rather
than writing it into this page's CSS where no other page can reach it. That is
how the library grows — from real pages that needed something, not from guessing
up front.
