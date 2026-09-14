# `aiwp_repeater`

A repeating group of fields, without ACF Pro.

This field is written against ACF's public extension API. It does not copy ACF
Pro's source and does not imitate ACF Pro's database layout.

## Defining one

```json
{
  "id": "cards",
  "name": "cards",
  "label": "Benefit cards",
  "type": "aiwp_repeater",
  "min": 2,
  "max": 6,
  "sub_fields": [
    { "id": "title", "name": "title", "label": "Title", "type": "text" },
    { "id": "body",  "name": "body",  "label": "Description", "type": "textarea" },
    { "id": "image", "name": "image", "label": "Image", "type": "image" }
  ]
}
```

Sub field types: `text` `textarea` `number` `email` `url` `image` `wysiwyg`
`select` `true_false` `link`.

A repeater cannot contain a repeater. One is downgraded to `text` rather than
silently accepted.

## Reading it

```php
$cards = get_field( 'benefits_cards', $page_id );

foreach ( $cards as $card ) {
    echo esc_html( $card['title'] );
    echo wp_get_attachment_image( $card['image'], 'medium' );
}
```

Rows come back as plain PHP arrays keyed by sub field name, in order.

## In a template

```html
{{#each:benefits.cards}}
<article>
  <h3>{{text:@item.title}}</h3>
  <p>{{text:@item.body}}</p>
  {{#if:@item.image}}<img src="{{image_url:@item.image}}" alt="{{attr:@item.title}}">{{/if}}
</article>
{{/each}}
```

## Storage

The whole repeater is **one** post meta value: a serialised list of name-keyed
rows.

```php
array(
  array( 'title' => 'Fast approval',    'body' => '…', 'image' => 145 ),
  array( 'title' => 'Flexible options', 'body' => '…', 'image' => 192 ),
)
```

ACF Pro stores each row and each sub field as its own meta row. This field does
not, and does not try to be compatible with that layout. The field owns its
format.

## Editing it in wp-admin

Add, duplicate, remove, and drag to reorder. Each row renders real ACF sub
fields, so an image sub field gets the normal media picker. New rows are cloned
from a hidden template and then handed to ACF (`acf.doAction('append', …)`) so
those controls initialise.

Limits are enforced twice: the UI disables the buttons, and `validate_value()`
rejects a payload that breaks `min`, `max` or a required sub field.

## REST

The field reports a schema derived from its sub fields:

```json
{
  "type": ["array", "null"],
  "items": {
    "type": "object",
    "properties": {
      "title": { "type": ["string", "null"] },
      "image": { "type": ["integer", "null"] }
    }
  }
}
```

## One implementation note

`acf_format_value()` caches per post id + field name. Every row shares a sub
field name, so going through it would return row 1's value for every row. This
field applies the `acf/format_value/type={type}` filter directly instead.
