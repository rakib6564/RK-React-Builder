<?php
/**
 * A real robots.txt file left in the site folder (for example from an older website on the same hosting) overrides
 * WordPress, and its "Sitemap:" line can name another domain. This notice shows administrators when that happens and
 * offers one button that points those lines at this site's own sitemap (the original file is kept as a backup).
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Host without "www." and in lower case, or ''. */
function rk_builder_robots_host( $url ) {
	$h = wp_parse_url( (string) $url, PHP_URL_HOST );
	return is_string( $h ) ? strtolower( preg_replace( '/^www\./i', '', $h ) ) : '';
}

/** The "Sitemap:" addresses in a robots.txt that belong to another website than $home. */
function rk_builder_robots_foreign_sitemaps( $text, $home ) {
	$mine = rk_builder_robots_host( $home );
	$out  = array();
	if ( preg_match_all( '/^\s*Sitemap\s*:\s*(\S+)/im', (string) $text, $m ) ) {
		foreach ( $m[1] as $u ) {
			$h = rk_builder_robots_host( $u );
			if ( '' !== $h && '' !== $mine && $h !== $mine ) { $out[] = $u; }
		}
	}
	return $out;
}

/** The same text with every foreign "Sitemap:" line replaced by this site's own sitemap (added once). */
function rk_builder_robots_fixed( $text, $sitemap, $home ) {
	$mine = rk_builder_robots_host( $home );
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	$kept  = array();
	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*Sitemap\s*:\s*(\S+)/i', $line, $m ) ) {
			$h = rk_builder_robots_host( $m[1] );
			if ( '' !== $h && $h !== $mine ) { continue; }
		}
		$kept[] = $line;
	}
	$body = rtrim( implode( "\n", $kept ) );
	if ( ! preg_match( '/^\s*Sitemap\s*:\s*' . preg_quote( $sitemap, '/' ) . '\s*$/im', $body ) ) { $body .= "\n\nSitemap: " . $sitemap; }
	return $body . "\n";
}

function rk_builder_robots_path() { return trailingslashit( ABSPATH ) . 'robots.txt'; }

/** The stale foreign sitemap addresses in the real robots.txt file, or an empty list. */
function rk_builder_robots_problem() {
	$path = rk_builder_robots_path();
	if ( ! is_readable( $path ) ) { return array(); }
	return rk_builder_robots_foreign_sitemaps( (string) file_get_contents( $path ), home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

function rk_builder_robots_notice() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) { return; }
	$bad = rk_builder_robots_problem();
	if ( ! $bad ) { return; }
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=rk_builder_fix_robots' ), 'rk_builder_fix_robots' );
	echo '<div class="notice notice-warning"><p><strong>RK Builder:</strong> your site\'s <code>robots.txt</code> file points search engines to another website\'s sitemap (<code>' . esc_html( $bad[0] ) . '</code>). ';
	echo '<a class="button button-primary" href="' . esc_url( $url ) . '">Point it at this site\'s sitemap</a> The original file is kept as <code>robots.txt.rk-backup</code>.</p></div>';
}

function rk_builder_robots_handle_fix() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'rk_builder_fix_robots' );
	$path = rk_builder_robots_path();
	$dest = admin_url( 'index.php' );
	if ( ! is_readable( $path ) || ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_safe_redirect( add_query_arg( 'rk_robots', 'locked', $dest ) );
		exit;
	}
	$old = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$new = rk_builder_robots_fixed( $old, home_url( '/wp-sitemap.xml' ), home_url( '/' ) );
	$ok  = false !== file_put_contents( $path . '.rk-backup', $old ) && false !== file_put_contents( $path, $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	wp_safe_redirect( add_query_arg( 'rk_robots', $ok ? 'fixed' : 'locked', $dest ) );
	exit;
}

function rk_builder_robots_result_notice() {
	if ( ! isset( $_GET['rk_robots'] ) || ! current_user_can( 'manage_options' ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
	$r = sanitize_key( wp_unslash( $_GET['rk_robots'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'fixed' === $r ) { echo '<div class="notice notice-success is-dismissible"><p>robots.txt now points to this site\'s sitemap.</p></div>'; }
	if ( 'locked' === $r ) { echo '<div class="notice notice-error"><p>robots.txt could not be changed from WordPress (the file is read-only). Edit it in your host\'s file manager.</p></div>'; }
}

add_action( 'admin_notices', 'rk_builder_robots_notice' );
add_action( 'admin_notices', 'rk_builder_robots_result_notice' );
add_action( 'admin_post_rk_builder_fix_robots', 'rk_builder_robots_handle_fix' );
