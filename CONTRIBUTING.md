# Contributing

Thanks for helping with Contentrain Bridge, the free WordPress plugin that exports a site's content layer as a Contentrain store.

## Ways to contribute

- Report a bug: an export that fails, a field that is missing or wrong, a plugin or theme the export does not read.
- Propose an improvement: a source the plugin should model, a clearer admin screen, better docs.
- Send a pull request with a fix or a test.

Security issues do not go in public issues. See [SECURITY.md](SECURITY.md).

## Before you open an issue

- Use the latest [release](https://github.com/Contentrain/wordpress-bridge/releases/latest).
- Include the WordPress and PHP versions, and the plugins involved (for example ACF, Yoast, a page builder).
- Never attach production credentials or a full WordPress export. A small reproduction (one post type, a few posts) is enough.

## Development

The plugin has no runtime dependency and no build step. The checks CI runs are listed in [RELEASING.md](RELEASING.md). The quick ones:

```sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
composer install && composer lint
npm ci --ignore-scripts
npm test
```

The WordPress acceptance suite runs in Docker:

```sh
npm run test:wordpress
```

## Pull requests

- Keep a pull request to one change, with a test when behaviour changes.
- The output contract comes from [`@contentrain/types`](https://github.com/Contentrain/ai/tree/main/packages/types). A change to the exported store shape starts there, not in the plugin.
- The plugin must keep passing the official WordPress Plugin Check (`npm run test:plugin`).
- Do not change the version or `readme.txt` stable tag in a pull request. Releases are cut by maintainers.

## License

The plugin is licensed under [GPL-2.0-or-later](LICENSE). By opening a pull request, you agree that your contribution is licensed under the same terms.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
