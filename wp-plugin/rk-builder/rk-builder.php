<?php
/**
 * Plugin Name: RK Builder (All-in-One)
 * Description: Visual page builder in one plugin: React editor inside wp-admin, strict layout validation, draft/publish with revisions, preview links, PHP public rendering, Service/Portfolio content types. No Node.js required.
 * Version: 1.42.0
 * Author: Rakib Hasan
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * License: GPL-2.0-or-later
 * Text Domain: rk-builder
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RK_BUILDER_VERSION', '1.42.0' );
define( 'RK_BUILDER_NS', 'rk/v1' );
define( 'RK_BUILDER_DIR', __DIR__ . '/' );
if ( ! defined( 'RK_BUILDER_URL' ) ) { define( 'RK_BUILDER_URL', function_exists( 'plugin_dir_url' ) ? plugin_dir_url( __FILE__ ) : '' ); }

require_once RK_BUILDER_DIR . 'includes/settings.php';
require_once RK_BUILDER_DIR . 'includes/validation.php';
require_once RK_BUILDER_DIR . 'includes/storage.php';
require_once RK_BUILDER_DIR . 'includes/preview.php';
require_once RK_BUILDER_DIR . 'includes/revalidate.php';
require_once RK_BUILDER_DIR . 'includes/cors.php';
require_once RK_BUILDER_DIR . 'includes/rest.php';
require_once RK_BUILDER_DIR . 'includes/builder.php';
require_once RK_BUILDER_DIR . 'includes/content.php';
require_once RK_BUILDER_DIR . 'includes/types.php';
require_once RK_BUILDER_DIR . 'includes/templates.php';
require_once RK_BUILDER_DIR . 'includes/global.php';
require_once RK_BUILDER_DIR . 'includes/mcp.php';
require_once RK_BUILDER_DIR . 'includes/mcp-copy.php';
require_once RK_BUILDER_DIR . 'includes/dyn-transfer.php';
require_once RK_BUILDER_DIR . 'includes/seo-entries.php';
require_once RK_BUILDER_DIR . 'includes/media-upload.php';
require_once RK_BUILDER_DIR . 'includes/site-transfer.php';
require_once RK_BUILDER_DIR . 'includes/themes.php';
require_once RK_BUILDER_DIR . 'includes/kit.php';
require_once RK_BUILDER_DIR . 'includes/library.php';
require_once RK_BUILDER_DIR . 'includes/install-undo.php';
require_once RK_BUILDER_DIR . 'includes/dashboard.php';
require_once RK_BUILDER_DIR . 'includes/integrations.php';
require_once RK_BUILDER_DIR . 'includes/assets.php';
require_once RK_BUILDER_DIR . 'includes/admin.php';
require_once RK_BUILDER_DIR . 'includes/renderer.php';
require_once RK_BUILDER_DIR . 'includes/visualizer.php';
require_once RK_BUILDER_DIR . 'includes/public.php';
require_once RK_BUILDER_DIR . 'includes/login.php';
require_once RK_BUILDER_DIR . 'includes/places.php';
require_once RK_BUILDER_DIR . 'includes/robots-fix.php';
require_once RK_BUILDER_DIR . 'includes/schema.php';
require_once RK_BUILDER_DIR . 'includes/seo.php';
require_once RK_BUILDER_DIR . 'includes/cache.php';
require_once RK_BUILDER_DIR . 'includes/migration.php';
require_once RK_BUILDER_DIR . 'includes/setup.php';
require_once RK_BUILDER_DIR . 'includes/suite.php';

add_action( 'init', 'rk_builder_register_content_types' );
add_action( 'rest_api_init', 'rk_builder_register_rest_fields' );
add_action( 'rest_api_init', 'rk_builder_register_routes' );
add_action( 'rest_api_init', 'rk_builder_install_cors', 15 );
add_action( 'admin_menu', 'rk_builder_register_admin_menu' );
rk_builder_register_admin_hooks(); // settings page, setup wizard, migration tool, row actions, schema upgrade (includes/setup.php)

register_activation_hook( __FILE__, 'rk_builder_activate' );
function rk_builder_activate( $network_wide = false ) {
	rk_builder_register_content_types();
	flush_rewrite_rules();
	rk_builder_mark_setup_pending( $network_wide ); // one-time redirect to Settings > RK Builder > Setup
}
