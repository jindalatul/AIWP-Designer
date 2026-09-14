# How to build a website with AIWP Designer

A short guide. You talk to your AI; the AI builds the pages.

---

## Before you start

1. WordPress running.
2. Advanced Custom Fields (free) active.
3. AIWP Designer active.
4. Your AI connected over MCP (**AIWP Designer → MCP Connection** → generate a
   token → paste the connect command into your client).

Then fill in **AIWP Designer → Brand & Business**. Five minutes here changes the
output more than anything else you can do. Be specific:

- bad: "We help businesses grow."
- good: "We design and build houses for private clients in West Yorkshire.
  Budgets £400k–£1.2m. Most clients have never built before and are nervous
  about cost."

---

## Step 1 — Set the look of the site, once

Say:

> Create the design system for this site. It should feel quiet, editorial and
> expensive — near-black, warm off-white, one copper accent. A serif for display
> type, a plain sans for body.

The AI picks the colours, spacing, radii and typefaces and stores them.

This is **site-wide**. Every page uses it. You do this once, not per page.

If you already have a website, say "look at https://oursite.com first" — the AI
opens it and reads your existing colours and type before proposing anything.

---

## Step 2 — Add your images first

Upload real photographs to **Media** before you ask for a page. The AI can only
use images that are already in your library — it will never invent one.

With no images, it will design something type-led instead. That can look good,
but for most businesses real photographs are the difference.

---

## Step 3 — Build the header and footer once

Say:

> Build the shared header and footer for the site.

The AI makes one navigation and one footer that every page uses. Build it early:
otherwise each page invents its own nav and they drift apart.

When you add a page later, say "add it to the navigation" — you change it in one
place, not on every page.

## Step 4 — Ask for a page

Say what the page is *for*, not what it should contain:

> Build a page for first-time home buyers. The point is to get them to start an
> application. They are nervous about the deposit and about being judged for
> asking basic questions.

Not:

> Build a page with a hero, three feature cards and an FAQ.

The second one gets you exactly that, and it will look like every other page on
the internet. Let the AI choose the structure from the goal.

The AI will:

1. read your brand and the site
2. plan the page
3. build it as a **draft**
4. open the preview and look at it
5. fix the weakest parts
6. give you a link

---

## Step 5 — Look at it and say what is wrong

Be blunt and specific. The AI can see the page, so you can talk about what you
see:

- "The three steps all look the same. Give them some rhythm."
- "The headline is too polite. Make it a claim."
- "That section is doing nothing. Cut it."
- "Check it on a phone — the numbers row is cramped."

Two or three rounds of this is normal. The first version is a draft, not an
answer.

---

## Step 6 — Publish

Nothing goes public until you say so:

> Publish it.

---

## Asking for a contact form

Just ask:

> Add a contact form. Name, email, roughly what they want to build, and their
> budget as a range. Send it to studio@example.com.

The AI never writes the form itself — it says what to ask for, and the plugin
builds it. You get, without asking:

- checks on required fields and email addresses, with errors shown by the field
- typed answers kept if something is wrong, so nobody retypes anything
- spam protection (a hidden trap field and a timing check)
- every message saved in WordPress under **AIWP Designer → Form Entries**
- an email to whoever you named, with Reply-To set to the sender
- a thank-you message in place of the form

It works with JavaScript switched off.

Keep forms short. Four questions get answered; twelve do not.

## Editing content afterwards

Three ways. Use whichever suits you.

**In WordPress.** Pages → edit the page. Every headline, paragraph, image and
list row is a normal field, grouped by section. No HTML.

**Ask the AI.** "Change the headline to X." That updates the words only and does
not touch the design.

**In code.** `get_field( 'hero_headline' )` works like any ACF site, and
`/wp-json/aiwp-designer/v1/pages/142/data` gives you the whole page as JSON for a
React or Next.js front end.

---

## Changing the design later

- **One section** — "Redesign the pricing section, make the middle plan
  obviously the recommended one."
- **The whole page** — "Redesign this page. Keep the words."
- **The whole site** — "Change the accent colour to deep green." This restyles
  every AIWP page, which is usually what you want.

Every change is a version. If one makes things worse:

> Roll back to version 3.

Nothing is ever lost — a rollback is itself a new version.

---

## Two brands on one site

The design system is site-wide, so a second brand would restyle the first. If one
page genuinely needs its own palette, tell the AI to put the override in *that
page's* CSS:

```css
:root {
  --aiwp-color-accent: #3f6bff;
}
```

Inside page CSS, `:root` is rewritten to that page only, so it cannot leak.

---

## What the AI can and cannot do

**Can:** invent the page structure, write the markup and CSS, write the starting
copy, define the editable fields, use your photographs, add accordions, tabs,
modals, carousels, counters, reveals and sticky headers.

**Cannot:** write PHP or JavaScript, load anything from another server, touch
your theme, publish without being asked, or delete your content.

That is deliberate. It is why you can let an AI edit your live website.

---

## Getting better results

- **Say what the page is for.** Goal and audience beat a list of sections.
- **Give it real numbers and real objections.** "Ninety-one per cent of work is
  repeat clients" is content; "trusted by many" is filler.
- **Upload good photographs.** The single biggest lever after the brief.
- **Ask for fewer sections.** Four strong ones beat eight weak ones.
- **Criticise like a client, not like a developer.** "That feels cheap" is more
  useful than "increase the padding".
- **Always look at the phone version** before you publish.

---

## When something goes wrong

| What you see | What to do |
|---|---|
| The AI says a field type is unavailable | ACF is not active, or the type is not installed |
| A change was refused | Read the message — it names the exact problem |
| "Version conflict" | Someone else edited it. Ask the AI to re-read the page |
| A page looks unstyled | The design system has not been created yet |
| The AI will not publish | By design. Ask it to publish explicitly |
