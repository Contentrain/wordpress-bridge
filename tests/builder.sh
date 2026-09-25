#!/usr/bin/env bash
# Builder export against the acceptance WordPress that tests/run.sh left up (KEEP=1).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker compose -f "$here/compose.yml" run --rm -T cli eval-file /var/www/html/wp-content/plugins/contentrain-bridge/tests/builder.php
