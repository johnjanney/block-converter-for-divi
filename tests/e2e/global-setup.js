// @ts-check
/**
 * Log in once, and hand every test the resulting session.
 *
 * Driving wp-login.php per test was both slow and flaky: WordPress focuses the
 * username field from a script on load, and filling both fields immediately
 * afterwards occasionally put the password into the username box. That is a
 * property of the login form, not of the plugin, and it is not what these
 * tests are for.
 *
 * Logging in once also means a test failure is about the Tools screen rather
 * than about authentication.
 */

import { chromium } from '@playwright/test';

export default async function globalSetup( config ) {
    const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';
    const browser = await chromium.launch();
    const page = await browser.newPage( { baseURL } );

    await page.goto( '/wp-login.php' );
    await page.waitForSelector( '#user_login' );

    // Set the values directly and confirm them, rather than racing the form's
    // own focus handling.
    await page.locator( '#user_login' ).fill( 'admin' );
    await page.locator( '#user_pass' ).fill( 'password' );
    await page.locator( '#user_login' ).evaluate( ( el ) => {
        if ( el.value !== 'admin' ) {
            throw new Error( `username field holds "${ el.value }" — the form raced the fill` );
        }
    } );

    await Promise.all( [
        page.waitForURL( /wp-admin/, { timeout: 30_000 } ),
        page.locator( '#wp-submit' ).click(),
    ] );

    await page.context().storageState( { path: 'tests/e2e/.auth.json' } );
    await browser.close();
}
