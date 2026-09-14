---
id: worked-examples
version: 1
category: design
description: The same brief solved badly and then well, so the difference is visible.
---

Six pairs. Each is one brief, solved twice. The first version is not broken — it
validates, it renders, it is what gets built when nobody decides anything. The
second is what it looks like when somebody did.

Read these for the **decision**, not the markup. Copying the markup would give
every site the same look, which is the thing to avoid. The businesses below are
deliberately unalike for the same reason.

---

## 1. Three services — a plumber

**Weak**

```html
<div class="grid">
  <div class="card"><h3>Emergency callouts</h3><p>24 hours a day.</p></div>
  <div class="card"><h3>Boiler servicing</h3><p>Annual checks.</p></div>
  <div class="card"><h3>Bathroom fitting</h3><p>Full installations.</p></div>
</div>
```

Three equal cards. Every service looks equally important, so none is. The
customer with a burst pipe at 2am — the one who pays most and decides fastest —
has to read all three.

**Strong**

```html
<section class="urgent">
  <p class="urgent__now">Burst pipe? Water everywhere?</p>
  <h2 class="urgent__head">We answer the phone at 2am.</h2>
  <a class="urgent__call" href="tel:...">Call 0161 xxx xxxx</a>
  <p class="urgent__eta">Average arrival in Didsbury: 38 minutes</p>
</section>

<ul class="also">
  <li><a href="/boiler-servicing">Boiler servicing</a> — annual checks and certificates</li>
  <li><a href="/bathrooms">Bathroom fitting</a> — full installations, fixed quotes</li>
</ul>
```

**The decision:** one of the three is worth more than the other two put together,
so it gets a section and they get a list. The phone number is the design. "38
minutes" is a real number doing more work than any adjective.

---

## 2. A headline — a funeral director

**Weak**

```html
<h1 class="h1">Compassionate funeral services in Leeds</h1>
<p class="lede">We provide caring and professional support at a difficult time.</p>
```

**Strong**

```html
<h1 class="h1">We will sit with you and work out what to do next.</h1>
<p class="lede">There is no hurry today. Call 0113 xxx xxxx whenever you are ready,
  or come in — we are on Otley Road, open until six.</p>
```

**The decision:** the first says what the business is. The second says what
happens next, in a voice that sounds like a person. Nobody in grief is shopping
for "compassionate services". Type stays quiet here on purpose — this is a page
where a loud headline would be wrong.

---

## 3. Proof — a B2B tool

**Weak**

```html
<div class="logos"><img …><img …><img …><img …></div>
<p>Trusted by leading companies</p>
```

**Strong**

```html
<figure class="proof">
  <blockquote>We cut our brief-writing time from three days to about four hours.
    I did not expect the structure work to be the part that mattered.</blockquote>
  <figcaption>Content lead, agency of 40, using it since March</figcaption>
</figure>
```

**The decision:** a row of grey logos proves nothing — anyone can put a logo on a
page. One specific sentence with a number in it proves something. If you cannot
name the person, say what you can: role, size, how long. Never invent either.

---

## 4. A grid of many things — a skate shop

**Weak**

```html
<div class="grid-4">
  <!-- 24 identical product tiles -->
</div>
```

Twenty-four equal tiles is a spreadsheet. The eye has nowhere to land and nothing
about it says skate shop rather than dental supplies.

**Strong**

```html
<div class="wall">
  <article class="wall__hero"><!-- the deck they actually want to sell --></article>
  <article class="wall__item">…</article>
  <article class="wall__item">…</article>
  <article class="wall__wide"><!-- "What we ride" — staff picks, three across --></article>
  <article class="wall__item">…</article>
</div>
```

**The decision:** break the grid on purpose. One item two columns wide, one row
that is a staff-picks strip, the rest ordinary. A shop has opinions about its
stock and the layout is where that shows.

---

## 5. A form — a law firm

**Weak**

```html
<form>
  <input name="name" placeholder="Name">
  <input name="email" placeholder="Email">
  <textarea name="message" placeholder="Message"></textarea>
  <button>Submit</button>
</form>
```

**Strong**

```html
<form>
  <p class="form__promise">A solicitor reads this, not a chatbot.
    We reply within one working day.</p>

  <label for="matter">What has happened?</label>
  <textarea id="matter" name="matter" rows="5"></textarea>
  <p class="form__help">A sentence or two is plenty. Do not send documents yet.</p>

  <label for="name">Your name</label>
  <input id="name" name="name" type="text" autocomplete="name">

  <label for="email">Where to reply</label>
  <input id="email" name="email" type="email" autocomplete="email">

  <button>Ask a solicitor</button>
</form>
```

**The decisions:** real labels, not placeholders that vanish when you type. The
important question first, because people abandon forms at the point they get
bored. The button says what happens. One line removes the fear that stops people
sending it.

---

## 6. Numbers — a bakery

**Weak**

```html
<div class="stats">
  <div class="stat"><span>100%</span><span>Quality</span></div>
  <div class="stat"><span>24/7</span><span>Service</span></div>
  <div class="stat"><span>1000+</span><span>Happy customers</span></div>
</div>
```

Three numbers that mean nothing. "100% quality" is not a measurement, and
"1000+ happy customers" is a number chosen to look like a number.

**Strong**

```html
<p class="rhythm">We start the ovens at <b>3am</b>. The sourdough has had
  <b>18 hours</b> by then. When the last loaf goes at about <b>two in the
  afternoon</b>, that is it until tomorrow.</p>
```

**The decision:** the numbers are true and they are in a sentence. They say
something about how this bakery works that a stat row cannot. If you have no real
numbers, do not build a stat row — write a sentence instead.

---

## What the six have in common

- something on the page is more important than the rest, and looks it
- a real number, a real sentence, a real name beats an adjective every time
- the layout comes from what the business actually does, not from a grid
- if the same page would work for a different company, it is not finished

None of them is about a style. Two of these sites should be quiet and two should
be loud, and that decision belongs in `style.personality`, not here.
