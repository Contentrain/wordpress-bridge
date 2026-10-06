#!/usr/bin/env bash
# Export requests the host kills, against the acceptance WordPress that tests/run.sh left up (KEEP=1).
# Runs in the web container with plain `php`, because each simulated request is a child process started
# with its own -d memory_limit / -d max_execution_time.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker compose -f "$here/compose.yml" exec -T wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/export-hang.php
