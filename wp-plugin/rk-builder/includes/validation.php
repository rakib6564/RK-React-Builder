<?php
/**
 * Strict, pure-PHP validation of the builder data contract.
 *
 * This file must not call any WordPress function: it runs in plain CLI tests and mirrors the
 * Zod schemas in client/src/lib/schema/* and client/src/blocks/<type>/schema.ts. Anything the
 * schema does not explicitly allow is reported as an issue; nothing is ever silently dropped.
 *
 * Issues are lists of array( 'path' => 'blocks.0.props.ctaHref', 'message' => '...' ), with the
 * same dotted path format the TS side produces.
 *
 * $image_hosts: array of lower-case hosts (optionally host:port) that absolute http(s) image URLs
 * may use. Pass null to skip the host restriction (used when re-reading already stored data).
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_SCHEMA_VERSION' ) ) { define( 'RK_BUILDER_SCHEMA_VERSION', 1 ); }
if ( ! defined( 'RK_BUILDER_MAX_BLOCKS' ) ) { define( 'RK_BUILDER_MAX_BLOCKS', 100 ); }
if ( ! defined( 'RK_BUILDER_MAX_URL' ) ) { define( 'RK_BUILDER_MAX_URL', 500 ); }
if ( ! defined( 'RK_BUILDER_MAX_ISSUES' ) ) { define( 'RK_BUILDER_MAX_ISSUES', 100 ); }

/* ------------------------------------------------------------------ *
 * Small helpers
 * ------------------------------------------------------------------ */

/** Whitespace as JavaScript's \s sees it (used inside a character class under /u). */
function rk_builder_ws_class() {
	return '\s\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';
}

/** true for lists and for the empty array (ambiguous in PHP: could be [] or {}). */
function rk_builder_is_list( $v ) {
	if ( ! is_array( $v ) ) { return false; }
	return array() === $v || array_keys( $v ) === range( 0, count( $v ) - 1 );
}

/** true for associative arrays and for the empty array (ambiguous: could be [] or {}). */
function rk_builder_is_object( $v ) {
	if ( ! is_array( $v ) ) { return false; }
	return array() === $v || array_keys( $v ) !== range( 0, count( $v ) - 1 );
}

/** String length in UTF-16 code units (what JS .length reports); -1 when not valid UTF-8. */
function rk_builder_strlen( $s ) {
	if ( ! is_string( $s ) || 1 !== preg_match( '//u', $s ) ) { return -1; }
	$n = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : (int) preg_match_all( '/./su', $s );
	$astral = (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $s );
	return $n + $astral;
}

function rk_builder_add_issue( array &$issues, $path, $message ) {
	if ( count( $issues ) < RK_BUILDER_MAX_ISSUES ) {
		$issues[] = array( 'path' => (string) $path, 'message' => (string) $message );
	}
}

function rk_builder_join_path( $base, $key ) {
	return '' === $base ? (string) $key : $base . '.' . $key;
}

/** Normalise a list of allowed image hosts; entries may be bare hosts, host:port or full URLs. */
function rk_builder_normalize_hosts( $hosts ) {
	if ( null === $hosts ) { return null; }
	if ( is_string( $hosts ) ) { $hosts = explode( ',', $hosts ); }
	$out = array();
	foreach ( (array) $hosts as $h ) {
		if ( ! is_string( $h ) ) { continue; }
		$h = strtolower( trim( $h ) );
		$h = preg_replace( '~^https?://~', '', $h );
		$h = rtrim( (string) $h, '/' );
		if ( '' === $h || false !== strpos( $h, '*' ) || false !== strpos( $h, '/' ) ) { continue; }
		$out[] = $h;
	}
	return array_values( array_unique( $out ) );
}

/* ------------------------------------------------------------------ *
 * URL checks
 * ------------------------------------------------------------------ */

function rk_builder_has_bad_url_chars( $v ) {
	$r = preg_match( '/[\x00-\x1f\x7f' . rk_builder_ws_class() . ']/u', $v );
	return 0 !== $r; // false (error, e.g. invalid UTF-8) is also "bad"
}

/**
 * Parse the authority of an absolute http(s) URL. Returns array( host, port ) (host lower-cased,
 * port '' when absent) or null when the URL is not a well-formed http(s) URL with a host.
 */
function rk_builder_parse_http_authority( $v ) {
	if ( 1 !== preg_match( '~^https?://([^/?#]*)~i', $v, $m ) ) { return null; }
	$auth = $m[1];
	$at   = strrpos( $auth, '@' );
	if ( false !== $at ) { $auth = substr( $auth, $at + 1 ); }
	$host = $auth;
	$port = '';
	if ( 1 === preg_match( '~^(\[[0-9A-Fa-f:.]+\])(?::([0-9]*))?$~', $auth, $mm ) ) {
		$host = $mm[1];
		$port = isset( $mm[2] ) ? $mm[2] : '';
	} elseif ( 1 === preg_match( '~^([^:\[\]]+)(?::([0-9]*))?$~', $auth, $mm ) ) {
		$host = $mm[1];
		$port = isset( $mm[2] ) ? $mm[2] : '';
	} else {
		return null;
	}
	if ( '' === $host || ( '' !== $port && (int) $port > 65535 ) ) { return null; }
	return array( strtolower( $host ), $port );
}

/** Link targets: "", site-relative "/x", fragment "#x", http(s), mailto, tel. */
function rk_builder_is_safe_link( $v ) {
	if ( ! is_string( $v ) ) { return false; }
	if ( '' === $v ) { return true; }
	$len = rk_builder_strlen( $v );
	if ( $len < 0 || $len > RK_BUILDER_MAX_URL ) { return false; }
	if ( rk_builder_has_bad_url_chars( $v ) || false !== strpos( $v, '\\' ) ) { return false; }
	if ( '//' === substr( $v, 0, 2 ) ) { return false; }
	if ( '/' === $v[0] || '#' === $v[0] ) { return true; }
	if ( 1 !== preg_match( '~^([A-Za-z][A-Za-z0-9+.\-]*):~', $v, $m ) ) { return false; }
	$scheme = strtolower( $m[1] );
	if ( 'http' === $scheme || 'https' === $scheme ) {
		return null !== rk_builder_parse_http_authority( $v );
	}
	return 'mailto' === $scheme || 'tel' === $scheme;
}

/** Image sources: site-relative or http(s) (host-restricted unless $image_hosts is null). */
function rk_builder_is_safe_image_url( $v, $image_hosts = null ) {
	return null === rk_builder_image_url_problem( $v, $image_hosts );
}

/** Returns null when fine, otherwise a human message. */
function rk_builder_image_url_problem( $v, $image_hosts = null ) {
	if ( ! is_string( $v ) ) { return 'Expected string'; }
	$len = rk_builder_strlen( $v );
	if ( $len < 1 || $len > RK_BUILDER_MAX_URL ) { return 'Unsafe or malformed image URL'; }
	if ( rk_builder_has_bad_url_chars( $v ) || false !== strpos( $v, '\\' ) ) { return 'Unsafe or malformed image URL'; }
	if ( '//' === substr( $v, 0, 2 ) ) { return 'Unsafe or malformed image URL'; }
	if ( '/' === $v[0] ) { return null; }
	$auth = rk_builder_parse_http_authority( $v );
	if ( null === $auth ) { return 'Unsafe or malformed image URL'; }
	if ( null !== $image_hosts ) {
		$hosts    = rk_builder_normalize_hosts( $image_hosts );
		$hostport = '' === $auth[1] ? $auth[0] : $auth[0] . ':' . $auth[1];
		if ( ! in_array( $auth[0], $hosts, true ) && ! in_array( $hostport, $hosts, true ) ) {
			return 'Image host is not allowed';
		}
	}
	return null;
}

/* ------------------------------------------------------------------ *
 * Field checkers: each returns null (ok) or an error message
 * ------------------------------------------------------------------ */

function rk_builder_check_string( $v, $min, $max ) {
	if ( ! is_string( $v ) ) { return 'Expected string'; }
	$len = rk_builder_strlen( $v );
	if ( $len < 0 ) { return 'Invalid UTF-8'; }
	if ( $len < $min ) { return 'String must contain at least ' . $min . ' character(s)'; }
	if ( $len > $max ) { return 'String must contain at most ' . $max . ' character(s)'; }
	return null;
}

/** JSON numbers like 6 and 6.0 both count as integers (same as JS). */
function rk_builder_is_intlike( $v ) {
	if ( is_int( $v ) ) { return true; }
	return is_float( $v ) && is_finite( $v ) && floor( $v ) === $v && abs( $v ) < 9007199254740992;
}

function rk_builder_check_int( $v, $min, $max ) {
	if ( ! rk_builder_is_intlike( $v ) ) { return 'Expected integer'; }
	if ( $v < $min ) { return 'Number must be greater than or equal to ' . $min; }
	if ( $v > $max ) { return 'Number must be less than or equal to ' . $max; }
	return null;
}

function rk_builder_check_social_url( $v ) {
	if ( ! is_string( $v ) ) { return 'Expected string'; }
	$len = rk_builder_strlen( $v );
	if ( $len < 0 || $len > RK_BUILDER_MAX_URL ) { return 'URL too long or invalid'; }
	if ( '' === $v ) { return null; }
	if ( 1 !== preg_match( '~^https://[^' . rk_builder_ws_class() . ']+\z~u', $v ) ) { return 'Expected an https URL'; }
	return null;
}

/* Field spec constructors. */
function rk_builder_f_text( $min, $max, $optional = false ) { return array( 't' => 'text', 'min' => $min, 'max' => $max, 'opt' => $optional ); }
function rk_builder_f_link( $optional = false ) { return array( 't' => 'link', 'opt' => $optional ); }
function rk_builder_f_image() { return array( 't' => 'image' ); }
function rk_builder_f_image_opt() { return array( 't' => 'image', 'opt' => true ); }
function rk_builder_f_int( $min, $max, $optional = false ) { return array( 't' => 'int', 'min' => $min, 'max' => $max, 'opt' => $optional ); }
function rk_builder_f_bool( $optional = false ) { return array( 't' => 'bool', 'opt' => $optional ); }
function rk_builder_f_enum( array $values, $optional = false ) { return array( 't' => 'enum', 'values' => $values, 'opt' => $optional ); }
function rk_builder_f_slug() { return array( 't' => 'slug' ); }
/** A string that must match a regular expression (anchored with \z by the caller). */
function rk_builder_f_pattern( $re, $optional = false ) { return array( 't' => 'pattern', 're' => $re, 'opt' => $optional ); }

/** Data sources the dynamic blocks accept. Mirrors client/src/blocks/dynamic/schema.ts. */
function rk_builder_dyn_source_re() { return '/^(title|excerpt|content|date|modified|readtime|author|terms:[a-z][a-z0-9_-]{0,31}|field:[a-z][a-z0-9_]{0,31})\z/'; }
function rk_builder_dyn_image_source_re() { return '/^(featured|field:[a-z][a-z0-9_]{0,31})\z/'; }
function rk_builder_dyn_field_source_re() { return '/^(field:[a-z][a-z0-9_]{0,31})?\z/'; }
function rk_builder_dyn_type_re() { return '/^(current|[a-z][a-z0-9_]{0,19})\z/'; }

/** Block prop specs. Mirrors client/src/blocks/<type>/schema.ts. */
function rk_builder_block_specs() {
	static $specs = null;
	if ( null !== $specs ) { return $specs; }
	$order_by   = rk_builder_f_enum( array( 'date', 'title', 'menu_order' ) );
	$order      = rk_builder_f_enum( array( 'asc', 'desc' ) );
	$collection = function ( $source ) use ( $order_by, $order ) {
		return array(
			'title'    => rk_builder_f_text( 0, 120 ),
			'source'   => rk_builder_f_enum( array( $source ) ),
			'limit'    => rk_builder_f_int( 1, 24 ),
			'cols'     => rk_builder_f_int( 2, 4 ),
			'category' => rk_builder_f_slug(),
			'orderBy'  => $order_by,
			'order'    => $order,
			// display options (all optional; see client/src/blocks/gridOptions.ts)
			'eyebrow'        => rk_builder_f_text( 0, 80, true ),
			'intro'          => rk_builder_f_text( 0, 300, true ),
			'tone'           => rk_builder_f_enum( array( 'light', 'muted' ), true ),
			'equalHeight'    => rk_builder_f_bool( true ),
			'imageRatio'     => rk_builder_f_enum( array( 'auto', 'landscape', 'wide', 'square', 'portrait' ), true ),
			'showImage'      => rk_builder_f_bool( true ),
			'showExcerpt'    => rk_builder_f_bool( true ),
			'excerptLines'   => rk_builder_f_int( 0, 8, true ),
			'showCategories' => rk_builder_f_bool( true ),
			'cardLink'       => rk_builder_f_enum( array( 'none', 'title', 'button' ), true ),
			'buttonLabel'    => rk_builder_f_text( 0, 40, true ),
			'cardStyle'      => rk_builder_f_enum( array( 'bordered', 'soft', 'plain' ), true ),
			'gap'            => rk_builder_f_enum( array( 'sm', 'md', 'lg' ), true ),
			'mobileCols'     => rk_builder_f_int( 1, 2, true ),
			'viewAllLabel'   => rk_builder_f_text( 0, 60, true ),
			'viewAllHref'    => rk_builder_f_link( true ),
		);
	};
	$specs = array(
		'hero'      => array(
			'heading' => rk_builder_f_text( 1, 160 ),
			'sub'     => rk_builder_f_text( 0, 400 ),
			'cta'     => rk_builder_f_text( 0, 60 ),
			'ctaHref' => rk_builder_f_link(),
			'bgMediaId' => rk_builder_f_int( 0, 2147483647, true ),
			'bgUrl'     => rk_builder_f_image_opt(),
		),
		'heading'   => array(
			'text'  => rk_builder_f_text( 1, 200 ),
			'level' => rk_builder_f_enum( array( 2, 3 ) ),
		),
		'text'      => array(
			'text' => rk_builder_f_text( 0, 5000 ),
		),
		'image'     => array(
			'mediaId'    => rk_builder_f_int( 0, 2147483647, true ),
			'url'        => rk_builder_f_image(),
			'alt'        => rk_builder_f_text( 0, 300 ),
			'decorative' => rk_builder_f_bool(),
			'width'      => rk_builder_f_int( 1, 10000, true ),
			'height'     => rk_builder_f_int( 1, 10000, true ),
			'srcset'     => rk_builder_f_text( 0, 1500, true ),
		),
		'cta'       => array(
			'heading' => rk_builder_f_text( 1, 160 ),
			'cta'     => rk_builder_f_text( 1, 60 ),
			'ctaHref' => rk_builder_f_link(),
		),
		'services'  => $collection( 'service' ),
		'portfolio' => $collection( 'portfolio' ),
		'spacer'    => array( 'h' => rk_builder_f_int( 8, 240 ) ),
		'divider'   => array( 'style' => rk_builder_f_enum( array( 'solid', 'dashed' ) ) ),
		'reusable'  => array( 'refId' => rk_builder_f_int( 1, 2147483647 ) ),
		'navbar'    => array(
			'brand'       => rk_builder_f_text( 1, 80 ),
			'logoMediaId' => rk_builder_f_int( 0, 2147483647, true ),
			'logoUrl'     => rk_builder_f_image_opt(),
			'links'       => rk_builder_f_text( 0, 3000 ),
			'phone'       => rk_builder_f_text( 0, 40 ),
			'phoneHref'   => rk_builder_f_link(),
			'overlay'     => rk_builder_f_bool(),
			'bg'          => rk_builder_f_enum( array( 'auto', 'light', 'dark', 'primary' ), true ),
			'size'        => rk_builder_f_enum( array( 'compact', 'regular', 'tall' ), true ),
			'align'       => rk_builder_f_enum( array( 'spread', 'left' ), true ),
			'logoSize'    => rk_builder_f_enum( array( 'sm', 'md', 'lg' ), true ),
			'buttons'     => rk_builder_f_enum( array( 'auto', 'solid', 'outline' ), true ),
			'ctaText'     => rk_builder_f_text( 0, 40, true ),
			'ctaHref'     => rk_builder_f_link( true ),
			'shadow'      => rk_builder_f_bool( true ),
			'shrink'      => rk_builder_f_bool( true ),
			'topText'     => rk_builder_f_text( 0, 160, true ),
			'topLabel'    => rk_builder_f_text( 0, 40, true ),
			'topHref'     => rk_builder_f_link( true ),
			'topDismiss'  => rk_builder_f_bool( true ),
			'topTone'     => rk_builder_f_enum( array( 'primary', 'dark', 'light' ), true ),
		),
		'coverhero' => array(
			'crumb'     => rk_builder_f_text( 0, 400 ),
			'eyebrow'   => rk_builder_f_text( 0, 80 ),
			'heading'   => rk_builder_f_text( 1, 160 ),
			'sub'       => rk_builder_f_text( 0, 400 ),
			'cta'       => rk_builder_f_text( 0, 60 ),
			'ctaHref'   => rk_builder_f_link(),
			'cta2'      => rk_builder_f_text( 0, 60 ),
			'cta2Href'  => rk_builder_f_link(),
			'bgMediaId' => rk_builder_f_int( 0, 2147483647, true ),
			'bgUrl'     => rk_builder_f_image_opt(),
			'size'      => rk_builder_f_enum( array( 'screen', 'page' ) ),
		),
		'section'   => array(
			'eyebrow'   => rk_builder_f_text( 0, 80 ),
			'heading'   => rk_builder_f_text( 0, 200 ),
			'body'      => rk_builder_f_text( 0, 3000 ),
			'linkLabel' => rk_builder_f_text( 0, 60 ),
			'linkHref'  => rk_builder_f_link(),
			'tone'      => rk_builder_f_enum( array( 'light', 'muted' ) ),
			'center'    => rk_builder_f_bool( true ),
			'pill'      => rk_builder_f_text( 0, 120, true ),
		),
		'split'     => array(
			'eyebrow'      => rk_builder_f_text( 0, 80 ),
			'heading'      => rk_builder_f_text( 1, 200 ),
			'body'         => rk_builder_f_text( 0, 3000 ),
			'facts'        => rk_builder_f_text( 0, 600 ),
			'cta'          => rk_builder_f_text( 0, 60 ),
			'ctaHref'      => rk_builder_f_link(),
			'imageMediaId' => rk_builder_f_int( 0, 2147483647, true ),
			'imageUrl'     => rk_builder_f_image_opt(),
			'imageAlt'     => rk_builder_f_text( 0, 300 ),
			'side'         => rk_builder_f_enum( array( 'left', 'right' ) ),
			'tone'         => rk_builder_f_enum( array( 'light', 'muted' ) ),
			'links'        => rk_builder_f_text( 0, 600, true ),
			'checks'       => rk_builder_f_text( 0, 1800, true ),
		),
		'contactband' => array( 'heading' => rk_builder_f_text( 1, 160 ), 'sub' => rk_builder_f_text( 0, 400 ), 'phone' => rk_builder_f_text( 0, 40 ), 'email' => rk_builder_f_text( 0, 120 ) ),
		'values'    => array( 'eyebrow' => rk_builder_f_text( 0, 80 ), 'heading' => rk_builder_f_text( 1, 200 ), 'items' => rk_builder_f_text( 0, 3000 ), 'cols' => rk_builder_f_int( 2, 4 ), 'tone' => rk_builder_f_enum( array( 'light', 'muted' ) ), 'quote' => rk_builder_f_bool( true ) ),
		'panel'     => array(
			'mode'      => rk_builder_f_enum( array( 'rows', 'intro' ) ),
			'eyebrow'   => rk_builder_f_text( 0, 80 ),
			'heading'   => rk_builder_f_text( 0, 200 ),
			'body'      => rk_builder_f_text( 0, 3000 ),
			'checks'    => rk_builder_f_text( 0, 1500 ),
			'actions'   => rk_builder_f_text( 0, 1500 ),
			'items'     => rk_builder_f_text( 0, 4000 ),
			'itemStyle' => rk_builder_f_enum( array( 'feature', 'contact' ) ),
			'flip'      => rk_builder_f_bool(),
			'kicker'    => rk_builder_f_text( 0, 80 ),
			'box'       => rk_builder_f_bool(),
			'tone'      => rk_builder_f_enum( array( 'light', 'muted' ) ),
		),
		'catalog'   => array(
			'eyebrow'  => rk_builder_f_text( 0, 80 ),
			'heading'  => rk_builder_f_text( 0, 200 ),
			'intro'    => rk_builder_f_text( 0, 1000 ),
			'items'    => rk_builder_f_text( 0, 40000 ),
			'cols'     => rk_builder_f_int( 2, 4 ),
			'tone'     => rk_builder_f_enum( array( 'light', 'muted' ) ),
			'numbered' => rk_builder_f_bool(),
			'filters'  => rk_builder_f_bool( true ),
			'joined'   => rk_builder_f_bool( true ),
			'modals'     => rk_builder_f_text( 0, 40000, true ),
			'modalLabel' => rk_builder_f_text( 0, 40, true ),
			'modalCta'   => rk_builder_f_text( 0, 120, true ),
			'pageSize'   => rk_builder_f_int( 0, 100, true ),
			'search'     => rk_builder_f_bool( true ),
			'searchLabel' => rk_builder_f_text( 0, 60, true ),
			'tagField'   => rk_builder_f_enum( array( 'blurb', 'eyebrow' ), true ),
		),
		'detail'    => array(
			'eyebrow'      => rk_builder_f_text( 0, 80 ),
			'heading'      => rk_builder_f_text( 1, 200 ),
			'body'         => rk_builder_f_text( 0, 2000 ),
			'note'         => rk_builder_f_text( 0, 600 ),
			'stepsTitle'   => rk_builder_f_text( 0, 120 ),
			'steps'        => rk_builder_f_text( 0, 3000 ),
			'factorsTitle' => rk_builder_f_text( 0, 120 ),
			'factorsIntro' => rk_builder_f_text( 0, 300 ),
			'factors'      => rk_builder_f_text( 0, 3000 ),
			'links'        => rk_builder_f_text( 0, 1500 ),
			'faqTitle'     => rk_builder_f_text( 0, 120 ),
			'faq'          => rk_builder_f_text( 0, 6000 ),
			'asideTitle'   => rk_builder_f_text( 0, 120 ),
			'asideText'    => rk_builder_f_text( 0, 400 ),
			'phone'        => rk_builder_f_text( 0, 40 ),
			'ctaLabel'     => rk_builder_f_text( 0, 60 ),
			'ctaHref'      => rk_builder_f_link(),
		),
		'calculator' => array( 'heading' => rk_builder_f_text( 0, 120 ), 'types' => rk_builder_f_text( 0, 1200 ), 'amount' => rk_builder_f_int( 1, 100000 ), 'resultLabel' => rk_builder_f_text( 0, 60 ), 'note' => rk_builder_f_text( 0, 400 ), 'ctaLabel' => rk_builder_f_text( 0, 60 ), 'ctaHref' => rk_builder_f_link() ),
		'visualizer' => array( 'cities' => rk_builder_f_text( 0, 800 ), 'submitLabel' => rk_builder_f_text( 1, 80 ), 'ctaLabel' => rk_builder_f_text( 0, 80 ), 'ctaHref' => rk_builder_f_link() ),
		'reviews'    => array( 'eyebrow' => rk_builder_f_text( 0, 80 ), 'heading' => rk_builder_f_text( 0, 200 ), 'intro' => rk_builder_f_text( 0, 300 ), 'limit' => rk_builder_f_int( 1, 12 ), 'minRating' => rk_builder_f_int( 1, 5 ), 'cols' => rk_builder_f_int( 2, 3 ), 'showSummary' => rk_builder_f_bool(), 'showLinks' => rk_builder_f_bool(), 'tone' => rk_builder_f_enum( array( 'light', 'muted' ) ) ),
		'dynfield'   => array(
			'source'   => rk_builder_f_pattern( rk_builder_dyn_source_re() ),
			'tag'      => rk_builder_f_enum( array( 'h1', 'h2', 'h3', 'h4', 'p', 'div', 'span' ) ),
			'style'    => rk_builder_f_enum( array( 'plain', 'eyebrow', 'lead', 'badge' ) ),
			'align'    => rk_builder_f_enum( array( 'left', 'center', 'right' ) ),
			'label'    => rk_builder_f_text( 0, 60 ),
			'prefix'   => rk_builder_f_text( 0, 30 ),
			'suffix'   => rk_builder_f_text( 0, 30 ),
			'link'     => rk_builder_f_bool(),
			'fallback' => rk_builder_f_text( 0, 120 ),
		),
		'dynimage'   => array(
			'source'   => rk_builder_f_pattern( rk_builder_dyn_image_source_re() ),
			'ratio'    => rk_builder_f_enum( array( 'landscape', 'wide', 'square', 'portrait', 'auto' ) ),
			'link'     => rk_builder_f_bool(),
			'fallback' => rk_builder_f_enum( array( 'hide', 'placeholder' ) ),
		),
		'dyngallery' => array(
			'source' => rk_builder_f_pattern( rk_builder_dyn_field_source_re() ),
			'cols'   => rk_builder_f_int( 1, 6 ),
			'ratio'  => rk_builder_f_enum( array( 'landscape', 'wide', 'square', 'portrait', 'auto' ) ),
			'gap'    => rk_builder_f_enum( array( 'sm', 'md', 'lg' ) ),
			'limit'  => rk_builder_f_int( 0, 60 ),
		),
		'dynrepeater' => array(
			'source'  => rk_builder_f_pattern( rk_builder_dyn_field_source_re() ),
			'layout'  => rk_builder_f_enum( array( 'list', 'table', 'cards' ) ),
			'cols'    => rk_builder_f_int( 1, 4 ),
			'heading' => rk_builder_f_text( 0, 120 ),
		),
		'dyninfo'    => array(
			'heading' => rk_builder_f_text( 0, 120 ),
			'sources' => rk_builder_f_text( 0, 600 ),
			'labels'  => rk_builder_f_bool(),
			'layout'  => rk_builder_f_enum( array( 'rows', 'grid' ) ),
		),
		'loopgrid'   => array(
			'eyebrow'     => rk_builder_f_text( 0, 80 ),
			'heading'     => rk_builder_f_text( 0, 200 ),
			'intro'       => rk_builder_f_text( 0, 300 ),
			'postType'    => rk_builder_f_pattern( rk_builder_dyn_type_re() ),
			'taxonomy'    => rk_builder_f_pattern( '/^[a-z0-9_-]{0,32}\z/' ),
			'term'        => rk_builder_f_slug(),
			'limit'       => rk_builder_f_int( 1, 48 ),
			'orderBy'     => rk_builder_f_enum( array( 'date', 'title', 'menu_order', 'modified', 'rand' ) ),
			'order'       => rk_builder_f_enum( array( 'asc', 'desc' ) ),
			'cols'        => rk_builder_f_int( 1, 4 ),
			'mobileCols'  => rk_builder_f_int( 1, 2 ),
			'gap'         => rk_builder_f_enum( array( 'sm', 'md', 'lg' ) ),
			'templateId'  => rk_builder_f_int( 0, 2147483647 ),
			'equalHeight' => rk_builder_f_bool(),
			'filters'     => rk_builder_f_bool(),
			'search'      => rk_builder_f_bool(),
			'pagination'  => rk_builder_f_bool(),
			'related'     => rk_builder_f_bool(),
			'emptyText'   => rk_builder_f_text( 0, 160 ),
			'tone'        => rk_builder_f_enum( array( 'light', 'muted' ) ),
		),
		'brandstrip' => array( 'label' => rk_builder_f_text( 0, 120 ), 'items' => rk_builder_f_text( 0, 600 ) ),
		'gallery'   => array(
			'items'    => rk_builder_f_text( 0, 45000 ),
			'filters'  => rk_builder_f_bool( true ),
			'columns'  => rk_builder_f_int( 2, 4, true ),
			'shape'    => rk_builder_f_enum( array( 'rows', 'square', 'landscape', 'portrait', 'wide' ), true ),
			'gap'      => rk_builder_f_enum( array( 'sm', 'md', 'lg' ), true ),
			'featured' => rk_builder_f_bool( true ),
			'lightbox' => rk_builder_f_bool( true ),
			'captions' => rk_builder_f_enum( array( 'overlay', 'below', 'hover', 'none' ), true ),
			'source'   => rk_builder_f_enum( array( 'manual', 'media', 'portfolio', 'service' ), true ),
			'limit'    => rk_builder_f_int( 1, 40, true ),
			'filter'   => rk_builder_f_text( 0, 80, true ),
			'pageSize' => rk_builder_f_int( 0, 100, true ),
		),
		'sitefooter' => array(
			'brand'        => rk_builder_f_text( 1, 80 ),
			'logoMediaId'  => rk_builder_f_int( 0, 2147483647, true ),
			'logoUrl'      => rk_builder_f_image_opt(),
			'tagline'      => rk_builder_f_text( 0, 300 ),
			'colATitle'    => rk_builder_f_text( 0, 60 ),
			'colALinks'    => rk_builder_f_text( 0, 1500 ),
			'colBTitle'    => rk_builder_f_text( 0, 60 ),
			'colBLinks'    => rk_builder_f_text( 0, 1500 ),
			'contactTitle' => rk_builder_f_text( 0, 60 ),
			'phone'        => rk_builder_f_text( 0, 40 ),
			'email'        => rk_builder_f_text( 0, 120 ),
			'address'      => rk_builder_f_text( 0, 300 ),
			'copyright'    => rk_builder_f_text( 0, 200 ),
			'note'         => rk_builder_f_text( 0, 300 ),
			'tone'         => rk_builder_f_enum( array( 'dark', 'light', 'primary' ), true ),
			'social'       => rk_builder_f_text( 0, 800, true ),
			'legal'        => rk_builder_f_text( 0, 800, true ),
		),
		'testimonial' => array(
			'quote'  => rk_builder_f_text( 1, 600 ),
			'author' => rk_builder_f_text( 1, 80 ),
			'role'   => rk_builder_f_text( 0, 120 ),
		),
		'login'     => array(
			'heading'  => rk_builder_f_text( 1, 160 ),
			'intro'    => rk_builder_f_text( 0, 400 ),
			'button'   => rk_builder_f_text( 1, 40 ),
			'remember' => rk_builder_f_bool( true ),
			'forgot'   => rk_builder_f_bool( true ),
			'layout'   => rk_builder_f_enum( array( 'card', 'split' ), true ),
			'side'     => rk_builder_f_enum( array( 'left', 'right' ), true ),
			'imageMediaId' => rk_builder_f_int( 0, 2147483647, true ),
			'imageUrl'     => rk_builder_f_image_opt(),
			'imageAlt'     => rk_builder_f_text( 0, 300, true ),
			'showBrand'    => rk_builder_f_bool( true ),
			'showDetails'  => rk_builder_f_bool( true ),
		),
		'contact'   => array(
			'heading' => rk_builder_f_text( 1, 160 ),
			'intro'   => rk_builder_f_text( 0, 400 ),
			'phone'   => rk_builder_f_text( 0, 40 ),
			'email'   => rk_builder_f_text( 0, 120 ),
			'address' => rk_builder_f_text( 0, 200 ),
			'hours'   => rk_builder_f_text( 0, 200 ),
		),
	);
	return $specs;
}

/** Validate one field value; returns an error message or null. */
function rk_builder_check_field( $spec, $v, $image_hosts ) {
	switch ( $spec['t'] ) {
		case 'text':
			return rk_builder_check_string( $v, $spec['min'], $spec['max'] );
		case 'link':
			if ( ! is_string( $v ) ) { return 'Expected string'; }
			return rk_builder_is_safe_link( $v ) ? null : 'Unsafe or malformed link URL';
		case 'image':
			return rk_builder_image_url_problem( $v, $image_hosts );
		case 'int':
			return rk_builder_check_int( $v, $spec['min'], $spec['max'] );
		case 'bool':
			return is_bool( $v ) ? null : 'Expected boolean';
		case 'slug':
			if ( ! is_string( $v ) ) { return 'Expected string'; }
			return 1 === preg_match( '/^[a-z0-9-]{0,60}\z/', $v ) ? null : 'Invalid slug';
		case 'pattern':
			if ( ! is_string( $v ) ) { return 'Expected string'; }
			return 1 === preg_match( $spec['re'], $v ) ? null : 'Invalid value';
		case 'enum':
			foreach ( $spec['values'] as $allowed ) {
				if ( is_int( $allowed ) ) {
					if ( rk_builder_is_intlike( $v ) && (int) $v === $allowed ) { return null; }
				} elseif ( $v === $allowed ) {
					return null;
				}
			}
			return 'Invalid value';
	}
	return 'Invalid field';
}

/** Validate an object against a flat field table (strict: unknown keys are issues). */
function rk_builder_validate_fields( $value, array $fields, $path, array &$issues, $image_hosts ) {
	if ( ! rk_builder_is_object( $value ) ) {
		rk_builder_add_issue( $issues, $path, 'Expected object' );
		return;
	}
	foreach ( $value as $k => $_ ) {
		if ( ! isset( $fields[ $k ] ) ) {
			rk_builder_add_issue( $issues, rk_builder_join_path( $path, $k ), 'Unrecognized key "' . $k . '"' );
		}
	}
	foreach ( $fields as $name => $spec ) {
		$p = rk_builder_join_path( $path, $name );
		if ( ! array_key_exists( $name, $value ) ) {
			if ( empty( $spec['opt'] ) ) { rk_builder_add_issue( $issues, $p, 'Required' ); }
			continue;
		}
		$err = rk_builder_check_field( $spec, $value[ $name ], $image_hosts );
		if ( null !== $err ) { rk_builder_add_issue( $issues, $p, $err ); }
	}
}

function rk_builder_trim_ws( $s ) {
	$r = preg_replace( '/^[' . rk_builder_ws_class() . ']+|[' . rk_builder_ws_class() . ']+\z/u', '', $s );
	return null === $r ? $s : $r;
}

/* ------------------------------------------------------------------ *
 * Layout
 * ------------------------------------------------------------------ */

/**
 * Validate a layout document `{version:1, blocks:[{id,type,props}]}`.
 *
 * @param mixed      $doc          Decoded JSON (assoc arrays).
 * @param array|null $image_hosts  Allowed absolute image hosts; null = unrestricted.
 * @return array[] Issues; empty means valid.
 */
function rk_builder_validate_layout( $doc, $image_hosts = array() ) {
	$issues = array();
	if ( ! rk_builder_is_object( $doc ) ) {
		rk_builder_add_issue( $issues, '', 'Expected object' );
		return $issues;
	}
	foreach ( $doc as $k => $_ ) {
		if ( 'version' !== $k && 'blocks' !== $k ) {
			rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' );
		}
	}
	if ( ! array_key_exists( 'version', $doc ) ) {
		rk_builder_add_issue( $issues, 'version', 'Required' );
	} elseif ( ! rk_builder_is_intlike( $doc['version'] ) || (int) $doc['version'] !== RK_BUILDER_SCHEMA_VERSION ) {
		rk_builder_add_issue( $issues, 'version', 'Invalid literal value, expected ' . RK_BUILDER_SCHEMA_VERSION );
	}
	if ( ! array_key_exists( 'blocks', $doc ) ) {
		rk_builder_add_issue( $issues, 'blocks', 'Required' );
		return $issues;
	}
	if ( ! rk_builder_is_list( $doc['blocks'] ) ) {
		rk_builder_add_issue( $issues, 'blocks', 'Expected array' );
		return $issues;
	}
	$blocks = $doc['blocks'];
	if ( count( $blocks ) > RK_BUILDER_MAX_BLOCKS ) {
		rk_builder_add_issue( $issues, 'blocks', 'Array must contain at most ' . RK_BUILDER_MAX_BLOCKS . ' element(s)' );
		return $issues; // do not spend time on an oversized document
	}
	$specs = rk_builder_block_specs();
	$seen  = array();
	foreach ( $blocks as $i => $block ) {
		$bp = 'blocks.' . $i;
		if ( ! rk_builder_is_object( $block ) ) {
			rk_builder_add_issue( $issues, $bp, 'Expected object' );
			continue;
		}
		foreach ( $block as $k => $_ ) {
			if ( 'id' !== $k && 'type' !== $k && 'props' !== $k ) {
				rk_builder_add_issue( $issues, $bp . '.' . $k, 'Unrecognized key "' . $k . '"' );
			}
		}
		$id = null;
		if ( ! array_key_exists( 'id', $block ) ) {
			rk_builder_add_issue( $issues, $bp . '.id', 'Required' );
		} elseif ( ! is_string( $block['id'] ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}\z/', $block['id'] ) ) {
			rk_builder_add_issue( $issues, $bp . '.id', 'Invalid block id' );
		} else {
			$id = $block['id'];
			if ( isset( $seen[ $id ] ) ) {
				rk_builder_add_issue( $issues, $bp . '.id', 'Duplicate block id "' . $id . '"' );
			}
			$seen[ $id ] = true;
		}
		$type = null;
		if ( ! array_key_exists( 'type', $block ) ) {
			rk_builder_add_issue( $issues, $bp . '.type', 'Required' );
		} elseif ( ! is_string( $block['type'] ) || ! isset( $specs[ $block['type'] ] ) ) {
			rk_builder_add_issue( $issues, $bp . '.type', 'Unknown block type' );
		} else {
			$type = $block['type'];
		}
		if ( ! array_key_exists( 'props', $block ) ) {
			rk_builder_add_issue( $issues, $bp . '.props', 'Required' );
			continue;
		}
		if ( null === $type ) { continue; } // props of an unknown/missing type cannot be checked
		$before = count( $issues );
		rk_builder_validate_fields( $block['props'], $specs[ $type ], $bp . '.props', $issues, $image_hosts );
		if ( 'image' === $type && count( $issues ) === $before ) {
			$p = $block['props'];
			if ( ! $p['decorative'] && '' === rk_builder_trim_ws( $p['alt'] ) ) {
				rk_builder_add_issue( $issues, $bp . '.props.alt', 'Alt text is required unless the image is decorative' );
			}
		}
	}
	return $issues;
}

/** Normalise a VALID layout: fixed key order, integral floats become ints. */
function rk_builder_canonicalize_layout( array $layout ) {
	$specs  = rk_builder_block_specs();
	$blocks = array();
	foreach ( $layout['blocks'] as $block ) {
		$spec  = $specs[ $block['type'] ];
		$props = array();
		foreach ( $spec as $name => $field ) {
			if ( ! array_key_exists( $name, $block['props'] ) ) { continue; }
			$v = $block['props'][ $name ];
			if ( ( 'int' === $field['t'] || 'enum' === $field['t'] ) && rk_builder_is_intlike( $v ) ) { $v = (int) $v; }
			$props[ $name ] = $v;
		}
		$blocks[] = array( 'id' => $block['id'], 'type' => $block['type'], 'props' => $props );
	}
	return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => $blocks );
}

function rk_builder_empty_layout() {
	return array( 'version' => RK_BUILDER_SCHEMA_VERSION, 'blocks' => array() );
}

/* ------------------------------------------------------------------ *
 * Theme
 * ------------------------------------------------------------------ */

function rk_builder_theme_fonts() {
	return array( 'Space Grotesk', 'IBM Plex Mono', 'Georgia', 'System Sans', 'Humanist Sans', 'Classic Serif', 'Rounded Sans' );
}

/** Optional design-system choices on a theme (mirrors client/src/lib/schema/theme.ts). */
function rk_builder_theme_design_enums() {
	return array(
		'radius'      => array( 'square', 'soft', 'round' ),
		'buttonStyle' => array( 'solid', 'outline' ),
	);
}
function rk_builder_theme_heading_weights() { return array( 400, 500, 600, 700, 800 ); }

function rk_builder_default_theme() {
	return array(
		'version' => RK_BUILDER_SCHEMA_VERSION,
		'primary' => '#C7F36B',
		'bg'      => '#F8F5ED',
		'ink'     => '#1B2430',
		'font'    => 'Space Grotesk',
	);
}

function rk_builder_check_hex( $v ) {
	if ( ! is_string( $v ) ) { return 'Expected string'; }
	return 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', $v ) ? null : 'Expected a #RRGGBB color';
}

/** Validate a theme document (strict). Mirrors client/src/lib/schema/theme.ts. */
function rk_builder_validate_theme( $doc, $image_hosts = array() ) {
	$issues = array();
	if ( ! rk_builder_is_object( $doc ) ) {
		rk_builder_add_issue( $issues, '', 'Expected object' );
		return $issues;
	}
	$known = array( 'version', 'primary', 'bg', 'ink', 'font', 'logoMediaId', 'logoUrl', 'social', 'header', 'footer', 'accent', 'dark', 'surface', 'bodyFont', 'headingFont', 'headingWeight', 'radius', 'buttonStyle' );
	foreach ( $doc as $k => $_ ) {
		if ( ! in_array( (string) $k, $known, true ) ) {
			rk_builder_add_issue( $issues, (string) $k, 'Unrecognized key "' . $k . '"' );
		}
	}
	if ( ! array_key_exists( 'version', $doc ) ) {
		rk_builder_add_issue( $issues, 'version', 'Required' );
	} elseif ( ! rk_builder_is_intlike( $doc['version'] ) || (int) $doc['version'] !== RK_BUILDER_SCHEMA_VERSION ) {
		rk_builder_add_issue( $issues, 'version', 'Invalid literal value, expected ' . RK_BUILDER_SCHEMA_VERSION );
	}
	foreach ( array( 'primary', 'bg', 'ink' ) as $c ) {
		if ( ! array_key_exists( $c, $doc ) ) {
			rk_builder_add_issue( $issues, $c, 'Required' );
		} elseif ( null !== ( $e = rk_builder_check_hex( $doc[ $c ] ) ) ) {
			rk_builder_add_issue( $issues, $c, $e );
		}
	}
	if ( ! array_key_exists( 'font', $doc ) ) {
		rk_builder_add_issue( $issues, 'font', 'Required' );
	} elseif ( ! is_string( $doc['font'] ) || ! in_array( $doc['font'], rk_builder_theme_fonts(), true ) ) {
		rk_builder_add_issue( $issues, 'font', 'Invalid enum value' );
	}
	foreach ( array( 'accent', 'dark', 'surface' ) as $c ) {
		if ( array_key_exists( $c, $doc ) && null !== ( $e = rk_builder_check_hex( $doc[ $c ] ) ) ) { rk_builder_add_issue( $issues, $c, $e ); }
	}
	foreach ( array( 'bodyFont', 'headingFont' ) as $f ) {
		if ( array_key_exists( $f, $doc ) && ( ! is_string( $doc[ $f ] ) || ! in_array( $doc[ $f ], rk_builder_theme_fonts(), true ) ) ) { rk_builder_add_issue( $issues, $f, 'Invalid enum value' ); }
	}
	if ( array_key_exists( 'headingWeight', $doc ) && ( ! rk_builder_is_intlike( $doc['headingWeight'] ) || ! in_array( (int) $doc['headingWeight'], rk_builder_theme_heading_weights(), true ) ) ) {
		rk_builder_add_issue( $issues, 'headingWeight', 'Invalid enum value' );
	}
	foreach ( rk_builder_theme_design_enums() as $k => $allowed ) {
		if ( array_key_exists( $k, $doc ) && ( ! is_string( $doc[ $k ] ) || ! in_array( $doc[ $k ], $allowed, true ) ) ) { rk_builder_add_issue( $issues, $k, 'Invalid enum value' ); }
	}
	if ( array_key_exists( 'logoMediaId', $doc ) && null !== ( $e = rk_builder_check_int( $doc['logoMediaId'], 0, 2147483647 ) ) ) {
		rk_builder_add_issue( $issues, 'logoMediaId', $e );
	}
	if ( array_key_exists( 'logoUrl', $doc ) && null !== ( $e = rk_builder_image_url_problem( $doc['logoUrl'], $image_hosts ) ) ) {
		rk_builder_add_issue( $issues, 'logoUrl', $e );
	}
	if ( array_key_exists( 'social', $doc ) ) {
		$s = $doc['social'];
		if ( ! rk_builder_is_object( $s ) ) {
			rk_builder_add_issue( $issues, 'social', 'Expected object' );
		} else {
			foreach ( $s as $k => $v ) {
				if ( 'instagram' !== $k && 'linkedin' !== $k ) {
					rk_builder_add_issue( $issues, 'social.' . $k, 'Unrecognized key "' . $k . '"' );
				} elseif ( null !== ( $e = rk_builder_check_social_url( $v ) ) ) {
					rk_builder_add_issue( $issues, 'social.' . $k, $e );
				}
			}
		}
	}
	if ( array_key_exists( 'header', $doc ) ) {
		rk_builder_validate_fields( $doc['header'], array( 'sticky' => rk_builder_f_bool( true ) ), 'header', $issues, $image_hosts );
	}
	if ( array_key_exists( 'footer', $doc ) ) {
		rk_builder_validate_fields( $doc['footer'], array( 'columns' => rk_builder_f_int( 1, 4, true ) ), 'footer', $issues, $image_hosts );
	}
	return $issues;
}

/** Normalise a VALID theme: fixed key order, ints. Empty sub-objects stay empty arrays here. */
function rk_builder_canonicalize_theme( array $theme ) {
	$out = array(
		'version' => RK_BUILDER_SCHEMA_VERSION,
		'primary' => $theme['primary'],
		'bg'      => $theme['bg'],
		'ink'     => $theme['ink'],
		'font'    => $theme['font'],
	);
	foreach ( array( 'accent', 'dark', 'surface', 'bodyFont', 'headingFont', 'radius', 'buttonStyle' ) as $k ) {
		if ( array_key_exists( $k, $theme ) ) { $out[ $k ] = $theme[ $k ]; }
	}
	if ( array_key_exists( 'headingWeight', $theme ) ) { $out['headingWeight'] = (int) $theme['headingWeight']; }
	if ( array_key_exists( 'logoMediaId', $theme ) ) { $out['logoMediaId'] = (int) $theme['logoMediaId']; }
	if ( array_key_exists( 'logoUrl', $theme ) ) { $out['logoUrl'] = $theme['logoUrl']; }
	if ( array_key_exists( 'social', $theme ) ) {
		$out['social'] = array();
		foreach ( array( 'instagram', 'linkedin' ) as $k ) {
			if ( array_key_exists( $k, $theme['social'] ) ) { $out['social'][ $k ] = $theme['social'][ $k ]; }
		}
	}
	if ( array_key_exists( 'header', $theme ) ) {
		$out['header'] = array();
		if ( array_key_exists( 'sticky', $theme['header'] ) ) { $out['header']['sticky'] = (bool) $theme['header']['sticky']; }
	}
	if ( array_key_exists( 'footer', $theme ) ) {
		$out['footer'] = array();
		if ( array_key_exists( 'columns', $theme['footer'] ) ) { $out['footer']['columns'] = (int) $theme['footer']['columns']; }
	}
	return $out;
}

/** Make empty sub-objects serialise as {} rather than []. Use right before returning JSON. */
function rk_builder_theme_for_output( array $theme ) {
	foreach ( array( 'social', 'header', 'footer' ) as $k ) {
		if ( isset( $theme[ $k ] ) && array() === $theme[ $k ] ) { $theme[ $k ] = new stdClass(); }
	}
	return $theme;
}

/**
 * Convert whatever the old/prototype stored (flat `logo`, font "Inter", free-form social map,
 * missing version) into a valid v1 theme. Tolerant on purpose: bad fields fall back to the
 * defaults. Always returns a valid theme.
 *
 * @param mixed $stored
 */
function rk_builder_migrate_theme( $stored ) {
	if ( ! is_array( $stored ) ) { return rk_builder_default_theme(); }
	if ( isset( $stored['version'] ) && 1 === $stored['version'] && array() === rk_builder_validate_theme( $stored, null ) ) {
		return rk_builder_canonicalize_theme( $stored );
	}
	$theme = rk_builder_default_theme();
	foreach ( array( 'primary', 'bg', 'ink' ) as $c ) {
		if ( isset( $stored[ $c ] ) && null === rk_builder_check_hex( $stored[ $c ] ) ) { $theme[ $c ] = $stored[ $c ]; }
	}
	if ( isset( $stored['font'] ) && is_string( $stored['font'] ) && in_array( $stored['font'], rk_builder_theme_fonts(), true ) ) {
		$theme['font'] = $stored['font'];
	}
	$logo = isset( $stored['logoUrl'] ) ? $stored['logoUrl'] : ( isset( $stored['logo'] ) ? $stored['logo'] : '' );
	if ( is_string( $logo ) && '' !== $logo && rk_builder_is_safe_image_url( $logo, null ) ) { $theme['logoUrl'] = $logo; }
	if ( isset( $stored['logoMediaId'] ) && null === rk_builder_check_int( $stored['logoMediaId'], 0, 2147483647 ) ) {
		$theme['logoMediaId'] = (int) $stored['logoMediaId'];
	}
	if ( isset( $stored['social'] ) && is_array( $stored['social'] ) ) {
		$social = array();
		foreach ( array( 'instagram', 'linkedin' ) as $k ) {
			if ( isset( $stored['social'][ $k ] ) && null === rk_builder_check_social_url( $stored['social'][ $k ] ) ) { $social[ $k ] = $stored['social'][ $k ]; }
		}
		if ( $social ) { $theme['social'] = $social; }
	}
	if ( isset( $stored['header'] ) && is_array( $stored['header'] ) && isset( $stored['header']['sticky'] ) ) {
		$theme['header'] = array( 'sticky' => (bool) $stored['header']['sticky'] );
	}
	if ( isset( $stored['footer'] ) && is_array( $stored['footer'] ) && isset( $stored['footer']['columns'] ) && null === rk_builder_check_int( $stored['footer']['columns'], 1, 4 ) ) {
		$theme['footer'] = array( 'columns' => (int) $stored['footer']['columns'] );
	}
	return array() === rk_builder_validate_theme( $theme, null ) ? $theme : rk_builder_default_theme();
}

/** Deep, key-order-insensitive but type-strict comparison of two JSON-like values. */
function rk_builder_deep_equal( $a, $b ) {
	if ( $a instanceof stdClass ) { $a = (array) $a; }
	if ( $b instanceof stdClass ) { $b = (array) $b; }
	if ( is_array( $a ) && is_array( $b ) ) {
		if ( count( $a ) !== count( $b ) ) { return false; }
		foreach ( $a as $k => $v ) {
			if ( ! array_key_exists( $k, $b ) || ! rk_builder_deep_equal( $v, $b[ $k ] ) ) { return false; }
		}
		return true;
	}
	if ( is_float( $a ) && is_int( $b ) ) { return $a === (float) $b; }
	if ( is_int( $a ) && is_float( $b ) ) { return (float) $a === $b; }
	return $a === $b;
}
