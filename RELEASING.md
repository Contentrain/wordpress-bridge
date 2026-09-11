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

`Policy::frontmatter` refuses to finalize an export whose metadata needs escaped
characters, because the published reader strips a scalar's quotes without
decoding its escapes. This gate decides whether that refusal is still right, and
it decides it on *real output*: the acceptance suite writes `reader-compat.md`
with the plugin's own writer and the guard off, plus `reader-compat.json` with
the values that went in, and the script reads the document back and compares.

Measured today, against the same PHP-produced document:

| Reader | Result |
|---|---|
| `@contentrain/types@1.13.1` (published) | 4 of 14 values corrupted — the quote, backslash, newline and tab cases. **Guard stays** |
| Contentrain/ai PR #179 build | 14 of 14 survive. **Guard can be lifted** |

When the package carrying that fix is on npm:

1. Bump the `@contentrain/types` pin in `package.json`.
2. Run `npm run test:reader`; it must pass without `CONTENTRAIN_TYPES`.
3. Drop the `$strict` guard from `Policy::frontmatter` and the two
   `lossy published-reader ... blocks finalization` checks in
   `tests/integration.php`; replace them with the positive case.
4. Add `npm run test:reader` to the acceptance job in CI, so the compatibility
   cannot silently regress.
5. Remove the release-blocker note from `README.md`.

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

## Open gates

These are the reason this plugin is not on WordPress.org yet. None is closed by
another green CI run.

| Gate | What is actually missing |
|---|---|
| **Reader compatibility** | The published `@contentrain/types` reads escaped frontmatter lossily, so Bridge refuses to finalize an export whose metadata needs escapes — which is any site with a double quote in a title or a newline in an excerpt. Fixed in Contentrain/ai PR #179 and **proven against this writer's own PHP output** by `npm run test:reader`. Follow *The reader gate* above once the package is on npm |
| **B-10 real delivery** | The GitHub delivery test is a mock. No real repository has received a delivery: first delivery, repeat delivery, a user edit in between, a conflict, an interruption, and a private-repo restriction are all unproven, and delivery is the plugin's headline feature |
| **B-11 real consumption** | Studio reading and editing the delivered models and content, and a Git change reaching the generated Astro page, has not been demonstrated end to end |
| **B-01 directory review** | Plugin Check reporting zero errors is not directory approval. The manual policy and readme review has not happened. CI lints PHP 7.4 syntax but runs acceptance on one WordPress and one ACF version; large-site and multisite behaviour is untested |
| **Source coverage** | `complete_source_coverage: false` is correct and must not be presented otherwise. ACF Pro flexible/relationship/gallery/options and term fields, widget and theme_mods extraction, a real Polylang/WPML fixture, and gettext/JS/HTML scanning accuracy are incomplete |
| **Remote** | The repository is local-only. `github.com/Contentrain/wordpress-bridge` does not exist yet |

## Plugin Check warnings

Thirteen, zero errors. They are third-party hook names — WPML's own filters,
which have to be spelled WPML's way — and direct database queries in a one-shot
exporter where the caching rule does not apply. Reviewers accept both with a
stated reason; do not silence them by weakening the check.
