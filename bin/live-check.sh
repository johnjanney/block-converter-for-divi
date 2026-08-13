#!/usr/bin/env bash
#
# Run the plugin against a real WordPress.
#
# The offline suite (tests/run.php) proves the converter emits correct markup.
# This proves the rest of the sentence: that the endpoints enforce what they
# claim, that WordPress stores the converted content unchanged, that a restore
# returns the original bytes, and that what comes back *out of the database*
# still validates against the real block library.
#
# That last distinction is the point. Every previous check validated the
# converter's in-memory output. Between that and a page a user opens sit
# wp_slash(), wp_update_post(), KSES, and MySQL — and the worst defect this
# plugin ever shipped lived in exactly that gap.
#
# Usage:
#   bash bin/live-check.sh            # start the environment if needed, then test
#   bash bin/live-check.sh --keep     # leave it running afterwards (default)
#   bash bin/live-check.sh --stop     # stop it when finished
#
# Requires Docker and Node 22+. First run pulls WordPress images and is slow.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=bin/_wp-env.sh
. "${ROOT}/bin/_wp-env.sh"

STOP_AFTER=0
for arg in "$@"; do
    case "$arg" in
        --stop) STOP_AFTER=1 ;;
        --keep) STOP_AFTER=0 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

PLUGIN_PATH="wp-content/plugins/block-converter-for-divi"

# ---- Prerequisites ---------------------------------------------------------

if ! docker info >/dev/null 2>&1; then
    echo "error: the Docker daemon is not running; wp-env needs it." >&2
    exit 1
fi

if [[ ! -d node_modules/@wordpress/env ]]; then
    echo "Installing wp-env..."
    npm install --no-fund --no-audit
fi

# ---- Bring the environment up ---------------------------------------------
#
# `wp-env start` is idempotent: it is a no-op against an already-running
# environment, so this does not need to know whether one exists.
#
# Through the shared helper, which clears the leftover checkouts `wp-env
# destroy` does not remove and retries. This script called `npx wp-env start`
# directly and died on a stale root-owned checkout during a review — a failure
# the helper beside it already knew how to repair. It also runs the pending
# database upgrade, which bin/wp-matrix.sh routinely leaves behind.

echo "Starting WordPress (first run pulls images and takes a few minutes)..."
d2g_wp_env_start || { echo "error: could not start wp-env" >&2; exit 1; }

WP_VERSION="$(npx wp-env run cli wp core version 2>/dev/null | head -1 | tr -d '\r')"
echo "WordPress ${WP_VERSION} is up."

# Truncate the debug log so what it holds afterwards belongs to this run.
# Without this a warning from any previous run makes every later run look
# dirty, and a real new warning is indistinguishable from an old one.
npx wp-env run cli rm -f wp-content/debug.log >/dev/null 2>&1 || true

# ---- The live suite --------------------------------------------------------

echo
echo "== Live suite: endpoints, database round trip, restore =="
if ! npx wp-env run cli wp eval-file "${PLUGIN_PATH}/tests/live/run.php"; then
    echo >&2
    echo "error: the live suite failed." >&2
    exit 1
fi

# ---- Validate what the database actually holds -----------------------------

STORED="tests/live/stored-output.json"

if [[ ! -s "$STORED" ]]; then
    echo "error: the live suite did not write ${STORED}." >&2
    exit 1
fi

echo
echo "== Real block validation, against content read back out of the database =="
if [[ ! -d tests/js/node_modules ]]; then
    echo "Installing the block validator..."
    npm --prefix tests/js ci
fi

if ! node tests/js/validate.mjs "$STORED"; then
    echo >&2
    echo "error: content stored by WordPress does not validate." >&2
    exit 1
fi

# ---- Anything WordPress complained about ----------------------------------
#
# WP_DEBUG_LOG is on in .wp-env.json. A notice or deprecation raised during the
# run is a real finding even when every assertion passed, and this job's own
# documentation said so long before it acted on it: for four releases the log
# was printed with the word "warning" and the script then exited 0. A gate that
# reports and does not fail is a gate that is open.

echo
echo "== WordPress debug log =="
DIRTY_LOG=0
if npx wp-env run cli test -s wp-content/debug.log 2>/dev/null; then
    npx wp-env run cli cat wp-content/debug.log 2>/dev/null | tail -30
    DIRTY_LOG=1
else
    echo "empty — no notices, warnings or deprecations."
fi

if [[ "$STOP_AFTER" -eq 1 ]]; then
    echo
    echo "Stopping the environment..."
    npx wp-env stop >/dev/null
fi

if [[ "$DIRTY_LOG" -eq 1 ]]; then
    echo
    echo "error: WordPress logged the above during the run. The suite passed, but" >&2
    echo "a notice, warning or deprecation is a defect in its own right." >&2
    exit 1
fi

echo
echo "Live check passed."
