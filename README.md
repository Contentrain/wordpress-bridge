# Contentrain Bridge

Free, local-first WordPress extraction bridge for the Contentrain RawIR v1 migration contract.

- License: GPL-2.0-or-later
- No account or paid service required
- No telemetry or external requests
- Explicit, nonce-protected administrator export
- Comment export off by default and privacy-minimized

The JSON contract is defined by the MIT-licensed `@contentrain/types` package in `Contentrain/ai`; the plugin does not import or embed private Migrate or proprietary Studio code.

## Development

This repository intentionally has no runtime dependency or build step. Run PHP syntax checks with:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Before a WordPress.org submission, run the official Plugin Check plugin and validate `readme.txt` against the current directory rules.
