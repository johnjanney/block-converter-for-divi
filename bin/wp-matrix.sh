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

# shellcheck source=bin/_wp-env.sh
. "${ROOT}/bin/_wp-env.sh"

PLUGIN_PATH="wp-content/plugins/block-converter-for-divi"
OVERRIDE="${ROOT}/.wp-env.override.json"
RESULTS="$(mktemp)"

# Versions whose run left something in wp-content/debug.log.
DIRTY_LOGS=()

# WordPress version paired with a PHP version that release actually supported.
# Running 6.0 on PHP 8.3 produces deprecation noise from core, not from us,
# which would drown the signal this script exists to read.
declare -A PHP_FOR=(
    [6.0]=7.4 [6.1]=7.4 [6.2]=8.0 [6.3]=8.0 [6.4]=8.1
    [6.5]=8.1 [6.6]=8.2 [6.7]=8.2 [6.8]=8.2
)

# The floor the plugin declares. Versions below it are *expected* to fail — that
# is what makes them worth testing. WordPress enforces the header itself and
# refuses to activate the plugin, which is the protection the number exists to
# provide, so a below-floor version that passed would mean the floor is higher
# than it needs to be.
FLOOR="$(grep -m1 -E '^\s*\*\s*Requires at least:' block-converter-for-divi.php \
    | sed -E 's/.*Requires at least:[[:space:]]*//' | tr -d '[:space:]')"

if [[ -z "$FLOOR" ]]; then
    echo "error: could not read 'Requires at least' from the plugin header." >&2
    exit 1
fi

echo "Plugin declares WordPress ${FLOOR} or newer."

# Is $1 at least $FLOOR?
version_supported() {
    [[ "$1" == "latest" ]] && return 0
    [[ "$( printf '%s\n%s\n' "$FLOOR" "$1" | sort -V | head -1 )" == "$FLOOR" ]]
}

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
    d2g_wp_env_reset

    if ! d2g_wp_env_start; then
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

    # Judge the result against what the declared floor says should happen.
    if version_supported "$VERSION"; then
        if [[ $STATUS -eq 0 ]]; then
            VERDICT="PASS"          # supported, and it works
        else
            VERDICT="BROKEN"        # supported, and it does not — a real failure
        fi
    else
        if [[ $STATUS -eq 0 ]]; then
            VERDICT="FLOOR HIGH"    # unsupported, but works: the floor is too high
        else
            VERDICT="as declared"   # unsupported and refused, which is the point
        fi
    fi

    printf '%s\t%s\t%s\t%s\n' \
        "${ACTUAL_WP:-$VERSION} (PHP ${ACTUAL_PHP:-?})" "$VERDICT" "${SUMMARY:-refused}" "$MISSING" >> "$RESULTS"

    # A version that passes its checks while WordPress logs a deprecation has
    # not passed. Recorded per version so the table still prints in full.
    LOG="$(npx wp-env run cli cat wp-content/debug.log 2>/dev/null | grep -vE '^\s*$' | head -5)"
    if [[ -n "$LOG" ]]; then
        echo "  WordPress logged:"
        echo "$LOG" | sed 's/^/    /'
        DIRTY_LOGS+=("${ACTUAL_WP:-$VERSION}")
    fi
done

echo
echo "============================================================"
echo "  Matrix"
echo "============================================================"
printf '%-24s %-12s %-22s %s\n' "WORDPRESS" "VERDICT" "CHECKS" "BLOCKS NOT REGISTERED"
while IFS=$'\t' read -r wp verdict summary missing; do
    printf '%-24s %-12s %-22s %s\n' "$wp" "$verdict" "$summary" "$missing"
done < "$RESULTS"

BROKEN="$(grep -c 'BROKEN' "$RESULTS" || true)"
TOO_HIGH="$(grep -c 'FLOOR HIGH' "$RESULTS" || true)"
rm -f "$RESULTS"

echo
if [[ "${#DIRTY_LOGS[@]}" -gt 0 ]]; then
    echo "WordPress logged notices, warnings or deprecations on: ${DIRTY_LOGS[*]}" >&2
    exit 1
fi

if [[ "$BROKEN" -gt 0 ]]; then
    echo "${BROKEN} version(s) at or above the declared floor of ${FLOOR} do not work." >&2
    echo "Either fix them, or raise 'Requires at least'." >&2
    exit 1
fi

if [[ "$TOO_HIGH" -gt 0 ]]; then
    echo "${TOO_HIGH} version(s) below the declared floor of ${FLOOR} work fine." >&2
    echo "'Requires at least' is higher than it needs to be; consider lowering it." >&2
    exit 1
fi

echo "Every version behaves as 'Requires at least: ${FLOOR}' says it should."
