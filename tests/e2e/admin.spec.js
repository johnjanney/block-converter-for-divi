// @ts-check
/**
 * The Tools screen, driven in a real browser.
 *
 * Everything else in this project tests the converter or the endpoints. The
 * admin screen is jQuery against a real DOM, and nothing tested it at all —
 * which matters most for the batch runner, because that is where 2.0.0 counted
 * failed pages as successes and told the user everything had converted.
 *
 * These tests therefore care less about pixels than about the two things a
 * wrong answer here would cost a user:
 *
 *   - being told a conversion succeeded when it did not;
 *   - being unable to tell which page failed, or why.
 *
 * The site is seeded by tests/e2e/seed.php before the run. Pages are titled
 * `e2e: …` so a test can find its own row without depending on what else the
 * site contains.
 */

import { test, expect } from '@playwright/test';

const TOOLS = '/wp-admin/tools.php?page=block-converter-for-divi';

/** The row for a seeded page, found by its visible title. */
function rowFor( page, title ) {
    return page.locator( '#d2g-results tbody tr', { has: page.locator( `a:text-is("${ title }")` ) } );
}

// Every test starts from the same seeded site and an existing session.
//
// Re-seeding per test is not tidiness: these tests convert pages, and a
// conversion is not reversible from the next test's point of view. Without
// this, the restore test found a page the convert test had already converted
// and failed for a reason that had nothing to do with restoring.
test.beforeEach( async ( { page, request } ) => {
    const seeded = await request.get( '/?d2g-e2e-seed=1' );
    expect( seeded.ok(), 'the site must re-seed between tests' ).toBeTruthy();

    await page.goto( TOOLS );
    await expect( page.locator( '#d2g-scan' ) ).toBeVisible();
} );

test( 'the screen loads with no console errors and nothing visible until a scan', async ( { page } ) => {
    const errors = [];
    page.on( 'pageerror', ( e ) => errors.push( e.message ) );
    page.on( 'console', ( m ) => m.type() === 'error' && errors.push( m.text() ) );

    await page.reload();
    await expect( page.locator( '#d2g-scan' ) ).toBeVisible();
    await expect( page.locator( '#d2g-results' ) ).toBeHidden();
    await expect( page.locator( '#d2g-batch-bar' ) ).toBeHidden();

    expect( errors, 'the admin script must load without errors' ).toEqual( [] );
} );

test( 'scanning lists the Divi pages', async ( { page } ) => {
    await page.click( '#d2g-scan' );
    await expect( page.locator( '#d2g-results' ) ).toBeVisible();
    await expect( rowFor( page, 'e2e: simple page' ) ).toBeVisible();
    await expect( page.locator( '#d2g-status' ) ).toContainText( /page\(s\) found/ );
} );

test( 'preview shows the conversion and names what will be lost', async ( { page } ) => {
    await page.click( '#d2g-scan' );
    await rowFor( page, 'e2e: simple page' ).locator( '.d2g-preview-btn' ).click();

    const modal = page.locator( '#d2g-preview-modal' );
    await expect( modal ).toBeVisible();

    // Both panes carry real content, not a spinner that never resolved.
    await expect( page.locator( '#d2g-preview-original' ) ).toContainText( '[et_pb_text]' );
    await expect( page.locator( '#d2g-preview-converted' ) ).toContainText( '<!-- wp:heading' );

    // The lossy setting on this page must be reported rather than dropped.
    // Asserted on the animation, not on spacing: spacing is now mapped onto
    // core's block supports, and pinning this to whichever category happens to
    // be unmappable today means the test breaks every time the converter gets
    // better at its job.
    await expect( page.locator( '#d2g-preview-warnings' ) ).toBeVisible();
    await expect( page.locator( '#d2g-preview-warnings' ) ).toContainText( /animation/i );

    // Nothing has been written yet: preview does not convert.
    await page.locator( '.d2g-modal-close' ).click();
    await expect( modal ).toBeHidden();
    await expect( rowFor( page, 'e2e: simple page' ).locator( '.d2g-convert-btn' ) ).toBeEnabled();
} );

test( 'the preview dialog is keyboard operable', async ( { page } ) => {
    await page.click( '#d2g-scan' );
    await rowFor( page, 'e2e: simple page' ).locator( '.d2g-preview-btn' ).click();

    const modal = page.locator( '#d2g-preview-modal' );
    await expect( modal ).toBeVisible();
    await expect( modal ).toHaveAttribute( 'aria-modal', 'true' );

    // Focus moves into the dialog rather than being left behind it.
    await expect( page.locator( '.d2g-modal-close' ) ).toBeFocused();

    // Escape closes, and focus returns to where it came from.
    await page.keyboard.press( 'Escape' );
    await expect( modal ).toBeHidden();
    await expect( rowFor( page, 'e2e: simple page' ).locator( '.d2g-preview-btn' ) ).toBeFocused();
} );

test( 'converting one page reports success and disables the row', async ( { page } ) => {
    page.on( 'dialog', ( d ) => d.accept() );

    await page.click( '#d2g-scan' );
    const row = rowFor( page, 'e2e: simple page' );
    await row.locator( '.d2g-convert-btn' ).click();

    // Success has to be announced, not just drawn. #d2g-status is the screen's
    // aria-live region: before this was fixed, a failed conversion spoke and a
    // successful one was silent.
    await expect( page.locator( '#d2g-status' ) ).toContainText( /converted successfully/i );
    await expect( row.locator( '.d2g-convert-btn' ) ).toBeDisabled();
    await expect( row ).toHaveClass( /d2g-row-converted|d2g-row-done/ );

    // A backup was taken, so Restore appears.
    await expect( row.locator( '.d2g-restore-btn' ) ).toBeVisible();

    // This page carries a lossy setting. Converting without previewing must
    // still tell the user what could not be carried over — the warnings used to
    // be returned by the server and dropped by the browser.
    const warnings = page.locator( '#d2g-warnings' );
    await expect( warnings ).toBeVisible();
    await expect( warnings ).toContainText( /animation/i );
    await expect( warnings ).toContainText( 'et_pb_section' );
} );

test( 'restoring puts the page back and makes it convertible again', async ( { page } ) => {
    page.on( 'dialog', ( d ) => d.accept() );

    await page.click( '#d2g-scan' );
    const row = rowFor( page, 'e2e: simple page' );

    await row.locator( '.d2g-convert-btn' ).click();
    await expect( row.locator( '.d2g-restore-btn' ) ).toBeVisible();

    await row.locator( '.d2g-restore-btn' ).click();
    await expect( page.locator( '#d2g-status' ) ).toContainText( /restored/i );
    await expect( row.locator( '.d2g-convert-btn' ) ).toBeEnabled();
} );

/**
 * The regression this whole file exists for.
 *
 * One page in the batch is edited behind the browser's back after the scan, so
 * its source token is stale and the endpoint refuses it. The batch must report
 * two converted and one failed — and must name the page that failed. In 2.0.0
 * it would have reported three converted.
 */
test( 'a batch with one failure reports the failure separately', async ( { page } ) => {
    page.on( 'dialog', ( d ) => d.accept() );

    await page.click( '#d2g-scan' );

    for ( const title of [ 'e2e: batch one', 'e2e: batch two', 'e2e: batch converted' ] ) {
        await rowFor( page, title ).locator( 'input.d2g-select' ).check();
    }

    // The failure is a page that already holds a conversion, which the server
    // refuses permanently. It used to be a page whose token had gone stale, and
    // that is no longer a failure — the row re-reads and converts. This test is
    // about the batch runner naming its failures, which was the 2.0.0 defect,
    // so it needs a refusal that stays refused.
    const refusedRow = rowFor( page, 'e2e: batch converted' );
    const refusedId = await refusedRow.getAttribute( 'data-id' );

    await page.click( '#d2g-convert-selected' );

    const status = page.locator( '#d2g-status' );
    await expect( status ).toContainText( /finished with errors/i, { timeout: 30000 } );
    await expect( status ).toContainText( '2' );
    await expect( status ).toContainText( /1 failed|, 1/ );

    // The user has to be able to tell *which* page failed.
    await expect( status ).toContainText( String( refusedId ) );

    // The two that worked are done; the one that failed is still convertible.
    await expect( rowFor( page, 'e2e: batch one' ).locator( '.d2g-convert-btn' ) ).toBeDisabled();
    await expect( rowFor( page, 'e2e: batch two' ).locator( '.d2g-convert-btn' ) ).toBeDisabled();
    await expect( refusedRow ).toHaveClass( /d2g-row-error/ );
} );

test( 'a page edited after the scan is re-read, converted, and says so', async ( { page, request } ) => {
    page.on( 'dialog', ( d ) => d.accept() );

    await page.click( '#d2g-scan' );

    const row = rowFor( page, 'e2e: batch stale' );
    const id = await row.getAttribute( 'data-id' );
    await row.locator( 'input.d2g-select' ).check();

    // Edited behind the browser's back, the way a colleague saving in another
    // tab — or an importer still rewriting URLs — would do it.
    await request.post( 'http://localhost:8888/?d2g-e2e-touch=' + id );

    await page.click( '#d2g-convert-selected' );

    // No longer a dead end: the row comes back once with the token the server
    // handed it and converts what is actually there.
    const status = page.locator( '#d2g-status' );
    await expect( status ).toContainText( /converted/i, { timeout: 30000 } );
    await expect( status ).not.toContainText( /finished with errors/i );
    await expect( row.locator( '.d2g-convert-btn' ) ).toBeDisabled();
    await expect( row ).not.toHaveClass( /d2g-row-error/ );

    // And it is not silent about it. The page that was scanned and previewed is
    // not the page that was converted, which the user has to be told.
    const warnings = page.locator( '#d2g-warnings' );
    await expect( warnings ).toBeVisible();
    await expect( warnings ).toContainText( /edited between being scanned and being converted/i );
} );

test( 'the data-retention setting persists', async ( { page } ) => {
    page.on( 'dialog', ( d ) => d.accept() );

    const box = page.locator( '#d2g-delete-data' );
    await expect( box ).not.toBeChecked();

    await box.check();
    // The server's own wording, which is more use than a bare "Saved."
    await expect( page.locator( '#d2g-settings-feedback' ) ).toContainText( /backups will be deleted/i );

    await page.reload();
    await expect( page.locator( '#d2g-delete-data' ) ).toBeChecked();
} );

test( 'filters and sorting re-query the server', async ( { page } ) => {
    await page.click( '#d2g-scan' );
    await expect( rowFor( page, 'e2e: simple page' ) ).toBeVisible();

    // Posts only: the seeded pages are all `page`, so the list must empty out
    // and say so rather than silently showing stale rows.
    await page.selectOption( '#d2g-filter-type', 'post' );
    await expect( page.locator( '#d2g-status' ) ).toContainText( /no divi pages found|0 page/i );
    await expect( rowFor( page, 'e2e: simple page' ) ).toHaveCount( 0 );

    await page.selectOption( '#d2g-filter-type', 'page' );
    await expect( rowFor( page, 'e2e: simple page' ) ).toBeVisible();

    // Sorting by title must reorder rather than no-op.
    const before = await page.locator( '#d2g-results tbody tr td:nth-child(2)' ).allInnerTexts();
    await page.locator( '.d2g-sortable[data-sort="title"] .d2g-sort-btn' ).click();
    await expect( page.locator( '#d2g-results tbody tr' ).first() ).toBeVisible();
    const after = await page.locator( '#d2g-results tbody tr td:nth-child(2)' ).allInnerTexts();
    expect( after ).not.toEqual( before );
} );
