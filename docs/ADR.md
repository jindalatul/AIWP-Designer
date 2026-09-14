# Architecture decision records

Every place this build departs from DOCUMENT.md, and why.

## ADR-001 — MCP tool names use underscores, not dots

**Spec:** `workflow.prepare`, `page.create`, …

**Problem:** Several MCP clients pass server tool names straight into a model API
whose tool-name rule is `^[a-zA-Z0-9_-]{1,128}$`. A dot makes the tool
unusable there.

**Decision:** Tools are named `workflow_prepare`, `page_create` and so on.
`ToolRegistry::call()` normalises `.` and `-` to `_`, so a client that sends the
dotted name from the specification still works.

**Intention preserved:** the catalogue, arguments and behaviour are unchanged.

## ADR-002 — Page CSS is scoped by the plugin, not trusted to the AI

**Spec:** "Page CSS should normally be scoped under `[data-aiwp-page="UUID"]`.
The CSS validator should detect selectors likely to leak globally."

**Problem:** Detection alone means every generated page is one forgotten prefix
away from restyling the whole site, and the AI must spend attention on
bookkeeping rather than design.

**Decision:** `CSSValidator::validate_and_scope()` parses the CSS and rewrites
every selector under the page scope. `@media`, `@supports` and `@container`
bodies are scoped recursively; `@keyframes` and `@font-face` are left alone;
`html`, `body`, `:root` and `*` collapse onto the page container. The dangerous
constructs from the specification are still hard rejections.

**Intention preserved:** page CSS cannot leak. The AI writes plain CSS.

## ADR-003 — A `Storage/FileStore` class owns the upload directory

**Spec:** names `Design/*Repository`, `Pages/PageRepository` and so on, with no
single filesystem owner.

**Decision:** one class owns the root path, atomic writes, recursive copy and
delete, and the "is this path inside the root" check. Every other class asks it
for a path. Path traversal is refused in one place rather than five.

## ADR-004 — Composer is optional at runtime

**Spec:** "Use Composer PSR-4 autoloading."

**Decision:** `composer.json` declares PSR-4 as specified, and
`src/autoload.php` provides the same mapping so the plugin runs from a plain
checkout with no `composer install`. Composer's autoloader is used when present.

## ADR-005 — The block-theme page shell does not call `get_header()`

**Problem:** `get_header()` and `get_footer()` are deprecated in block themes and
emit a notice on every request.

**Decision:** `templates/page-shell.php` calls them only for classic themes. For
block themes it emits its own document skeleton and renders the theme's `header`
and `footer` template parts through `do_blocks()`. `chrome: "blank"` behaves the
same on both.

## ADR-006 — Repeater sub-values bypass `acf_format_value()`

**Problem:** `acf_format_value()` caches per post id + field name. Every repeater
row shares a sub field name, so formatting row 2 returned row 1's value.

**Decision:** `AIRepeaterField::format_sub_value()` applies
`acf/format_value/type={type}` directly, skipping the cache.

## ADR-007 — `update_field()` returning false is not a failure

**Problem:** ACF returns false when the stored value already equals the new one,
which made unchanged content look like a write failure and rolled back updates.

**Decision:** `FieldValueManager::write_path()` treats a false return as success
when reading the value back shows it matches.

## ADR-008 — Field ids are unique per section, not per page

**Problem:** The rename guard keyed field ids page-wide, so two sections each
having a field with id `heading` looked like an illegal rename.

**Decision:** the guard keys on `section.id`. ACF keys already included the
section, so stored content was never affected.

## ADR-009 — Content is warned about, not rewritten

**Problem:** ACF `url` fields accept absolute URLs only. A page created with
`"/apply"` renders fine but cannot be saved from wp-admin.

**Decision:** `page_create` and `page_update` return a warning naming the field
and the fix. The prompts tell the AI to use `link` or `text` for relative paths.
The plugin does not silently rewrite what the AI wrote.

## ADR-010 — Phase order

**Spec:** implement one phase at a time and stop after Phase 0.

**Decision:** the operator asked for a plugin that could be connected to an MCP
client and judged on the websites it produces, so the whole working path was
built in one pass. Phase 10 (React visual editor) is not built; everything else
is. `IMPLEMENTATION_STATUS.md` records exactly what is and is not done.

## ADR-011 — Design systems may load web fonts from an allowlisted host

**Spec:** "External CSS imports must not be permitted. AI page CSS may not load
arbitrary remote stylesheets."

**Problem:** With system fonts only, there is a ceiling on how good a generated
page can look. Typography is the largest single lever on whether a page reads as
designed, and every real modern site uses a chosen typeface.

**Decision:** The *design system* — one site-wide decision, made once, by someone
with `aiwp_manage_design` — may set `typography.font_url`. It is kept only if it
is `https` and on `fonts.googleapis.com` or `fonts.bunny.net`; anything else is
dropped silently rather than stored. A preconnect hint is added. **Page CSS is
unchanged: it still cannot load anything remote, and `@import` is still a hard
rejection.**

**Intention preserved:** an individual page still cannot reach the network.

## ADR-012 — `{{image_url}}` returns the full-size file

**Problem:** It returned WordPress's `large` size, which is 1024px wide. A
full-bleed hero on a 1440px retina screen was visibly soft, which made every
generated page look cheap regardless of the design.

**Decision:** `image_url` returns the full-size URL, and `image_srcset`,
`image_alt`, `image_width` and `image_height` were added so templates emit proper
responsive images. The prompts now show the complete `<img>` pattern.

## ADR-013 — CSS comments are stripped before anything else reads the CSS

**Problem:** The scoper treated a leading comment as a selector prelude. A comment
containing a comma produced a corrupted stylesheet; one containing a brace broke
the balance check; one mentioning `@import` was rejected outright.

**Decision:** `CSSValidator` removes comments first, with a scanner that leaves
text inside quotes alone. Everything downstream — the forbidden-string check, the
brace count, the scoper — sees comment-free CSS.

## ADR-014 — The plugin baseline forces `[hidden]` to win

**Problem:** The behavior library hides things by setting the `hidden` property.
Page CSS setting `display` on the same element beat the user-agent
`[hidden]{display:none}` rule, so a modal rendered open over the whole page.

**Decision:** The compiled global CSS includes `[hidden]{display:none!important}`,
and a modal dialog is hidden by CSS until its behavior marks itself ready — so it
also stays closed when JavaScript never runs. Page authors cannot break this by
accident.

## ADR-015 — `design_update_system` merges

**Problem:** `design_create_system` replaces the whole token set, so sending only
a new accent colour reset the palette, typography and spacing to defaults.

**Decision:** `design_update_system` was implemented as the specification's tool
list intended: it merges into the current system and keeps the stored global CSS
unless new CSS is sent. Both design tools now report `affects_pages` and warn
that the design system is site-wide.

## ADR-016 — The AI declares forms; the plugin owns the markup

**Spec:** templates may not contain `<form>` or `<input>`, which left no way to
collect anything — a fatal gap for a real website.

**Decision:** forms are declarative, like behaviors. The page package carries a
`forms` array saying what to ask for, and the template marks the spot with
`<div data-aiwp-form="id"></div>`. The plugin renders the markup, sets the action
to `admin-post.php`, signs a token, validates, stores, notifies and handles spam.
The template restriction is unchanged: raw form markup is still rejected.

**Why not allow `<form>` with a forced action:** an AI-supplied form could still
carry field names that collide with WordPress internals, or ask for a password.
Declaring intent and generating the markup removes the whole class of problem.

## ADR-017 — Shared chrome content lives on a hidden WordPress page

**Problem:** with only `theme` and `blank`, every page rebuilt its own navigation.
A ten-page site meant ten copies that drift apart.

**Decision:** `site_set_chrome` stores one header and one footer, and pages opt in
with `chrome: "site"`. The content is held on a draft WordPress page marked
`_aiwp_chrome`, so it registers ACF field groups like any AIWP page and is
editable in wp-admin without a new editing surface. The markup and CSS are
versioned through the same `aiwp_versions` index. The holder page is filtered out
of every page listing.

**Alternative rejected:** an ACF options page, which is ACF Pro only.

## ADR-018 — The plugin baseline is a shipped stylesheet, not part of the design system

**Problem:** the baseline CSS was compiled into the site's `global.css` when a
design system was saved. New baseline rules — including the form styles — never
reached an existing site until someone re-saved the design system.

**Decision:** the baseline ships as `assets/dist/base.css` and is enqueued ahead
of the design system. Tokens and authored global CSS stay in `global.css`, which
is site data. Updating the plugin now updates the baseline everywhere.

## ADR-019 — Chrome CSS may match the element carrying its scope

**Problem:** the shared header and footer carry `data-aiwp-chrome`, which is the
scope. A rule written for `.aiwp-chrome--footer` was rewritten to
`[data-aiwp-chrome] .aiwp-chrome--footer` — a descendant selector that can never
match the element itself. The footer rendered unstyled.

**Decision:** `validate_and_scope_to()` takes a `match_root` flag. With it set, a
class or attribute selector is emitted twice — once concatenated with the scope
and once as a descendant. Chrome CSS uses it; page CSS does not, so page
stylesheets do not double in size.

## ADR-020 — ACF's caches are cleared when a schema changes

**Problem:** ACF keeps registered fields, field groups and loaded values in
in-memory stores for the life of a request. Changing a page or chrome schema and
writing its content in the same call — which is exactly what `page_update` and
`site_set_chrome` do — left some fields unwritten: new fields silently dropped,
and moved fields keeping their old value. `update_field()` reported success, so
nothing surfaced until the page rendered with stale content.

**Decision:** `SchemaRegistrar::refresh()` resets ACF's `fields`, `field-groups`
and `values` stores plus the local stores, fires `aiwp/register_local_fields` so
anything else can put its own local groups back, then re-registers every AIWP
group. `aiwp/refresh_acf` now calls that instead of re-registering on top of a
stale cache.

**Covered by:** an end-to-end test that adds a field, moves another, and writes
all three values in one `page_update`.

## ADR-021 — AIWP pages do not use the block editor

**Problem:** an AIWP page's words live in ACF fields and its layout lives in a
`.aiwp` template. The block editor can see neither. Opening one showed an empty
canvas, a block inserter and a "Choose a pattern" prompt — an editing surface
that does nothing useful and invites someone to add blocks that will never
render.

**Decision:** `use_block_editor_for_post` returns false for AIWP pages, and the
classic editor's content box is removed too, along with the boxes that do not
apply (comments, trackbacks, excerpt, custom fields, page attributes). What is
left is the title, the permalink, the generated field groups and a line pointing
at the preview. The shared header and footer holder is additionally protected
from being published or trashed.

## ADR-022 — The AI Pages screen is a WP_List_Table

**Problem:** the screen was a hand-rolled `<table class="widefat">` with no
search, sorting, pagination or row actions. On a site with more than a handful of
pages it stopped being usable, and it did not behave like anything else in
wp-admin.

**Decision:** it extends `WP_List_Table`, so it inherits the behaviour people
already know: status views with counts, search, sortable columns, pagination,
Screen Options, row actions and bulk actions. There is deliberately no "Add New"
button — pages come from the AI, and the screen says so instead.

## ADR-023 — A homepage claims the site root by itself

**Problem:** a site could be built page by page and still serve WordPress's
default blog listing at its root, because nothing in the flow set
Settings → Reading. Every site built this way was one forgotten setting away from
looking broken.

**Decision:** `FrontPage::wants_front_page()` gives the root to a page named
`home`, `homepage`, `index` or `front page` — by title or slug — but only while
the slot is empty. An existing front page is never taken over implicitly, a
second page named Home does not steal it, and `page.front_page` decides it
outright in either direction.

The claim is stored as post meta and applied when the page is published, since a
draft front page would serve a 404 at the site root. `site_set_front_page` moves
it later, and `page_id: 0` hands the root back to the blog.

**Covered by:** 12 unit tests on the naming rule and 14 end-to-end checks
including the draft case and the second-Home case.

## ADR-024 — Navigation uses WordPress menus, not a repeater

**Problem:** the shared header carried its links in an `aiwp_repeater`. That gave
the site two menu systems: the plugin's, and the one every WordPress admin
expects under Appearance → Menus. The repeater also could not mark the current
page, could not nest, and could not link to anything WordPress knows about —
categories, custom post types — without hand-typing a URL.

**Decision:** the plugin registers two menu locations (`aiwp_primary`,
`aiwp_footer`). A template places `<nav data-aiwp-menu="primary"></nav>` and
`MenuRenderer` fills it with `wp_nav_menu()`, adding predictable classes
(`aiwp-menu`, `aiwp-menu__item`, `aiwp-menu__link`, `is-current`) so page CSS can
style it. `site_set_menu` lets the AI build the menu once; after that it is an
ordinary WordPress menu the owner edits without asking anyone.

When a location has no menu assigned, the published AIWP pages are listed
instead, so a freshly built site never shows an empty header.

**Intention preserved:** the AI still never writes navigation markup — it places
a location and the plugin renders it, the same pattern as forms and behaviors.

## ADR-025 — The plugin remembers menu assignments itself

**Problem:** WordPress stores which menu is in which location as a theme mod.
That has two consequences nobody expects: saving the Menus screen without the
location box ticked writes `0`, and switching theme empties it entirely. Either
way `wp_nav_menu()` falls through to `fallback_cb`, so a site that had a
carefully built header suddenly listed every published page instead, with no
error and nothing in the admin to explain it.

**Decision:** `site_set_menu` records the assignment in an `aiwp_menu_locations`
option as well as the theme mod. `MenuRenderer::menu_id_for()` prefers the theme
mod, falls back to the remembered menu when it still exists, and repairs the
theme mod when it does. Menus are then rendered by id rather than by location, so
a cleared theme mod cannot swap the menu for a page list. The page-list fallback
survives only for a location that has genuinely never been set up.

## ADR-026 — Menu items are updated in place

**Problem:** `set_menu()` deleted every item and recreated it. Item ids changed on
every call, and any edit the site owner had made — a renamed label, a reordered
item, a custom CSS class — was destroyed the next time the AI touched the menu.

**Decision:** existing items are reused position by position and updated in place.
Only items beyond the end of the new list are deleted. The menu stays the site
owner's, which was the point of using WordPress menus in the first place.

## ADR-027 — The code editor has no JavaScript mode

**Context:** a code editor for the template and CSS was wanted, and JavaScript was
raised alongside them.

**Decision:** template and CSS only. The reason an AI can be given access to a
live website is that nothing it writes is executed. A JavaScript file in the
plugin would not hand that power to the AI — MCP would have no route to it — but
it would make the plugin a thing that runs code, and that distinction has to be
explained to every person who asks. The seven behaviors already cover what
JavaScript is usually wanted for, and a site that genuinely needs custom scripts
has a hundred plugins to choose from.

**Consequence:** the safety story stays one sentence long.

## ADR-028 — Hand-edited code goes through the AI's validators

**Decision:** the Code screen posts to REST endpoints that call `PageValidator`
and `ChromeManager::store()` — the same paths an MCP tool uses. Nothing can be
stored by hand that the AI could not have stored, a failing save changes nothing,
and a successful one creates a version. The browser also refuses PHP before
posting, but that is only for a quicker message; the server check is the guard.

The editor shows the CSS as authored rather than as scoped, so people are not
confronted with selectors the plugin rewrote. Both are stored per version.

## ADR-029 — The AI writes the constraints, then is bound by them

**Problem:** two things were pulling against each other. Every site should look
different, which needs freedom. Every page within a site should look like the
same site, which needs constraint. Prompts alone cannot do both: a rule strong
enough to keep page twelve matching page one is also a rule that makes every
plumber's site identical.

**Decision:** split the two by tier. The site's visual language is authored once,
by the AI, for that business. Pages are then composed from it and may not go
around it.

The visual language is three things, all stored on the site and all read back
before any page is designed:

- **tokens** — colour, type, spacing, radius. The spacing scale went from five
  steps to nine, because five is not enough to build a page with, and a page
  that cannot find 24px invents it. That was measurable: every real page on the
  test site had ten to seventeen spacing values off the scale.
- **style** — seven decisions in words: personality, layout, surface, motion,
  buttons, imagery, signature. Words, not values, because the next page has to
  read them and act on them. There is no correct answer to any of them.
- **components** — the set of pieces this site is built from. `ComponentLibrary`
  makes them addressable: an id, a name, a `use_when` line, and the CSS. They
  were previously inside global CSS as one blob, which nothing could query, so
  every page re-read it, guessed, and wrote its own button.

**What the plugin does not ship:** a catalogue of sections, or archetypes for
industries. Shipping a plumber layout would make every plumber's site the same,
which is the failure this is meant to prevent. The plugin ships the mechanism
and the craft rules; the AI designs the look.

**Enforcement, because a prompt is not a guarantee:** `design_review` checks a
page against the tier above it. A page that defines three or more components of
its own — one class, on its own, setting at least two of background, border,
shadow, radius or padding — is told to put them in the library where the next
page can reach them. A page that uses shadows on a site whose `style.surface`
says hairlines is told it is contradicting a decision the site already made. A
site with no library at all is told to build one.

**Consequence:** freedom lives at the top, discipline at the bottom, and the
reviewer is the thing that keeps them honest. Taste rules were removed from the
page-designer prompt for the same reason — "small radii read as considered" and
"gradients are dated" were one designer's preferences shipped as truth, and they
were why every site built with this plugin came out looking alike.

## ADR-030 — The first page is where the site's language comes from

**Problem:** the intended way to build a site is to design the first page
properly and then build everything else in that language. Nothing implemented
that. `build-page` had no step between "page one is finished" and "start page
two", so whatever the first page invented stayed in the first page's stylesheet.
Page two read the tokens and started over. A beautiful homepage and eleven pages
that did not match was the expected outcome, not an accident.

**Decision:** two additions, both small.

`ComponentExtractor` reads a page's CSS and proposes the components inside it. A
rule counts as a candidate when it is built on one class and sets at least two of
background, border, radius, shadow, padding or colour. BEM parts join their block
(`.card`, `.card__title`, `.card--wide` are one thing). Anything with a
descendant, child or sibling part is treated as placement, not a component, so a
page arranging things is not mistaken for a page defining them.

It proposes and does not decide. It cannot know what a thing is *for*, and
`use_when` is the field that makes a library usable, so the AI names each one and
writes that line. Run on the real test site it proposed the footer, the menu, the
top bar and the wordmark, with their hover and focus states intact, and found
nothing in the page stylesheets — correctly, because those pages were written as
layout on top of shared components.

`page_type` goes on the page manifest, and `site_get_context` groups pages by it.
Before this, building the fourth service page meant designing a fourth different
service page: nothing recorded that those pages were the same kind of thing. The
workflow now says to read an existing page of the same kind before designing.

**Consequence:** the flow the plugin was always described as having now exists.
Design the first page, lift its language into the site, build the rest in it.

## ADR-031 — A tool refuses an argument it does not have

**Decision:** `ToolRegistry::call()` compares the incoming argument names against
the tool's schema and fails with `AIWP_UNKNOWN_ARGUMENT`, naming the arguments it
does take.

**Why:** `design_update_system` was called with `tokens` instead of
`design_system`. It returned `"success": true` and changed nothing. Nothing was
wrong in the result, nothing was logged, and the mistake only surfaced later when
the site was the wrong colour. An AI cannot recover from a success that did not
happen. Refusing costs one round trip; succeeding silently costs a turn and,
that time, a design system.

## ADR-032 — Articles are posts, and one design serves all of them

**Decision:** an article is a WordPress post. Its body stays in `post_content`,
written in the block editor. One design — template, CSS, and a schema of extra
fields — is stored per *kind* of article and applies to every post of that kind.

**Why not a page each:** a landing page is one design for one page, so its
template belongs with it. An article is the opposite: two hundred articles, one
design. A template per post would be two hundred copies and two hundred ways to
drift apart.

**Why the body stays in post_content:** a writer needs a real writing surface,
and everything else in WordPress — search, feeds, SEO plugins, pasting from a
document — expects to find the body there. The block editor is turned off for
AIWP pages because there is nothing to type; on a post it is left alone, because
the body is the point. The design supplies the furniture around it.

`{{post_body:post.body}}` prints WordPress's own `the_content` output without
escaping it, which is what a theme does. Escaping again would strip the embeds
and blocks the editor produced. It is the only unescaped filter in the template
language, so `TemplateValidator` refuses it on any path but `post.body`:
otherwise `{{post_body:hero.text}}` would hand anyone who can edit a field a way
to put script on the page.

`kind` is in the storage path from the start, so a how-to and a case study can
look different later without moving anything. There is one kind today.

**Found while building it:** `FieldValueManager` looked the ACF field key up
from the post's own uuid meta. That is right for a page, which owns its fields,
and wrong for an article design, which owns the same fields on every post of a
type. Writes silently did nothing. The uuid is now passed in by whoever owns the
schema.

## ADR-033 — The baseline is the weakest stylesheet on the page

**Problem:** three bugs with one cause, all reported from a real site.

`.aiwp-page a { color: inherit }` in the plugin baseline is one class plus one
tag. `.cr-btn` is one class. The baseline won, so every button's white text was
turned back to body colour: dark text on a dark button. The same shape —
`.aiwp-page h1`, `.aiwp-page p`, `.aiwp-page img` — beat every component the AI
could write.

**Decision:** every baseline rule scoped to a wrapper wraps that scope in
`:where()`, which weighs nothing. `:where(.aiwp-page) a` is tag-weight, so any
class beats it. That is what a baseline is for. The focus ring is left strong on
purpose: a component must not be able to remove it by accident.

`BaselineCssTest` fails the build if a wrapper-scoped rule is written the old
way, if a selector is defined twice, or if the form control loses `box-sizing`.

**Two more from the same file:**

Form controls had no `box-sizing`. A browser gives `input` content-box and
`select` border-box, so with identical padding a text box came out 52px tall and
a dropdown 44px, and no row of fields ever lined up.

`AIWP_VERSION` had been `0.3.0` for four releases of CSS changes, so every
browser that had loaded a stylesheet kept the old one — which is why the button
bug was invisible until the version moved. The plugin's own assets are now
versioned by file modification time, so nobody has to remember.

## ADR-034 — SEO splits into what needs an opinion and what does not

**Decision:** two layers, and the plugin is honest about which it is doing.

The first needs nothing from anybody: a title of a workable length, a meta
description, exactly one h1, headings that do not skip a level, alt text,
internal links out, whether anything links in, a readable address. Those run on
every page of every site whether a brief exists or not.

The second needs somebody to have said what the page was for — the keyword, the
questions it promised to answer, the concepts it promised to cover. Where there
is no brief, those checks are skipped and `not_checked` says so, rather than the
report implying a clean bill of health.

**The seam is `PageBrief`, and it is source-agnostic on purpose.** ConvertRank's
brief maps onto it almost one to one, a person can type into it, another tool can
post to it. It takes entities as objects or as strings, questions as a list or as
lines of text, because those are the shapes briefs actually arrive in. The plugin
owns the checking; it does not own the strategy.

**Structured data is built by the plugin, never written by the AI.** Schema is a
set of claims a search engine treats as facts, and a model asked to produce it
will assert an aggregateRating nobody measured. So the graph is derived from the
rendered page: WebSite, Organization, WebPage or Article, BreadcrumbList, and
FAQPage only where the markup genuinely holds question-shaped headings with real
answers under them. Asking for `FAQPage` in the brief is not enough — the
practical consequence is that to get FAQ schema you write a proper FAQ section,
which is the right order round.

## ADR-035 — The plugin stands down from meta tags when an SEO plugin is active

**Problem:** SEO plugins are the most installed plugins in WordPress. A plugin
that also prints a title and a description gives those sites two of each, which
is worse than printing none.

**Decision:** detect Yoast, Rank Math, SEO Press and All in One by class or
function, and when one is present stop printing and write the brief's values
into that plugin's own post meta instead — never overwriting a value somebody
typed there by hand. `seo_audit` reports `meta_owner` so it is visible who is in
charge. On a site with no SEO plugin, this one prints them, because a page with
no description gets a search result assembled out of whatever sentence a machine
found first.

Verified both ways on a live site: with the detection satisfied, our description
tag disappeared and the values arrived in the other plugin's fields; with it
removed, the tag came back.

**What is deliberately not built:** keyword research, rank tracking, and any
score claiming to predict how a page will rank. Nothing here talks to a search
engine, and a number that pretends otherwise is worse than no number.

## ADR-036 — The site owner can add their own fields, and the AI cannot delete them

**Problem:** the owner could fill in fields but never create one. Wanting to add
opening hours meant asking the AI and waiting. That is the wrong shape for
something a person needs on a Tuesday afternoon.

**Decision:** a section carries an `owner` flag. The owner adds sections and
fields through a box on the page edit screen — labels and types only, never
markup and never CSS, so nothing they add can break a page.

**The property that makes it safe to offer:** the AI sends the whole section
list on every `page_update`. Without protection, the first redesign after
somebody added a section would delete it, and nobody would find out until the
field they filled in was gone. `keeping_owner_sections()` merges instead, and an
id collision resolves in the owner's favour, because it is their site.

**Rendering:** the template was written before those fields existed, so it does
not mention them. Anything the template does not print is rendered after it in
plain ruled rows built from the site's own tokens. Deliberately modest — visible
and correct rather than designed — because the alternative is somebody filling in
a form and seeing nothing happen, which reads as the plugin being broken.

`page_get` returns `owner_sections` separately and says what they are, so the
next redesign places them properly instead of leaving them to the fallback.

**Found while building it:** `to_array()` dropped the flag, so it survived in
memory and died the moment the schema was stored — which is exactly when it
needed to exist. Round-tripping through storage is now the first test.
