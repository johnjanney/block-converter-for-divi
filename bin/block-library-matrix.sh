#!/usr/bin/env bash
#
# Validate the fixture corpus against the block library each supported
# WordPress release actually ships.
#
# The converter suite validates with one block library: whatever
# tests/js/package-lock.json pins. That answers "would a current editor accept
# this?" and nothing else. A block's save() is JavaScript that changes between
# releases — core/image gained a `lightbox` wrapper, core/quote moved to inner
# blocks, core/details did not exist before 6.3 — so markup that is valid under
# one library can be reported as "unexpected or invalid content" under another.
# The plugin claims 6.1 through 7.0. Until this existed, that claim rested on
# block *registration*, which is a much weaker statement than save() agreement.
#
# It also fixes a smaller embarrassment: the pinned 10.3.0 is newer than any
# released WordPress. 7.0.2 ships 9.40.2. See tests/js/versions.json.
#
# Each version is installed into its own tree under a cache directory, so a
# re-run is fast. Nothing here touches tests/js/node_modules.
#
# Usage:
#   bash bin/block-library-matrix.sh                 # every mapped version
#   bash bin/block-library-matrix.sh 6.1 7.0.2       # only these
#   bash bin/block-library-matrix.sh --show-mapping  # print the mapping and stop
#   bash bin/block-library-matrix.sh --clean         # drop the install cache
#
# Requires network access on the first run for each version.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAP="tests/js/versions.json"
CACHE="${D2G_BL_CACHE:-${TMPDIR:-/tmp}/d2g-block-library-matrix}"

if [[ ! -f "$MAP" ]]; then
    echo "error: ${MAP} is missing." >&2
    exit 1
fi

WANTED=()
for arg in "$@"; do
    case "$arg" in
        --show-mapping)
            node -e '
                const m = require("./tests/js/versions.json");
                for (const v of m.versions) {
                    console.log(`WordPress ${v.wordpress}\t@wordpress/block-library ${v["block-library"]}\t${v.note}`);
                }
            '
            exit 0
            ;;
        --clean)
            rm -rf "$CACHE"
            echo "removed ${CACHE}"
            exit 0
            ;;
        -*) echo "unknown option: $arg" >&2; exit 2 ;;
        *) WANTED+=( "$arg" ) ;;
    esac
done

# ---- The corpus ------------------------------------------------------------
#
# Produced by the converter itself rather than kept as a second copy of the
# fixtures, so this cannot drift from what the plugin emits today.

CASES="$(mktemp)"
trap 'rm -f "$CASES"' EXIT

# Built by the converter itself rather than kept as a second copy of the
# fixtures, so it cannot drift from what the plugin emits today — and built
# once per version, because the plugin's output legitimately differs between
# them: it asks WordPress whether a block exists before emitting it.
build_corpus() {
    D2G_UNREGISTERED="$1" D2G_WP_VERSION="$2" php -r '
        require "tests/bootstrap.php";
        require ABSPATH . "includes/load.php";
        // The plugin adapts its output to the WordPress it is running on, so
        // the corpus has to be generated as that version, not as the newest.
        $GLOBALS["d2g_test_wp_version"] = (string) getenv( "D2G_WP_VERSION" );
        $fixtures = require "tests/fixtures.php";
        $out = [];
        foreach ( $fixtures as $name => $fixture ) {
            if ( ! empty( $fixture["unchanged"] ) ) {
                continue;
            }
            // A fixture that declares its own unregistered blocks is testing
            // that fallback; honour it on top of the version-wide list.
            $GLOBALS["d2g_test_unregistered"] = array_unique( array_merge(
                array_filter( explode( ",", (string) getenv( "D2G_UNREGISTERED" ) ) ),
                $fixture["unregistered"] ?? []
            ) );
            $markup = ( new D2G_Converter() )->convert( $fixture["divi"] );
            if ( "" !== trim( $markup ) ) {
                $out[ $name ] = $markup;
            }
        }
        echo json_encode( $out );
    '
}

# ---- One version at a time -------------------------------------------------

mkdir -p "$CACHE"
FAILED=()
SKIPPED=()

while IFS=$'\t' read -r WP BL UNREGISTERED NOTE; do
    if [[ ${#WANTED[@]} -gt 0 ]]; then
        match=0
        for w in "${WANTED[@]}"; do [[ "$w" == "$WP" ]] && match=1; done
        [[ $match -eq 1 ]] || continue
    fi

    DIR="${CACHE}/wp-${WP}"
    echo "============================================================"
    echo "  WordPress ${WP} — @wordpress/block-library ${BL}"
    echo "  ${NOTE}"
    echo "============================================================"

    if [[ ! -d "${DIR}/node_modules/@wordpress/block-library" ]]; then
        mkdir -p "$DIR"
        # `overrides` is load-bearing, not tidiness — see tests/js/versions.json.
        node -e '
            const map = require("./tests/js/versions.json");
            const wp = process.argv[1];
            const v = map.versions.find( ( entry ) => entry.wordpress === wp );
            // Only block-library is installed directly. Everything else comes
            // in as its dependency, pinned by `overrides` to the version that
            // release of WordPress shipped — which is what stops npm nesting a
            // second copy of @wordpress/data, /blocks or /private-apis under a
            // transitive dependency. Two copies of any of them throws at import
            // time and says nothing about the version under test.
            require( "fs" ).writeFileSync(
                process.argv[ 2 ],
                JSON.stringify( {
                    name: `d2g-block-library-wp-${ wp }`.replace( /\./g, "-" ),
                    private: true,
                    dependencies: { "@wordpress/block-library": v[ "block-library" ] },
                    overrides: v.overrides && Object.keys( v.overrides ).length
                        ? v.overrides
                        : { "@wordpress/blocks": v.blocks, "@wordpress/element": v.element },
                }, null, 2 )
            );
        ' "$WP" "${DIR}/package.json"
        echo "installing..."
        if ! ( cd "$DIR" && npm install --no-fund --no-audit --silent >/dev/null 2>&1 ); then
            echo "  could not install the packages for WordPress ${WP}." >&2
            SKIPPED+=( "${WP} (install failed)" )
            echo
            continue
        fi
    fi

    if ! build_corpus "$UNREGISTERED" "$WP" > "$CASES" || [[ ! -s "$CASES" ]]; then
        echo "  could not build the fixture corpus for WordPress ${WP}." >&2
        SKIPPED+=( "${WP} (corpus failed)" )
        echo
        continue
    fi

    echo "corpus: $(node -e "process.stdout.write(String(Object.keys(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8'))).length))" "$CASES") fixtures"

    REPORT="${DIR}/report.json"
    node tests/js/validate.mjs "$CASES" --modules "$DIR" --json > "$REPORT"

    # The verdict is not the validator's exit code: a release can have failures
    # that are known, explained, and deliberately not fixed. Those are listed in
    # versions.json and reported as known. Anything else fails, and so does a
    # known failure that has started passing — otherwise the list rots into a
    # blanket exemption.
    if node -e '
        const fs = require( "fs" );
        const report = JSON.parse( fs.readFileSync( process.argv[ 1 ], "utf8" ) );
        const map = require( "./tests/js/versions.json" );
        const entry = map.versions.find( ( v ) => v.wordpress === process.argv[ 2 ] );
        const known = entry[ "known-failures" ] || {};

        const failing = new Set(
            report.results.filter( ( r ) => r.problems.length ).map( ( r ) => r.name )
        );
        const blocks = report.results.reduce( ( n, r ) => n + r.blocks, 0 );

        const unexpected = [ ...failing ].filter( ( name ) => ! ( name in known ) );
        const cured = Object.keys( known ).filter( ( name ) => ! failing.has( name ) );

        for ( const name of unexpected ) {
            const r = report.results.find( ( x ) => x.name === name );
            process.stdout.write( `FAIL  ${ name }\n` );
            for ( const p of r.problems ) {
                process.stdout.write( `        ${ p.path }\n          ${ p.reason }\n` );
            }
        }
        for ( const name of [ ...failing ].filter( ( n ) => n in known ) ) {
            process.stdout.write( `known ${ name }\n          ${ known[ name ] }\n` );
        }
        for ( const name of cured ) {
            process.stdout.write(
                `FIXED ${ name } now validates here. Remove it from known-failures in tests/js/versions.json.\n`
            );
        }

        process.stdout.write(
            `${ report.results.length - unexpected.length }/${ report.results.length } fixtures valid or known, ` +
            `${ blocks } blocks checked (@wordpress/block-library ${ report.libraryVersion })\n`
        );

        process.exit( unexpected.length || cured.length ? 1 : 0 );
    ' "$REPORT" "$WP"; then
        echo
    else
        FAILED+=( "$WP" )
        echo
    fi
done < <(node -e '
    const m = require("./tests/js/versions.json");
    for (const v of m.versions) {
        process.stdout.write([
            v.wordpress, v["block-library"],
            ( v.unregistered || [] ).join( "," ), v.note,
        ].join("\t") + "\n");
    }
')

# ---- Verdict ---------------------------------------------------------------

echo "============================================================"
if [[ ${#SKIPPED[@]} -gt 0 ]]; then
    # Named rather than counted silently: a version that did not run is not a
    # version that passed, and a summary that hides it reads like one.
    echo "not run: ${SKIPPED[*]}" >&2
fi

if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "Converted markup is not valid on: ${FAILED[*]}" >&2
    echo "Either fix the emitted markup, or raise 'Requires at least'." >&2
    exit 1
fi

if [[ ${#SKIPPED[@]} -gt 0 ]]; then
    exit 1
fi

echo "Every mapped WordPress release accepts the converted markup."
