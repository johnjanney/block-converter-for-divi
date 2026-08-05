// @ts-check
/**
 * Browser tests for the Tools screen.
 *
 * Runs against the wp-env instance on :8888. Serial, single worker, because
 * every test drives the same WordPress install and the conversions they
 * perform are not independent — two workers converting the same seeded page
 * would race for its write lock and one would legitimately fail.
 *
 * Use bin/e2e.sh rather than calling Playwright directly: it seeds the site
 * first, and runs the browser inside Playwright's own container so the run does
 * not depend on which system libraries happen to be installed.
 */

import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !! process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],
    timeout: 60_000,
    expect: { timeout: 15_000 },

    globalSetup: './tests/e2e/global-setup.js',

    use: {
        baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
        storageState: 'tests/e2e/.auth.json',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },

    projects: [
        { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
    ],
} );
