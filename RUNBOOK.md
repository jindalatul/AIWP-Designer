# Running this for real

Everything in one place: what exists, how to start it, and the exact order to
build a site with both MCP servers connected.

---

## 1. What exists

| Thing | Where |
|---|---|
| The plugin | `aiwp-designer/` (mounted live into the container) |
| Test WordPress site | `docker-compose.yml` — http://localhost:8090 |
| Setup / reset | `./setup.sh`, `./reset.sh` |
| Unit tests (no WordPress) | `./aiwp-designer/scripts/test.sh` |
| End-to-end tests | `python3 tests/smoke.py` |
| MCP command-line driver | `python3 tests/driver.py <tool> '<json>'` |
| Worked example pages | `tests/designs/` |
| Non-technical guide | `guide/AIWP-Designer-launch-guide.pdf` |

Demo pages currently live on the test site:

- `/halden/` — architecture studio (editorial, image-led)
- `/contact/` — working contact form, shared header and footer
- `/ledgerline/` — B2B SaaS (tabs, pricing, table, FAQ, carousel, modal)
- `/convertrank/` — built from a real ConvertRank brief

---

## 2. Start the site

```bash
cd ~/Desktop/aiwp
./setup.sh          # first time, or after ./reset.sh
docker compose up -d   # afterwards
```

Site: http://localhost:8090 · Admin: `/wp-admin` (`admin` / `admin`)
The MCP token is printed by `setup.sh` and saved in `.mcp-token`.

---

## 3. Connect both MCP servers

Two servers, two jobs. ConvertRank decides **what** to build; AIWP builds it.

```bash
# The website builder
claude mcp add --transport http aiwp \
  http://localhost:8090/wp-json/aiwp-designer/v1/mcp \
  --header "Authorization: Bearer $(cat ~/Desktop/aiwp/.mcp-token)"

# The content strategy, one per project
claude mcp add --transport http convertrank-mainsite \
  "http://localhost:8000/agent_platform/api/mcp_server.php?project=PROJECT_ID"
```

Check both are up:

```bash
claude mcp list
```

For a site on a real host, swap `localhost:8090` for the domain and generate the
token in **AIWP Designer → MCP Connection** on that site.

---

## 4. The real-world loop

In one session with both servers connected:

1. **What to build next** — `list_due_pages` on ConvertRank.
2. **The brief** — `get_brief` for a page id. H1, outline, questions, keywords,
   CTA, voice rules, forbidden words.
3. **The brand** — `get_brand`. Colours, fonts, tone.
4. **Set the look, once** — `workflow_prepare` (`create_design_system`) then
   `design_create_system` with the ConvertRank tokens.
5. **Header and footer, once** — `workflow_prepare` (`build_chrome`) then
   `site_set_chrome`.
6. **Build the page** — `workflow_prepare` (`build_page`) then `page_create`,
   with `chrome: "site"` and a form if the brief asks for one.
7. **Look at it** — open `preview_url`, critique, `page_update`.
8. **Check it** — `performance_static_audit`.
9. **Publish** — `page_publish` with `confirm_publish: true`.
10. **Mark it live** in ConvertRank, then go back to step 1.

Said in plain words to a connected AI, that whole loop is:

> Ask ConvertRank what page is due, read its brief and brand, set up the design
> system and the shared header and footer, then build that page in WordPress,
> look at it, improve it and show me the draft.

---

## 5. Things to know before doing it for real

- **One site, one brand.** The design system and the shared header/footer are
  site-wide. A second brand needs its own WordPress site, or a `:root` override
  inside that page's own CSS.
- **`tests/smoke.py` changes site state while it runs.** It writes its own
  design system, chrome and menu, then puts yours back at the end. It also
  leaves behind the pages it created. Still do not point it at a live site.
- **Upload photos first.** The AI can only use attachments that already exist.
- **Drafts are private.** A preview link is signed and expires in an hour.
- **Email needs a mail service.** WordPress `wp_mail` on a bare container usually
  cannot send. Form entries are always stored, so nothing is lost — but install
  an SMTP plugin before relying on the notification email.
- **Publishing is always explicit.** Nothing goes public unless asked.

---

## 6. Known gaps

- No React visual editor; content is edited through WordPress admin fields.
- Nothing forces the AI to actually open the preview before saying it is done.
- Page CSS is unbounded. Nothing tracks whether it shrinks as the component
  library grows, so a site can accumulate near-duplicate CSS page by page.
- The shared header and footer live on a hidden page with its own storage, its
  own admin screen and its own review path. It works, and it is a special case
  that costs something every time anything touches it.
- A brief's `source` and `source_id` are stored but not emitted as a meta tag,
  so a tool that verifies its own pages by reading one cannot find it.
