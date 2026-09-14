---
id: template-language
version: 2
category: core
description: The complete .aiwp template language.
---

The template is HTML plus a small set of interpolations and two blocks. That is
the whole language.

## Printing a field

```
{{text:hero.headline}}        escaped plain text
{{attr:hero.image_alt}}       escaped attribute value
{{url:hero.cta_url}}          escaped, scheme-checked URL
{{html:hero.body}}            sanitised rich HTML (wysiwyg fields)
{{image_url:hero.image}}      full-size image URL for that attachment
{{image_srcset:hero.image}}   the responsive srcset WordPress already generated
{{image_alt:hero.image}}      alt text from the media library
{{image_width:hero.image}}    intrinsic width in pixels
{{image_height:hero.image}}   intrinsic height in pixels
```

A path is `section.field`. Sections and fields are the ones you defined in
`sections`.

## Conditional

```html
{{#if:hero.image}}
<figure class="hero__media">
  <img src="{{image_url:hero.image}}" alt="{{image_alt:hero.image}}">
</figure>
{{/if}}
```

Empty string, empty array, `null` and `false` are all falsy.

## Repeater loop

```html
{{#each:benefits.cards}}
<article class="benefit">
  <h3>{{text:@item.title}}</h3>
  <p>{{text:@item.description}}</p>
  {{#if:@item.image}}
    <img src="{{image_url:@item.image}}" alt="{{attr:@item.title}}">
  {{/if}}
</article>
{{/each}}
```

Inside `{{#each}}`, `@item.subfield` refers to the current row. `@item` is only
valid inside an each block, and only for subfields the repeater declares.

## Not in this version

There is no `else`, no partial, no component, no expression, no maths, no
function call. Do not invent syntax; the parser rejects it.

## Images

Images are WordPress attachment ids, from `media_search`. Never a path, never an
external URL.

Write every image like this, so it is sharp on a retina screen and reserves its
space while loading:

```html
<img src="{{image_url:hero.image}}"
     srcset="{{image_srcset:hero.image}}"
     sizes="(min-width: 64rem) 60vw, 100vw"
     alt="{{image_alt:hero.image}}"
     width="{{image_width:hero.image}}"
     height="{{image_height:hero.image}}">
```

Set `sizes` to how wide the image really is at each breakpoint. Crop with CSS
(`aspect-ratio` plus `object-fit: cover`), never by choosing a smaller file.

Give the first image on the page `loading="eager"` and everything below the fold
`loading="lazy"`.

## Behaviors

Add interactivity by declaring it:

```html
<div class="faq" data-aiwp-behavior="accordion" data-aiwp-accordion-single="true">
  <div data-aiwp-accordion-item data-aiwp-open>
    <button data-aiwp-accordion-trigger>Question</button>
    <div data-aiwp-accordion-panel>Answer</div>
  </div>
</div>
```

Supported hooks per behavior:

- `accordion` — `data-aiwp-accordion-item`, `-trigger`, `-panel`, optional
  `data-aiwp-open` on an item, optional `data-aiwp-accordion-single="true"` on
  the root
- `tabs` — `data-aiwp-tablist`, `data-aiwp-tab` (one per panel),
  `data-aiwp-tabpanel`, in matching order
- `modal` — `data-aiwp-modal-open`, `data-aiwp-modal-dialog`,
  `data-aiwp-modal-close`
- `counter` — on the number element: `data-aiwp-counter-to="1200"`, optional
  `-prefix` / `-suffix`
- `reveal` — on any element; it fades in once on scroll
- `sticky` — on any element; it gets `is-stuck` once it leaves the top, optional
  `data-aiwp-sticky-offset="24"`
- `carousel` — `data-aiwp-carousel-track` around the slides, optional
  `data-aiwp-carousel-prev` / `-next`

List every behavior you use in the `behaviors` array of the page package.

Only `data-aiwp-*` and `aria-*` custom attributes are allowed. There is no
`style` attribute — everything visual goes in the page CSS.
