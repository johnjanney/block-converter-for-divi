#!/usr/bin/env bash
#
# Shared wp-env helpers. Sourced, not run.
#
# `wp-env destroy` removes containers, volumes and networks — but not the
# WordPress checkouts it made under ~/wp-env/<hash>/. A later `start` then dies
# with "destination path … already exists and is not an empty directory", and
# because .wp-env.json maps a file into wp-content, some of what it left behind
# is owned by root and cannot be removed from the host at all.
#
# Any script that destroys and recreates an environment needs this. Both
# bin/multisite-check.sh and bin/wp-matrix.sh do.

# Remove an environment completely, including the checkouts destroy leaves.
#
# The removal runs in a container because the files are root-owned: they were
# created by Docker, and the host user cannot delete them.
d2g_wp_env_reset() {
    echo "y" | npx wp-env destroy >/dev/null 2>&1 || true

    local instance
    instance="$(npx wp-env install-path 2>/dev/null | tr -d '\r' | tail -1)"

    if [[ -n "$instance" && -d "$instance" ]]; then
        docker run --rm -v "${instance}:/instance" alpine \
            sh -c 'rm -rf /instance/WordPress /instance/tests-WordPress /instance/WordPress-PHPUnit /instance/tests-WordPress-PHPUnit /instance/wp-env-cache.json' \
            >/dev/null 2>&1 || true
    fi
}

# Start an environment, resetting first if a stale checkout blocks it.
d2g_wp_env_start() {
    if npx wp-env start >/dev/null 2>&1; then
        d2g_wp_env_update_db
        return 0
    fi

    echo "  (start failed; clearing leftover checkouts and retrying)"
    d2g_wp_env_reset
    npx wp-env start >/dev/null 2>&1
    d2g_wp_env_update_db
}

# Clear a pending database upgrade.
#
# WordPress compares db_version against the core it is running and, when they
# differ, redirects *every* admin page to wp-admin/upgrade.php until someone
# runs the upgrade. bin/wp-matrix.sh moves the environment between WordPress
# versions, so it routinely leaves the shared environment in that state — and
# the next browser run then failed nine tests with "element not found", because
# what the browser was actually looking at was the update screen.
#
# A no-op when nothing is pending.
d2g_wp_env_update_db() {
    npx wp-env run cli wp core update-db >/dev/null 2>&1 || true
}
