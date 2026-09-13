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
