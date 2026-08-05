#!/usr/bin/env bash
#
# Run the plugin on a real multisite network.
#
# Multisite is the one configuration where the plugin's own capability check is
# not enough. `manage_options` gates the screen, and any site administrator has
# it — but on a network only *super* admins hold `unfiltered_html`, and
# wp_update_post() filters post_content through KSES for everyone else.
#
# Measured on WordPress 7.0.2: a Divi Code module holding a tracking script
# converts to a core/html block, and KSES stores the JavaScript as visible text
# with the <script> tags gone and any <iframe> deleted. Silently. That is what
# this checks the plugin now refuses to do.
#
# The network is built from scratch, because converting an existing install to
# multisite is a one-way change and the other suites expect single-site.
#
# Usage:
#   bash bin/multisite-check.sh              # build, test, leave the network up
#   bash bin/multisite-check.sh --restore    # afterwards, rebuild as single-site

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLUGIN_PATH="wp-content/plugins/block-converter-for-divi"
RESTORE=0
for arg in "$@"; do
    [[ "$arg" == "--restore" ]] && RESTORE=1
done

if ! docker info >/dev/null 2>&1; then
    echo "error: the Docker daemon is not running." >&2
    exit 1
fi

if [[ ! -d node_modules/@wordpress/env ]]; then
    npm install --no-fund --no-audit
fi

# shellcheck source=bin/_wp-env.sh
. "${ROOT}/bin/_wp-env.sh"

echo "Building a clean network..."
d2g_wp_env_reset
d2g_wp_env_start || { echo "error: could not start wp-env" >&2; exit 1; }

npx wp-env run cli wp core multisite-convert >/dev/null 2>&1 || {
    echo "error: multisite-convert failed" >&2
    exit 1
}

# A site administrator who is deliberately not a super admin. This is the user
# the whole exercise is about.
npx wp-env run cli wp user create siteadmin siteadmin@example.test \
    --role=administrator --user_pass=pw >/dev/null 2>&1 || true

npx wp-env run cli rm -f wp-content/debug.log >/dev/null 2>&1 || true

echo
echo "== Multisite suite =="
npx wp-env run cli wp eval-file "${PLUGIN_PATH}/tests/live/multisite.php"
STATUS=$?

echo
echo "== WordPress debug log =="
if npx wp-env run cli test -s wp-content/debug.log 2>/dev/null; then
    npx wp-env run cli cat wp-content/debug.log 2>/dev/null | tail -20
    echo
    echo "warning: WordPress logged the above during the run." >&2
else
    echo "empty — no notices, warnings or deprecations."
fi

if [[ "$RESTORE" -eq 1 ]]; then
    echo
    echo "Rebuilding as single-site..."
    d2g_wp_env_reset
    d2g_wp_env_start
fi

if [[ $STATUS -ne 0 ]]; then
    echo
    echo "Multisite check FAILED." >&2
    exit 1
fi

echo
echo "Multisite check passed."
