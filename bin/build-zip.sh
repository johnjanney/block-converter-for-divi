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
CONST_VERSION="$(grep -m1 -E "define\(\s*'BCFD_VERSION'" "$MAIN" | sed -E "s/.*'BCFD_VERSION'\s*,\s*'([^']+)'.*/\1/")"

if [[ -z "$HEADER_VERSION" ]]; then
    echo "error: could not read the Version header from ${MAIN}" >&2
    exit 1
fi

if [[ "$HEADER_VERSION" != "$CONST_VERSION" ]]; then
    echo "error: version mismatch — header is '${HEADER_VERSION}', BCFD_VERSION is '${CONST_VERSION}'." >&2
    echo "       Both must match; BCFD_VERSION is used for asset cache-busting." >&2
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

# ---- Instructions must name constants that still exist ---------------------
#
# The release procedure in CHANGELOG.md told maintainers to check `D2G_VERSION`
# for three releases after the constant became `BCFD_VERSION`, because nothing
# reads documentation. This does, for the two files that give current
# instructions: the release procedure at the top of CHANGELOG.md, and BRIEF.md.
#
# A line recording the rename itself is allowed to name the old constant, and so
# is a struck-through roadmap entry — both are history rather than instruction.

# The old spelling of each constant the plugin actually defines, so this tracks
# the code rather than a hard-coded list. Class names still use the D2G_ prefix
# and are current, which is why this cannot simply grep for D2G_.
OLD_NAMES="$(grep -oE "define\(\s*'BCFD_[A-Z_]+'" "$MAIN" \
    | sed -E "s/.*'BCFD_([A-Z_]+)'/D2G_\1/" | sort -u | paste -sd'|')"

STALE_DOCS=""
if [[ -n "$OLD_NAMES" ]]; then
    STALE_DOCS="$(
        {
            # Only the preamble: the dated release entries below the first
            # version heading are a record of what happened and say so.
            sed -n "1,/^## \[/p" CHANGELOG.md | grep -nE "\b(${OLD_NAMES})\b" | sed 's#^#CHANGELOG.md:#'
            [[ -f BRIEF.md ]] && grep -nE "\b(${OLD_NAMES})\b" BRIEF.md | sed 's#^#BRIEF.md:#'
        } | grep -viE 'rename|~~' || true
    )"
fi

if [[ -n "$STALE_DOCS" ]]; then
    echo "error: documentation names a constant this plugin no longer defines." >&2
    echo "$STALE_DOCS" | sed 's/^/       /' >&2
    echo "       The plugin defines BCFD_*; D2G_* is only for lines describing the rename." >&2
    exit 1
fi

VERSION="$HEADER_VERSION"
ARCHIVE="dist/${SLUG}-${VERSION}.zip"

# ---- Conversion fixtures must pass before anything is packaged -------------
#
# The converter's output is a large hand-built markup string, and its whole
# history is bug fixes to that string. The fixture suite is what stops a fixed
# defect coming back, so it gates the build rather than being run on trust.

if [[ -f tests/run.php ]]; then
    if ! command -v php >/dev/null 2>&1; then
        echo "error: php is required to run the fixture suite before building." >&2
        exit 1
    fi
    # --require-validator makes a missing Node harness a build failure rather
    # than a skipped check. Block validity is the plugin's central claim; a
    # release must not be cut on a run that could not test it.
    echo "Running the converter suite (including real WordPress block validation)..."
    if ! php tests/run.php --require-validator >/tmp/d2g-tests.$$ 2>&1; then
        cat /tmp/d2g-tests.$$ >&2
        rm -f /tmp/d2g-tests.$$
        echo "error: the test suite failed; refusing to build." >&2
        echo "       If the block validator is missing: npm --prefix tests/js ci" >&2
        exit 1
    fi
    tail -3 /tmp/d2g-tests.$$
    rm -f /tmp/d2g-tests.$$
fi

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
    --exclude '.github' \
    --exclude '.gitignore' \
    --exclude 'dist' \
    --exclude 'build' \
    --exclude 'bin' \
    --exclude 'BRIEF.md' \
    --exclude 'OPENQUESTIONS.md' \
    --exclude 'CODEX-REVIEW.md' \
    --exclude 'CODEX-REVIEW-RESPONSE.md' \
    --exclude 'tests' \
    --exclude 'node_modules' \
    --exclude 'package.json' \
    --exclude 'package-lock.json' \
    --exclude '.wp-env.json' \
    --exclude '.wp-env.override.json' \
    --exclude 'playwright.config.js' \
    --exclude 'test-results' \
    --exclude 'playwright-report' \
    --exclude 'live-test-files' \
    ./ "build/${SLUG}/"

# A single top-level directory, so the WordPress plugin uploader installs it
# to wp-content/plugins/block-converter-for-divi/.
( cd build && zip -rq "../${ARCHIVE}" "$SLUG" )

rm -rf build

# ---- Refuse to ship anything that is not the plugin ------------------------
#
# The exclude list above is a denylist, and a denylist silently ships whatever
# nobody remembered to add to it. This is the backstop: the archive's top level
# must contain exactly the files and directories the plugin is made of, so a new
# development file added at the root fails the build instead of being published.

EXPECTED="$(printf '%s\n' \
    "${SLUG}/" \
    "${SLUG}/CHANGELOG.md" \
    "${SLUG}/INSTRUCTIONS.md" \
    "${SLUG}/LICENSE" \
    "${SLUG}/README.md" \
    "${SLUG}/admin/" \
    "${SLUG}/block-converter-for-divi.php" \
    "${SLUG}/includes/" \
    "${SLUG}/readme.txt" \
    "${SLUG}/uninstall.php" | sort)"

# Reduce every entry to its top level inside the archive: a path with a
# second-level directory becomes "slug/dir/", a root file stays as it is.
ACTUAL="$(unzip -Z1 "$ARCHIVE" | sed -E "s#^(${SLUG}/[^/]+/).*#\1#" | sort -u)"

if [[ "$EXPECTED" != "$ACTUAL" ]]; then
    echo "error: the archive does not contain what the plugin is made of." >&2
    # `|| true`: diff exits non-zero when it finds a difference, which is the
    # expected case here, and `set -o pipefail` would otherwise abort the script
    # on this line — skipping the cleanup below and leaving an archive that
    # failed validation on disk, where the no-overwrite rule then blocks
    # rebuilding it.
    { diff <(echo "$EXPECTED") <(echo "$ACTUAL") || true; } | sed 's/^/       /' >&2
    echo "       Update bin/build-zip.sh if this change is intended." >&2
    rm -f "$ARCHIVE"
    exit 1
fi

echo "Built ${ARCHIVE}"
unzip -l "$ARCHIVE" | tail -n +2
