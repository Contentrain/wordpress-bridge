# Contentrain Bridge

Free, local-first WordPress bridge: models site content and interface text as Contentrain JSON/Markdown, delivered as a ZIP or to a branch in your own GitHub repository.

- License: GPL-2.0-or-later
- No account or paid service required
- No telemetry, and no network request until you start a GitHub delivery yourself
- Explicit, nonce-protected administrator export
- Comment export off by default and privacy-minimized
- ACF groups and repeaters modelled as real collections rather than one anonymous value bag
- Media copied into `media/` and content relinked, so the export does not depend on the source site
- Output verified against the published Contentrain toolchain, not only the plugin's own validator (`tests/verify-store.mjs`)

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
tests/run.sh                 # docker compose, ~130 checks, writes tests/.out/store
node tests/verify-store.mjs  # contentrain validate + canonical byte parity
```

Point `CONTENTRAIN_TYPES` at a local `@contentrain/types` build to verify
against an unreleased serializer.

Before a WordPress.org submission, run the official Plugin Check plugin and validate `readme.txt` against the current directory rules.
