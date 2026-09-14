# Changelog

All notable changes to AIWP Designer.

## [0.12.0] — 2026-09-14

Built a three-page site cold from a written specification and fixed
everything the build ran into. Nine faults. Three of them were invisible
— nothing looked broken, so nobody would have found them.

### Fixed
- **Every page scrolled sideways on a phone.** The baseline set no
  `box-sizing`, so the most ordinary rule a page can write —
  `.wrap { width: 100%; padding-inline: 26px }` — came out exactly 52px
  wider than the screen. Every brief this plugin is handed says the page
  must not scroll horizontally, and every page that wrote that rule broke
  it.
- **A modal said `aria-modal="true"` and let Tab walk straight out.** The
  markup told a screen reader nothing outside the dialog existed while the
  keyboard went there anyway. Tab and Shift+Tab now wrap inside it.
- **A skip link had nothing to land on.** Every site is asked for one and
  the rendered `<main>` carried no id, so the guess landed on nothing — a
  skip link that does not skip, which is worse than none because it looks
  like the requirement was met. It is `id="aiwp-main"` now.
- **A homepage could be published and never get the site root.** The
  intent was recorded and only `page_publish` honoured it. Publish the
  page any other way and the root went on serving the blog listing, while
  `site_get_context` reported no problem at all.
- **`behaviors.js` was cached by plugin version.** The baseline CSS was
  moved to file time once, with a comment saying the version had sat still
  through four releases and every browser kept the old file. The
  JavaScript, the repeater script and the code editor were left behind. A
  behaviour fixed without a version bump reached nobody.
- **`scroll-behavior` was refused as Internet Explorer's `behavior:`.**
  The plugin's own prompt asks every site for a reduced-motion block
  containing it, so the validator refused the stylesheet it had asked for.
- **`page_update` could not change a title,** so `seo_audit` reported a
  title that says nothing and there was no way to act on it except by hand
  in wp-admin.
- **`page_set_brief` replaced the brief instead of merging it.** Sending
  one field wiped the description, the questions and the concepts, and the
  next audit went quiet because there was nothing left to check against.
- **A menu label that matched its page title was dropped,** so renaming
  three pages for search silently rewrote the navigation to match. And
  `site_set_menu` built a second menu for a location that already had one.

### Changed
- `tabindex` is allowed on a template, and only as `-1`. The plugin's own
  modal focuses its dialog and could not, because no template could write
  it. Any other value rewrites the tab order of the whole page.
- Two review findings stood down where their advice was wrong: a phone
  number on the contact page is not the header's call to action asked
  twice, and a header's logo mark is not a page component.

## [0.11.0] — 2026-09-14

The 0.10.0 release could check that a page was safe. It could not tell whether
the page was any good, and in two places it stopped the owner editing their own
site. This release is mostly that: things a person would have noticed, that no
check did.

### Fixed
- **A page with a repeater could not be saved from wp-admin at all.** A
  submitted repeater carries three things that are not rows somebody filled in:
  the hidden template row JavaScript clones, the row counter, and sub values
  named after their ACF field key rather than their field name. `update_value`
  knew all three. `validate_value` knew none of them, so it counted a phantom
  empty row and read every real row as blank — "allows at most 3 row(s)" on a
  field holding exactly three, and "is required in row 1" on a row full of
  text. This was true on every site the plugin has ever built. Storing content
  in fields exists so the owner can edit it without us; that did not work.
- **Every page ever rendered had two `<main>` landmarks**, because the renderer
  wrapped the template's own one in a second.
- **A template using a behavior did not always get the script that runs it.**
  An element marked `reveal` without a matching manifest entry stayed at zero
  opacity forever — content that was there and could not be seen. Behaviors are
  now read from the markup, so the script always loads.
- **A URL containing a null byte or a tab passed validation and then rendered
  differently**, because DOMDocument truncates an attribute at the null byte
  and the renderer does not. The validator and the browser have to read the
  same string or the whole safety model is decoration.
- **`page_get` returned the scoped CSS, not the CSS that was written.** Reading
  a page and sending it back therefore polluted the stored copy a little more
  each time.
- **An argument in the wrong object was silently dropped.** `page.chrome` did
  nothing and said nothing. Unknown arguments are now refused by name, nested
  ones included.
- **A call that changed nothing reported success.** It now says why, or does
  the thing.

### Added
- **`site_stage`** — what stage this site is at and what comes next, so a
  session that starts cold does not start in the middle.
- **Design review gained the faults that make competent work look amateur**:
  headline leading left at body settings, padding heavier on one side than the
  other, blocks that were meant to line up and miss by a few pixels, and motion
  that is not consistent with itself.
- **The same call to action twice** — once in the page and once in the footer —
  is now noticed, by comparing what actually renders.
- **`page_look` findings are part of the step nobody can skip**, so the craft
  report arrives with the mandatory read-back rather than beside it.

### Changed
- **"Chrome" is now "header and footer"** in every tool, prompt and screen. It
  was our word, not the owner's.
- **The header and footer are designed and reviewed like a page**, not treated
  as furniture that appears by itself.
- **Three review findings that fired on correct work were removed or narrowed.**
  A comparison table repeating a fact is not a page repeating itself; a page
  that chose to be quiet is not a page nobody looked at. A check people learn
  to ignore is worse than no check.

## [0.10.0] — 2026-09-14

### Added
- **`page_look`, and publishing now insists on it.** Every check up to now read
  the parts: the template parses, the CSS is scoped, the schema is sound. All
  of that passes on a page with a band of empty space in the middle of it,
  because none of it looks at the page. `page_look` reads the finished page
  back — what it says, in the order a visitor meets it, plus the things that
  only exist after rendering: a field the template prints that was left empty,
  the same sentence printed twice, standing placeholder text, links that go
  nowhere, a page that was built but never written. `page_publish` refuses
  until the version being published has been read back, so this happens rather
  than being hoped for.

  Run over six real pages it found one thing, and the thing was true: a field
  that exists and that the template never prints.

## [0.9.1] — 2026-09-14

### Security
- **A link could carry a scheme the check did not see.** A browser throws away
  tabs, newlines and other control characters before it works out a URL's
  scheme, so `href="java&#9;script:alert(1)"` runs. The check read the raw
  string, so it did not. URLs are now read the way the browser reads them, and
  the scheme must be one of http, https, mailto or tel — an allowlist, because
  a list of the bad ones is only ever as long as the last thing somebody
  thought of. The same normalising is applied to `url()` in CSS.
- **A null byte made this plugin's checks and the browser read different
  markup.** Everything here rests on one assumption: what the validator
  inspects is what the visitor gets. The validator inspects markup with
  DOMDocument, which truncates an attribute at the first null, so
  `href="jav\0ascript:alert(1)"` arrived as `href="jav"` and looked harmless —
  while the renderer printed the template as written and the browser dropped
  the null and followed the link. Control characters are now refused outright
  in templates and in CSS, which closes the class of trick rather than one
  spelling of it. Tabs and line breaks are ordinary formatting and still fine.

Both needed an MCP token that can edit pages. An editor holding one could have
reached an administrator's session that way, so this is worth updating for.

## [0.9.0] — 2026-09-14

### Added
- **A site can be moved to another WordPress.** AIWP Designer > Move this site
  writes one zip holding every page, the words in them, the design system, the
  header and footer, the menus, the articles and the images they use, and the
  import puts it back together on the other install. Attachment ids, menu
  targets and the front page are rewritten on the way in, because none of those
  numbers mean the same thing on the other site. A page travels under the id it
  already had, so importing the same file twice updates the same pages instead
  of making a second copy of the site. MCP tokens and the signing secrets never
  travel.
- **Motion is decided once, like the type scale.** `motion` tokens —
  `fast`, `base`, `slow`, `ease` and `travel` — come out as
  `--aiwp-motion-*`, and the reveal behaviour that ships with the plugin now
  borrows them instead of moving at a speed the plugin picked. An easing curve
  may be a real `cubic-bezier()` or `steps()`; every other shape is refused,
  because a bracket is how CSS injection gets in.
- **design_review notices a page that types its own timings.** Motion was never
  missing — one site had .16s, .18s and .22s across three pages, small enough
  that nobody could name it and enough to make the site feel assembled rather
  than designed.

### Fixed
- **Two fields could be stored in the same place.** Section `hero` with a field
  `sub_title` and section `hero_sub` with a field `title` both came out as
  `hero_sub_title`, so whoever filled in the second box silently wiped the
  first.
- **Uninstall left a trail**: seven options, four role capabilities, every
  cached template and each person's rows-per-page setting.
- **A failed write said nothing useful.** The plugin already worked out which
  user owns the folder, which user PHP runs as and the exact `chown` that fixes
  it, and two callers threw that away.
- `readme.txt` said Stable tag 0.1.0 while the plugin said 0.8.0, which wp.org
  refuses outright.

## [0.8.0] — 2026-09-13

### Added
- **The site owner can add their own sections and fields.** A Fields screen per
  page, reached by a button under the page title. Sections a person adds are
  marked as theirs, and the AI cannot delete them when it rebuilds the page. A
  field the template does not print still appears on the page, in plain ruled
  rows built from the site's own tokens, so filling in a box is never a thing
  that does nothing.
- **Removing asks for a typed word**, not a tick. Removing a section the design
  uses asks for the section's own id, because that deletion leaves holes in a
  live page.
- **The template line is shown beside every field.** A box labelled "Eyebrow"
  is stored as `hero.label`, and nobody can guess that formatted text needs
  `{{html:}}` while an image needs `{{image_url:}}`. The line to type now sits
  next to the field on the Fields screen, where clicking copies it, and under
  the box on the value form. A repeater copies the whole `{{#each}}…{{/each}}`
  block, not just the opening tag.
- **Template & CSS is one click from the fields**, on both screens.

### Fixed
- **Two fields could be stored in the same place.** Section `hero` with a field
  `sub_title` and section `hero_sub` with a field `title` both came out as
  `hero_sub_title`, so whoever filled in the second box silently wiped the
  first. The schema now refuses the pair and names both paths; the Fields
  screen never offers a colliding name.
- **Uninstall left a trail.** Seven options, four role capabilities, every
  cached template and each person's rows-per-page setting stayed behind
  forever. Caches and capabilities now always go, because they mean nothing
  without the plugin; pages, files and the design still only go when the site
  owner has ticked the box that says so.

## [0.7.1] — 2026-09-13

### Fixed
- **CSS opened minified in the code editor.** The scoped stylesheet the browser
  downloads has no line breaks in it, and pages saved before the authored copy
  was kept had nothing else to show. Minified CSS is now laid out for editing —
  one declaration per line, indented inside media queries — while the file that
  ships to visitors stays compact. The formatter only adds line breaks and
  indentation, so it cannot mangle `:hover`, `url(data:…)` or a quoted value.

### Added
- **Full screen in the code editor.** The admin menu, admin bar and page chrome
  get out of the way and the editor fills the window. Esc leaves it, and the
  choice is remembered.
- The editor is much taller by default — it sizes to the window instead of a
  fixed 620px.

## [0.7.0] — 2026-09-13

### Added
- **A code editor for the template and CSS**, at AIWP Designer → Code, and from
  the Code row action on any page. Built on WordPress's own CodeMirror, so there
  is no new dependency and it matches the rest of wp-admin.
  - A custom highlighting mode colours `{{text:…}}`, `{{#if:…}}` and `{{#each:…}}`
    so the template reads as a language rather than HTML with noise in it, and
    marks a malformed directive in red.
  - **Check** runs the real server-side validators and reports each problem
    against its file and line, with a marker in the gutter and a link that jumps
    to it.
  - Save refuses anything that does not validate, and a save that succeeds
    creates a version — so a hand edit can be rolled back like any other change.
  - PHP is refused in the browser for a fast message, and refused again on the
    server, which is the guard that actually matters.
  - **No JavaScript editor.** The reason an AI can be trusted with a live site is
    that nothing it writes is executed, and a box that runs code would end that.
- Page CSS is now stored as written as well as scoped, so the editor shows what
  you typed rather than the rewritten selectors.

## [0.6.2] — 2026-09-13

### Changed
- **The header and footer content is now edited on its own admin screen.**
  AIWP Designer → Header & Footer shows the actual fields — product name, footer
  tagline, buttons, contact details, link columns — instead of a button pointing
  at a hidden draft page. The template markup and CSS moved into a collapsed
  read-only section, since only the AI writes those.

## [0.6.1] — 2026-09-12

### Fixed
- **Editing a menu in Appearance → Menus could silently stop working.** WordPress
  keeps menu-to-location assignments in a theme mod, which it clears when the
  Menus screen is saved without the location box ticked — and which a theme
  switch empties outright. The site then fell back to listing every published
  page, with nothing to say why. The plugin now remembers the assignment itself,
  keeps using that menu, and repairs the theme mod.
- **`site_set_menu` deleted and recreated every item.** That churned item ids and
  destroyed anything the site owner had changed on the Menus screen. Items are
  now updated in place; only items genuinely removed from the list are deleted.
- The Header & Footer screen now shows which menu is in each location, and says
  plainly when a location is empty and pages are being listed instead.
- The form rate limit was 8 submissions per address per hour, which is too tight
  for an office or a mobile network behind one address. It is now 30, and
  filterable through `aiwp/form_rate_limit`.

## [0.6.0] — 2026-09-12

### Changed
- **Navigation now uses WordPress's own menu system.** The plugin used to carry
  its link list in a repeater, which meant the site had two menu systems and
  neither was the one a WordPress admin knows. Templates place
  `<nav data-aiwp-menu="primary"></nav>` and the plugin fills it from
  Appearance → Menus. The current page is marked with `is-current` and
  `aria-current="page"`, and sub-menus work, exactly as in a theme.

### Added
- Menu locations `primary` and `footer`, registered with `register_nav_menus()`.
- `site_set_menu` builds a real WordPress menu and assigns it to a location, so
  the site owner can then edit it under Appearance → Menus without the AI. Items
  can be `page_id` (the link follows a slug change) or a plain `url`.
- `site_get_menus` reports what is assigned and what is in it.
- When nothing is assigned to a location, the published pages are listed instead,
  so a header is never empty.
- The validator rejects an unknown menu location.

## [0.5.0] — 2026-09-12

### Added
- **A homepage now becomes the WordPress front page on its own.** A page named
  `Home` (by title or slug) claims the site root while nothing else has claimed
  it, so a freshly built site does not sit on the default blog listing. An
  existing front page is never replaced by accident, and `page.front_page` on
  `page_create` or `page_update` decides it explicitly either way.
- The claim is recorded at build time and applied at publish, because pointing
  the site root at a draft would show visitors a not-found page.
- `site_set_front_page` to move it, or to hand the root back to the blog listing
  with `page_id: 0`.
- `site_get_context` reports the front page, whether it is an AIWP page, and
  whether it is published.
- The admin marks the front page with WordPress's own "Front Page" label, and
  will not offer to trash it.

## [0.4.0] — 2026-09-12

### Added
- **AI Pages is now a real WordPress list table.** Status views with counts,
  search, sortable columns, pagination, Screen Options for rows per page, row
  actions (edit fields, preview, open page, trash) and a bulk Move to Trash —
  the same furniture as the native Pages screen.
- Columns for AIWP version, design system version, chrome mode and page goal,
  with a warning when a page was built against an older design system.

### Changed
- **AIWP pages no longer open in the block editor.** The content lives in fields
  and the layout lives in a template Gutenberg cannot see, so the screen offered
  an editing surface that did nothing. The edit screen is now the title, the
  fields, and a line saying where the design lives. No block sidebar, and no
  "Choose a pattern" prompt.
- The comments, trackbacks, excerpt, custom fields and page attributes boxes are
  hidden on AIWP pages, since none of them apply.
- The shared header and footer holder can no longer be published or trashed from
  the admin — both would break the chrome on every page.

## [0.3.1] — 2026-09-12

### Fixed
- **Changing a schema and writing content in the same call silently dropped
  fields.** ACF caches registered fields for the life of a request, so a field
  added by `page_update` or `site_set_chrome` was written against a stale cache
  and lost, while a field that moved kept its old value. The caches are now
  cleared and the groups re-registered before content is written.
- Chrome CSS could not style the header and footer elements themselves.

## [0.3.0] — 2026-09-12

### Added
- **Forms.** The AI declares what a form asks for; the plugin owns the markup,
  the action, validation, storage, notification and spam handling. It never
  writes `<form>`. Ten field types, a six-column width system, errors shown by
  the field, typed answers kept on failure, honeypot and timing checks, per-IP
  rate limiting, entries stored in `{prefix}aiwp_form_entries` and readable in
  wp-admin or through the `form_entries` tool. Works with JavaScript off.
- **Shared header and footer.** `site_set_chrome` builds one header and footer
  for the whole site; pages opt in with `chrome: "site"`. The content lives on a
  hidden WordPress page, so it is editable through ordinary ACF fields, and the
  markup and CSS are versioned like a page.
- Admin screens for **Header & Footer** and **Form Entries**.
- `build_chrome` workflow, `core/forms.md` and `design/chrome.md` prompts.

### Fixed
- **The plugin baseline was compiled into the site's design system file**, so a
  plugin update never reached an existing site until someone re-saved the design.
  It now ships as `assets/dist/base.css` and is enqueued ahead of the design
  system.
- `page_publish` re-validated a page without its forms and refused to publish it.
- A malformed email address was reported as a missing field rather than a bad one.
- An improbably fast submission is now flagged as suspect and kept, rather than
  silently discarded — a returning visitor with autofill is not a bot.

## [0.2.0] — 2026-09-12

Found by building two real pages through MCP and looking at the result.

### Fixed
- **Images were served at 1024px into full-width slots, with no `srcset`.**
  `{{image_url}}` now returns the full-size file, and there are new
  `{{image_srcset}}`, `{{image_alt}}`, `{{image_width}}` and `{{image_height}}`
  filters. A hero image is now sharp on a retina screen.
- **A CSS comment corrupted the stylesheet.** The scoper read a comment as a
  selector, so any comment containing a comma or a brace broke the page. Comments
  are now stripped before validation and scoping, which also stops a comment
  mentioning `@import` being rejected.
- **A modal rendered open on page load.** Page CSS setting `display` beat the
  browser's `[hidden]` rule. The baseline now forces `[hidden]` to win, and a
  modal stays closed until its behavior has initialised — including when
  JavaScript never runs.
- **`scope` on `<th>` was rejected**, making accessible tables impossible. The
  element and attribute allowlists now cover tables, inline SVG, and the usual
  document semantics.
- **`design_create_system` silently reset tokens that were not sent.**

### Added
- **Web fonts.** A design system may load one stylesheet from
  `fonts.googleapis.com` or `fonts.bunny.net`, with a preconnect hint. Page CSS
  still cannot load anything remote. Typography was the biggest single limit on
  how good a page could look.
- **`design_update_system`** — merges into the current design system instead of
  replacing it, as the specification's tool list intended.
- Design tools now report `affects_pages` and warn that the design system is
  site-wide.
- `docs/building-websites.md` — a short guide for people using the plugin.

### Changed
- `design/page-designer.md` rewritten with concrete craft rules, and a new
  `design/composition.md` of layout patterns. The prompts previously said "vary
  the sections" without saying how.

## [0.1.0] — 2026-09-12

First working build.

### Added
- MCP server at `POST /wp-json/aiwp-designer/v1/mcp` with 17 tools, read-only
  resources and six workflow prompts.
- `workflow_prepare` gate: every mutating tool requires a workflow id, so the AI
  always receives the plugin's current instructions before changing the site.
- Safe `.aiwp` template language with a real parser, AST and per-context escaping.
- HTML element and attribute allowlist enforced by parsing, not substring checks.
- Page CSS validation plus automatic scoping to `[data-aiwp-page="UUID"]`.
- `aiwp_repeater` ACF field type, written against ACF's public extension API.
- Dynamic ACF field groups registered from page schemas at `acf/init`.
- Global design system: tokens compiled to CSS custom properties, versioned.
- Trusted behavior library: accordion, tabs, modal, counter, reveal, sticky,
  carousel.
- Immutable version snapshots, `{prefix}aiwp_versions` index, rollback that keeps
  history, and `expected_version` conflict protection.
- Signed short-lived preview tokens for draft review.
- Headless endpoint `GET /pages/{id}/data`.
- Static performance auditor.
- Admin: dashboard, AI pages table, design system view, brand onboarding,
  MCP connection screen with one-time token display.
- Local prompt library with frontmatter, registry, compiler and workflow manager.
