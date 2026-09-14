# Forms

You never write `<form>`, `<input>` or any form markup — the validator rejects it.
You declare what the form asks for, and the plugin renders it, validates it,
stores the submission, emails a notification and deals with spam.

## Declare it

In the page package:

```json
"forms": [
  {
    "id": "enquiry",
    "title": "Tell us about the site",
    "submit_label": "Send enquiry",
    "success_message": "Thank you. We reply to every enquiry within two working days.",
    "notify_email": "studio@example.com",
    "consent_note": "We only use these details to reply to your enquiry.",
    "fields": [
      { "id": "name",    "name": "name",    "label": "Your name", "type": "text",     "required": true,  "width": "half" },
      { "id": "email",   "name": "email",   "label": "Email",     "type": "email",    "required": true,  "width": "half" },
      { "id": "budget",  "name": "budget",  "label": "Budget",    "type": "select",   "width": "half",
        "choices": { "under_400": "Under £400k", "400_800": "£400k–£800k", "over_800": "Over £800k" } },
      { "id": "message", "name": "message", "label": "What are you hoping to build?", "type": "textarea", "required": true, "rows": 6 },
      { "id": "consent", "name": "consent", "label": "I am happy to be contacted about this enquiry", "type": "consent", "required": true }
    ]
  }
]
```

## Place it

In the template, leave an empty element where the form belongs:

```html
<div class="enquiry__form" data-aiwp-form="enquiry"></div>
```

The plugin fills it in. A placeholder pointing at a form you did not declare is a
hard error, and a form you declared but never placed is a warning.

## Field types

`text` `email` `tel` `url` `number` `textarea` `select` `radio` `checkbox` `consent`

Each field takes `required`, `placeholder`, `help`, and `width` — `full`, `half`
or `third`, on a six-column grid. `select` and `radio` need `choices`.

Every form must have an `email` or `tel` field; without one a reply is
impossible, and the package is rejected.

## Style it

The markup is predictable. Style it in your page CSS:

```
.aiwp-form            the form
.aiwp-form__fields    the grid
.aiwp-form__field     one field, plus --half / --third and .is-invalid
.aiwp-form__label
.aiwp-form__input     input, textarea and select
.aiwp-form__choice    a checkbox or radio row
.aiwp-form__help      the help line
.aiwp-form__error     the error line
.aiwp-form__submit    the button
.aiwp-form__success   the message shown after sending
```

There is a plain default so a form is never unstyled, but it is a fallback, not a
design. Style the form like the rest of the page: same type, same field height as
your buttons, same radius, real focus states.

## What the plugin does for you

- required, email, URL and number checks, with the errors shown by the field
- values kept when there is an error, so nobody retypes anything
- a honeypot and a timing check for bots
- rate limiting per address
- the entry stored in WordPress, visible under AIWP Designer → Form Entries
- an email to `notify_email`, or the site admin, with Reply-To set to the sender
- the success message shown in place of the form

It works with JavaScript switched off.

## Ask for less

A form with four fields gets filled in. A form with twelve does not. Ask for the
fewest things that let someone reply properly, and put anything optional last.
