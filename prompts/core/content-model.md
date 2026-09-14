---
id: content-model
version: 1
category: core
description: How to decide what becomes an editable field.
---

Every page is `sections` (the field model) + `template` (the markup) + `content` (the starting words).

A section is a group of fields with an id and a label. It is how the fields are organised in the WordPress editor, so name sections after what the visitor sees: `hero`, `how_it_works`, `proof`, `pricing`, `faq`.

Make a field when a business owner would plausibly want to change the words, the image or the link. Keep it in the template when it is pure structure or decoration.

Field types available (confirm with `site.get_capabilities`):

`text` `textarea` `number` `email` `url` `image` `file` `wysiwyg` `select` `checkbox` `radio` `true_false` `link` `aiwp_repeater`

Use `aiwp_repeater` for anything that repeats: cards, steps, FAQ entries, stats, logos, testimonials, table rows. Declare its `sub_fields`. Repeaters cannot nest inside repeaters.

```json
{
  "id": "benefits",
  "label": "Benefits",
  "fields": [
    { "id": "heading", "name": "heading", "label": "Heading", "type": "text" },
    {
      "id": "cards", "name": "cards", "label": "Benefit cards", "type": "aiwp_repeater",
      "min": 2, "max": 6,
      "sub_fields": [
        { "id": "title", "name": "title", "label": "Title", "type": "text" },
        { "id": "body", "name": "body", "label": "Description", "type": "textarea" },
        { "id": "image", "name": "image", "label": "Image", "type": "image" }
      ]
    }
  ]
}
```

Content mirrors that shape:

```json
{
  "benefits": {
    "heading": "Why buyers choose us",
    "cards": [
      { "title": "Low down payment", "body": "From 3% down on qualifying homes." }
    ]
  }
}
```

Field type notes that matter in practice:

- `url` accepts absolute URLs only (`https://example.com/apply`). For a link inside this site use type `link`, which stores title + url + target, or plain `text` if you only need the path.
- `image` stores a WordPress attachment id, not a path or a URL.
- `link` returns `{ title, url, target }`; print it as `{{url:hero.cta.url}}` is NOT valid — give the link its own fields, or use `{{url:...}}` on a `url` field and `{{text:...}}` for the label.
- `true_false` is 1 or 0, and works well with `{{#if:...}}`.

## Fields the site owner added

`page_get` returns `owner_sections`: fields a person added through the admin,
without you. They belong to them.

- **Never leave them out of the sections you send back.** The plugin keeps them
  whatever you send, but a schema that omits them is a mistake, not a decision.
- **Place them in the template.** Until you do, they render in a plain block
  after your design. That is a fallback so the owner sees their words, not a
  finished thing.
- **Do not rename or retype them.** They chose those labels; somebody has
  already typed content into them.
- If a section they added duplicates one of yours, say so rather than quietly
  designing around it.

Rules:

- a field `id` is permanent; changing a label is fine, moving an id is a migration
- write real, specific starting content — never "Lorem ipsum", never "Your headline here"
- never invent an attachment id; get a real one from `media_search` or `media_import`, and make the design look right with the image missing anyway
