# Contentrain Bridge

Free, local-first WordPress bridge: models site content and interface text as Contentrain JSON/Markdown, delivered as a ZIP or to a branch in your own GitHub repository.

- License: GPL-2.0-or-later
- No account or paid service required
- No telemetry, and no network request until you start a GitHub delivery yourself
- Explicit, nonce-protected administrator export
- Comment export off by default and privacy-minimized
- ACF groups and repeaters modelled as real collections rather than one anonymous value bag
- Supported local media copied into `media/` and content relinked; missing or oversized files remain source URLs with warnings
- Output verified against the published Contentrain toolchain, not only the plugin's own validator (`tests/verify-store.mjs`)

> **Source coverage is incomplete, and the export says so.** Every manifest
> carries `complete_source_coverage: false`. This exports the content layer and
> the interface text it can model; it does not reproduce a WordPress site.
> Advanced page-builder runtime, widget and theme settings, rendered-state
> output and source-code reuse are outside what it reads today — see
> [Current coverage boundaries](#current-coverage-boundaries). A store that
> validates is not a claim that a site has been migrated.

The JSON contract is defined by the MIT-licensed `@contentrain/types` package in `Contentrain/ai`; the plugin does not import or embed private Migrate or proprietary Studio code.

## Install and update

Download `contentrain-bridge.zip` from the
[latest release](https://github.com/Contentrain/wordpress-bridge/releases/latest)
and install it in **Plugins > Add New > Upload Plugin**. Updates are manual while
the plugin is distributed outside WordPress.org: upload the new release ZIP the
same way and WordPress replaces the installed version. Each release is the exact
archive CI built and tested ([RELEASING.md](RELEASING.md)).

## Development

This repository intentionally has no runtime dependency or build step. Run PHP syntax checks with:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

The acceptance suite runs the plugin inside a throwaway WordPress and leaves the
finished store on the host, which the external verification then reads with the
published Contentrain packages:

```sh
tests/run.sh                 # docker compose, integration checks, writes tests/.out/store
node tests/verify-store.mjs  # contentrain validate + canonical byte parity
```

Point `CONTENTRAIN_TYPES` at a local `@contentrain/types` build to verify
against an unreleased serializer.

Before a WordPress.org submission, run the official Plugin Check plugin and validate `readme.txt` against the current directory rules.

## Reproducible validation and packaging

Run `npm ci --ignore-scripts` to install the pinned development toolchain. Node is
not needed on the WordPress server. `npm run test:wordpress` generates the fixture,
`npm run test:store` runs the pinned Contentrain CLI and canonical serializer, and
`npm test` verifies the handoff and tamper rejection. ACF installation is required;
a missing dependency fails acceptance instead of skipping its assertions.

`npm run package` writes `dist/contentrain-bridge.zip`. Development dependencies,
tests, Git metadata and internal tools are excluded from the WordPress archive.

## Migrate intake adapter

After unpacking a reviewed export:

```sh
node tools/prepare-migrate.mjs /path/to/export /path/to/new-intake
```

The adapter verifies every manifest hash, preserves Bridge entry identities,
reconstructs RawIR and comments export, and produces `store/.contentrain` plus
Migrate's intake summary/source map. Transferred media is under `public/media`.
The destination must not exist; tampered exports are rejected. This adapter does
not contact WordPress or GitHub. The Migrate consumer must copy that public media
into the emitted project's public directory. Hosted Migrate onboarding and an
Astro end-to-end acceptance are separate integration gates, not proven by this
adapter test.

An export started over REST (below) also carries its RawIR v1 as
`bridge/rawir.json` (listed in the manifest with its sha256): the same document
this adapter builds from the raw files, so its reader needs no assembler of its
own. The admin screen's export, its ZIP and GitHub delivery never carry it: the
site's repository holds the store, not a raw copy of every record.

## REST export API (for Migrate)

An administrator's application password is enough to start, advance and read an
export; every route needs `export` + `manage_options`, like the read API. Where
the host strips the Authorization header, the Contentrain Migrate connection key
does the same job (below).

| Route | Answer |
|---|---|
| `GET /wp-json/contentrain-bridge/v1/about` | No sign-in: `{ version, auth: ["app_password", "key"] }`. A 404 is a Bridge older than 0.4.0 (application password only). |
| `POST /wp-json/contentrain-bridge/v1/exports` `{ types?, private?, comments?, media_files?, max_age?, fresh? }` | `201 { export, reused: false }`; the caller's live export with the same scope instead: `200 { export, reused: true }`. With `max_age` (seconds) only one started within it is reused; an older one is removed and replaced (`fresh=1`: always). `409 bridge_export_busy` while the one to replace is running, or was read in the last 10 minutes (a download in progress). At most three live remote exports per user (`429`). |
| `GET /wp-json/contentrain-bridge/v1/exports` | `{ exports: [export] }`, the caller's remote exports, oldest first. |
| `POST /wp-json/contentrain-bridge/v1/exports/{id}/advance` | Runs steps for about 20 seconds: `{ export }`. While another request holds it: `{ export, busy: true }`, nothing run. |
| `GET /wp-json/contentrain-bridge/v1/exports/{id}` | Once `ready`: the file list with sha256 and bytes. `?file=` one file up to 8 MiB; `?file=&offset=N&length=M` any file in base64 chunks of up to 8 MiB, with the whole file's `sha256` and `bytes`. |
| `POST /wp-json/contentrain-bridge/v1/exports/{id}/read` `{ file?, offset?, length? }` | The same answers as `GET /exports/{id}`, for a caller whose key travels in the body. |
| `DELETE /wp-json/contentrain-bridge/v1/key` | With the key only (and its pairing): `{ revoked: true }`. Migrate closes the key when the order is done. |

`media_files: false` keeps every attachment record but copies no file: nothing
under `media/`, and the store keeps the WordPress upload URLs (a reader that
localizes media itself). It is part of the scope a reused export must match.

`export` is `{ id, phase, step, cursor, counts, files, scope, created_at,
expires_at }`. Poll `advance` until `phase` is terminal: `ready`, or `failed`
with `error: { code, message }`: `content_changed` (WordPress changed under the
snapshot; start again), `too_large`, `review_required` or `export_failed`. A
remote export scans no theme or plugin source, so it has no interface text to
review and never waits on a person. Exports expire 24 hours after they start.

### Connection key (BR-27)

An administrator creates it under Tools > Contentrain Bridge ("Connect to
Contentrain Migrate") and pastes it into Migrate. It is shown once; only its
SHA-256 is stored.

- Send it as the `X-Contentrain-Key` header **and**, in every POST, as the
  `contentrain_key` body field (either may be stripped on the way; both are
  compared when both arrive). It is never read from the query string.
- Every call also carries `contentrain_pairing` (Migrate's order id,
  `[A-Za-z0-9_-]{8,64}`): in the JSON body of a POST or DELETE, in the query of a
  GET. The first accepted call of any route pairs the key with it — an access
  check that reads a non-existent export (`404 bridge_not_found`) included;
  another pairing is refused.
- It acts as the administrator who created it, while they still have `export`
  + `manage_options`, and opens these routes only. It sees only the exports it
  started, and runs one at a time: another scope while one runs is
  `409 bridge_export_live`; once that one is `ready` or `failed`, a rerun starts.
- Unpaired, it stops working an hour after it was created. Paired, it has no
  idle limit (reruns can be days apart) and works until 14 days after it was
  created, or until it is revoked (the Bridge screen, or `DELETE /key`) or
  replaced. HTTPS only (or `local` sites), like WordPress's application passwords.

| Code | Status | Meaning |
|---|---|---|
| `bridge_key_invalid` | 401 | Unknown key. |
| `bridge_key_expired` | 401 | Unpaired an hour after creation, or 14 days old. |
| `bridge_key_required` | 401 | `DELETE /key` without the key. |
| `bridge_key_revoked` | 401 | Revoked, or replaced by a newer key. |
| `bridge_key_mismatch` | 400 | Header and body carry different keys. |
| `bridge_key_pairing_required` | 400 | No valid `contentrain_pairing`. |
| `bridge_key_forbidden` | 403 | Its administrator can no longer export. |
| `bridge_key_paired_elsewhere` | 403 | The key belongs to another order. |
| `bridge_key_insecure` | 403 | The site is not served over HTTPS. |
| `bridge_key_rate_limited` | 429 | A wrong key after ten from this address in 15 minutes; `data.retry_after` seconds. The right key is compared first and never held back. |
| `bridge_not_found` | 404 | Not this key's export. |

## Delta cursor (what changed since the last delivery)

Every export writes `bridge/inventory.json` (`contentrain-bridge-inventory@2`):
one row per post, page, CPT record, attachment, menu item and public term in
scope, every status, with a fingerprint of the mapped record. WordPress keeps no
delta state; the inventory travels with the content. When a GitHub delivery finds
an inventory in the repository, it writes `bridge/delta.json`, a
`SourceDeltaPlan` (`@contentrain/types`) with `created`, `updated`, `moved` and
`deleted` (`trashed`/`purged`) entries.

Deletions are proven by comparing inventories, never by `modified_after`: that
feed misses deletions and meta-only (ACF) edits, and `tests/delta.php` proves it.
An inventory edited in Git, a legacy inventory, a type that left the scope (its
plugin was deactivated) or a truncated walk makes `deletions_detectable` false
rather than reporting records as deleted. In a public-scope export, drafts and
trashed records keep their id, status and fingerprint but not their slug or
address. `model`, `entry_id`, `conflict` and `redirects` are left to the planner,
which reads the store.

```sh
KEEP=1 npm run test:wordpress && npm run test:delta && npm run test:secrets
```

## SEO, redirects and routing

Every export writes what the site tells search engines and how it builds addresses:

- `bridge/seo.json` / `bridge/seo-entries.json`: Yoast SEO, Rank Math, AIOSEO and SEOPress.
  Per post and term: title, description, canonical, robots, Open Graph, Twitter,
  focus keyword and the JSON-LD graph. Site-wide: separator, title and description
  templates, social defaults, verification codes. With Yoast active, the values
  are the ones the page renders (`resolved: true`), and the robots directives are
  the ones `wp_robots()` prints. A deactivated plugin's stored data is still
  exported, marked unresolved. With no SEO plugin the file says `status: "none"`.
  Where a plugin is not running to resolve its values, Bridge renders its templates
  (`%title% %sep% %sitename%`, `#post_title #separator_sa #site_title`,
  `%%post_title%%`, `%%title%%`) from the record: `rendered` holds the title,
  description, canonical, robots, Open Graph and Twitter text, `template_source`
  says whether each came from the record, its type's template or the plugin's
  default, and `unresolved` names any variable left out (never guessed). URLs
  stay at the source origin. Entries are keyed `post:<ID>` (the
  `bridge/entry-source-map.json` key) and `term:<taxonomy>:<term_id>`; a home
  page that lists posts has its head under `home` in `bridge/seo.json`.
  Integration tokens (SEMrush, Wincher, MyYoast) never leave.
- `bridge/redirects.json`: Redirection, Yoast Premium, Rank Math, Safe Redirect
  Manager and WordPress's own old-slug redirects, as one `RawRedirect` list. Every
  stored rule is either listed as served or in `excluded` with its reason
  (disabled, conditional, 410, plugin inactive).
- `bridge/routing.json`: permalink structure, category/tag bases, trailing slash,
  static front and posts page, and each post type's and taxonomy's rewrite rules.

`tests/seo.sh` checks every exported head value against the page WordPress serves,
requests every exported redirect, and runs `@contentrain/verify` with the served
pages as its baseline.

## Repeat delivery

A delivery is always a new branch; the default branch is never written. It
starts from the repository's default branch, or from a base branch you name
(read and compared against, never written). An empty repository is refused with
what to do: give it a first commit, such as a README, and deliver again. On a
repository that already holds a Bridge export:

- A record the delta proves was deleted in WordPress (trashed or purged) has its
  document, metadata and media removed on the branch, and the commit lists each
  one. A file that is missing from the new export for any other reason stops the
  delivery with its name: nothing is removed without that proof.
- A managed file someone edited in the repository since the last delivery stops
  the delivery with the file, the person and the date. Deliver again choosing
  "keep the repository version" or "use the WordPress version"; either choice is
  listed in the commit.

`npm run test:delivery-git` proves both against a repository with history and
replays every state into real Git. `npm run test:e2e` runs the whole chain,
WordPress to Astro, including a second delivery.

## Interface text

With "Find interface text" on, an export inventories the text the site shows
that is not content: gettext calls, literal HTML and `echo`ed strings in theme
templates, JavaScript strings, widget titles and text, menu labels, Customizer
settings, and — opt-in, fetched from the site as a visitor — what the home,
single, page, search and not-found pages actually render.

`bridge/hardcoded-text.json` lists every candidate with all its occurrences and
exactly one outcome: `transfer` (to the `ui-strings` dictionary, the
`theme-settings` singleton, or content already exported), `exclude` with its
reason (code, URL, number, placeholder-only, dynamic gettext argument, secret,
content, or rendered from a source string it links to), or `error` (a file too
large or unreadable, a page that could not be fetched). Occurrences merge only
when text, locale and context are all the same. Keys depend on the text and its
context only, so moving a string to another file does not change its key.

## Source coverage report

Every export writes `bridge/coverage.json`, also shown on the Contentrain Bridge
screen when the export is ready. Each place WordPress keeps content — every post
type and status (REST-hidden types included), taxonomies, term and post meta,
attachments, comments, users, options, widgets, Customizer, sticky posts, page
templates, shortcodes, embeds, reusable blocks, patterns, network sites and every
database table — is counted in the database and split into `exported`,
`excluded:<reason>` or `unsupported:<reason>`. A source whose outcomes do not add
up to its count is marked `balanced: false`; a table the export does not read is
listed with its row count.

## Services to reconnect

`bridge/integrations.json` lists the outside services the site is connected to —
analytics, CRM, newsletter, ads, comments, captcha, CDN — with the evidence for
each: an active plugin, a settings option by name, a script host in the theme or
on the rendered home page, a form wired to the service, or embedded content. Each
says whether an account must be reconnected and whether a credential is set on
WordPress. No key, token or password is exported: a credential is only checked
for being set, in memory, and the export names the setting, never its value. The
list is shown on the Bridge screen and reaches Migrate's intake as an
`integration_reconnect_required` issue.

## Current coverage boundaries

ACF shapes that cannot be fully represented use a reported structured fallback;
partial named models are not accepted. Same-name groups use a stable field-key
suffix. Nested sensitive ACF values are removed before RawIR is written. Advanced
builder runtime, widget/theme settings extraction, rendered-state coverage and
source-code reuse are not yet complete.

Every manifest carries `complete_source_coverage: false`. That flag is the
honest answer to "is this everything?", and it is set on every export rather
than only on the ones that noticed a gap. A successful content-store validation
means the store is well-formed and the published toolchain reads it — not that
every WordPress behaviour came across.

Document metadata carrying quotes, backslashes, newlines or tabs is written as
JSON escapes and read back unchanged. That was a release blocker until
`@contentrain/types@1.14.0`: the published reader stripped a scalar's quotes
without decoding its escapes, so Bridge refused to finalize such an export rather
than publish content the reader would alter.

It is no longer taken on faith. `npm run test:reader` reads this writer's own
output back with the pinned reader and compares it to the values that went in, and
it runs in CI — so the compatibility cannot regress silently. Metadata that is not
valid UTF-8 still stops the export.
