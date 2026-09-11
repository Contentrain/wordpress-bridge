#!/usr/bin/env bash
# Real GitHub delivery against a real repository.
#
# Not part of the default suite: it needs a repository it may write to, and a
# token. Everything else is proved against a mocked API, which says nothing
# about GitHub — and delivery is this plugin's headline feature.
#
#   BRIDGE_TEST_REPO=owner/repo BRIDGE_TEST_TOKEN=... npm run test:delivery
#
# Use a repository that exists only for this. The run merges into the default
# branch and edits a file there, because that is what the conflict case is.
#
# The token is passed to the container for this command only and is never
# written to disk. Give it Contents read/write on that one repository and
# nothing else — a broadly scoped token has no business in a test container.
set -euo pipefail

: "${BRIDGE_TEST_REPO:?Set BRIDGE_TEST_REPO=owner/repo}"
: "${BRIDGE_TEST_TOKEN:?Set BRIDGE_TEST_TOKEN}"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")

# The site the acceptance suite installs. Run `KEEP=1 npm run test:wordpress`
# first, or this has nothing to deliver from.
if ! "${compose[@]}" run --rm -T cli option get blogname >/dev/null 2>&1; then
  echo "No installed test site. Run: KEEP=1 npm run test:wordpress" >&2
  exit 2
fi

"${compose[@]}" exec -T \
  -e BRIDGE_TEST_REPO="$BRIDGE_TEST_REPO" \
  -e BRIDGE_TEST_TOKEN="$BRIDGE_TEST_TOKEN" \
  wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/delivery-live.php
