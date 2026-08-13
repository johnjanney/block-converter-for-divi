#!/usr/bin/env bash
#
# Build the installable ZIP for bin/diagnose-encoding.php.
#
# The diagnostic is one file, and the way it reaches the person who needs it is
# Plugins -> Add New -> Upload Plugin, which wants a ZIP holding a folder. That
# archive was assembled by hand once. Four commits later the file had been
# rewritten twice and the archive had not, so dist/ still held the version whose
# cleanup deleted every post named `bcfd-import-probe-1` — including a page of
# yours that happened to be called that. Nothing rebuilt it because nothing was
# responsible for rebuilding it. This is.
#
# Usage: ./bin/build-diagnostic-zip.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="divi-quote-encoding-diagnostic"
SOURCE="bin/diagnose-encoding.php"
ARCHIVE="dist/${SLUG}.zip"

if [[ ! -f "$SOURCE" ]]; then
    echo "error: ${SOURCE} is missing." >&2
    exit 1
fi

# ---- The file has to be loadable and has to say which version it is ---------

if ! command -v php >/dev/null 2>&1; then
    echo "error: php is required to check the source before packaging it." >&2
    exit 1
fi

php -l "$SOURCE" >/dev/null

# `|| true` so a missing header reaches the check below. Without it `set -e`
# takes grep's exit status and kills the script here, which fails for the right
# reason and says nothing at all — and the message below is the whole point.
VERSION="$( { grep -m1 -E '^\s*\*\s*Version:' "$SOURCE" || true; } | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

if [[ -z "$VERSION" ]]; then
    echo "error: no Version header in ${SOURCE}." >&2
    echo "       The archive is not versioned in its filename, so the header is" >&2
    echo "       the only way anyone can tell which copy they were sent." >&2
    exit 1
fi

# ---- Refuse to package a copy that has lost its safety properties -----------
#
# There is no test suite for this file: it is a throwaway tool that only does
# anything on a site with the fault, and it cannot be exercised here. What it
# can have is a refusal to ship without the two things whose absence made the
# last version dangerous.
#
# Deliberately a check for the property, not for the old bug. "Does not contain
# a delete-by-slug query" would pass any rewrite that invented a new way to
# delete somebody's page; "proves ownership before deleting, and does not write
# on an unauthenticated GET" is what actually has to be true.

MISSING=()

grep -q '_bcfd_probe_run' "$SOURCE" \
    || MISSING+=("the per-run ownership marker: cleanup must delete only what this run created")
grep -q 'check_admin_referer' "$SOURCE" \
    || MISSING+=("a nonce check on the run action: a capability check says who you are, not that you asked")
grep -q 'wp_verify_nonce' "$SOURCE" \
    || MISSING+=("a nonce check on the plain-text URL, which starts the same writes")

if [[ ${#MISSING[@]} -gt 0 ]]; then
    echo "error: ${SOURCE} is missing a safety property this archive must not ship without:" >&2
    printf '       - %s\n' "${MISSING[@]}" >&2
    exit 1
fi

# ---- Stage and zip ----------------------------------------------------------
#
# The file is renamed to match its folder, because WordPress identifies a plugin
# by `folder/file.php` and a mismatch makes it awkward to talk about.

rm -rf build-diagnostic
mkdir -p dist "build-diagnostic/${SLUG}"
cp "$SOURCE" "build-diagnostic/${SLUG}/${SLUG}.php"

# Same file in, same bytes out — so the digest identifies the contents rather
# than the minute somebody happened to run this.
#
# A ZIP stores each entry's modification time, and a checkout does not preserve
# mtimes, so building the same source twice normally produces two archives with
# two different digests. Recording a digest in a commit message is then a
# statement nobody else can check. The timestamp comes from the source file's
# last commit instead: stable across clones, and it moves exactly when the file
# does. `-X` drops the uid, gid and extra attributes for the same reason.
STAMP="$(git log -1 --format=%cd --date=format:%Y%m%d%H%M.%S -- "$SOURCE" 2>/dev/null || true)"

if [[ -n "$STAMP" ]]; then
    touch -t "$STAMP" "build-diagnostic/${SLUG}/${SLUG}.php" "build-diagnostic/${SLUG}"
else
    # Not a git checkout, or the file is not committed yet. Still buildable —
    # the archive is simply not reproducible, which is worth saying out loud.
    echo "note: ${SOURCE} has no commit date here, so this archive's digest is"
    echo "      this build's, not the source's. Commit first if you mean to quote it."
fi

# Overwritten rather than kept, which is the opposite of the rule for release
# archives in bin/build-zip.sh — and for the opposite reason. Old plugin
# versions are a rollback path worth keeping. An old copy of this is a tool that
# permanently deletes a page and looks exactly like the one that does not, so
# there is nothing here worth being able to go back to.
rm -f "$ARCHIVE"
( cd build-diagnostic && zip -rqX "../${ARCHIVE}" "$SLUG" )
rm -rf build-diagnostic

# ---- The archive must contain that file, and nothing else -------------------

EXPECTED="$(printf '%s\n' "${SLUG}/" "${SLUG}/${SLUG}.php" | sort)"
ACTUAL="$(unzip -Z1 "$ARCHIVE" | sort)"

if [[ "$EXPECTED" != "$ACTUAL" ]]; then
    echo "error: the archive does not hold what it should." >&2
    { diff <(echo "$EXPECTED") <(echo "$ACTUAL") || true; } | sed 's/^/       /' >&2
    rm -f "$ARCHIVE"
    exit 1
fi

# And the copy inside has to be this tree's copy, byte for byte. A build that
# packaged something else is the failure this script exists to prevent, so it is
# checked rather than assumed.
VERIFY="$(mktemp -d)"
trap 'rm -rf "$VERIFY"' EXIT
unzip -qq "$ARCHIVE" -d "$VERIFY"

if ! diff -q "${VERIFY}/${SLUG}/${SLUG}.php" "$SOURCE" >/dev/null; then
    echo "error: the file in the archive is not ${SOURCE}." >&2
    rm -f "$ARCHIVE"
    exit 1
fi

SIZE="$(wc -c < "$ARCHIVE" | tr -d '[:space:]')"
DIGEST="$(sha256sum "$ARCHIVE" | cut -d' ' -f1)"

echo "Built ${ARCHIVE}"
echo "  version : ${VERSION}"
echo "  size    : ${SIZE} bytes"
echo "  sha256  : ${DIGEST}"
echo
echo "The version in the header is the only thing that tells one copy of this"
echo "from another — the filename never changes. Quote it when you send it on."
