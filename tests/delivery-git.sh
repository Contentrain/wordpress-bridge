#!/usr/bin/env bash
# Repeat delivery with deletions and a person's edit, replayed into real Git.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
"${compose[@]}" exec -T wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/delivery-git.php
rm -rf "$here/.out/git"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):/tmp/bridge-git" "$here/.out/git"
chmod -R a+rX "$here/.out"
node "$here/delivery-git.mjs" "$here/.out/git"
