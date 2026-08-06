#!/usr/bin/env bash
#
# Drive the Tools screen in a real browser.
#
# Everything else in this project tests the converter or the endpoints. The
# admin screen is jQuery against a real DOM and had no tests at all — which
# matters most for the batch runner, because that is where 2.0.0 counted failed
# pages as successes and told the user everything had converted.
#
# The browser runs inside Playwright's own container. That is not a preference:
# Chromium needs system libraries this machine does not have and cannot install
# without root, and pinning the container to the installed Playwright version
# means the run does not depend on what happens to be on the host. It needs
# --network host so the browser can reach wp-env on localhost:8888, which works
# on Linux, including GitHub runners.
#
# Usage:
#   bash bin/e2e.sh              # seed, then run every spec
#   bash bin/e2e.sh --headed     # same, with a visible browser (needs a display)
#   bash bin/e2e.sh -g "batch"   # only specs matching a pattern

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=bin/_wp-env.sh
. "${ROOT}/bin/_wp-env.sh"

PLUGIN_PATH="wp-content/plugins/block-converter-for-divi"

if ! docker info >/dev/null 2>&1; then
    echo "error: the Docker daemon is not running." >&2
    exit 1
fi

if [[ ! -d node_modules/@playwright/test ]]; then
    echo "Installing development dependencies..."
    npm install --no-fund --no-audit
fi

echo "Starting WordPress..."
npx wp-env start >/dev/null

# bin/wp-matrix.sh leaves the shared environment on whichever WordPress it last
# installed, and a version change makes WordPress redirect every admin page to
# the database-upgrade screen until someone clears it. See bin/_wp-env.sh.
d2g_wp_env_update_db

# The seeder deletes anything a previous run left, so a re-run starts from the
# same state rather than accumulating pages.
echo "Seeding..."
npx wp-env run cli wp eval-file "${PLUGIN_PATH}/tests/e2e/seed.php" 2>/dev/null | grep -E '^\{' || {
    echo "error: seeding failed." >&2
    exit 1
}

npx wp-env run cli rm -f wp-content/debug.log >/dev/null 2>&1 || true

PW_VERSION="$(node -p "require('./node_modules/@playwright/test/package.json').version")"
IMAGE="mcr.microsoft.com/playwright:v${PW_VERSION}-noble"

echo
echo "== Browser tests (Playwright ${PW_VERSION}) =="

# --ipc=host: Chromium's default shared-memory allocation inside a container is
# too small and shows up as tabs crashing mid-test.
# `set -e` would exit here on a browser failure, before the debug log below is
# read — and a failing run is exactly when its contents are worth having. The
# `if` makes the command a tested condition, which suspends errexit for it.
STATUS=0
if ! docker run --rm \
    --network host \
    --ipc=host \
    -v "${ROOT}:/work" \
    -w /work \
    -e CI="${CI:-}" \
    -e WP_BASE_URL="http://localhost:8888" \
    "$IMAGE" \
    npx playwright test "$@"; then
    STATUS=1
fi

echo
echo "== WordPress debug log =="
if npx wp-env run cli test -s wp-content/debug.log 2>/dev/null; then
    npx wp-env run cli cat wp-content/debug.log 2>/dev/null | tail -20
    echo
    echo "error: WordPress logged the above while the browser drove it." >&2
    STATUS=1
else
    echo "empty — the screen produced no PHP notices."
fi

exit $STATUS
