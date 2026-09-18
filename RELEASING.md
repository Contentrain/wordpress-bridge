# Releasing Contentrain Bridge

Two different things get called "release-ready", and conflating them is how a
plugin ships before it is finished.

1. **The archive is sound** — it builds, every automated gate is green, and what
   it installs is what was tested.
2. **The product is finished** — the things the plugin promises have been done
   against something real, not a mock.

The first is checked below and is currently true. The second has open gates,
listed at the end, and they are not paperwork.

## Build the archive

```bash
npm ci --ignore-scripts
npm run test:wordpress    # container acceptance suite
npm run test:plugin       # official Plugin Check
npm run test:store        # contentrain validate + canonical byte parity
npm test                  # handoff intake
docker compose -f tests/compose.yml run --rm -T cli \
  eval-file /var/www/html/wp-content/plugins/contentrain-bridge/tests/uninstall.php
npm run package           # dist/contentrain-bridge.zip
docker compose -f tests/compose.yml down -v
```

CI runs exactly this. A green run means the archive matches what was tested —
`tools/package.py` archives runtime files only, so `tests/`, `tools/`, `dist/`
and `.github/` never reach a user's site.

Run the acceptance suite with `KEEP=1`, as CI does: the steps after it reuse the
installed site, and without it the site is torn down and Plugin Check has
nothing to check. Start from `docker compose -f tests/compose.yml down -v` — a
reused stack keeps the content of previous runs, which inflates the check and
entry counts and makes a local number look unlike CI's.

Last full clean run: 148 acceptance checks · Plugin Check 0 errors / 13
warnings · `contentrain validate` 12 models, 21 entries · canonical byte parity
39 files · 3 documents carry frontmatter · handoff and uninstall pass · archive
19 runtime files.

## The reader gate

```bash
npm run test:reader                                   # the pinned published reader
CONTENTRAIN_TYPES=../ai/packages/types/dist/index.mjs npm run test:reader
```

This gate decides, on *real output*, whether the reader this plugin is pinned to
can read what this plugin writes. The acceptance suite writes `reader-compat.md`
with the plugin's own frontmatter writer, plus `reader-compat.json` holding the
values that went in; the script reads the document back and compares.

It exists because the answer was once no. Until `@contentrain/types@1.14.0` the
published reader stripped a scalar's quotes without decoding its escapes — 4 of
14 values came back changed — and `Policy::frontmatter` refused to finalize an
export whose metadata needed escapes rather than publish content the reader
would alter. With 1.14.0 pinned, 14 of 14 survive, the guard is gone, and this
gate runs in CI so the compatibility cannot regress silently.

Run it against a candidate build before bumping the pin:

```bash
CONTENTRAIN_TYPES=../ai/packages/types/dist/index.mjs npm run test:reader
```

A failure means that reader would alter this plugin's output. Do not bump to it.

## Version

One version lives in three places and CI fails if they disagree: the plugin
header `Version:`, the `CONTENTRAIN_BRIDGE_VERSION` constant, and `Stable tag:`
in `readme.txt`. Change all three, add a `== Changelog ==` entry, then build.

## readme.txt

CI refuses a readme that claims no external requests while the code calls the
GitHub API. If `wp_remote_*` appears under `includes/`, `readme.txt` must carry
an `= External services =` section naming `api.github.com`. This is both a
WordPress.org review requirement and an accurate privacy statement; do not
weaken the check to make a release pass.

## Dependency pins

`package.json` pins `@contentrain/types`, `@contentrain/wp-import` and
`contentrain` to exact published versions. They are what the store verification
runs against, so the pins are the claim "this plugin's output is readable by the
published chain". Bump them deliberately and re-run `npm run test:store`; a pin
ahead of npm, or behind a fix the plugin depends on, makes that claim false.

## The delivery gate

```bash
KEEP=1 npm run test:wordpress
BRIDGE_TEST_REPO=owner/repo BRIDGE_TEST_TOKEN=... npm run test:delivery
```

Everything else proves delivery through a mocked HTTP API, which says a great
deal about this plugin's state machine and nothing about GitHub. This runs the
real thing against a real repository: first delivery with a pause and resume
mid-run, the branch on GitHub matching the commit the plugin reported, every
exported file present, the manifest self-identifying, the token absent from the
job state and from user meta, a repeat delivery returning the existing receipt
without a second commit or a second branch, and — after the delivery is merged
and a person edits a managed file — the next delivery refusing rather than
silently winning.

It is not in CI: it needs a repository it may write to and a credential. Use a
repository that exists only for this, because the run merges into the default
branch and edits a file there. Give the token Contents read/write on that one
repository and nothing else; a broadly scoped token has no business in a test
container.

Last run: **13 checks against `Contentrain/bridge-delivery-test`**, including the
conflict refusal with the real message — *Git content conflict at
bridge/site.json. Keep the repository edit and reconcile before exporting
again.*

## Open gates

These are the reason this plugin is not on WordPress.org yet. None is closed by
another green CI run.

| Gate | What is actually missing |
|---|---|
| ~~**Reader compatibility**~~ | **Closed.** `@contentrain/types@1.14.0` decodes escaped frontmatter; the pin is bumped, the guard is gone, and `npm run test:reader` proves it against this writer's own PHP output on every CI run |
| ~~**B-10 real delivery**~~ | **Closed.** `npm run test:delivery` runs first delivery, pause and resume, repeat delivery, and the conflict after a user edit against a real private repository — see *The delivery gate*. What it does not yet cover: a repository whose tree GitHub truncates, branch protection on the default branch, and the private-content/public-repository refusal |
| ~~**Remote**~~ | **Closed.** `git ls-remote origin` and `git branch -vv` confirm `main` is pushed and in sync with `github.com/Contentrain/wordpress-bridge` |
| **B-04 SEO/redirect runtime coverage** | Yoast SEO and Redirection are tested at runtime: `npm run test:seo` compares every exported head value and redirect with what the live site serves. Rank Math, AIOSEO, Yoast Premium redirects and Safe Redirect Manager are tested **only at the stored-format level** (their real table/meta/option shapes, seeded) — their rendered values and live redirects are not. Multilingual SEO (WPML/Polylang) is not covered. `@contentrain/verify@0.2.0` cannot yet report a page the migration dropped (`status.baseline-page-missing` is unreleased) |
| ~~**B-07 integrations**~~ | **Closed, 2026-09-18.** `npm run test:integrations`: a real Mailchimp for WP install plus the stored settings of HubSpot, Akismet, Hotjar, Jetpack, reCAPTCHA (Contact Form 7), WPForms and Gravity Forms feeds, and a tracking script in the rendered head, are all reported with evidence; deliberately fake credentials planted in those settings appear in no exported file, and `npm run test:secrets` scans `bridge/integrations.json` too. Not covered: services configured only in a page builder's own storage |
| **B-11 real consumption** | **Narrowed.** `npm run test:e2e` runs in CI: the richest fixture (ACF, Yoast, Redirection, menu, media) is exported, built by Astro through `@contentrain/query`'s loader with one page per entry and every relation, media file, menu item and redirect checked; three WordPress edits then reach Git as exactly those three entries and the right pages after a rebuild. **Still open:** the GitHub leg of that run needs the `BRIDGE_TEST_REPO`/`BRIDGE_TEST_TOKEN` repository secrets (a dedicated repository; see the script) and reports `SKIP` until they exist — the local leg commits the same trees to a real Git repository. Studio reading and editing the delivered store is waiting on staging and has not been run |
| **B-01 directory review** | **Narrowed, 2026-09-18.** Manual review done: no trialware/upsell language anywhere in the plugin; the one outbound request (`wp_remote_request` to `api.github.com`) is disclosed in `readme.txt` and gated behind an explicit consent checkbox, nonce and capability check; the `migrate.contentrain.io` link is a plain anchor the admin must click, not a request; zero third-party code ships in the archive — the three npm packages are dev/test-only (excluded by `tools/package.py`) and all MIT, compatible with `GPL-2.0-or-later`. Still missing: the WP.org submission itself, and CI still only lints PHP 7.4/8.3 syntax and runs acceptance on one WordPress/ACF version — large-site and multisite behaviour is untested |
| **Source coverage** | `complete_source_coverage: false` is correct and must not be presented otherwise. **Narrowed, 2026-09-18 (B-03):** `relationship`, `post_object`, `taxonomy`, `gallery`, `user` and `link` now become real relations (`Acf::reference()`), and `flexible_content` becomes a collection with its layout name preserved (`Acf::flexible()`), each measured against a real ACF 6.8.10 fixture with zero silent drops. **Narrowed, 2026-09-18 (B-05):** menus resolve their target to kind/post_type/taxonomy/slug with an unresolved target or parent reported rather than guessed at (`Models::menus()`), and language pairs (`bridge/language-pairs.json`) are one row per translation group against a real Polylang fixture, not one per post. **Source universe (B-02), 2026-09-18:** every export writes `bridge/coverage.json` — each content-bearing source (every post type and status, taxonomies, term meta, post and attachment meta, comments, users, options, widgets, Customizer, sticky, page templates, shortcodes, embeds, reusable-block references, registered patterns, network sites, and every database table) with a database count split into exported / excluded:reason / unsupported:reason; `npm run test:coverage` requires every source to add up in both scopes and Bridge's RawIR to equal a WXR export read by `@contentrain/wp-import` on 12 post fields, attachments, terms, authors and comments. `complete_source_coverage` stays false because the report itself names what is unsupported: shortcode and embed rendering, unregistered post types, and plugin tables it does not read. **Interface text (B-08), 2026-09-18:** widget, menu-label and Customizer (theme_mods) text and gettext/HTML/echo/JS scanning are measured by `npm run test:text` against a fixture theme and five rendered pages: every occurrence is accounted for (transfer / exclude with reason / error), deduplication only on identical text+locale+context. **Partial translation, 2026-09-18:** a missing locale never gets a silent copy of another locale's text; `bridge/language-pairs.json` names the gap (`missing_translations`) instead. For a `document`-kind model (a real WordPress `post`) this is already `contentrain validate`'s own `Missing translation` **warning**, not an error — verified against the real installed validator, not assumed. Taxonomy terms and menu items are `i18n: false` since B-05, so an untranslated term or an untranslated menu target was never a parity question to begin with. Still open: `clone` and ACF Options Page fields require ACF **Pro** (same class of constraint as the B-10 GitHub credential — verified empirically that ACF Free does not register either field type); WPML has no free tier to install and test against, so its existing code paths remain structurally present but unverified; a `collection`-kind model (a WordPress `page` or any other post type) with a genuinely untranslated entry still fails `contentrain validate`'s `Entry parity` **error** — the published validator's own design (`config.locales.supported` is one project-wide list with no per-model or per-entry override; see `reports/BS-4-contract.md` for the exact source and the upstream change this needs), not something this plugin's own code can close; a real third-party theme or page builder, block-theme template parts beyond HTML text nodes, and strings a plugin renders only for logged-in users remain unmeasured |

## Plugin Check warnings

Fourteen, zero errors. They are third-party hook names — WPML's own filters,
which have to be spelled WPML's way — and direct database queries in a one-shot
exporter where the caching rule does not apply. Reviewers accept both with a
stated reason; do not silence them by weakening the check.
