#!/usr/bin/env bash
#
# Run the live suite against several WordPress versions.
#
# `Requires at least:` is a promise, and until this existed it was a guess
# derived by reading which blocks the converter emits and looking up when each
# arrived. The first run proved the guess wrong: core/list-item and
# core/comments are not registered on WordPress 6.0, so every converted list
# would have shown "your site doesn't include support for this block".
#
# This is the only way to know. Each version gets a clean environment, because
# WordPress volumes carry a bundled theme that a older core cannot load.
#
# Usage:
#   bash bin/wp-matrix.sh                 # the default set
#   bash bin/wp-matrix.sh 6.5 6.6         # only these versions
#
# Slow: roughly two minutes per version, most of it Docker.
# Requires Docker and Node 22+.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLUGIN_PATH="wp-content/plugins/block-converter-for-divi"
OVERRIDE="${ROOT}/.wp-env.override.json"
RESULTS="$(mktemp)"

# WordPress version paired with a PHP version that release actually supported.
# Running 6.0 on PHP 8.3 produces deprecation noise from core, not from us,
# which would drown the signal this script exists to read.
declare -A PHP_FOR=(
    [6.0]=7.4 [6.1]=7.4 [6.2]=8.0 [6.3]=8.0 [6.4]=8.1
    [6.5]=8.1 [6.6]=8.2 [6.7]=8.2 [6.8]=8.2
)

if [[ $# -gt 0 ]]; then
    VERSIONS=( "$@" )
else
    # The floor, the two releases around core/details (6.3), what readme.txt
    # claims, and whatever wp-env installs as current.
    VERSIONS=( 6.0 6.1 6.2 6.3 6.8 latest )
fi

cleanup() {
    rm -f "$OVERRIDE"
}
trap cleanup EXIT

for VERSION in "${VERSIONS[@]}"; do
    echo
    echo "============================================================"
    echo "  WordPress ${VERSION}"
    echo "============================================================"

    if [[ "$VERSION" == "latest" ]]; then
        rm -f "$OVERRIDE"
    else
        PHP="${PHP_FOR[$VERSION]:-8.2}"
        cat > "$OVERRIDE" <<EOF
{
  "core": "WordPress/WordPress#${VERSION}",
  "phpVersion": "${PHP}"
}
EOF
    fi

    # A clean environment per version. Reusing one carries wp-content forward,
    # and a theme from a newer release fatals on an older core.
    echo "y" | npx wp-env destroy >/dev/null 2>&1 || true

    if ! npx wp-env start >/dev/null 2>&1; then
        echo "  ERROR: the environment would not start"
        printf '%s\tENV FAILED\t-\t-\n' "$VERSION" >> "$RESULTS"
        continue
    fi

    ACTUAL_WP="$(npx wp-env run cli wp core version 2>/dev/null | grep -E '^[0-9]' | head -1 | tr -d '\r')"
    ACTUAL_PHP="$(npx wp-env run cli php -r 'echo PHP_VERSION;' 2>/dev/null | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

    npx wp-env run cli rm -f wp-content/debug.log >/dev/null 2>&1 || true

    OUTPUT="$(npx wp-env run cli wp eval-file "${PLUGIN_PATH}/tests/live/run.php" 2>&1)"
    STATUS=$?

    echo "$OUTPUT" | grep -E '^(ok|FAIL)' || true

    SUMMARY="$(echo "$OUTPUT" | grep -oE '[0-9]+ passed, [0-9]+ failed' | head -1)"
    MISSING="$(echo "$OUTPUT" | grep -oE 'not registered here: .*' | sed 's/not registered here: //' | head -1)"
    [[ -z "$MISSING" ]] && MISSING='-'

    if [[ $STATUS -eq 0 ]]; then
        printf '%s\tPASS\t%s\t%s\n' "${ACTUAL_WP:-$VERSION} (PHP ${ACTUAL_PHP:-?})" "${SUMMARY:-?}" "$MISSING" >> "$RESULTS"
    else
        printf '%s\tFAIL\t%s\t%s\n' "${ACTUAL_WP:-$VERSION} (PHP ${ACTUAL_PHP:-?})" "${SUMMARY:-?}" "$MISSING" >> "$RESULTS"
    fi

    LOG="$(npx wp-env run cli cat wp-content/debug.log 2>/dev/null | grep -vE '^\s*$' | head -5)"
    if [[ -n "$LOG" ]]; then
        echo "  WordPress logged:"
        echo "$LOG" | sed 's/^/    /'
    fi
done

echo
echo "============================================================"
echo "  Matrix"
echo "============================================================"
printf '%-24s %-6s %-22s %s\n' "WORDPRESS" "RESULT" "CHECKS" "BLOCKS NOT REGISTERED"
while IFS=$'\t' read -r wp result summary missing; do
    printf '%-24s %-6s %-22s %s\n' "$wp" "$result" "$summary" "$missing"
done < "$RESULTS"

FAILURES="$(grep -c 'FAIL' "$RESULTS" || true)"
rm -f "$RESULTS"

echo
if [[ "$FAILURES" -gt 0 ]]; then
    echo "${FAILURES} version(s) failed. 'Requires at least' must not claim any of them."
    exit 1
fi
echo "All versions passed."
