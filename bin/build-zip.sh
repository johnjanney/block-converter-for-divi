#!/usr/bin/env bash
#
# Build a distributable plugin ZIP named for the current version.
#
# The version is read from the plugin header rather than passed in, so the
# archive name can never disagree with what the plugin reports. An existing
# archive is never overwritten — old versions are the rollback path and are
# kept permanently (see CHANGELOG.md).
#
# Usage: ./bin/build-zip.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="block-converter-for-divi"
MAIN="${SLUG}.php"

# ---- Resolve and cross-check the version -----------------------------------

HEADER_VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
CONST_VERSION="$(grep -m1 -E "define\(\s*'D2G_VERSION'" "$MAIN" | sed -E "s/.*'D2G_VERSION'\s*,\s*'([^']+)'.*/\1/")"

if [[ -z "$HEADER_VERSION" ]]; then
    echo "error: could not read the Version header from ${MAIN}" >&2
    exit 1
fi

if [[ "$HEADER_VERSION" != "$CONST_VERSION" ]]; then
    echo "error: version mismatch — header is '${HEADER_VERSION}', D2G_VERSION is '${CONST_VERSION}'." >&2
    echo "       Both must match; D2G_VERSION is used for asset cache-busting." >&2
    exit 1
fi

# WordPress.org serves whatever Stable tag points at, so a stale value here
# publishes the wrong version.
if [[ -f readme.txt ]]; then
    STABLE_TAG="$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]')"

    if [[ "$STABLE_TAG" != "$HEADER_VERSION" ]]; then
        echo "error: readme.txt 'Stable tag: ${STABLE_TAG}' does not match version '${HEADER_VERSION}'." >&2
        echo "       WordPress.org serves the version named by Stable tag." >&2
        exit 1
    fi
fi

VERSION="$HEADER_VERSION"
ARCHIVE="dist/${SLUG}-${VERSION}.zip"

# ---- Never clobber a previously built archive ------------------------------

if [[ -e "$ARCHIVE" ]]; then
    echo "error: ${ARCHIVE} already exists." >&2
    echo "       Old versions are kept permanently and are never overwritten." >&2
    echo "       Bump the version before building again." >&2
    exit 1
fi

# ---- Stage and zip ---------------------------------------------------------

rm -rf build
mkdir -p dist "build/${SLUG}"

rsync -a \
    --exclude '.git' \
    --exclude '.gitignore' \
    --exclude 'dist' \
    --exclude 'build' \
    --exclude 'bin' \
    --exclude 'BRIEF.md' \
    --exclude 'OPENQUESTIONS.md' \
    ./ "build/${SLUG}/"

# A single top-level directory, so the WordPress plugin uploader installs it
# to wp-content/plugins/block-converter-for-divi/.
( cd build && zip -rq "../${ARCHIVE}" "$SLUG" )

rm -rf build

echo "Built ${ARCHIVE}"
unzip -l "$ARCHIVE" | tail -n +2
