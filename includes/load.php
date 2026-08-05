<?php
/**
 * Load the conversion classes, in dependency order.
 *
 * One list, used by both the plugin and the test suite. They were previously
 * two lists in two files, which is a standing invitation for the tests to
 * exercise a different set of classes than the plugin ships.
 *
 * There is no autoloader on purpose: a WordPress plugin that registers one
 * competes with every other plugin doing the same, and this is nine files.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$d2g_includes = __DIR__ . '/';

require_once $d2g_includes . 'class-d2g-parser.php';
require_once $d2g_includes . 'class-d2g-style-mapper.php';
require_once $d2g_includes . 'class-d2g-block-builder.php';
require_once $d2g_includes . 'class-d2g-html-converter.php';

require_once $d2g_includes . 'renderers/class-d2g-module-renderer.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-layout.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-text.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-media.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-content.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-interactive.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-pricing.php';
require_once $d2g_includes . 'renderers/class-d2g-renderer-dynamic.php';

require_once $d2g_includes . 'class-d2g-converter.php';

unset( $d2g_includes );
