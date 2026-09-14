# AIWP Designer

## Complete Product, Architecture, MCP, Prompt, Template Engine, ACF, Visual Editor, Security and Testing Specification

**Working plugin name:** AIWP Designer  
**Plugin slug:** `aiwp-designer`  
**PHP namespace:** `AIWP\Designer`  
**REST namespace:** `aiwp-designer/v1`  
**MCP protocol target:** `2026-07-28`

---

# 1. Instructions for the Coding Agent

You are implementing a production-quality open-source WordPress plugin named **AIWP Designer**.

Read this entire document before writing code.

Do not redesign the architecture unless there is a technical reason that makes something in this specification impossible.

If a change is necessary:

1. Explain the problem.
2. Describe the proposed change.
3. Record the decision in `docs/ADR.md`.
4. Preserve the original product intention.
5. Continue only after the alternative has tests.

Do not implement the entire project in one uncontrolled pass.

Implement the phases in the exact order defined in this document.

After every phase:

1. Run all tests for that phase.
2. Run all previously created tests.
3. Fix all failures.
4. Update `IMPLEMENTATION_STATUS.md`.
5. Update `TEST_RESULTS.md`.
6. Do not begin the next phase while tests are failing.

Maintain these files throughout development:

```text
DOCUMENT.md
IMPLEMENTATION_STATUS.md
TEST_RESULTS.md
CHANGELOG.md
docs/ADR.md
```

`IMPLEMENTATION_STATUS.md` must show:

```text
Phase
Status
Completed functionality
Incomplete functionality
Known issues
Tests passing
```

Never silently skip functionality because it is difficult.

Never replace a required feature with a mock implementation unless the specification explicitly says that phase may use a temporary stub.

---

# 2. Product Vision

AIWP Designer is not another drag-and-drop WordPress page builder.

It is an **AI-native website design system for WordPress**.

The user's own AI application, such as Claude or ChatGPT, acts as the website designer.

The AI:

- understands the website
- understands the brand
- decides page structure
- decides section structure
- creates editable content fields
- creates page markup
- creates page CSS
- selects supported interactions
- creates initial content
- reviews the rendered page
- improves the design
- can redesign individual sections later

The WordPress plugin acts as the trusted execution environment.

The architecture is:

```text
Claude / ChatGPT / MCP Client
            │
            │ MCP
            ▼
       AIWP Designer
            │
    ┌───────┼─────────┐
    │       │         │
   ACF    Template   Design
 Fields   Renderer    System
    │       │         │
    └───────┼─────────┘
            │
            ▼
       WordPress Page
            │
            ▼
        Preview URL
            │
            ▼
    Claude / ChatGPT
     visually reviews
            │
            ▼
        Improvements
```

The plugin does **not** contain its own LLM.

The user brings their own AI through MCP.

---

# 3. Core Product Principles

The following principles are mandatory.

## 3.1 AI has design freedom

There must not be a predefined page structure.

Do not force pages into structures such as:

```text
Hero
Features
Testimonials
FAQ
CTA
```

AI can create those sections when appropriate, but it may also invent completely different structures.

A page could contain:

```text
Editorial introduction
Interactive comparison
Timeline
Statistics story
Case-study strip
Product walkthrough
Calculator
Trust narrative
FAQ
CTA
```

The plugin provides the rendering system, not the page design.

---

## 3.2 AI never writes executable PHP

AI-generated page files must be non-executable.

AI may generate:

- safe template markup
- CSS
- structured field definitions
- content
- behavior declarations

AI must not generate:

- PHP
- SQL
- shell commands
- executable server code
- arbitrary filesystem commands

---

## 3.3 No unrestricted AI-generated JavaScript in MVP

JavaScript behaviors must initially come from a trusted library shipped with the plugin.

AI selects behaviors through declarative attributes.

Example:

```html
<div data-aiwp-behavior="accordion">
```

The plugin provides the JavaScript implementation.

---

## 3.4 Content and presentation remain separate

Example:

Template:

```html
<h1>{{text:hero.headline}}</h1>
```

Content:

```text
hero.headline =
"Buy Your First Home With Confidence"
```

Changing the page design must not require rewriting content.

Changing content must not require modifying the template.

---

## 3.5 Structured content remains developer friendly

Standard content fields use **ACF Free**.

Complex repeated content uses a custom ACF field type created by this plugin:

```text
aiwp_repeater
```

Developers must be able to retrieve content using familiar ACF APIs whenever possible.

Example:

```php
get_field( 'hero_headline' );
get_field( 'benefit_cards' );
```

`benefit_cards` should return a normal PHP array.

---

# 4. Supported User Personas

The same plugin must support three types of users.

## 4.1 Small Business Owner

Primary interface:

- Claude
- ChatGPT
- visual editor

Example instruction:

```text
Create a page describing our first-time home buyer mortgage program.
```

The business owner should not need to understand:

- HTML
- CSS
- ACF
- templates
- PHP

---

## 4.2 Web Designer

Can use:

- AI
- visual editor
- design controls
- section redesign
- typography controls
- colors
- spacing
- imagery
- responsive preview

---

## 4.3 Designer / Developer

Can additionally inspect:

- template source
- CSS
- page schema
- ACF field definitions
- design tokens
- behavior declarations
- page manifest
- version history

The developer must still not be able to accidentally turn AI templates into executable PHP.

---

# 5. Runtime Requirements

Target:

```text
WordPress: 6.6+
PHP: 8.1+
Node.js development environment: current LTS
ACF Free: current stable 6.x
```

Do not require ACF Pro.

If ACF is not active:

- plugin remains installed
- admin notice explains dependency
- page generation features remain disabled
- existing rendered pages should fail gracefully
- do not cause fatal errors

The plugin must check for ACF at runtime before calling ACF functions.

---

# 6. Repository Structure

Create the project approximately as follows:

```text
aiwp-designer/
│
├── aiwp-designer.php
├── composer.json
├── package.json
├── phpunit.xml
├── phpcs.xml
├── DOCUMENT.md
├── IMPLEMENTATION_STATUS.md
├── TEST_RESULTS.md
├── CHANGELOG.md
│
├── src/
│   ├── Plugin.php
│   │
│   ├── Admin/
│   │   ├── AdminMenu.php
│   │   ├── Dashboard.php
│   │   ├── Onboarding.php
│   │   └── Settings.php
│   │
│   ├── ACF/
│   │   ├── ACFManager.php
│   │   ├── SchemaRegistrar.php
│   │   ├── FieldKeyGenerator.php
│   │   ├── FieldValueManager.php
│   │   └── Fields/
│   │       └── AIRepeaterField.php
│   │
│   ├── Template/
│   │   ├── TemplateEngine.php
│   │   ├── TemplateParser.php
│   │   ├── TemplateValidator.php
│   │   ├── TemplateAST.php
│   │   ├── FieldResolver.php
│   │   ├── OutputEscaper.php
│   │   └── TemplateRepository.php
│   │
│   ├── Design/
│   │   ├── DesignSystem.php
│   │   ├── DesignSystemRepository.php
│   │   ├── CSSValidator.php
│   │   ├── CSSRepository.php
│   │   └── DesignVersionManager.php
│   │
│   ├── Pages/
│   │   ├── PageManager.php
│   │   ├── PageManifest.php
│   │   ├── PageSchema.php
│   │   ├── PageRepository.php
│   │   └── PageValidator.php
│   │
│   ├── Versioning/
│   │   ├── VersionManager.php
│   │   ├── Snapshot.php
│   │   └── RollbackManager.php
│   │
│   ├── Rendering/
│   │   ├── PageRenderer.php
│   │   ├── TemplateLoader.php
│   │   └── AssetManager.php
│   │
│   ├── REST/
│   │   ├── Routes.php
│   │   ├── PagesController.php
│   │   ├── FieldsController.php
│   │   ├── DesignController.php
│   │   └── VersionsController.php
│   │
│   ├── MCP/
│   │   ├── MCPServer.php
│   │   ├── MCPRequest.php
│   │   ├── MCPResponse.php
│   │   ├── Protocol/
│   │   ├── Tools/
│   │   ├── Resources/
│   │   ├── Prompts/
│   │   └── Auth/
│   │
│   ├── Prompts/
│   │   ├── PromptRegistry.php
│   │   ├── PromptLoader.php
│   │   ├── PromptCompiler.php
│   │   └── WorkflowManager.php
│   │
│   ├── Security/
│   │   ├── CapabilityManager.php
│   │   ├── FileValidator.php
│   │   ├── Sanitizer.php
│   │   └── AuditLogger.php
│   │
│   └── Performance/
│       └── StaticAuditor.php
│
├── prompts/
│   ├── core/
│   ├── design/
│   ├── review/
│   └── workflows/
│
├── templates/
│   ├── page-shell.php
│   └── editor-shell.php
│
├── assets/
│   ├── src/
│   │   ├── admin/
│   │   ├── visual-editor/
│   │   └── frontend/
│   │
│   └── dist/
│
├── tests/
│   ├── php/
│   ├── js/
│   ├── integration/
│   ├── mcp/
│   └── fixtures/
│
└── scripts/
```

Use Composer PSR-4 autoloading.

---

# 7. Generated Runtime Files

Never write generated templates into the active WordPress theme.

Use:

```php
wp_upload_dir()
```

Create:

```text
/wp-content/uploads/aiwp-designer/
```

Multisite should include blog/site ID.

Example:

```text
aiwp-designer/
│
├── design/
│   ├── current/
│   │   ├── tokens.json
│   │   └── global.css
│   │
│   └── versions/
│
├── pages/
│   ├── PAGE_UUID/
│   │   ├── current/
│   │   │   ├── template.aiwp
│   │   │   ├── page.css
│   │   │   ├── schema.json
│   │   │   └── manifest.json
│   │   │
│   │   └── versions/
│   │       ├── 1/
│   │       ├── 2/
│   │       └── 3/
│
└── cache/
```

AI never receives arbitrary filesystem paths.

AI refers to:

```text
page_id
page_uuid
version
```

The plugin decides the physical location.

---

# 8. Page Ownership

A normal WordPress `page` remains the canonical WordPress object.

AIWP metadata:

```text
_aiwp_enabled
_aiwp_page_uuid
_aiwp_schema
_aiwp_manifest
_aiwp_active_version
_aiwp_design_version
```

Do not replace WordPress pages with a custom post type.

This allows:

- normal WordPress URLs
- navigation menus
- SEO plugins
- revisions where applicable
- normal permissions
- familiar WordPress behavior

---

# 9. Page Manifest

Every generated page has a manifest.

Example:

```json
{
  "schema_version": 1,
  "page_uuid": "uuid",
  "wordpress_page_id": 142,
  "template_version": 4,
  "design_system_version": 2,
  "created_by": "mcp",
  "visual_direction": "premium editorial financial services",
  "page_goal": "lead generation",
  "primary_conversion": "start application",
  "behaviors": [
    "accordion",
    "reveal"
  ]
}
```

Do not store hidden chain-of-thought.

Only store concise design decisions useful to future editing.

---

# 10. Field Architecture

## 10.1 Standard Fields

Use existing ACF Free field types whenever available.

Before accepting a schema field, verify:

```php
acf_get_field_type( $type )
```

The MCP capabilities endpoint must tell the AI which field types are currently available.

Never assume every ACF installation exposes exactly the same field types.

Typical fields include:

```text
text
textarea
number
email
url
image
file
wysiwyg
select
checkbox
radio
true_false
link
```

---

# 11. Custom AI Repeater

Implement a custom ACF field type:

```text
aiwp_repeater
```

Class:

```text
AIWP\Designer\ACF\Fields\AIRepeaterField
```

It must extend:

```php
acf_field
```

Register it through the normal ACF field-type registration API.

Do not copy ACF Pro repeater source code.

This must be an independently implemented field using ACF's public extension APIs.

---

# 12. AI Repeater Requirements

The repeater must support:

- add row
- delete row
- duplicate row
- drag/drop reorder
- minimum rows
- maximum rows
- labels
- instructions
- required fields
- standard supported ACF subfields
- nested value validation
- REST output
- `get_field()` compatibility

Initial maximum nesting depth:

```text
aiwp_repeater → standard fields
```

Do not support repeater-inside-repeater in MVP.

Design the internal interfaces so nested repeaters can be added later.

---

# 13. AI Repeater Storage

Store the repeater as one ACF field value.

Example:

```php
get_field( 'feature_cards', $page_id );
```

should return:

```php
[
    [
        'title'       => 'Fast Approval',
        'description' => '...',
        'image'       => 145,
    ],
    [
        'title'       => 'Flexible Options',
        'description' => '...',
        'image'       => 192,
    ],
]
```

Do not attempt to duplicate ACF Pro's internal database layout.

The custom field owns its own storage format.

Use normal WordPress/ACF serialization through post meta.

---

# 14. AI Repeater Subfields

Each repeater has a subfield definition.

Example:

```json
{
  "id": "field-uuid",
  "name": "feature_cards",
  "label": "Feature Cards",
  "type": "aiwp_repeater",
  "sub_fields": [
    {
      "id": "subfield-title",
      "name": "title",
      "label": "Title",
      "type": "text"
    },
    {
      "id": "subfield-description",
      "name": "description",
      "label": "Description",
      "type": "textarea"
    },
    {
      "id": "subfield-image",
      "name": "image",
      "label": "Image",
      "type": "image"
    }
  ]
}
```

Render standard ACF subfield interfaces where technically possible.

After dynamically adding rows, invoke the correct ACF JavaScript lifecycle so ACF controls such as image fields initialize correctly.

---

# 15. Repeater REST Schema

`aiwp_repeater` must expose a REST schema equivalent to:

```json
{
  "type": ["array", "null"],
  "items": {
    "type": "object",
    "properties": {
      "title": {
        "type": ["string", "null"]
      },
      "description": {
        "type": ["string", "null"]
      },
      "image": {
        "type": ["integer", "null"]
      }
    }
  }
}
```

Schema must be derived dynamically from subfield definitions.

---

# 16. Dynamic ACF Field Groups

AI-generated schemas must be stored in page metadata.

Do not generate PHP source files containing ACF definitions.

During:

```text
acf/init
```

load the schema for AIWP pages and register field groups programmatically.

Generate stable keys.

Example:

```text
group_aiwp_PAGEUUID_SECTIONUUID
field_aiwp_PAGEUUID_FIELDUUID
```

Field keys must not change when labels change.

Field IDs are immutable after creation.

---

# 17. Field Names

Field names should be human-readable and developer friendly.

Example AI path:

```text
hero.headline
```

ACF field name:

```text
hero_headline
```

Example:

```text
benefits.cards
```

becomes:

```text
benefits_cards
```

Repeater subfield:

```text
title
```

The page schema must maintain the mapping.

---

# 18. Field Rename Rule

Once a field has content, its internal `id` must never change.

Changing its label is allowed.

Changing its field name requires an explicit migration.

Do not silently rename metadata.

Implement later:

```text
migrate_field_name()
```

For MVP, reject accidental renames unless migration is explicitly requested.

---

# 19. WordPress Admin Structured Editing

Users must be able to edit AI-generated content without using the visual editor.

When editing an AIWP page in WordPress:

- show generated ACF fields
- organize fields by section
- use clear section labels
- allow `aiwp_repeater` editing
- preserve normal ACF experience

Possible organization:

```text
HERO

Headline
Description
Image
CTA

BENEFITS

Heading
Feature Cards

TRUST

Statistics
Logos
```

Do not require users to use the React editor.

---

# 20. Headless Compatibility

Every generated field group must be configured for REST access where supported.

Also provide a plugin-owned normalized endpoint:

```text
GET /wp-json/aiwp-designer/v1/pages/{id}/data
```

Example:

```json
{
  "id": 142,
  "slug": "first-time-home-buyers",
  "fields": {
    "hero": {
      "headline": "Buy Your First Home",
      "description": "..."
    },
    "benefits": {
      "cards": [
        {
          "title": "Low Down Payment",
          "description": "..."
        }
      ]
    }
  }
}
```

This endpoint is intended for:

- Next.js
- React
- Astro
- mobile applications
- headless WordPress
- custom integrations

---

# 21. Safe Template Language

File extension:

```text
.aiwp
```

The language is intentionally small.

Never interpret it using PHP `eval`.

Never compile AI input into executable PHP.

---

# 22. Template Field Syntax

Escaped text:

```text
{{text:hero.headline}}
```

Escaped HTML attribute:

```text
{{attr:hero.image_alt}}
```

Escaped URL:

```text
{{url:hero.cta_url}}
```

Sanitized rich HTML:

```text
{{html:hero.body}}
```

Image URL:

```text
{{image_url:hero.image}}
```

---

# 23. Conditional Syntax

```html
{{#if:hero.image}}

<div class="hero-image">
    <img
        src="{{image_url:hero.image}}"
        alt="{{attr:hero.image_alt}}"
    >
</div>

{{/if}}
```

---

# 24. Repeater Syntax

```html
{{#each:benefits.cards}}

<article class="benefit-card">
    <h3>{{text:@item.title}}</h3>
    <p>{{text:@item.description}}</p>

    {{#if:@item.image}}
        <img
            src="{{image_url:@item.image}}"
            alt="{{attr:@item.title}}"
        >
    {{/if}}
</article>

{{/each}}
```

---

# 25. Allowed Template Constructs

Supported:

```text
text
attr
url
html
image_url
if
each
```

Later versions may add:

```text
else
partial
component
```

Do not implement unnecessary template-language complexity in MVP.

---

# 26. Template Restrictions

Reject templates containing:

```text
<?php
<?= 
<script
javascript:
onerror=
onclick=
onload=
<iframe
<object
<embed
<form action=
```

Some items may be supported later through explicit safe components.

Do not rely only on substring checks.

Parse and validate HTML.

Use an allowlist.

---

# 27. HTML Allowlist

Allow normal semantic HTML such as:

```text
main
section
article
header
footer
nav
div
span
h1-h6
p
ul
ol
li
a
button
img
picture
source
figure
figcaption
blockquote
strong
em
small
table
thead
tbody
tr
th
td
details
summary
```

Attributes should also be allowlisted.

Examples:

```text
class
id
href
src
srcset
sizes
alt
title
target
rel
role
aria-*
data-aiwp-*
```

Never allow raw event-handler attributes.

---

# 28. Template Parser

Do not implement the complete renderer using fragile chained regular expressions.

Create:

```text
TemplateParser
TemplateAST
TemplateValidator
FieldResolver
OutputEscaper
```

Workflow:

```text
Template source
     ↓
Tokenize
     ↓
Parse directives
     ↓
Build AST
     ↓
Validate
     ↓
Resolve fields
     ↓
Escape by context
     ↓
Render HTML
```

---

# 29. Template Output Escaping

`text`

Use HTML text escaping.

`attr`

Use attribute escaping.

`url`

Use URL escaping and protocol validation.

`html`

Use controlled WordPress HTML sanitization.

`image_url`

Resolve WordPress attachment and return safe URL.

Never use one generic escaping method for every context.

---

# 30. Plugin Page Shell

The plugin itself may contain trusted PHP.

AI may not modify it.

Example:

```text
/templates/page-shell.php
```

This shell:

1. verifies AIWP page
2. calls theme header if configured
3. renders AI template
4. calls theme footer if configured

Support page chrome modes:

```text
theme
blank
```

`theme`:

Uses active WordPress theme header/footer.

`blank`:

Uses minimal plugin-owned HTML shell.

Default:

```text
theme
```

---

# 31. Global Design System

The website has one active global design system.

Example `tokens.json`:

```json
{
  "version": 1,
  "colors": {
    "primary": "#15253d",
    "secondary": "#ffffff",
    "accent": "#d99b3d",
    "text": "#1e1e1e",
    "muted": "#707070"
  },
  "typography": {
    "heading_font": "Inter",
    "body_font": "Inter",
    "base_size": "16px"
  },
  "spacing": {
    "xs": "8px",
    "sm": "16px",
    "md": "32px",
    "lg": "64px",
    "xl": "96px"
  },
  "radius": {
    "small": "6px",
    "medium": "12px",
    "large": "24px"
  },
  "container": {
    "max": "1200px"
  }
}
```

---

# 32. CSS Variables

Compile tokens into:

```css
:root {
    --aiwp-color-primary: #15253d;
    --aiwp-color-accent: #d99b3d;
    --aiwp-color-text: #1e1e1e;

    --aiwp-space-sm: 16px;
    --aiwp-space-md: 32px;
    --aiwp-space-lg: 64px;

    --aiwp-radius-md: 12px;
}
```

Page CSS should reference these variables whenever reasonable.

---

# 33. Global CSS

Store:

```text
design/current/global.css
```

Global CSS contains:

- reset additions only where needed
- typography
- containers
- button foundations
- accessibility helpers
- basic animation utilities
- design tokens
- common layout helpers

Do not turn `global.css` into a dumping ground for page-specific CSS.

---

# 34. Page CSS

Each page gets:

```text
page.css
```

Example:

```text
pages/PAGE_UUID/current/page.css
```

Only enqueue this CSS on that page.

---

# 35. CSS Scope

Every rendered AIWP page receives:

```html
<main
    class="aiwp-page"
    data-aiwp-page="PAGE_UUID"
>
```

Page CSS should normally be scoped under:

```css
[data-aiwp-page="PAGE_UUID"] {
}
```

The CSS validator should detect selectors likely to leak globally.

Reject obviously dangerous global rules such as:

```css
body * {
}

html {
}

* {
}
```

unless explicitly part of approved global design CSS.

---

# 36. CSS Security

Reject:

```text
@import
expression(
javascript:
-moz-binding
behavior:
```

Validate `url()` values.

External CSS imports must not be permitted.

AI page CSS may not load arbitrary remote stylesheets.

---

# 37. JavaScript Behavior System

Create a small trusted frontend behavior library.

Initial behaviors:

```text
accordion
tabs
modal
counter
reveal
sticky
carousel
```

Each behavior must:

- be accessible
- support keyboard controls where applicable
- avoid jQuery
- use progressive enhancement
- initialize only when present

AI declares:

```html
<div data-aiwp-behavior="accordion">
```

Plugin JavaScript initializes it.

---

# 38. No Generated JavaScript

The page-generation MCP payload must not contain:

```text
javascript
script
custom_js
```

Reject `<script>` from templates.

Custom JavaScript can be considered as an advanced developer-only feature after MVP.

---

# 39. Image Handling

Content references WordPress media attachment IDs.

Example:

```json
{
  "hero_image": 182
}
```

Template:

```html
<img
    src="{{image_url:hero.image}}"
    alt="{{attr:hero.image_alt}}"
>
```

Renderer should use WordPress image APIs where possible.

Support:

- responsive `srcset`
- width
- height
- lazy loading
- eager loading for appropriate hero images

Do not let AI invent local filesystem paths.

---

# 40. React Visual Editor

Build a React-based visual editor.

Recommended architecture:

```text
React editor shell
       │
       ▼
same-origin iframe
       │
       ▼
actual WordPress preview
```

Do not recreate the page separately in React.

The iframe should render the **real frontend template engine**.

This ensures what the user edits is what visitors see.

---

# 41. Visual Editor URL

Example:

```text
/wp-admin/admin.php?page=aiwp-editor&post=142
```

The iframe loads a protected preview.

The editor preview must never expose unpublished content publicly without authorization.

---

# 42. Editable DOM Markers

When rendering editor mode:

```html
<h1
    data-aiwp-editable="true"
    data-aiwp-field-id="FIELD_UUID"
    data-aiwp-field-path="hero.headline"
>
    Buy Your First Home
</h1>
```

Repeater item:

```html
<h3
    data-aiwp-editable="true"
    data-aiwp-field-id="REPEATER_UUID"
    data-aiwp-row="1"
    data-aiwp-subfield="title"
>
    Fast Approval
</h3>
```

Do not expose sensitive internal data.

---

# 43. Visual Editor Communication

Use `postMessage` between iframe and React parent.

Events:

```text
AIWP_FIELD_SELECTED
AIWP_REPEATER_ITEM_SELECTED
AIWP_SECTION_SELECTED
AIWP_PREVIEW_READY
AIWP_CONTENT_UPDATED
AIWP_REFRESH_REQUESTED
```

Validate:

```text
event.origin
```

Never blindly trust cross-window messages.

---

# 44. Visual Editor Functions

User must be able to:

- click text
- edit text
- edit rich text
- replace image
- edit link
- edit button text
- edit button URL
- add repeater row
- remove repeater row
- duplicate repeater row
- reorder repeater rows
- save
- undo unsaved editor change

Design changes remain AI operations.

---

# 45. Content Edit vs Design Edit

Keep these concepts separate.

Content edit:

```text
Change "Apply Today" to "Start Your Application"
```

Updates ACF value.

Design edit:

```text
Make this section feel much more premium.
```

Updates:

```text
template
CSS
possibly schema
```

The UI should make the distinction clear.

---

# 46. AI Design Workflow

The intended workflow is:

```text
User request
    ↓
AI gets workflow instructions
    ↓
AI reads site context
    ↓
AI reads design system
    ↓
AI plans page
    ↓
AI sends page package
    ↓
Plugin validates
    ↓
Plugin stores draft
    ↓
Plugin returns preview URL
    ↓
AI opens preview URL
    ↓
AI critiques visual output
    ↓
AI updates weak areas
    ↓
New preview
    ↓
User approval
    ↓
Publish
```

The first generated design is not assumed to be final.

---

# 47. Local Prompt Library

All prompts are stored locally inside the plugin.

No external prompt API exists in this version.

Structure:

```text
prompts/
│
├── core/
│   ├── system.md
│   ├── safety.md
│   ├── template-language.md
│   ├── css-rules.md
│   └── content-model.md
│
├── design/
│   ├── brand-analysis.md
│   ├── design-system.md
│   ├── page-planner.md
│   ├── page-designer.md
│   ├── responsive-design.md
│   └── visual-polish.md
│
├── review/
│   ├── design-critic.md
│   ├── accessibility.md
│   └── performance.md
│
└── workflows/
    ├── create-design-system.md
    ├── build-page.md
    ├── redesign-page.md
    ├── redesign-section.md
    └── improve-page.md
```

---

# 48. Prompt Frontmatter

Every prompt file begins with:

```yaml
---
id: page-designer
version: 1
category: design
description: Designs an individual WordPress page.
---
```

Prompt versions are part of plugin releases.

---

# 49. Prompt Compiler

Create:

```text
PromptRegistry
PromptLoader
PromptCompiler
WorkflowManager
```

The system dynamically assembles prompts.

Example build-page prompt:

```text
core/system
+
core/safety
+
core/template-language
+
core/css-rules
+
design/page-planner
+
design/page-designer
+
design/responsive-design
+
review/design-critic
+
current site context
+
current design system
+
user request
```

Do not send unrelated prompts.

---

# 50. Important MCP Prompt Delivery Rule

Do not assume that an MCP client will automatically read all server prompts.

Therefore every mutating workflow begins by calling:

```text
workflow.prepare
```

This tool returns:

```json
{
  "workflow_id": "uuid",
  "workflow_type": "build_page",
  "instructions": "...compiled instructions...",
  "expires_at": "...",
  "required_context": [
    "site_context",
    "design_system"
  ]
}
```

All mutation tools require:

```text
workflow_id
```

This guarantees that the AI receives the plugin's current instructions before modifying the website.

---

# 51. Workflow IDs

Workflow IDs:

- are UUIDs
- expire
- are associated with one WordPress user
- are associated with one workflow type
- are stored temporarily
- cannot grant permissions beyond the authenticated user

Do not treat workflow IDs as authentication.

---

# 52. AI Reasoning Requirements

Prompts should instruct the AI to reason carefully internally.

Do not request hidden chain-of-thought.

Require concise structured decisions instead.

Example:

```json
{
  "page_goal": "Generate consultation requests",
  "audience": "First-time home buyers",
  "visual_direction": "Modern, reassuring and premium",
  "primary_conversion": "Start application",
  "story": [
    "Establish confidence",
    "Explain options",
    "Reduce uncertainty",
    "Provide proof",
    "Ask for application"
  ]
}
```

Store this as design metadata.

---

# 53. Initial Core Prompt

`prompts/core/system.md`

Initial content:

```text
You are acting as a senior digital designer, UX strategist and frontend design architect for a WordPress website.

Your job is not to assemble generic page-builder sections.

Design pages intentionally around:
- business objective
- audience
- brand
- content hierarchy
- conversion objective
- visual storytelling
- responsive experience

You may invent page structures freely.

Do not assume every page needs:
- a giant hero
- three-column cards
- gradients
- testimonial sliders
- FAQs
- excessive rounded cards

Avoid repetitive AI-looking layouts.

Use the site's design system but do not let it prevent creative composition.

Separate content from presentation.

Never generate PHP, SQL, shell commands or executable server code.

Use only the template language and field types provided by the AIWP Designer capabilities.

Before completing a page, inspect the rendered preview when available and perform a visual refinement pass.
```

---

# 54. Initial Page Designer Prompt

`prompts/design/page-designer.md`

```text
Design the page as an experienced agency-level web designer.

First understand:
- why this page exists
- who will visit
- what visitors need to understand
- what action visitors should take
- how the page relates to surrounding pages
- the existing brand and visual language

Choose an intentional visual direction before producing markup.

Create enough variation between sections that the page has rhythm.

Avoid making every section a centered heading followed by three cards.

Use:
- hierarchy
- typography
- whitespace
- asymmetric composition
- imagery
- contrast
- content density changes
- storytelling
- interaction

only when they improve the page.

The design must work on:
- desktop
- tablet
- mobile

Use semantic markup.

Use the AIWP safe template language.

Use editable structured fields for meaningful content.

Do not hard-code business content into the template when it should be editable.

Use the global design tokens wherever appropriate.

Page-specific CSS is allowed.

Do not generate JavaScript. Use supported behaviors.

After the initial page is rendered, visually inspect the preview and improve weak areas before considering the work complete.
```

---

# 55. Initial Design Critic Prompt

`prompts/review/design-critic.md`

```text
Review the rendered page as a demanding senior creative director.

Do not judge whether the code merely works.

Judge whether the page looks intentionally designed.

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
13. mobile behavior
14. professionalism
15. whether the design feels generic or distinctive

Identify the few changes that would produce the largest visual improvement.

Do not redesign elements merely to make them different.

Preserve strong work.

Then implement the improvements through the approved update tools.
```

---

# 56. Initial Responsive Prompt

`prompts/design/responsive-design.md`

```text
Responsive design must be intentional rather than desktop CSS that merely collapses.

Consider:

- heading scale
- text measure
- horizontal padding
- section spacing
- image crop
- content ordering
- card stacking
- navigation of interactive elements
- button width
- readable touch targets

Do not hide important content simply because space is limited.

Avoid horizontal overflow.

Use a small number of meaningful breakpoints.
```

---

# 57. Initial Performance Prompt

`prompts/review/performance.md`

```text
Prefer custom-code-like frontend output.

Avoid:

- unnecessary DOM wrappers
- decorative markup with no purpose
- duplicated CSS
- large libraries
- unnecessary animations
- layout shifts
- oversized images

Prefer:

- semantic HTML
- design tokens
- page-specific CSS
- WordPress responsive images
- native browser capabilities
- plugin-provided behavior modules
```

---

# 58. MCP Server

Expose the MCP endpoint through WordPress.

Recommended endpoint:

```text
POST /wp-json/aiwp-designer/v1/mcp
```

Implement current MCP protocol behavior behind a protocol abstraction.

Create:

```text
MCPServer
ProtocolAdapterInterface
CurrentProtocolAdapter
```

This allows future MCP specification changes without rewriting tools.

---

# 59. MCP Operations

Implement at minimum:

```text
server/discover
tools/list
tools/call
prompts/list
prompts/get
resources/list
resources/read
```

Do not implement deprecated protocol features unless needed for compatibility later.

---

# 60. MCP Tools

Initial tool catalog:

```text
workflow.prepare

site.get_context
site.get_capabilities

design.get_system
design.create_system
design.update_system
design.publish_system

pages.list
page.get
page.create
page.update
page.update_content
page.validate
page.get_preview_url
page.publish
page.rollback

media.search

performance.static_audit
```

---

# 61. site.get_context

Returns:

```json
{
  "site": {
    "name": "Example",
    "url": "https://example.com",
    "description": "",
    "language": "en-US"
  },
  "theme": {
    "name": "Example Theme"
  },
  "homepage": {
    "id": 2,
    "url": "https://example.com"
  },
  "brand_inputs": {},
  "aiwp_pages": []
}
```

Do not return secrets.

---

# 62. site.get_capabilities

Returns current capabilities.

Example:

```json
{
  "acf": {
    "available": true,
    "version": "..."
  },
  "field_types": [
    "text",
    "textarea",
    "image",
    "link",
    "wysiwyg",
    "true_false",
    "aiwp_repeater"
  ],
  "behaviors": [
    "accordion",
    "tabs",
    "modal",
    "counter",
    "reveal",
    "sticky",
    "carousel"
  ],
  "template_language_version": 1
}
```

AI must use this instead of guessing capabilities.

---

# 63. design.create_system

Input includes:

```json
{
  "workflow_id": "uuid",
  "design_system": {
    "colors": {},
    "typography": {},
    "spacing": {},
    "radius": {},
    "container": {}
  },
  "global_css": ""
}
```

Plugin:

1. validates
2. creates design draft
3. returns version
4. does not silently alter existing pages

---

# 64. page.create

Input contract:

```json
{
  "workflow_id": "uuid",

  "page": {
    "title": "First-Time Home Buyers",
    "slug": "first-time-home-buyers",
    "status": "draft"
  },

  "design_metadata": {
    "page_goal": "Lead generation",
    "audience": "First-time home buyers",
    "visual_direction": "Premium and reassuring",
    "primary_conversion": "Start application"
  },

  "sections": [
    {
      "id": "hero",
      "label": "Introduction",
      "fields": [
        {
          "id": "headline",
          "name": "headline",
          "label": "Headline",
          "type": "text",
          "required": true
        }
      ]
    }
  ],

  "content": {
    "hero": {
      "headline": "Buy Your First Home With Confidence"
    }
  },

  "template": "<main>...</main>",

  "css": "...",

  "behaviors": [
    "reveal"
  ],

  "chrome": "theme"
}
```

---

# 65. page.create Response

Example:

```json
{
  "success": true,
  "page_id": 142,
  "page_uuid": "uuid",
  "version": 1,
  "status": "draft",
  "preview_url": "https://example.com/...",
  "validation": {
    "errors": [],
    "warnings": []
  }
}
```

Never report success if storage partially failed.

Use transactions where possible and cleanup on failure.

---

# 66. page.update

Update may change:

- schema
- template
- CSS
- behaviors
- design metadata

It must not automatically publish.

Every accepted update creates a new version.

---

# 67. page.update_content

Used when only field values change.

This should not create unnecessary template files.

Input:

```json
{
  "workflow_id": "uuid",
  "page_id": 142,
  "updates": [
    {
      "field_path": "hero.headline",
      "value": "A Better Headline"
    }
  ]
}
```

---

# 68. page.get

Return:

- page metadata
- page schema
- normalized content
- template
- CSS
- behaviors
- design metadata
- current version

Never return authentication tokens.

---

# 69. page.get_preview_url

Return a preview URL.

The URL may be public only when the underlying page is public.

Draft previews must require appropriate WordPress authorization or a short-lived signed preview token.

Signed preview tokens must:

- expire
- identify page
- identify version
- contain random entropy/signature
- not expose WordPress credentials

---

# 70. page.publish

Publishing must be an explicit operation.

Input:

```json
{
  "workflow_id": "uuid",
  "page_id": 142,
  "version": 4,
  "confirm_publish": true
}
```

Reject if:

```text
confirm_publish != true
```

Validate again before publishing.

---

# 71. page.rollback

Input:

```json
{
  "workflow_id": "uuid",
  "page_id": 142,
  "version": 2
}
```

Rollback itself creates a new version.

Never destroy later historical versions.

---

# 72. Prompt MCP Support

Also expose prompts through standard MCP prompt operations.

Examples:

```text
build_page
redesign_page
redesign_section
create_design_system
critique_page
```

`workflow.prepare` remains mandatory for mutation even if prompt discovery is supported.

---

# 73. MCP Resources

Expose useful read-only resources.

Examples:

```text
aiwp://site/context
aiwp://design/system
aiwp://template-language
aiwp://behaviors
aiwp://page/{id}
```

Never expose raw server filesystem paths.

---

# 74. MCP Authentication Development Mode

For development, implement a plugin-generated connection token.

Store only a secure hash in WordPress.

Requests use:

```text
Authorization: Bearer TOKEN
```

Provide:

```text
AIWP Designer → Settings → MCP Connection
```

Functions:

- generate token
- revoke token
- regenerate token
- show endpoint

The full token should only be shown when created.

Development tokens must be clearly labelled as development/manual connection mode.

---

# 75. Production MCP Authorization Architecture

Authentication must be isolated behind:

```text
McpAuthProviderInterface
```

Implement manual Bearer authentication first.

Architecture must allow standards-based OAuth to be added without changing MCP tools.

Before claiming universal production compatibility with every MCP client, implement and test the authentication flow required by those clients.

Never put access tokens in URL query parameters.

---

# 76. WordPress Permissions

Create plugin capabilities:

```text
aiwp_manage_design
aiwp_edit_pages
aiwp_publish_pages
aiwp_manage_connections
```

Map these to administrators initially.

Editors may receive:

```text
aiwp_edit_pages
```

but not connection management unless explicitly granted.

MCP operations must enforce capabilities.

Authentication alone is not authorization.

---

# 77. REST API for Visual Editor

Implement:

```text
GET    /pages/{id}
GET    /pages/{id}/data
PATCH  /pages/{id}/fields
GET    /pages/{id}/versions
POST   /pages/{id}/rollback
GET    /design-system
```

Use WordPress REST permissions callbacks.

Admin React requests use WordPress REST nonces.

---

# 78. Versioning

Every design mutation creates an immutable snapshot.

Snapshot:

```text
template.aiwp
page.css
schema.json
content.json
manifest.json
```

Also record metadata:

```text
version
timestamp
user
workflow
checksum
```

---

# 79. Version Database Table

Create:

```text
{$wpdb->prefix}aiwp_versions
```

Suggested columns:

```text
id BIGINT
page_id BIGINT
page_uuid VARCHAR
version INT
created_at DATETIME
created_by BIGINT
workflow_type VARCHAR
manifest LONGTEXT
checksum VARCHAR
```

Use proper indexes.

Version files remain on filesystem.

Database table acts as version index.

---

# 80. Rollback Process

Rollback:

1. verify requested version
2. snapshot current state
3. restore target schema
4. restore target field values
5. restore template
6. restore CSS
7. restore manifest
8. clear caches
9. create new version representing rollback
10. verify render

Only remove metadata belonging to AIWP fields.

Never delete unrelated custom fields.

---

# 81. Atomic Writes

Generated files should use safe writes.

Process:

```text
write temporary file
validate
fsync/close where applicable
rename to destination
```

Do not overwrite live template with incomplete content.

---

# 82. Security Rules

Never trust:

- MCP payload
- REST payload
- template content
- CSS
- stored database content
- file paths
- browser messages

Validate input.

Sanitize stored values appropriately.

Escape output at rendering time.

Use WordPress capability checks.

Use nonces for browser-originated mutation requests.

Do not treat a nonce as authentication.

---

# 83. Path Traversal Protection

Reject:

```text
../
..\
absolute filesystem paths
null bytes
```

Generated filenames are derived from internal UUIDs, never user-controlled paths.

---

# 84. Audit Log

Maintain security/action log for:

```text
MCP connection
page creation
page update
publish
rollback
design system update
token generation
token revocation
validation rejection
```

Do not log access tokens.

---

# 85. Static Performance Auditor

No hosted Chromium is required.

Create deterministic checks.

Return:

```text
HTML size
DOM element estimate
CSS size
number of page behaviors
image count
oversized original images
missing alt text
missing dimensions
duplicate IDs
invalid field references
CSS selector count
```

Example:

```json
{
  "status": "warning",
  "checks": {
    "css_bytes": 18234,
    "dom_nodes": 326,
    "missing_alt": 0,
    "oversized_images": 1
  }
}
```

---

# 86. Browser Performance Philosophy

Runtime frontend should consist approximately of:

```text
theme shell
+
semantic HTML
+
small global CSS
+
page-specific CSS
+
small behavior library
```

Do not load the React editor on the public frontend.

Do not load all page CSS globally.

Do not load unused behavior modules if avoidable.

---

# 87. AI Visual Critique

The plugin itself does not host Chromium.

For MVP:

```text
Plugin
   ↓
returns preview URL
   ↓
Claude / ChatGPT
   ↓
opens preview using its own browser capability
   ↓
reviews page
   ↓
updates through MCP
```

Do not require screenshot infrastructure.

Optional screenshot backends can be added later.

---

# 88. Existing Website Brand Analysis

When an existing site exists:

1. AI receives homepage URL.
2. AI inspects it using its own browser ability.
3. AI identifies:
   - colors
   - typography
   - spacing
   - buttons
   - image treatment
   - visual tone
4. AI proposes design system.
5. Plugin stores design system.

Plugin itself does not need to crawl the internet in MVP.

---

# 89. Fresh WordPress Installation

If the site has no meaningful design:

Onboarding collects:

```text
Business name
Business description
Audience
Products/services
Logo
Preferred style
Optional brand colors
Optional brand guidelines
Reference websites
```

The user's AI uses these to generate the initial design system.

---

# 90. Admin Navigation

Create:

```text
AIWP Designer
    Dashboard
    Pages
    Design System
    Visual Editor
    MCP Connection
    Settings
```

---

# 91. Dashboard

Show:

```text
ACF status
MCP status
Design system status
Number of AI pages
Current plugin version
Template language version
Recent activity
```

---

# 92. AI Pages Screen

Table:

```text
Page
Status
AIWP Version
Design System Version
Updated
Actions
```

Actions:

```text
Visual Edit
Edit Fields
Preview
Versions
Open WordPress Page
```

---

# 93. Developer View

Optional advanced view per page:

```text
Template
CSS
Schema
Manifest
Field Mapping
Behaviors
Version History
```

Editing template/CSS manually can be added, but changes must pass the same validators and create versions.

---

# 94. Error Handling

Use structured error codes.

Examples:

```text
AIWP_ACF_MISSING
AIWP_TEMPLATE_INVALID
AIWP_CSS_INVALID
AIWP_FIELD_TYPE_UNAVAILABLE
AIWP_FIELD_REFERENCE_MISSING
AIWP_PERMISSION_DENIED
AIWP_WORKFLOW_EXPIRED
AIWP_VERSION_CONFLICT
AIWP_FILE_WRITE_FAILED
AIWP_MCP_UNAUTHORIZED
```

MCP response should include actionable messages.

---

# 95. Conflict Protection

Every update should contain expected version.

Example:

```json
{
  "page_id": 142,
  "expected_version": 4
}
```

If current is 5:

return:

```text
AIWP_VERSION_CONFLICT
```

Do not overwrite newer work.

---

# 96. Caching

Cache:

- parsed template AST
- compiled field maps
- design system
- generated CSS URL/version

Invalidate on relevant change.

Do not prematurely implement complex full-page caching.

Remain compatible with normal WordPress caching plugins.

---

# 97. Accessibility Baseline

Generated frontend infrastructure must support:

- semantic markup
- keyboard navigation
- visible focus
- accessible buttons
- accessible accordions
- accessible tabs
- meaningful link behavior
- image alt text
- heading hierarchy warnings
- reduced motion

AI design prompt should consider accessibility.

Plugin validators enforce deterministic rules when possible.

---

# 98. SEO Compatibility

Do not attempt to replace major SEO plugins in MVP.

AIWP pages remain normal WordPress pages.

Ensure compatibility with:

- WordPress title system
- canonical URLs
- SEO plugin hooks
- standard page permalinks

Do not generate competing `<title>` or canonical tags from AI templates.

---

# 99. Uninstall Behavior

Uninstall must not silently delete user website content.

Default uninstall:

- remove plugin transient/cache data
- preserve generated pages
- preserve ACF data
- preserve page files
- preserve design system

Provide explicit setting:

```text
Delete all AIWP data on uninstall
```

Default:

```text
false
```

---

# 100. Development Tooling

PHP:

```text
PHPUnit
WordPress test suite
PHPCS with WordPress Coding Standards
PHPStan if practical
```

JavaScript:

```text
Vitest
React Testing Library
ESLint
```

Optional development E2E:

```text
Playwright
```

Playwright is allowed in local development/CI.

It is **not** a runtime dependency of the WordPress plugin.

---

# 101. Test Fixtures

Create fixtures for:

```text
simple page
repeater page
invalid template
invalid CSS
missing field
malicious template
malicious CSS
large page
rollback
version conflict
```

---

# 102. Phase 0 — Repository and Development Environment

Implement:

- repository structure
- Composer
- npm
- PHPUnit
- PHPCS
- JS test runner
- build scripts
- WordPress local environment instructions

Create:

```text
IMPLEMENTATION_STATUS.md
TEST_RESULTS.md
docs/ADR.md
```

### Tests

Must verify:

- PHP autoload works
- PHPUnit runs
- JS tests run
- asset build runs
- coding standards command runs
- plugin zip can be generated

Do not proceed until all pass.

---

# 103. Phase 1 — Plugin Bootstrap

Implement:

- plugin header
- activation hook
- deactivation hook
- dependency checker
- admin menu
- capability registration
- upload directory manager

### Tests

Verify:

- plugin activates
- plugin deactivates
- no PHP warnings
- ACF missing shows notice
- ACF present removes notice
- correct capabilities created
- runtime directory created safely
- multisite path handling works

---

# 104. Phase 2 — ACF Integration

Implement:

- ACF manager
- dynamic schema registrar
- stable field keys
- page-specific field groups
- standard fields
- REST visibility

### Tests

Create AIWP page schema with:

```text
text
textarea
image
link
```

Verify:

- fields appear on page edit screen
- values save
- values reload
- `get_field()` returns correct value
- REST output works where configured
- page schema survives reload
- duplicate keys cannot occur

---

# 105. Phase 3 — Custom AI Repeater

Implement `aiwp_repeater`.

### Tests

Test:

```text
add row
remove row
duplicate row
reorder row
save
reload
empty repeater
one row
multiple rows
min limit
max limit
required subfield
```

Verify:

```php
get_field( 'feature_cards' )
```

returns expected associative array.

Test REST schema.

Test malicious input sanitization.

Test image subfield initialization.

Do not proceed until stable.

---

# 106. Phase 4 — Safe Template Engine

Implement:

```text
parser
AST
validator
renderer
field resolver
escaping
conditionals
repeaters
```

### Tests

Test:

```text
text interpolation
attribute interpolation
URL interpolation
HTML interpolation
if true
if false
repeater zero rows
repeater one row
repeater multiple rows
missing field
invalid nesting
malformed syntax
```

Security tests must reject:

```text
PHP
script
event handlers
javascript URLs
unsafe iframe
object
embed
```

Add fuzz-style malformed template tests.

---

# 107. Phase 5 — Design System and CSS

Implement:

- tokens
- global CSS
- page CSS
- CSS validation
- page scoping
- asset enqueue

### Tests

Verify:

- tokens compile
- global CSS loads once
- page CSS loads only on correct page
- invalid CSS rejected
- `@import` rejected
- javascript URL rejected
- global selector leakage warning/rejection
- version hash changes when CSS changes

---

# 108. Phase 6 — Trusted Behavior Library

Implement initial behaviors:

```text
accordion
tabs
modal
counter
reveal
sticky
carousel
```

### Tests

For each:

- initialization
- no-JS fallback
- keyboard behavior where relevant
- ARIA state changes
- multiple instances
- no initialization when absent
- destroy/reinitialize if needed

Public page must not load React.

---

# 109. Phase 7 — Page Manager and Rendering

Implement:

- page creation
- page manifest
- template storage
- CSS storage
- field population
- page shell
- theme mode
- blank mode
- frontend rendering

### Tests

Create page package end-to-end.

Verify:

```text
WordPress page created
fields registered
content stored
template saved
CSS saved
page renders
theme header/footer appears
page CSS appears
ACF values appear
repeater renders
```

Test failure rollback if file write fails.

---

# 110. Phase 8 — Versioning

Implement:

- versions table
- snapshot creation
- checksums
- rollback
- version conflict protection

### Tests

Sequence:

```text
create v1
change to v2
change to v3
rollback to v1
```

Verify:

- page matches v1
- historical v2/v3 remain
- rollback creates new version
- ACF values restored
- schema restored
- CSS restored
- template restored

Test simultaneous conflicting updates.

---

# 111. Phase 9 — WordPress REST APIs

Implement editor/headless endpoints.

### Tests

Verify:

- unauthenticated mutation rejected
- insufficient capability rejected
- valid nonce works
- invalid nonce rejected
- fields update
- repeater updates
- normalized page data endpoint works
- REST responses contain no secrets

---

# 112. Phase 10 — React Visual Editor

Implement:

- editor screen
- iframe preview
- editable selection
- inline text editing
- field sidebar
- images
- links
- repeaters
- save

### Tests

Component tests:

```text
select field
edit text
edit link
select image
repeater row add/delete/reorder
```

Integration tests:

```text
iframe sends selected field
parent validates origin
REST save
preview refresh
```

Optional local Playwright test:

```text
open editor
click headline
edit
save
refresh
verify frontend
```

---

# 113. Phase 11 — Prompt System

Implement:

- prompt loader
- frontmatter parser
- registry
- compiler
- workflow manager
- local prompt files

### Tests

Verify:

- prompt versions load
- invalid prompt rejected
- workflow composition deterministic
- only required prompts included
- context inserted
- prompt files cannot escape prompt directory
- workflow IDs expire

Snapshot-test compiled prompts.

---

# 114. Phase 12 — MCP Read-Only Server

Implement current MCP protocol adapter.

Initially expose:

```text
server/discover
tools/list
resources/list
resources/read
prompts/list
prompts/get
site.get_context
site.get_capabilities
pages.list
page.get
design.get_system
```

### Tests

Use an MCP client test harness.

Verify:

- discovery
- tools listing
- prompt listing
- resource reads
- malformed JSON-RPC rejection
- protocol version handling
- unauthorized access rejection
- response schemas

---

# 115. Phase 13 — MCP Mutation Tools

Implement:

```text
workflow.prepare
design.create_system
design.update_system
page.create
page.update
page.update_content
page.validate
page.get_preview_url
page.publish
page.rollback
```

All mutation tools require workflow ID.

### Tests

Verify:

- mutation without workflow rejected
- expired workflow rejected
- wrong workflow type rejected
- successful page creation
- validation failure leaves site unchanged
- version conflicts rejected
- publishing requires explicit confirmation

---

# 116. Phase 14 — MCP Connection UI

Implement:

```text
AIWP Designer → MCP Connection
```

Display:

- MCP endpoint
- connection status
- generate token
- revoke token
- setup instructions

### Tests

Verify:

- token shown only at creation
- stored token is hashed
- revoke works
- regenerated token invalidates old token
- unauthorized tool calls fail

---

# 117. Phase 15 — Preview and Critique Workflow

Implement preview URL tool and workflow prompts.

### Manual acceptance test

User tells connected AI:

```text
Create a premium services page for this business.
```

Expected sequence:

```text
workflow.prepare
site.get_context
site.get_capabilities
design.get_system
page.create
page.get_preview_url
AI opens preview
AI reviews design
page.update if needed
preview again
```

Verify the workflow can be completed without directly accessing server files.

---

# 118. Phase 16 — Static Performance Auditor

Implement deterministic audit.

### Tests

Provide fixtures containing:

```text
missing alt
oversized image
duplicate ID
large CSS
large DOM
broken field reference
```

Verify correct warnings.

---

# 119. Phase 17 — Security Hardening

Perform explicit security review.

Test:

```text
XSS
stored XSS
template injection
CSS injection
path traversal
CSRF
capability bypass
REST authorization
MCP token guessing protections
malformed JSON
oversized payload
version conflict
unsafe URL schemes
```

Set reasonable request-size limits.

Run WordPress coding standards.

Fix all high/critical findings.

---

# 120. Phase 18 — Performance and Compatibility

Test with:

```text
default WordPress theme
popular classic theme
block theme
permalink variations
caching plugin if practical
PHP supported versions
WordPress supported versions
ACF Free current version
```

Verify no dependency on ACF Pro.

---

# 121. Phase 19 — Packaging

Prepare:

```text
readme.txt
README.md
LICENSE
CHANGELOG.md
screenshots
installation instructions
MCP setup guide
developer documentation
template language documentation
field API documentation
```

Build release ZIP containing only production files.

Exclude:

```text
node_modules
tests
development configuration
source maps if unnecessary
local secrets
```

---

# 122. Required Developer Documentation

Create:

```text
docs/
    architecture.md
    template-language.md
    acf-repeater.md
    mcp.md
    prompts.md
    rest-api.md
    security.md
    development.md
```

---

# 123. README User Workflow

Documentation should explain:

### Install

```text
1. Install WordPress.
2. Install ACF Free.
3. Install AIWP Designer.
4. Activate both.
```

### Setup

```text
5. Open AIWP Designer.
6. Complete brand/business onboarding.
7. Open MCP Connection.
8. Generate/configure connection credentials.
9. Connect Claude/ChatGPT-compatible MCP client.
```

### Build

User says:

```text
Analyze my website and create a Services page.
```

AI builds draft.

AI receives preview.

AI reviews and improves.

User reviews.

User publishes.

---

# 124. WordPress Developer Usage

Normal field:

```php
$headline = get_field( 'hero_headline' );
```

Repeater:

```php
$cards = get_field( 'benefits_cards' );

foreach ( $cards as $card ) {
    echo esc_html( $card['title'] );
}
```

This must work without ACF Pro.

---

# 125. Headless Usage

Document:

```text
GET /wp-json/aiwp-designer/v1/pages/142/data
```

Response should provide a predictable structured content model.

A developer should be able to ignore the AIWP frontend renderer entirely and use AIWP only as:

```text
AI page planning
+
ACF structured content
+
WordPress CMS
```

---

# 126. Theme Usage

A custom theme developer may ignore AIWP template rendering and use generated ACF data directly.

AIWP must never lock content exclusively into its template files.

This is a core requirement.

---

# 127. Import / Export Future Compatibility

Architecture must make it possible later to export:

```text
design system
page schema
page template
page CSS
content
manifest
```

as a portable package.

Full import/export UI is not required for MVP.

Do not design storage in a way that prevents this.

---

# 128. What Is Explicitly Out of Scope for MVP

Do not implement unless all core phases are complete:

- AI model hosting
- external prompt API
- hosted screenshot service
- arbitrary AI-generated JavaScript
- arbitrary AI-generated PHP
- ecommerce builder
- full Gutenberg replacement
- Elementor import
- Figma import
- AI image generation
- visual drag-and-drop layout building
- nested AI repeaters
- cloud synchronization
- collaboration
- real-time multi-user editing

---

# 129. Definition of MVP Success

MVP is successful when this scenario works:

A user has:

```text
fresh WordPress
ACF Free
AIWP Designer
Claude/ChatGPT MCP connection
```

The user says:

```text
Create a modern website page for our mortgage company's first-time home buyer program. Follow our existing brand.
```

The AI:

1. gets AIWP workflow instructions
2. understands the site
3. reads available capabilities
4. reads the design system
5. plans the page
6. invents the page structure
7. defines standard ACF fields
8. uses `aiwp_repeater` where repeated content is required
9. generates safe template markup
10. generates page CSS
11. selects approved behaviors
12. creates initial content
13. creates the draft through MCP
14. receives preview URL
15. opens the preview
16. critiques the actual design
17. improves weak sections
18. returns the page for user approval

The user can then:

```text
Visual Edit
```

or:

```text
Edit Fields
```

A developer can later retrieve the same content through:

```php
get_field()
```

or the REST API.

The frontend contains:

```text
no Elementor dependency
no ACF Pro dependency
no generated PHP
no generated JavaScript
no hosted AI dependency
```

---

# 130. Design Quality Acceptance Criteria

Generated pages should not merely function.

The system should encourage designs with:

- clear hierarchy
- intentional typography
- strong spacing
- visual rhythm
- meaningful imagery
- varying compositions
- responsive behavior
- clear conversion flow
- brand consistency
- minimal generic AI appearance

The default design workflow must contain at least one visual review pass when the connected AI is capable of opening the preview URL.

---

# 131. Architecture Summary

Final architecture:

```text
                     USER
                      │
               Claude / ChatGPT
                      │
                     MCP
                      │
                      ▼
               AIWP DESIGNER
                      │
       ┌──────────────┼──────────────┐
       │              │              │
   Prompt Engine   Page Engine   Design System
       │              │              │
       │         Safe Template       │
       │              │              │
       │            ACF Free         │
       │         ┌────┴─────┐        │
       │         │          │        │
       │     Standard    AIWP        │
       │      Fields    Repeater     │
       │         │          │        │
       └─────────┴────┬─────┴────────┘
                      │
                 Page Renderer
                      │
             Global + Page CSS
                      │
               Trusted JS
                      │
                      ▼
                 WEB PAGE
                      │
                      ▼
              PREVIEW URL
                      │
                      ▼
               Claude / GPT
                visual review
                      │
                      ▼
                 improvements
```

---

# 132. Non-Negotiable Rules for the Coding Agent

Do not:

- introduce Elementor
- require ACF Pro
- generate PHP from AI
- execute AI code
- allow raw AI JavaScript
- bypass validation
- write generated files to theme directory
- hide content in proprietary binary formats
- make React editor the only editing option
- make the hosted service mandatory
- implement external prompt APIs in this version
- skip tests between phases
- silently change this architecture

Always preserve:

```text
WordPress compatibility
ACF developer friendliness
headless compatibility
AI design freedom
safe execution
open-source usability
local prompts
MCP interoperability
versioning
rollback
```

---

# 133. Commands the Coding Agent Should Provide

At the end of Phase 0, document exact working commands similar to:

```bash
composer install
npm install

npm run build
npm run test
composer test
composer phpcs
```

If different commands are chosen, document them.

One command should run the full suite:

```bash
composer test:all
```

or:

```bash
npm run test:all
```

---

# 134. Final Release Gate

Do not describe the project as release-ready until all are true:

```text
[ ] PHP tests pass
[ ] JavaScript tests pass
[ ] MCP contract tests pass
[ ] ACF standard fields pass
[ ] AIWP repeater passes
[ ] REST tests pass
[ ] template injection tests pass
[ ] CSS security tests pass
[ ] rollback tests pass
[ ] visual editor tests pass
[ ] capability tests pass
[ ] MCP authentication tests pass
[ ] no ACF Pro dependency exists
[ ] plugin activation has no warnings
[ ] plugin deactivation has no warnings
[ ] fresh WordPress installation tested
[ ] production ZIP tested
[ ] README setup tested from scratch
```

---

# 135. How to Begin Implementation

After reading this entire specification:

### First task

Do only **Phase 0**.

Do not implement WordPress functionality yet.

Create:

```text
repository structure
Composer configuration
npm configuration
test infrastructure
coding standards
build scripts
status files
ADR file
```

Run all Phase 0 tests.

Show:

```text
files created
commands run
test results
problems discovered
```

Update:

```text
IMPLEMENTATION_STATUS.md
TEST_RESULTS.md
```

Then stop.

Wait for the next instruction to continue to Phase 1.

---

# 136. Instructions for Continuing Development

For every later phase, the operator will say:

```text
Continue with Phase X from DOCUMENT.md.

Read the current implementation and status files first.

Implement only that phase.

Run the new phase tests and the complete regression suite.

Fix all failures.

Update IMPLEMENTATION_STATUS.md and TEST_RESULTS.md.

Do not begin the next phase.
```

This development pattern must be followed until all phases are complete.

---

# END OF SPECIFICATION