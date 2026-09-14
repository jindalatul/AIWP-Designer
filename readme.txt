=== AIWP Designer ===
Contributors: Atul Jindal
Tags: ai, mcp, acf, design, page builder
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI-native website design system for WordPress. Your own AI designs the pages through MCP.

== Description ==

AIWP Designer is not another drag-and-drop page builder. It is the trusted place
where your own AI — Claude, ChatGPT or any MCP client — designs, checks, stores
and versions the pages of your website.

There is no LLM inside the plugin and no hosted service behind it. You bring your
own AI.

The AI writes a field schema, template markup, page CSS and the starting content.
It never writes PHP or JavaScript, and the plugin refuses anything that tries.

* No fixed page structure. The AI invents the sections.
* Content stays in ACF fields, so you can edit it in wp-admin as usual.
* Works with ACF free. ACF Pro is not required.
* Every change is a version. Roll back at any time.
* Headless-ready: a normalised JSON endpoint per page.

== Installation ==

1. Install and activate Advanced Custom Fields (free).
2. Install and activate AIWP Designer.
3. Open AIWP Designer, fill in Brand & Business.
4. Open MCP Connection and generate a token.
5. Connect your MCP client with that token.

== Frequently Asked Questions ==

= Do I need ACF Pro? =

No. The plugin ships its own repeater field built on ACF's public extension API.

= Does it send my content anywhere? =

No. The plugin has no outbound calls. Your AI client talks to your site directly.

= Can I still edit pages normally? =

Yes. Generated fields appear in the WordPress editor like any other ACF fields.

= What happens if I uninstall it? =

Nothing is deleted unless you turn on "Delete all AIWP data on uninstall".

== Changelog ==

= 0.10.0 =
* The AI now reads the finished page back before it can publish it, and is told what the page actually says.
* Catches an empty field the template prints, a sentence said twice, leftover placeholder text and links that go nowhere.

= 0.9.1 =
* Security: a link could hide "javascript:" from the check by splitting it with a tab or a null byte. Fixed in templates and in CSS.
* URL schemes are now an allowlist: http, https, mailto, tel, or a path on this site.

= 0.9.0 =
* Move this site: one file carries every page, the design, the menus and the images to another WordPress.
* Motion is decided once in the design system and shared by every page.
* Design review notices a page that types its own timings instead of using the site's.

= 0.8.0 =
* The site owner can add their own sections and fields. The AI cannot delete them.
* A field the template does not print still appears on the page, in the site's own styling.
* Removing a section or field asks for a typed word instead of a tick.
* The template line for each field is shown beside it, and clicking copies it.
* Fixed: two fields whose ids ran together were stored in one place, and one overwrote the other.
* Fixed: uninstall left options, role capabilities, caches and per-user settings behind.

= 0.7.0 =
* One design for every list of articles, written by the AI like any other page.
* SEO checks in two layers: what needs no opinion, and what needs a brief.
* Fixed: saving the code editor without changing anything no longer makes a version.

= 0.6.0 =
* Articles are WordPress posts, with one AI-written template for all of them.
* A table of contents built from the headings in the body.

= 0.5.0 =
* Design review: the AI can check its own work against the site's own decisions.
* Images, including SVG, imported and sanitised on the way in.

= 0.1.0 =
* First working build.

== Upgrade Notice ==

= 0.10.0 =
Publishing a page through MCP now requires calling page_look on it first.
If you drive this plugin with your own scripts, add that call.

= 0.9.1 =
Security fix. A page template could carry a javascript: link past the
validator by splitting the scheme with an invisible character. Update if
anyone other than you holds an MCP token for your site.

= 0.9.0 =
Adds a way to move a whole site to another WordPress install, under
AIWP Designer > Move this site.

= 0.8.0 =
Fixes a case where two fields could be stored in the same place and overwrite
each other. Upgrade before adding fields whose names run together, for example
a "hero" section with a "sub_title" field alongside a "hero_sub" section.
