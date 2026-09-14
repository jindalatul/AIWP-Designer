#!/usr/bin/env python3
"""End-to-end check of the AIWP Designer MCP server against the docker site."""
import json, os, sys, urllib.request, urllib.parse, urllib.error, pathlib, re

ROOT = pathlib.Path(__file__).resolve().parent.parent
# Defaults to the site this was written against. Point it elsewhere with
# AIWP_SITE=http://localhost:8092 AIWP_TOKEN=... so a run never has to be aimed
# at a site somebody is actually looking at.
SITE = os.environ.get("AIWP_SITE", "http://localhost:8090").rstrip("/")
URL = SITE + "/wp-json/aiwp-designer/v1/mcp"
TOKEN = os.environ.get("AIWP_TOKEN") or (ROOT / ".mcp-token").read_text().strip()

_id = [0]
passed, failed = [], []

# The container that serves AIWP_SITE. Some checks drive WordPress directly
# rather than over HTTP, and they have to land on the same site as everything
# else — a run aimed at 8092 that quietly runs its PHP against 8090 reports
# failures that are not real, and writes to a site nobody meant to touch.
CONTAINER = os.environ.get("AIWP_CONTAINER", "")


def wp(code):
    """Run a line of PHP inside the WordPress container."""
    import subprocess
    if CONTAINER:
        cmd = ["docker", "exec", "-u", "www-data", CONTAINER, "wp", "eval", code]
    else:
        cmd = ["docker", "compose", "exec", "-T", "-u", "www-data", "wordpress", "wp", "eval", code]
    out = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    lines = [l for l in out.stdout.strip().splitlines() if "PHP Warning" not in l]
    return lines[-1] if lines else (out.stderr.strip() if out.stderr else "")



def rpc(method, params=None):
    _id[0] += 1
    body = json.dumps({"jsonrpc": "2.0", "id": _id[0], "method": method, "params": params or {}}).encode()
    req = urllib.request.Request(URL, data=body, headers={
        "Content-Type": "application/json",
        "Authorization": f"Bearer {TOKEN}",
    })
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())


def tool(name, args=None):
    out = rpc("tools/call", {"name": name, "arguments": args or {}})
    return out["result"]["structuredContent"]


def check(label, ok, detail=""):
    (passed if ok else failed).append(label)
    print(("  PASS  " if ok else "  FAIL  ") + label + (f"   {detail}" if detail and not ok else ""))


def fetch(url):
    with urllib.request.urlopen(url) as r:
        return r.read().decode()


# --- put the site back afterwards ----------------------------------------
# This suite writes its own design system and chrome. On a site somebody is
# actually looking at, that wipes their brand. Remember what was there and
# restore it at the end, whether the run passes or not.

def created_pages():
    """AIWP pages that exist right now, so the ones this run adds can be told apart."""
    return {p["page_id"] for p in tool("pages_list", {}).get("pages", [])}


def snapshot():
    ds = tool("design_get_system", {})
    chrome = tool("site_get_chrome", {})
    menus = tool("site_get_menus", {})
    return {
        "pages": created_pages(),
        "tokens": ds.get("tokens", {}),
        "global_css": ds.get("authored_global_css") or ds.get("global_css", ""),
        "chrome": chrome,
        "menus": {name: info.get("menu_id", 0)
                  for name, info in menus.get("locations", {}).items()
                  if info.get("assigned")},
    }


def restore(before):
    if not before["tokens"]:
        return
    wid = tool("workflow_prepare", {"workflow_type": "create_design_system"})["workflow_id"]
    tool("design_update_system", {
        "workflow_id": wid,
        "design_system": {k: v for k, v in before["tokens"].items() if k != "version"},
        "global_css": before["global_css"],
    })

    chrome = before["chrome"]
    if chrome.get("header") or chrome.get("footer"):
        wid = tool("workflow_prepare", {"workflow_type": "build_chrome"})["workflow_id"]
        tool("site_set_chrome", {
            "workflow_id": wid,
            "sections": chrome.get("sections", []),
            "header": chrome.get("header", ""),
            "footer": chrome.get("footer", ""),
            "css": chrome.get("authored_css") or chrome.get("css", ""),
            "content": chrome.get("content", {}),
        })
    for location, menu_id in before.get("menus", {}).items():
        tool("site_set_menu", {"location": location, "menu_id": menu_id})

    # Delete the pages this run created. Before this, every run left a dozen
    # behind; after ten runs the real site had 9 pages and 72 test ones, 199
    # directories on disk and 18MB of files.
    added = created_pages() - before.get("pages", set())
    removed = 0
    for page_id in added:
        if tool("page_delete", {"page_id": page_id, "confirm_delete": True, "permanent": True}).get("success"):
            removed += 1

    print(f"  ....  site design, chrome and menus restored; {removed} test page(s) removed")


BEFORE = snapshot()


print("\n== protocol ==")
init = rpc("initialize", {"protocolVersion": "2025-06-18", "clientInfo": {"name": "smoke"}})
check("initialize returns serverInfo", init["result"]["serverInfo"]["name"] == "AIWP Designer")
check("prompts/list has workflows", len(rpc("prompts/list")["result"]["prompts"]) >= 5)
check("resources/list works", len(rpc("resources/list")["result"]["resources"]) >= 5)
check("unknown method errors cleanly", "error" in rpc("nope/nope"))

print("\n== workflow gate ==")
blocked = tool("page_create", {"page": {"title": "No workflow"}, "sections": [], "template": "<main></main>"})
check("page_create without workflow_id is rejected", blocked.get("error", {}).get("code") == "AIWP_WORKFLOW_REQUIRED")
bad = tool("page_create", {"workflow_id": "not-a-real-id", "page": {"title": "x"}, "sections": [], "template": "<main></main>"})
check("expired/unknown workflow_id is rejected", bad.get("error", {}).get("code") == "AIWP_WORKFLOW_EXPIRED")

prep = tool("workflow_prepare", {"workflow_type": "build_page"})
wf = prep["workflow_id"]
check("workflow_prepare returns instructions", len(prep["instructions"]) > 2000)
check("instructions include the template language", "{{#each:" in prep["instructions"])
check("instructions include the design system", "aiwp-color-primary" in prep["instructions"])

wrong_type = tool("page_update", {"workflow_id": wf, "page_id": 1})
check("wrong workflow type is rejected", wrong_type.get("error", {}).get("code") == "AIWP_WORKFLOW_TYPE_MISMATCH")

print("\n== validation ==")
v = tool("page_validate", {"sections": [], "template": "<main><?php echo 1; ?></main>"})
check("PHP in a template is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main><script>alert(1)</script></main>"})
check("<script> is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": '<main><img src="x" onerror="alert(1)"></main>'})
check("inline event handler is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": '<main><a href="javascript:alert(1)">x</a></main>'})
check("javascript: URL is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main><iframe src=/></main>"})
check("<iframe> is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main>{{text:hero.nope}}</main>"})
check("unknown field reference is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main>ok</main>", "css": "@import url(http://evil.test/x.css);"})
check("@import in CSS is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main>ok</main>", "behaviors": ["teleport"]})
check("unknown behavior is rejected", not v["valid"])
v = tool("page_validate", {"sections": [], "template": "<main>{{#if:a.b}}oops</main>"})
check("unclosed block is rejected", not v["valid"])
v = tool("page_validate", {
    "sections": [{"id": "hero", "label": "Hero", "fields": [{"id": "headline", "name": "headline", "label": "H", "type": "text"}]}],
    "template": "<main><h1>{{text:hero.headline}}</h1></main>",
    "css": ".x{color:red}",
})
check("a clean package validates", v["valid"], json.dumps(v))

print("\n== page create ==")
SECTIONS = [
    {"id": "hero", "label": "Hero", "fields": [
        {"id": "eyebrow", "name": "eyebrow", "label": "Eyebrow", "type": "text"},
        {"id": "headline", "name": "headline", "label": "Headline", "type": "text", "required": True},
        {"id": "intro", "name": "intro", "label": "Intro", "type": "textarea"},
        {"id": "cta_label", "name": "cta_label", "label": "CTA label", "type": "text"},
        {"id": "cta_url", "name": "cta_url", "label": "CTA URL", "type": "url"},
        {"id": "image", "name": "image", "label": "Image", "type": "image"},
    ]},
    {"id": "steps", "label": "How it works", "fields": [
        {"id": "heading", "name": "heading", "label": "Heading", "type": "text"},
        {"id": "items", "name": "items", "label": "Steps", "type": "aiwp_repeater", "min": 1, "max": 6, "sub_fields": [
            {"id": "title", "name": "title", "label": "Title", "type": "text"},
            {"id": "body", "name": "body", "label": "Body", "type": "textarea"},
        ]},
    ]},
    {"id": "faq", "label": "FAQ", "fields": [
        {"id": "heading", "name": "heading", "label": "Heading", "type": "text"},
        {"id": "entries", "name": "entries", "label": "Questions", "type": "aiwp_repeater", "sub_fields": [
            {"id": "question", "name": "question", "label": "Question", "type": "text"},
            {"id": "answer", "name": "answer", "label": "Answer", "type": "textarea"},
        ]},
    ]},
]

TEMPLATE = """<main>
<section class="sm-hero" data-aiwp-behavior="reveal">
  <div class="aiwp-container">
    <p class="sm-eyebrow">{{text:hero.eyebrow}}</p>
    <h1>{{text:hero.headline}}</h1>
    <p class="sm-intro">{{text:hero.intro}}</p>
    {{#if:hero.cta_url}}<a class="sm-cta" href="{{url:hero.cta_url}}">{{text:hero.cta_label}}</a>{{/if}}
    {{#if:hero.image}}<img src="{{image_url:hero.image}}" alt="{{attr:hero.headline}}" width="1200" height="700">{{/if}}
  </div>
</section>
<section class="sm-steps">
  <div class="aiwp-container">
    <h2>{{text:steps.heading}}</h2>
    <ol class="sm-step-list">
      {{#each:steps.items}}
      <li class="sm-step">
        <h3>{{text:@item.title}}</h3>
        <p>{{text:@item.body}}</p>
      </li>
      {{/each}}
    </ol>
  </div>
</section>
<section class="sm-faq">
  <div class="aiwp-container">
    <h2>{{text:faq.heading}}</h2>
    <div class="sm-accordion" data-aiwp-behavior="accordion" data-aiwp-accordion-single="true">
      {{#each:faq.entries}}
      <div data-aiwp-accordion-item>
        <button data-aiwp-accordion-trigger>{{text:@item.question}}</button>
        <div data-aiwp-accordion-panel><p>{{text:@item.answer}}</p></div>
      </div>
      {{/each}}
    </div>
  </div>
</section>
</main>"""

CSS = """body { background: #fff; }
.sm-hero { padding: var(--aiwp-space-xl) 0; background: var(--aiwp-color-primary); color: #fff; }
.sm-hero h1 { font-size: clamp(2.2rem, 5vw, 4rem); max-width: 16ch; }
.sm-eyebrow { text-transform: uppercase; letter-spacing: .12em; color: var(--aiwp-color-accent); }
.sm-intro { max-width: 62ch; }
.sm-cta { display: inline-block; background: var(--aiwp-color-accent); color: #ffffff; padding: 14px 28px; border-radius: var(--aiwp-radius-medium); font-weight: 600; text-decoration: none; }
.sm-steps { padding: var(--aiwp-space-lg) 0; }
.sm-step-list { display: grid; gap: var(--aiwp-space-md); grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); list-style: none; padding: 0; }
.sm-faq { padding: var(--aiwp-space-lg) 0; background: var(--aiwp-color-surface); }
.sm-accordion button { width: 100%; text-align: left; padding: 16px 0; background: none; border: 0; border-bottom: 1px solid var(--aiwp-color-border); font-size: 1.05rem; cursor: pointer; }
@media (max-width: 48rem) { .sm-hero { padding: var(--aiwp-space-lg) 0; } }"""

CONTENT = {
    "hero": {
        "eyebrow": "First-time buyers",
        "headline": "Buy your first home with confidence",
        "intro": "A mortgage built for people who have never done this before, with a named adviser from first question to front door.",
        "cta_label": "Start your application",
        "cta_url": "/apply",
    },
    "steps": {
        "heading": "How it works",
        "items": [
            {"title": "Tell us your budget", "body": "Ten minutes online, no credit check yet."},
            {"title": "Get a decision in principle", "body": "Usually the same working day."},
            {"title": "Find your home", "body": "Your adviser stays with you through the offer."},
        ],
    },
    "faq": {
        "heading": "Questions people actually ask",
        "entries": [
            {"question": "How much deposit do I need?", "answer": "From 3% on qualifying homes."},
            {"question": "Does applying hurt my credit score?", "answer": "The first stage is a soft search only."},
        ],
    },
}

created = tool("page_create", {
    "workflow_id": wf,
    "page": {"title": "First-Time Home Buyers", "slug": "first-time-home-buyers", "status": "draft"},
    "design_metadata": {"page_goal": "Lead generation", "audience": "First-time buyers",
                        "visual_direction": "Premium and reassuring", "primary_conversion": "Start application"},
    "sections": SECTIONS,
    "content": CONTENT,
    "template": TEMPLATE,
    "css": CSS,
    "behaviors": ["reveal", "accordion"],
    "chrome": "theme",
})
check("page_create succeeded", created.get("success") is True, json.dumps(created)[:600])
if not created.get("success"):
    print("\nFAILURES:", failed)
    sys.exit(1)

page_id = created["page_id"]
preview = created["preview_url"]
check("page_create returned version 1", created["version"] == 1)
check("page_create returned a signed preview URL", "aiwp_preview=" in preview)
check("a relative value in a url field is warned about",
      any("url field" in w for w in created["validation"]["warnings"]),
      json.dumps(created["validation"]["warnings"]))

print("\n== rendering ==")
html = fetch(preview)
check("draft preview renders for an anonymous request", "Buy your first home with confidence" in html)
check("repeater rows render", "Get a decision in principle" in html)
check("second repeater renders", "How much deposit do I need?" in html)
check("page is wrapped in the AIWP container", 'class="aiwp-page" data-aiwp-page=' in html)
check("theme header/footer are used", "<html" in html.lower() and "</body>" in html.lower())
check("page CSS is enqueued", "aiwp-designer" in html and "page.css" in html)
check("global CSS is enqueued", "global.css" in html)
check("behaviors JS is enqueued", "behaviors.js" in html)
check("empty conditional stayed empty", "<img" not in html.split('class="sm-steps"')[0].split("sm-hero")[-1])
check("no React on the public page", "react" not in html.lower())

bad_preview = re.sub(r"aiwp_preview=[^&]+", "aiwp_preview=forged", preview)
try:
    forged = fetch(bad_preview)
    check("a forged preview token does not show the draft", "Buy your first home" not in forged)
except urllib.error.HTTPError as e:
    check("a forged preview token does not show the draft", e.code == 404)

print("\n== css scoping ==")
page = tool("page_get", {"page_id": page_id})
uuid = page["page_uuid"]
css = page["css"]
check("page CSS is scoped to this page", css.count(f'[data-aiwp-page="{uuid}"]') > 5)
check("bare body selector was scoped away", "body {" not in css and "body{" not in css)
check("media queries survived scoping", "@media" in css)

print("\n== acf developer api ==")
wp_eval = wp

headline = wp_eval(f"echo get_field('hero_headline', {page_id});")
check("get_field() returns a plain field", headline == "Buy your first home with confidence", headline)
rows = wp_eval(f"$r = get_field('steps_items', {page_id}); echo json_encode($r);")
try:
    parsed = json.loads(rows)
    check("get_field() returns the repeater as a PHP array", isinstance(parsed, list) and len(parsed) == 3, rows)
    check("repeater rows are keyed by sub field name", parsed[1]["title"] == "Get a decision in principle", rows)
except Exception:
    check("get_field() returns the repeater as a PHP array", False, rows)

print("\n== headless rest ==")
data = json.loads(fetch(f"{SITE}/wp-json/aiwp-designer/v1/pages/{page_id}/data")) if False else None
try:
    fetch(f"{SITE}/wp-json/aiwp-designer/v1/pages/{page_id}/data")
    check("draft data endpoint is not public", False)
except urllib.error.HTTPError as e:
    check("draft data endpoint is not public", e.code in (401, 403))

print("\n== versioning ==")
prep2 = tool("workflow_prepare", {"workflow_type": "redesign_page", "page_id": page_id})
wf2 = prep2["workflow_id"]
upd = tool("page_update", {
    "workflow_id": wf2, "page_id": page_id, "expected_version": 1,
    "template": TEMPLATE.replace("sm-hero", "sm-hero v2"),
    "css": CSS + "\n.sm-hero { border-bottom: 4px solid var(--aiwp-color-accent); }",
    "sections": SECTIONS, "behaviors": ["reveal", "accordion"],
})
check("page_update creates version 2", upd.get("version") == 2, json.dumps(upd)[:400])

stale = tool("page_update", {"workflow_id": wf2, "page_id": page_id, "expected_version": 1, "template": TEMPLATE, "sections": SECTIONS})
check("a stale expected_version is rejected", stale.get("error", {}).get("code") == "AIWP_VERSION_CONFLICT")

back = tool("page_rollback", {"page_id": page_id, "version": 1})
check("rollback succeeds", back.get("success") is True, json.dumps(back)[:400])
check("rollback creates a new version rather than rewriting history", back.get("version") == 3)
hist = tool("page_versions", {"page_id": page_id})
check("all three versions remain in history", len(hist["versions"]) == 3)
after = tool("page_get", {"page_id": page_id})
check("content survived the rollback", after["content"]["hero"]["headline"] == "Buy your first home with confidence")
check("template was restored to v1", "sm-hero v2" not in after["template"])

print("\n== content-only update ==")
prep3 = tool("workflow_prepare", {"workflow_type": "update_content", "page_id": page_id})
cu = tool("page_update_content", {"workflow_id": prep3["workflow_id"], "page_id": page_id,
                                  "updates": [{"field_path": "hero.headline", "value": "Your first home, without the guesswork"}]})
check("page_update_content succeeds", cu.get("success") is True, json.dumps(cu)[:300])
html2 = fetch(preview)
check("new copy appears on the page", "without the guesswork" in html2)
check("no extra version was created", tool("page_versions", {"page_id": page_id})["current"] == 3)
bad_field = tool("page_update_content", {"workflow_id": prep3["workflow_id"], "page_id": page_id,
                                         "updates": [{"field_path": "hero.does_not_exist", "value": "x"}]})
check("unknown field path is rejected", bad_field.get("success") is not True)

print("\n== audit + publish ==")
audit = tool("performance_static_audit", {"page_id": page_id})
check("static audit runs", "checks" in audit, json.dumps(audit)[:300])
check("audit counts DOM nodes", audit["checks"]["dom_nodes"] > 10)
check("audit sees exactly one h1", audit["checks"]["h1_count"] == 1)

nope = tool("page_publish", {"page_id": page_id, "confirm_publish": False})
# Publishing now insists somebody read the page back first.
not_looked = tool("page_publish", {"page_id": page_id, "confirm_publish": True})
check("publish before anyone has read the page back is refused",
      not_looked.get("error", {}).get("code") == "AIWP_NOT_LOOKED_AT", json.dumps(not_looked)[:200])

look = tool("page_look", {"page_id": page_id})
check("page_look reads the page back", look.get("ok") is True, json.dumps(look)[:200])
check("it reports what the page says", isinstance(look.get("reading"), list) and len(look["reading"]) > 0,
      json.dumps(look.get("reading"))[:200])
check("it knows which version it read", look.get("version", 0) > 0, json.dumps(look)[:120])

check("publish without confirmation is refused", nope.get("success") is not True)
pub = tool("page_publish", {"page_id": page_id, "version": 3, "confirm_publish": True})
check("publish succeeds with confirmation", pub.get("success") is True, json.dumps(pub)[:300])

# A design fault the reviewer calls high is not a matter of taste, and
# page_publish refuses it the way it refuses an unread page.
wid_bad = tool("workflow_prepare", {"workflow_type": "redesign_page", "page_id": page_id})["workflow_id"]
faint = tool("page_update", {"workflow_id": wid_bad, "page_id": page_id,
                             "css": ".sm-faint { color: #cfd4dc; background: #ffffff; }"})
if faint.get("success"):
    tool("page_look", {"page_id": page_id})
    refused = tool("page_publish", {"page_id": page_id, "confirm_publish": True})
    check("publishing text nobody can read is refused",
          refused.get("error", {}).get("code") == "AIWP_DESIGN_FAULT", json.dumps(refused)[:220])
    wid_fix = tool("workflow_prepare", {"workflow_type": "redesign_page", "page_id": page_id})["workflow_id"]
    tool("page_update", {"workflow_id": wid_fix, "page_id": page_id, "css": ""})
    tool("page_look", {"page_id": page_id})
    check("and publishing works again once it is fixed",
          tool("page_publish", {"page_id": page_id, "confirm_publish": True}).get("success") is True)
live = fetch(pub["url"])
check("published page is public", "without the guesswork" in live)
public_data = json.loads(fetch(f"{SITE}/wp-json/aiwp-designer/v1/pages/{page_id}/data"))
check("published headless data endpoint works", public_data["fields"]["hero"]["headline"] == "Your first home, without the guesswork")
check("headless repeater shape is a list of objects", isinstance(public_data["fields"]["steps"]["items"], list))

print("\n== images, fonts, behaviors ==")
caps = tool("site_get_capabilities")
check("responsive image filters are advertised",
      {"image_srcset", "image_alt", "image_width", "image_height"} <= set(caps["template_filters"]))
check("web fonts are advertised with a host allowlist",
      caps["fonts"]["web_fonts_allowed"] and "fonts.googleapis.com" in caps["fonts"]["hosts"])
MIN_SECTION = [{"id": "s", "label": "S", "fields": [{"id": "t", "name": "t", "label": "T", "type": "text"}]}]
v = tool("page_validate", {"sections": MIN_SECTION,
                           "template": '<main><table><tr><th scope="col">{{text:s.t}}</th></tr></table></main>'})
check("accessible table markup validates", v["valid"], json.dumps(v))
v = tool("page_validate", {"sections": MIN_SECTION,
                           "template": '<main><svg viewBox="0 0 24 24"><path d="M4 12h16" stroke-width="2"></path></svg>{{text:s.t}}</main>'})
check("inline svg validates", v["valid"], json.dumps(v))
v = tool("page_validate", {"sections": [], "template": '<main><div style="position:fixed">x</div></main>'})
check("a style attribute is still refused", not v["valid"])
base_css = fetch(SITE + "/wp-content/plugins/aiwp-designer/assets/dist/base.css")
check("the baseline makes [hidden] win over page CSS", "[hidden]{display:none!important;}" in base_css)
check("a modal is closed before its behavior runs", "[data-aiwp-modal-dialog]:not([data-aiwp-ready])" in base_css)
check("the baseline ships with the plugin, so an update reaches every site", ".aiwp-form__input" in base_css)



def workflow(kind, page_id=None):
    args = {"workflow_type": kind}
    if page_id:
        args["page_id"] = page_id
    return tool("workflow_prepare", args)["workflow_id"]


def workflow_id_for_design():
    return tool("workflow_prepare", {"workflow_type": "create_design_system"})["workflow_id"]


print("\n== forms ==")
FORM = {
    "id": "enquiry", "title": "Enquiry", "submit_label": "Send",
    "success_message": "Thank you.", "notify_email": "studio@example.com",
    "fields": [
        {"id": "name", "name": "name", "label": "Name", "type": "text", "required": True},
        {"id": "email", "name": "email", "label": "Email", "type": "email", "required": True},
        {"id": "message", "name": "message", "label": "Message", "type": "textarea", "required": True},
    ],
}
FORM_SECTION = [{"id": "c", "label": "C", "fields": [{"id": "h", "name": "h", "label": "H", "type": "text"}]}]

v = tool("page_validate", {"sections": FORM_SECTION, "forms": [FORM],
                           "template": '<main><h1>{{text:c.h}}</h1><div data-aiwp-form="enquiry"></div></main>'})
check("a declared and placed form validates", v["valid"], json.dumps(v))

v = tool("page_validate", {"sections": FORM_SECTION, "forms": [],
                           "template": '<main><h1>{{text:c.h}}</h1><div data-aiwp-form="ghost"></div></main>'})
check("placing a form that was never declared is rejected", not v["valid"])

v = tool("page_validate", {"sections": FORM_SECTION, "forms": [FORM],
                           "template": '<main><h1>{{text:c.h}}</h1></main>'})
check("a declared but unplaced form is a warning", v["valid"] and any("never placed" in w for w in v["warnings"]))

no_reply = dict(FORM, fields=[{"id": "name", "name": "name", "label": "Name", "type": "text"}])
v = tool("page_validate", {"sections": FORM_SECTION, "forms": [no_reply],
                           "template": '<main><h1>{{text:c.h}}</h1><div data-aiwp-form="enquiry"></div></main>'})
check("a form with no way to reply is rejected", not v["valid"])

v = tool("page_validate", {"sections": FORM_SECTION, "forms": [FORM],
                           "template": '<main><form action="https://evil.test"><input name="a"></form></main>'})
check("raw form markup is still refused", not v["valid"])

form_page = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Smoke contact", "slug": "smoke-contact"},
    "sections": FORM_SECTION, "content": {"c": {"h": "Talk to us"}},
    "template": '<main><h1>{{text:c.h}}</h1><div data-aiwp-form="enquiry"></div></main>',
    "css": ".x{color:red}", "forms": [FORM],
})
check("a page with a form is created", form_page.get("success") is True, json.dumps(form_page)[:300])
form_page_id = form_page["page_id"]
# A form belongs on a published page: that is where a visitor lands after sending.
tool("page_look", {"page_id": form_page_id})
tool("page_publish", {"page_id": form_page_id, "confirm_publish": True})
form_html = fetch(tool("page_get", {"page_id": form_page_id})["url"])
check("the plugin renders a real form", "<form" in form_html and 'name="fields[email]"' in form_html)
check("the form posts to WordPress, not to an AI-supplied URL", "admin-post.php" in form_html)
check("the form carries a signed token", 'name="aiwp_token"' in form_html)
check("the form has a honeypot", 'name="aiwp_website"' in form_html)
check("the form reports its fields as required", 'required' in form_html)

# Start from a clean slate so repeated runs do not trip the rate limiter.
wp("global $wpdb; $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'aiwp_form_entries');")
entries_before = tool("form_entries", {"limit": 1})["total"]
token = re.search(r'name="aiwp_token" value="([^"]+)"', form_html).group(1)

def post_form(data):
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(SITE + "/wp-admin/admin-post.php", data=body,
                                 headers={"Content-Type": "application/x-www-form-urlencoded"})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.url
    except urllib.error.HTTPError as e:
        return e.code, ""

status, landed = post_form({"action": "aiwp_form_submit", "aiwp_token": token, "aiwp_website": "",
                            "fields[name]": "Bot Test", "fields[email]": "nope", "fields[message]": "x"})
check("a bad email does not store an entry", tool("form_entries", {"limit": 1})["total"] == entries_before)

status, landed = post_form({"action": "aiwp_form_submit", "aiwp_token": token,
                            "aiwp_website": "http://spam.test",
                            "fields[name]": "Spam", "fields[email]": "spam@example.com", "fields[message]": "buy things"})
check("the honeypot silently drops a bot", tool("form_entries", {"limit": 1})["total"] == entries_before)

status, landed = post_form({"action": "aiwp_form_submit", "aiwp_token": "forged-token",
                            "fields[name]": "X", "fields[email]": "x@example.com", "fields[message]": "x"})
check("a forged token is refused", tool("form_entries", {"limit": 1})["total"] == entries_before)

status, landed = post_form({"action": "aiwp_form_submit", "aiwp_token": token, "aiwp_website": "",
                            "fields[name]": "Priya Raman", "fields[email]": "priya@example.com",
                            "fields[message]": "We have a plot and no idea where to start."})
check("an improbably fast submission is flagged, not discarded",
      tool("form_entries", {"limit": 1})["entries"][0]["values"].get("name") == "Priya Raman")
after = tool("form_entries", {"limit": 5})
check("a good submission is stored", after["total"] == entries_before + 1, f"{after['total']} vs {entries_before}")
check("the stored entry holds what was sent",
      after["entries"][0]["values"].get("email") == "priya@example.com"
      and after["entries"][0]["values"].get("message", "").startswith("We have a plot"),
      json.dumps(after["entries"][0])[:300])

sent_html = fetch(landed) if landed else ""
check("the visitor sees the success message", "Thank you." in sent_html, sent_html[:200])

print("\n== schema change plus content in one call ==")
# ACF caches registered fields for the life of a request. Adding a field and
# writing its value in the same call used to drop it silently.
GROW_A = [{"id": "s", "label": "S", "fields": [
    {"id": "one", "name": "one", "label": "One", "type": "text"}]}]
GROW_B = [{"id": "s", "label": "S", "fields": [
    {"id": "zero", "name": "zero", "label": "Zero", "type": "text"},
    {"id": "one", "name": "one", "label": "One", "type": "text"},
    {"id": "two", "name": "two", "label": "Two", "type": "textarea"}]}]

grow = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Smoke grow", "slug": "smoke-grow"},
    "sections": GROW_A, "content": {"s": {"one": "first"}},
    "template": "<main><h1>{{text:s.one}}</h1></main>", "css": ".x{color:red}"})
check("a page is created for the schema-growth check", grow.get("success") is True, json.dumps(grow)[:250])
grow_id = grow["page_id"]

grown = tool("page_update", {
    "workflow_id": workflow("redesign_page", grow_id), "page_id": grow_id,
    "expected_version": grow["version"],
    "sections": GROW_B,
    "content": {"s": {"zero": "before", "one": "changed", "two": "after"}},
    "template": "<main><p>{{text:s.zero}}</p><h1>{{text:s.one}}</h1><p>{{text:s.two}}</p></main>"})
check("the update succeeds", grown.get("success") is True, json.dumps(grown)[:250])

after_grow = tool("page_get", {"page_id": grow_id})["content"]["s"]
check("a field added in the same call is written", after_grow.get("zero") == "before", json.dumps(after_grow))
check("a field that moved keeps its new value", after_grow.get("one") == "changed", json.dumps(after_grow))
check("a field added at the end is written", after_grow.get("two") == "after", json.dumps(after_grow))

grow_html = fetch(tool("page_get_preview_url", {"page_id": grow_id})["preview_url"])
check("all three render on the page",
      "before" in grow_html and "changed" in grow_html and "after" in grow_html)

print("\n== code editor ==")
import urllib.request as _u

def code_api(path, params, method="POST"):
    """The code endpoints authenticate by cookie in the browser. Here they are
    driven through WordPress itself, as the admin user."""
    php = "wp_set_current_user(1);"
    php += "$r = new WP_REST_Request('%s', '/aiwp-designer/v1/code%s');" % (method, path)
    php += "$r->set_%s_params(%s);" % ("body" if method == "POST" else "query", php_array(params))
    php += "echo wp_json_encode(rest_do_request($r)->get_data());"
    return json.loads(wp(php))


def php_array(d):
    parts = []
    for k, v in d.items():
        if isinstance(v, int):
            parts.append("'%s' => %d" % (k, v))
        else:
            parts.append("'%s' => %s" % (k, json.dumps(str(v))))
    return "array(" + ", ".join(parts) + ")"


code_page = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Smoke code", "slug": "smoke-code"},
    "sections": MIN_SECTION, "content": {"s": {"t": "Code"}},
    "template": "<main><h1>{{text:s.t}}</h1></main>", "css": ".sc{color:red}"})
check("a page for the code editor is created", code_page.get("success") is True, json.dumps(code_page)[:200])
code_id = code_page["page_id"]

read = code_api("", {"target": "page", "page_id": code_id}, "GET")
check("the editor reads the template and CSS",
      "template" in read.get("files", {}) and "css" in read.get("files", {}), json.dumps(read)[:250])
check("it returns the CSS as written, not the scoped version",
      read["files"]["css"]["contents"].strip().startswith(".sc{"), read["files"]["css"]["contents"][:80])

bad = code_api("/check", {"target": "page", "page_id": code_id,
                          "template": "<main>{{text:s.nope}}</main>", "css": ".sc{color:red}"})
check("check catches an unknown field", bad["valid"] is False and bad["errors"], json.dumps(bad)[:250])
check("and reports the line it is on", bad["errors"][0]["line"] > 0, json.dumps(bad["errors"][0]))

php = code_api("/save", {"target": "page", "page_id": code_id,
                         "template": '<main><?php echo 1; ?></main>', "css": ".sc{color:red}"})
check("the server refuses PHP even when the browser check is bypassed",
      php.get("saved") is False, json.dumps(php)[:250])

blocked = code_api("/save", {"target": "page", "page_id": code_id,
                             "template": "<main><h1>{{text:s.t}}</h1></main>",
                             "css": "@import url(https://evil.test/x.css);"})
check("a save with invalid CSS is refused", blocked.get("saved") is False, json.dumps(blocked)[:200])

before_version = tool("page_get", {"page_id": code_id})["version"]
good = code_api("/save", {"target": "page", "page_id": code_id,
                          "template": "<main><h1>{{text:s.t}}</h1><p>edited by hand</p></main>",
                          "css": ".sc{color:blue}"})
check("a valid save goes through", good.get("saved") is True, json.dumps(good)[:250])
check("and creates a new version", good.get("version", 0) > before_version, json.dumps(good)[:200])
check("the change is live", "edited by hand" in fetch(tool("page_get_preview_url", {"page_id": code_id})["preview_url"]))

# Pressing save on a file nobody edited used to make a version every time, so a
# history of thirty versions could contain two changes.
same = code_api("/save", {"target": "page", "page_id": code_id,
                          "template": "<main><h1>{{text:s.t}}</h1><p>edited by hand</p></main>",
                          "css": ".sc{color:blue}"})
check("saving a file nobody changed is not an error",
      same.get("saved") is True and same.get("unchanged") is True, json.dumps(same)[:250])
check("and it does not make a new version",
      same.get("version") == good.get("version"), json.dumps(same)[:200])

back = tool("page_rollback", {"page_id": code_id, "version": before_version})
check("a hand edit can be rolled back like any other change", back.get("success") is True, json.dumps(back)[:200])
check("and the page is back to what it was",
      "edited by hand" not in fetch(tool("page_get_preview_url", {"page_id": code_id})["preview_url"]))

print("\n== fields the owner added ==")
own = tool("page_get", {"page_id": code_id})
owner_section = [{"id": "owner_extra", "label": "Added by hand", "owner": True,
                  "fields": [{"id": "note", "name": "note", "label": "Note", "type": "text"}]}]
wid = workflow("redesign_page", code_id)
seeded = tool("page_update", {"workflow_id": wid, "page_id": code_id,
                              "sections": own["sections"] + owner_section})
check("a section can be marked as the owner's", seeded.get("success") is True, json.dumps(seeded)[:200])

back = tool("page_get", {"page_id": code_id})
check("it comes back flagged", "owner_extra" in [s["id"] for s in back.get("owner_sections", [])],
      json.dumps([s["id"] for s in back.get("owner_sections", [])]))

# The AI sends the whole list every time. Omitting it must not delete it.
wid = workflow("redesign_page", code_id)
without = tool("page_update", {"workflow_id": wid, "page_id": code_id,
                               "sections": [s for s in own["sections"]]})
survived = [s["id"] for s in tool("page_get", {"page_id": code_id})["sections"]]
check("an update that leaves it out does not delete it", "owner_extra" in survived, json.dumps(survived))

check("and the page says so, so the AI can place it",
      "owner" in tool("page_get", {"page_id": code_id}).get("owner_note", "").lower())

print("\n== seo ==")
seo_before = tool("seo_audit", {"page_id": code_id})
check("an audit runs on a page with no brief", "findings" in seo_before, json.dumps(seo_before)[:200])
check("and says the brief checks were skipped",
      any("brief" in n for n in seo_before.get("not_checked", [])), json.dumps(seo_before.get("not_checked"))[:200])

set_brief = tool("page_set_brief", {
    "page_id": code_id,
    "primary_keyword": "smoke keyword",
    "meta_title": "A title written for the smoke test, long enough to pass",
    "meta_description": "A description written for the smoke test that is comfortably long enough to clear the minimum length the auditor asks for.",
    "questions": ["What is a smoke keyword?"],
    "entities": ["a concept the page will not mention"],
    "schema_types": ["FAQPage"],
})
check("a brief can be recorded", set_brief.get("success") is True, json.dumps(set_brief)[:200])

got = tool("page_get_brief", {"page_id": code_id})
check("and read back", got.get("exists") is True and got["brief"]["primary_keyword"] == "smoke keyword",
      json.dumps(got)[:200])

seo_after = tool("seo_audit", {"page_id": code_id})
ids = [f["id"] for f in seo_after["findings"]]
check("the audit uses the brief once there is one", seo_after.get("has_brief") is True)
check("a keyword the page never says is reported", "keyword_absent" in ids, json.dumps(ids))
check("a concept the page never mentions is reported", "entities_missing" in ids, json.dumps(ids))
check("the title now comes from the brief",
      seo_after["title"].startswith("A title written"), seo_after["title"])
check("an audit still says what it did not check", len(seo_after.get("not_checked", [])) > 0)

unknown_page = tool("seo_audit", {"page_id": 999999})
check("auditing a page that does not exist fails cleanly", "error" in unknown_page, json.dumps(unknown_page)[:160])

print("\n== navigation menus ==")
caps = tool("site_get_capabilities")
check("menu locations are advertised", "primary" in caps["menus"]["locations"])

v = tool("page_validate", {"sections": MIN_SECTION,
                           "template": '<main><h1>{{text:s.t}}</h1><nav data-aiwp-menu="primary"></nav></main>'})
check("a known menu location validates", v["valid"], json.dumps(v))
v = tool("page_validate", {"sections": MIN_SECTION,
                           "template": '<main><h1>{{text:s.t}}</h1><nav data-aiwp-menu="sidebar"></nav></main>'})
check("an unknown menu location is rejected", not v["valid"])

pages_for_menu = tool("pages_list")["pages"][:3]
menu = tool("site_set_menu", {
    "location": "primary", "title": "Smoke menu",
    "items": [{"label": "First", "page_id": pages_for_menu[0]["page_id"]},
              {"label": "Elsewhere", "url": "https://example.com/"}]})
check("a WordPress menu is created", menu.get("success") is True, json.dumps(menu)[:250])
check("it is editable in Appearance > Menus", "nav-menus.php" in menu.get("edit_url", ""))
check("both item kinds were added", menu.get("items") == 2, json.dumps(menu)[:200])

described = tool("site_get_menus")["locations"]["primary"]
check("the menu reads back", described["assigned"] is True and len(described["items"]) == 2, json.dumps(described)[:250])

menu_page = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Smoke menu page", "slug": "smoke-menu-page"},
    "sections": MIN_SECTION, "content": {"s": {"t": "Menu test"}},
    "template": '<main><h1>{{text:s.t}}</h1><nav data-aiwp-menu="primary"></nav></main>',
    "css": ".x{color:red}"})
check("a page placing a menu is created", menu_page.get("success") is True, json.dumps(menu_page)[:250])

menu_html = fetch(menu_page["preview_url"])
check("the menu renders into the page", 'class="aiwp-menu' in menu_html and "Elsewhere" in menu_html)
check("menu items carry styling hooks", "aiwp-menu__item" in menu_html and "aiwp-menu__link" in menu_html)

# Replacing a menu should not append to the old one.
again = tool("site_set_menu", {"location": "primary", "title": "Smoke menu",
                               "items": [{"label": "Only one", "url": "https://example.com/"}]})
check("setting a menu again replaces it rather than appending",
      len(tool("site_get_menus")["locations"]["primary"]["items"]) == 1, json.dumps(again)[:200])

bad_loc = tool("site_set_menu", {"location": "nowhere", "items": []})
check("an unknown location is refused", bad_loc.get("success") is not True)

# Rebuild a menu with several items, then behave like the Menus screen.
tool("site_set_menu", {"location": "primary", "title": "Smoke menu",
                       "items": [{"label": "One", "url": "https://example.com/one"},
                                 {"label": "Two", "url": "https://example.com/two"},
                                 {"label": "Three", "url": "https://example.com/three"}]})
before_ids = [i["label"] for i in tool("site_get_menus")["locations"]["primary"]["items"]]
check("three items are in the menu", before_ids == ["One", "Two", "Three"], json.dumps(before_ids))

# Editing a menu must not churn every item id, or the owner's edits are lost.
ids_before = wp("$m = wp_get_nav_menu_object('Smoke menu'); echo implode(',', wp_list_pluck(wp_get_nav_menu_items($m->term_id), 'ID'));")
tool("site_set_menu", {"location": "primary", "title": "Smoke menu",
                       "items": [{"label": "One renamed", "url": "https://example.com/one"},
                                 {"label": "Two", "url": "https://example.com/two"}]})
ids_after = wp("$m = wp_get_nav_menu_object('Smoke menu'); echo implode(',', wp_list_pluck(wp_get_nav_menu_items($m->term_id), 'ID'));")
check("updating a menu reuses item ids instead of recreating them",
      ids_after.split(",")[:2] == ids_before.split(",")[:2], f"{ids_before} -> {ids_after}")
check("an item removed from the list is dropped",
      [i["label"] for i in tool("site_get_menus")["locations"]["primary"]["items"]] == ["One renamed", "Two"])

# Deleting an item in wp-admin must show up straight away.
wp("$m = wp_get_nav_menu_object('Smoke menu'); $items = wp_get_nav_menu_items($m->term_id); wp_delete_post($items[1]->ID, true);")
check("deleting an item in wp-admin is reflected at once",
      [i["label"] for i in tool("site_get_menus")["locations"]["primary"]["items"]] == ["One renamed"])

# WordPress clears the location when the Menus screen is saved without ticking
# the box, and a theme switch empties it too. The menu must survive both.
wp("$l = get_theme_mod('nav_menu_locations', array()); $l['aiwp_primary'] = 0; set_theme_mod('nav_menu_locations', $l);")
still = tool("site_get_menus")["locations"]["primary"]
check("a cleared location does not lose the menu", still["assigned"] is True, json.dumps(still)[:250])
check("and the theme mod is repaired",
      "aiwp_primary" in wp("echo json_encode(get_theme_mod('nav_menu_locations'));")
      and "0" != json.loads(wp("echo json_encode(get_theme_mod('nav_menu_locations'));")).get("aiwp_primary", 0))

print("\n== front page ==")
# Keep whatever the site already had, and put it back at the end.
front_before = tool("site_get_context")["homepage"]["id"]

# Start from an empty slot so the naming rule can be exercised.
tool("site_set_front_page", {"page_id": 0})
check("the root can be handed back to the blog listing", tool("site_get_context")["homepage"]["is_set"] is False)

MIN = [{"id": "s", "label": "S", "fields": [{"id": "h", "name": "h", "label": "H", "type": "text"}]}]

ordinary = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Smoke pricing", "slug": "smoke-pricing"},
    "sections": MIN, "content": {"s": {"h": "Pricing"}},
    "template": "<main><h1>{{text:s.h}}</h1></main>", "css": ".x{color:red}"})
check("an ordinary page does not claim the front page", ordinary.get("front_page") is False, json.dumps(ordinary)[:200])

home = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Home", "slug": "smoke-home"},
    "sections": MIN, "content": {"s": {"h": "Smoke home"}},
    "template": "<main><h1>{{text:s.h}}</h1></main>", "css": ".x{color:red}"})
check("a page named Home is created", home.get("success") is True, json.dumps(home)[:200])
home_id = home["page_id"]

# A draft must not become the front page: visitors would get a 404 at the root.
check("a draft homepage does not take the root yet", home.get("front_page") is False)
check("but the intent is recorded", home.get("front_page_pending") is True, json.dumps(home)[:300])

tool("page_look", {"page_id": home_id})
published = tool("page_publish", {"page_id": home_id, "confirm_publish": True})
check("publishing the homepage makes it the front page", published.get("front_page") is True, json.dumps(published)[:300])

root = fetch(SITE + "/")
check("the site root now serves that page", "Smoke home" in root, root[:200])

ctx = tool("site_get_context")["homepage"]
check("site context reports the front page", ctx["id"] == home_id and ctx["is_aiwp"] is True, json.dumps(ctx))
check("site context reports no front-page problem", ctx["problem"] == "", json.dumps(ctx))

second = tool("page_create", {
    "workflow_id": workflow("build_page"),
    "page": {"title": "Home", "slug": "smoke-home-2"},
    "sections": MIN, "content": {"s": {"h": "Second home"}},
    "template": "<main><h1>{{text:s.h}}</h1></main>", "css": ".x{color:red}"})
check("a second page named Home does not steal the root",
      second.get("front_page") is False and second.get("front_page_pending") is False, json.dumps(second)[:250])

draft_front = tool("site_set_front_page", {"page_id": ordinary["page_id"]})
check("pointing the root at a draft is refused, and recorded instead",
      draft_front.get("front_page") is False and "draft" in draft_front.get("note", ""),
      json.dumps(draft_front)[:250])
check("the root still serves the published homepage", "Smoke home" in fetch(SITE + "/"))

tool("page_look", {"page_id": ordinary["page_id"]})
tool("page_publish", {"page_id": ordinary["page_id"], "confirm_publish": True})
check("publishing it then hands over the root, because the intent was recorded",
      tool("site_get_context")["homepage"]["id"] == ordinary["page_id"])
check("the root follows", "Pricing" in fetch(SITE + "/"))

for pid in (home_id, second["page_id"], ordinary["page_id"]):
    tool("page_update_content", {"workflow_id": workflow("update_content", pid), "page_id": pid, "updates": []})

if front_before:
    tool("site_set_front_page", {"page_id": front_before})
else:
    tool("site_set_front_page", {"page_id": ordinary["page_id"]})

print("\n== shared header and footer ==")
chrome_before = tool("site_get_chrome")
CHROME_SECTIONS = [{"id": "brand", "label": "Brand", "fields": [
    {"id": "name", "name": "name", "label": "Name", "type": "text"}]},
    {"id": "nav", "label": "Nav", "fields": [
        {"id": "links", "name": "links", "label": "Links", "type": "aiwp_repeater", "sub_fields": [
            {"id": "label", "name": "label", "label": "Label", "type": "text"},
            {"id": "url", "name": "url", "label": "URL", "type": "text"}]}]}]

bad_chrome = tool("site_set_chrome", {"workflow_id": workflow("build_chrome"), "sections": CHROME_SECTIONS,
                                      "header": '<div><script>alert(1)</script></div>'})
check("chrome markup goes through the same validator", bad_chrome.get("success") is not True)

chrome = tool("site_set_chrome", {
    "workflow_id": workflow("build_chrome"),
    "sections": CHROME_SECTIONS,
    "content": {"brand": {"name": "Smoke Co"},
                "nav": {"links": [{"label": "Work", "url": "/work/"}, {"label": "Contact", "url": "/contact/"}]}},
    "header": '<div class="sc-bar"><a href="/">{{text:brand.name}}</a><nav aria-label="Primary">{{#each:nav.links}}<a href="{{attr:@item.url}}">{{text:@item.label}}</a>{{/each}}</nav></div>',
    "footer": '<div class="sc-foot"><p>{{text:brand.name}}</p></div>',
    "css": ".sc-bar{display:flex;gap:20px}",
})
check("the shared chrome is stored", chrome.get("success") is True, json.dumps(chrome)[:300])
check("chrome is versioned", chrome["version"] >= 1)

read_back = tool("site_get_chrome")
check("site_get_chrome reads it back", read_back["exists"] and "Smoke Co" in read_back["content"]["brand"]["name"])
check("chrome CSS is scoped to the chrome", "[data-aiwp-chrome]" in read_back["css"])

switched = tool("page_update", {"workflow_id": workflow("redesign_page", form_page_id), "page_id": form_page_id,
                                "expected_version": tool("page_get", {"page_id": form_page_id})["version"],
                                "chrome": "site"})
check("a page can switch to the shared chrome", switched.get("success") is True, json.dumps(switched)[:250])

chrome_html = fetch(tool("page_get_preview_url", {"page_id": form_page_id})["preview_url"])
check("the shared header renders on the page", 'data-aiwp-chrome="header"' in chrome_html and "Smoke Co" in chrome_html)
check("the shared footer renders on the page", 'data-aiwp-chrome="footer"' in chrome_html)
check("the shared nav links render", "/work/" in chrome_html)
check("chrome CSS is enqueued", "chrome.css" in chrome_html)
check("the chrome holder page is hidden from the page list",
      all(p["page_id"] != read_back.get("holder_id") for p in tool("pages_list")["pages"]))
check("site context does not list the chrome holder",
      all("header" not in (p["title"] or "").lower() or "footer" not in (p["title"] or "").lower()
          for p in tool("site_get_context")["aiwp_pages"]))

print("\n== design system ==")
prep4 = tool("workflow_prepare", {"workflow_type": "create_design_system"})
ds = tool("design_create_system", {"workflow_id": prep4["workflow_id"],
                                   "design_system": {"colors": {"primary": "#0f2a4a", "accent": "#e0a33e"}},
                                   "global_css": ".aiwp-page strong { font-weight: 700; }"})
check("design_create_system succeeds", ds.get("success") is True, json.dumps(ds)[:300])
check("design system version went up", ds["version"] >= 2)
bad_ds = tool("design_create_system", {"workflow_id": prep4["workflow_id"], "design_system": {}, "global_css": "@import url(http://evil.test/a.css);"})
check("malicious global CSS is rejected", bad_ds.get("success") is not True)
check("creating a design system says how many pages it restyles",
      ds.get("affects_pages", 0) >= 1 and any("one design system" in w for w in ds["warnings"]),
      json.dumps(ds.get("warnings")))

merged = tool("design_update_system", {"workflow_id": workflow_id_for_design(), "design_system": {"colors": {"accent": "#123456"}}})
check("design_update_system merges instead of resetting",
      merged.get("success") is True and merged["tokens"]["colors"]["accent"] == "#123456"
      and merged["tokens"]["colors"]["primary"] == ds["tokens"]["colors"]["primary"],
      json.dumps(merged)[:300])

bad_font = tool("design_update_system", {"workflow_id": workflow_id_for_design(),
                                         "design_system": {"typography": {"font_url": "https://evil.test/f.css"}}})
check("a font URL off the allowlist is dropped", bad_font["tokens"]["typography"]["font_url"] == "")
good_font = tool("design_update_system", {"workflow_id": workflow_id_for_design(),
                                          "design_system": {"typography": {"font_url": "https://fonts.bunny.net/css?family=inter:400"}}})
check("an allowlisted font URL is kept", good_font["tokens"]["typography"]["font_url"].startswith("https://fonts.bunny.net"))

# A menu the site owner already built must be placeable without rewriting it.
before_items = tool("site_get_menus", {})["locations"]["primary"]["items"]
reassigned = tool("site_set_menu", {"location": "primary", "menu_id": tool("site_get_menus", {})["locations"]["primary"]["menu_id"]})
after_items = tool("site_get_menus", {})["locations"]["primary"]["items"]
check("site_set_menu with menu_id alone leaves the menu items alone",
      reassigned.get("success") is True and after_items == before_items,
      json.dumps(reassigned)[:200])

unknown = tool("site_set_menu", {"location": "primary", "menu_idd": 1})
check("an argument the tool does not have is refused, not ignored",
      unknown.get("success") is False and unknown["error"]["code"] == "AIWP_UNKNOWN_ARGUMENT",
      json.dumps(unknown)[:200])

restore(BEFORE)

print(f"\n{len(passed)} passed, {len(failed)} failed")
if failed:
    print("\nFailed:")
    for f in failed:
        print("  -", f)
    sys.exit(1)
