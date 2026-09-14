---
id: safety
version: 1
category: core
description: What the plugin will reject outright.
---

The plugin validates everything you send. These are hard rejections, not warnings.

Templates are rejected if they contain:

- `<?php`, `<?=` or any PHP
- `<script>`, `<style>`, `<link>`, `<meta>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<input>`
- any inline event handler: `onclick=`, `onerror=`, `onload=` and friends
- `javascript:` or `vbscript:` URLs
- an HTML element or attribute outside the allowlist
- a `{{...}}` reference to a field the schema does not define

CSS is rejected if it contains:

- `@import`
- `expression(`
- `javascript:`
- `-moz-binding`
- `behavior:`
- a `url()` pointing at anything but an image

Page CSS is automatically scoped to that page. You can write plain selectors; the plugin rewrites them so they cannot leak into the theme. Do not try to target `html`, `body` or `*` to escape the page.

Interactivity comes from declared behaviors only:

```html
<div data-aiwp-behavior="accordion">
```

If a validation error comes back, read it, fix that exact thing and resend. Do not work around the validator.
