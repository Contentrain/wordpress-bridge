# Export screen, 0.6.3 → 0.6.4

Static renders of Tools > Contentrain Bridge, one per state, before (`before-*`, main at 0.6.3) and after (`after-*`).
Made without a WordPress install: the page's markup flattened to HTML, WordPress 6.7.1's own `common`, `forms`,
`buttons`, `l10n` and `dashicons` stylesheets, the plugin's `admin.css`/`admin.js`, `wp.i18n` stubbed, and `fetch`
answered from a canned export per state; captured headless at 1280 px, full page, as palette PNGs.

| State | What the canned server does |
|---|---|
| `empty` | no export yet |
| `running` | content stage, 118 of 412 posts, latest item named; the next step never answers (the loop is running) |
| `retrying` | the step answers HTTP 504; the first retry is counted down |
| `stopped` | four HTTP 504 answers: the screen gives up with Retry |
| `failed` | the server ended the export (`step_repeatedly_killed`) |
| `review` | every stage done, 37 texts to review |
| `ready` | the export finished |

These are not part of the plugin archive (`tools/package.py` lists what ships).
