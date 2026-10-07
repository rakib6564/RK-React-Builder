<?php
/**
 * Site integrations managed from the builder dashboard:
 *
 *   Business profile   Google Business Profile link, social profiles, address, opening hours, service areas
 *                      (feeds the Organization / LocalBusiness schema; see seo.php)
 *   Custom code        Search Console + Bing verification, Google Analytics 4, Tag Manager and free-form
 *                      head / body / footer snippets
 *   Reviews            Google reviews pulled with the Places API (key + Place ID) and/or added by hand,
 *                      shown by the "Reviews" block
 *   Redirects          301 / 302 rules
 *
 *   GET|POST /builder/code                    GET|POST /builder/reviews-admin
 *   GET|POST /builder/redirects               POST     /builder/reviews-admin/items
 *   GET      /reviews (public)                POST     /builder/reviews-admin/sync
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ------------------------------------------------------------------ *
 * Small helpers
 * ------------------------------------------------------------------ */

/** Absolute https:// (or http://) address, '' otherwise. */
function rk_builder_int_url( $v ) {
	$v = is_string( $v ) ? trim( $v ) : '';
	if ( '' === $v || 1 !== preg_match( '#^https?://[^\s<>"\']+$#i', $v ) ) { return ''; }
	return rk_builder_substr( $v, 0, 500 );
}

function rk_builder_int_http( $method, $url, array $args = array() ) {
	$pre = apply_filters( 'rk_builder_http', null, $method, $url, $args );
	if ( null !== $pre ) { return $pre; }
	$args['method'] = $method;
	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) { return $r; }
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ) );
}

/* ------------------------------------------------------------------ *
 * Business profile: parsed opening hours
 * ------------------------------------------------------------------ */

/**
 * "Mon-Fri 08:00-17:00" lines (or ";"-separated) -> schema.org OpeningHoursSpecification list.
 * Days: Mon Tue Wed Thu Fri Sat Sun (or full names), ranges with "-", lists with ",". Unreadable lines are skipped.
 */
function rk_builder_parse_hours( $text ) {
	$week = array( 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday' );
	$keys = array_keys( $week );
	$out  = array();
	foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || ! preg_match( '/^([A-Za-z,\s-]+?)\s+(\d{1,2}:\d{2})\s*(?:-|–|to)\s*(\d{1,2}:\d{2})$/u', $line, $m ) ) { continue; }
		$days = array();
		foreach ( explode( ',', $m[1] ) as $part ) {
			$bounds = array_map( function ( $d ) { return strtolower( substr( trim( $d ), 0, 3 ) ); }, preg_split( '/\s*-\s*/', trim( $part ) ) );
			if ( ! isset( $week[ $bounds[0] ] ) ) { continue 2; }
			if ( count( $bounds ) > 1 && isset( $week[ $bounds[1] ] ) ) {
				$i = array_search( $bounds[0], $keys, true );
				$j = array_search( $bounds[1], $keys, true );
				for ( $k = $i; $k <= $j; $k++ ) { $days[ $keys[ $k ] ] = $week[ $keys[ $k ] ]; }
			} else {
				$days[ $bounds[0] ] = $week[ $bounds[0] ];
			}
		}
		$pad = function ( $t ) { list( $h, $mi ) = explode( ':', $t ); return str_pad( $h, 2, '0', STR_PAD_LEFT ) . ':' . $mi; };
		if ( $days ) { $out[] = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_values( $days ), 'opens' => $pad( $m[2] ), 'closes' => $pad( $m[3] ) ); }
	}
	return $out;
}

/** Business types the LocalBusiness schema node may use. */
function rk_builder_business_types() {
	return array( 'LocalBusiness', 'HomeAndConstructionBusiness', 'GeneralContractor', 'Electrician', 'Plumber', 'HVACBusiness', 'RoofingContractor', 'HousePainter', 'ProfessionalService', 'Store', 'Restaurant', 'AutomotiveBusiness', 'HealthAndBeautyBusiness', 'LegalService', 'RealEstateAgent' );
}

/** The social / listing profiles that become schema.org sameAs links. */
function rk_builder_profile_keys() {
	return array( 'googleBusiness', 'facebook', 'instagram', 'linkedin', 'youtube', 'x', 'tiktok', 'yelp', 'pinterest' );
}

/* ------------------------------------------------------------------ *
 * Custom code
 * ------------------------------------------------------------------ */

function rk_builder_code_defaults() {
	return array( 'gsc' => '', 'bing' => '', 'ga4' => '', 'gtm' => '', 'skipLoggedIn' => true, 'head' => '', 'bodyStart' => '', 'footer' => '' );
}

function rk_builder_code_settings() {
	$o = get_option( 'rk_builder_code', array() );
	return array_merge( rk_builder_code_defaults(), is_array( $o ) ? $o : array() );
}

/** A Search Console / Bing token: the bare value, or the whole <meta> tag pasted from the site (the content is extracted). */
function rk_builder_code_token( $v ) {
	$v = is_string( $v ) ? trim( $v ) : '';
	if ( '' === $v ) { return ''; }
	if ( preg_match( '/content\s*=\s*["\']([A-Za-z0-9_\-]{10,120})["\']/i', $v, $m ) ) { return $m[1]; }
	return 1 === preg_match( '/^[A-Za-z0-9_\-]{10,120}\z/', $v ) ? $v : '';
}

function rk_builder_code_sanitize( $in, $may_raw ) {
	$old = rk_builder_code_settings();
	$in  = is_array( $in ) ? $in : array();
	$out = $old;
	foreach ( array( 'gsc', 'bing' ) as $k ) { if ( array_key_exists( $k, $in ) ) { $out[ $k ] = rk_builder_code_token( $in[ $k ] ); } }
	if ( array_key_exists( 'ga4', $in ) ) { $v = strtoupper( trim( (string) $in['ga4'] ) ); $out['ga4'] = 1 === preg_match( '/^(G|AW)-[A-Z0-9]{4,20}\z/', $v ) ? $v : ''; }
	if ( array_key_exists( 'gtm', $in ) ) { $v = strtoupper( trim( (string) $in['gtm'] ) ); $out['gtm'] = 1 === preg_match( '/^GTM-[A-Z0-9]{4,12}\z/', $v ) ? $v : ''; }
	if ( array_key_exists( 'skipLoggedIn', $in ) ) { $out['skipLoggedIn'] = ! empty( $in['skipLoggedIn'] ); }
	foreach ( array( 'head', 'bodyStart', 'footer' ) as $k ) {
		if ( ! array_key_exists( $k, $in ) ) { continue; }
		$v = is_string( $in[ $k ] ) ? (string) $in[ $k ] : '';
		if ( $v !== $old[ $k ] && ! $may_raw ) { continue; } // only people who may post raw HTML change the snippets
		$out[ $k ] = rk_builder_substr( $v, 0, 20000 );
	}
	return $out;
}

/** Which fields the user may not change (so the UI can disable them). */
function rk_builder_code_can_raw() {
	return current_user_can( 'unfiltered_html' );
}

function rk_builder_code_payload() {
	return array( 'code' => rk_builder_code_settings(), 'canEditCode' => rk_builder_code_can_raw() );
}

function rk_builder_handle_get_code( $req ) { return rk_builder_no_store( rk_builder_code_payload() ); }

function rk_builder_handle_set_code( $req ) {
	$body = rk_builder_dash_body( $req, array( 'gsc', 'bing', 'ga4', 'gtm', 'skipLoggedIn', 'head', 'bodyStart', 'footer' ), 'rk_invalid_code' );
	if ( is_wp_error( $body ) ) { return $body; }
	$may = rk_builder_code_can_raw();
	$old = rk_builder_code_settings();
	foreach ( array( 'head', 'bodyStart', 'footer' ) as $k ) {
		if ( isset( $body[ $k ] ) && is_string( $body[ $k ] ) && $body[ $k ] !== $old[ $k ] && ! $may ) {
			return rk_builder_error( 'rk_forbidden', 'Your account is not allowed to add custom code (it needs the unfiltered_html capability).', 403 );
		}
	}
	foreach ( array( 'ga4' => 'a Google Analytics ID like G-ABC123XYZ4', 'gtm' => 'a Tag Manager ID like GTM-ABC1234' ) as $k => $what ) {
		if ( isset( $body[ $k ] ) && '' !== trim( (string) $body[ $k ] ) ) {
			$probe = rk_builder_code_sanitize( array( $k => $body[ $k ] ), false );
			if ( '' === $probe[ $k ] ) { return rk_builder_invalid( 'rk_invalid_code', array( array( 'path' => $k, 'message' => 'Enter ' . $what . '.' ) ) ); }
		}
	}
	foreach ( array( 'gsc', 'bing' ) as $k ) {
		if ( isset( $body[ $k ] ) && '' !== trim( (string) $body[ $k ] ) && '' === rk_builder_code_token( $body[ $k ] ) ) {
			return rk_builder_invalid( 'rk_invalid_code', array( array( 'path' => $k, 'message' => 'Paste the verification code, or the whole meta tag.' ) ) );
		}
	}
	update_option( 'rk_builder_code', rk_builder_code_sanitize( $body, $may ) );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( rk_builder_code_payload() );
}

/** True when this request should get tracking and custom code (front end only). */
function rk_builder_code_active() {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_embed' ) && is_embed() ) ) { return false; }
	return true;
}

function rk_builder_code_skip_tracking( array $c ) {
	return ! empty( $c['skipLoggedIn'] ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( 'edit_pages' );
}

function rk_builder_code_print_head() {
	if ( ! rk_builder_code_active() ) { return; }
	$c = rk_builder_code_settings();
	$o = '';
	if ( '' !== $c['gsc'] ) { $o .= '<meta name="google-site-verification" content="' . rk_builder_h( $c['gsc'] ) . '">' . "\n"; }
	if ( '' !== $c['bing'] ) { $o .= '<meta name="msvalidate.01" content="' . rk_builder_h( $c['bing'] ) . '">' . "\n"; }
	if ( ! rk_builder_code_skip_tracking( $c ) ) {
		if ( '' !== $c['gtm'] ) {
			$o .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . rk_builder_h( $c['gtm'] ) . "');</script>\n";
		}
		if ( '' !== $c['ga4'] ) {
			$id = rk_builder_h( $c['ga4'] );
			$o .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>' . "\n" . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $id . "');</script>\n";
		}
	}
	if ( '' !== trim( $c['head'] ) ) { $o .= $c['head'] . "\n"; }
	echo $o; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verification/ID fields are validated and escaped; the snippet is the site owner's own HTML (unfiltered_html)
}

function rk_builder_code_print_body() {
	if ( ! rk_builder_code_active() ) { return; }
	$c = rk_builder_code_settings();
	$o = '';
	if ( '' !== $c['gtm'] && ! rk_builder_code_skip_tracking( $c ) ) {
		$o .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . rk_builder_h( $c['gtm'] ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
	}
	if ( '' !== trim( $c['bodyStart'] ) ) { $o .= $c['bodyStart'] . "\n"; }
	echo $o; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see rk_builder_code_print_head()
}

function rk_builder_code_print_footer() {
	if ( ! rk_builder_code_active() ) { return; }
	$c = rk_builder_code_settings();
	if ( '' !== trim( $c['footer'] ) ) { echo $c['footer'] . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see rk_builder_code_print_head()
}

/* ------------------------------------------------------------------ *
 * Reviews
 * ------------------------------------------------------------------ */

function rk_builder_reviews_store() {
	$o = get_option( 'rk_builder_reviews', array() );
	return array_merge( array( 'profileUrl' => '', 'placeId' => '', 'auto' => false, 'summary' => array( 'rating' => 0, 'count' => 0 ), 'items' => array(), 'syncedAt' => '', 'lastError' => '', 'mapsUrl' => '' ), is_array( $o ) ? $o : array() );
}

function rk_builder_reviews_key() {
	if ( defined( 'RK_BUILDER_PLACES_KEY' ) && '' !== (string) RK_BUILDER_PLACES_KEY ) { return (string) RK_BUILDER_PLACES_KEY; }
	return (string) get_option( 'rk_builder_reviews_key', '' );
}

function rk_builder_review_clean( $r ) {
	if ( ! is_array( $r ) ) { return null; }
	$author = rk_builder_theme_text( isset( $r['author'] ) ? $r['author'] : '', 80 );
	$text   = rk_builder_theme_text( isset( $r['text'] ) ? $r['text'] : '', 1500 );
	$rating = isset( $r['rating'] ) && is_numeric( $r['rating'] ) ? (int) round( (float) $r['rating'] ) : 0;
	if ( '' === $author || $rating < 1 || $rating > 5 ) { return null; }
	$id = isset( $r['id'] ) && is_string( $r['id'] ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,80}\z/', $r['id'] ) ? $r['id'] : 'm' . substr( hash( 'sha256', $author . '|' . $text . '|' . $rating ), 0, 12 );
	$src = isset( $r['source'] ) && 'google' === $r['source'] ? 'google' : 'manual';
	return array(
		'id'      => $id,
		'author'  => $author,
		'rating'  => $rating,
		'text'    => $text,
		'date'    => rk_builder_theme_text( isset( $r['date'] ) ? $r['date'] : '', 40 ),
		'source'  => $src,
		'url'     => rk_builder_int_url( isset( $r['url'] ) ? $r['url'] : '' ),
		'photo'   => rk_builder_int_url( isset( $r['photo'] ) ? $r['photo'] : '' ),
		'hidden'  => ! empty( $r['hidden'] ),
	);
}

/** The write-a-review link for a Place ID (empty without one). */
function rk_builder_reviews_write_url( $place_id ) {
	return 1 === preg_match( '/^[A-Za-z0-9_-]{10,200}\z/', (string) $place_id ) ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id ) : '';
}

/** What the block and the public endpoint show: visible reviews, summary and links. */
function rk_builder_reviews_public( $limit = 6, $min = 1 ) {
	$limit = min( 50, max( 1, (int) $limit ) );
	$s     = rk_builder_reviews_store();
	$items = array();
	foreach ( $s['items'] as $r ) {
		if ( ! empty( $r['hidden'] ) || $r['rating'] < $min ) { continue; }
		$items[] = $r;
	}
	$ix = array_keys( $items );
	usort( $ix, function ( $a, $b ) use ( $items ) { return $items[ $b ]['rating'] <=> $items[ $a ]['rating'] ?: $a <=> $b; } ); // stable on PHP 7.4 too
	$items = array_slice( array_map( function ( $i ) use ( $items ) { return $items[ $i ]; }, $ix ), 0, max( 1, (int) $limit ) );
	return array(
		'summary' => array( 'rating' => (float) $s['summary']['rating'], 'count' => (int) $s['summary']['count'] ),
		'items'   => $items,
		'links'   => array(
			'profile' => '' !== $s['profileUrl'] ? $s['profileUrl'] : $s['mapsUrl'],
			'write'   => rk_builder_reviews_write_url( $s['placeId'] ),
		),
	);
}

function rk_builder_reviews_admin_payload() {
	$s = rk_builder_reviews_store();
	return array(
		'config' => array( 'profileUrl' => $s['profileUrl'], 'placeId' => $s['placeId'], 'keySet' => '' !== rk_builder_reviews_key(), 'keyFromServer' => defined( 'RK_BUILDER_PLACES_KEY' ), 'auto' => (bool) $s['auto'], 'syncedAt' => $s['syncedAt'], 'lastError' => $s['lastError'], 'writeUrl' => rk_builder_reviews_write_url( $s['placeId'] ) ),
		'summary' => $s['summary'],
		'items'   => $s['items'],
	);
}

function rk_builder_handle_get_reviews_admin( $req ) { return rk_builder_no_store( rk_builder_reviews_admin_payload() ); }

function rk_builder_handle_set_reviews_admin( $req ) {
	$body = rk_builder_dash_body( $req, array( 'profileUrl', 'placeId', 'apiKey', 'clearKey', 'auto', 'summary' ), 'rk_invalid_reviews' );
	if ( is_wp_error( $body ) ) { return $body; }
	$s = rk_builder_reviews_store();
	if ( array_key_exists( 'profileUrl', $body ) ) {
		$u = rk_builder_int_url( $body['profileUrl'] );
		if ( '' !== trim( (string) $body['profileUrl'] ) && '' === $u ) { return rk_builder_invalid( 'rk_invalid_reviews', array( array( 'path' => 'profileUrl', 'message' => 'Paste the full https:// link to your Google Business Profile.' ) ) ); }
		$s['profileUrl'] = $u;
		if ( '' === $u ) { $s['mapsUrl'] = ''; } // clearing the link clears the map link that came with it
	}
	if ( array_key_exists( 'placeId', $body ) ) {
		$p = trim( (string) $body['placeId'] );
		if ( '' !== $p && 1 !== preg_match( '/^[A-Za-z0-9_-]{10,200}\z/', $p ) ) { return rk_builder_invalid( 'rk_invalid_reviews', array( array( 'path' => 'placeId', 'message' => 'A Place ID looks like ChIJ… (letters, numbers, dashes).' ) ) ); }
		$s['placeId'] = $p;
	}
	if ( array_key_exists( 'auto', $body ) ) { $s['auto'] = ! empty( $body['auto'] ); }
	if ( isset( $body['summary'] ) && is_array( $body['summary'] ) && empty( $s['syncedAt'] ) ) {
		$rt = isset( $body['summary']['rating'] ) && is_numeric( $body['summary']['rating'] ) ? max( 0, min( 5, round( (float) $body['summary']['rating'], 1 ) ) ) : 0;
		$ct = isset( $body['summary']['count'] ) && is_numeric( $body['summary']['count'] ) ? max( 0, min( 1000000, (int) $body['summary']['count'] ) ) : 0;
		$s['summary'] = array( 'rating' => (float) $rt, 'count' => $ct );
	}
	$changed = ! empty( $body['clearKey'] ) || ( isset( $body['apiKey'] ) && '' !== trim( (string) $body['apiKey'] ) ) || ( array_key_exists( 'placeId', $body ) && $body['placeId'] !== rk_builder_reviews_store()['placeId'] );
	if ( $changed ) { $s['lastError'] = ''; } // the old error no longer applies once the key or Place ID changes
	update_option( 'rk_builder_reviews', $s, false );
	if ( ! empty( $body['clearKey'] ) ) { delete_option( 'rk_builder_reviews_key' ); }
	if ( isset( $body['apiKey'] ) && is_string( $body['apiKey'] ) && '' !== trim( $body['apiKey'] ) ) {
		$k = trim( $body['apiKey'] );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{20,120}\z/', $k ) ) { return rk_builder_invalid( 'rk_invalid_reviews', array( array( 'path' => 'apiKey', 'message' => 'That does not look like a Google API key.' ) ) ); }
		update_option( 'rk_builder_reviews_key', $k, false );
	}
	rk_builder_reviews_schedule();
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( rk_builder_reviews_admin_payload() );
}

/** Replace the list (manual reviews plus the show/hide state of synced ones). */
function rk_builder_handle_set_reviews_items( $req ) {
	$body = rk_builder_dash_body( $req, array( 'items' ), 'rk_invalid_reviews' );
	if ( is_wp_error( $body ) ) { return $body; }
	if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) || count( $body['items'] ) > 100 ) { return rk_builder_invalid( 'rk_invalid_reviews', array( array( 'path' => 'items', 'message' => 'Send up to 100 reviews.' ) ) ); }
	$items = array();
	$seen  = array();
	foreach ( $body['items'] as $i => $raw ) {
		$r = rk_builder_review_clean( $raw );
		if ( null === $r ) { return rk_builder_invalid( 'rk_invalid_reviews', array( array( 'path' => 'items.' . $i, 'message' => 'Each review needs a name and a rating from 1 to 5.' ) ) ); }
		if ( isset( $seen[ $r['id'] ] ) ) { continue; }
		$seen[ $r['id'] ] = true;
		$items[] = $r;
	}
	$s = rk_builder_reviews_store();
	$s['items'] = $items;
	update_option( 'rk_builder_reviews', $s, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( rk_builder_reviews_admin_payload() );
}

/** Pull rating, review count and up to five reviews from the Places API (New). @return true|WP_Error */
function rk_builder_reviews_sync() {
	$s   = rk_builder_reviews_store();
	$key = rk_builder_reviews_key();
	if ( '' === $s['placeId'] || '' === $key ) { return rk_builder_error( 'rk_conflict', 'Add your Place ID and a Google API key first.', 409 ); }
	$url = 'https://places.googleapis.com/v1/places/' . rawurlencode( $s['placeId'] );
	$res = rk_builder_int_http( 'GET', $url, array( 'timeout' => 20, 'headers' => array( 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'rating,userRatingCount,reviews,googleMapsUri,displayName' ) ) );
	$fail = function ( $msg ) use ( $s ) {
		$s['lastError'] = $msg;
		update_option( 'rk_builder_reviews', $s, false );
		return rk_builder_error( 'rk_upstream', $msg, 502 );
	};
	if ( is_wp_error( $res ) ) { return $fail( 'Could not reach Google: ' . $res->get_error_message() ); }
	$data = json_decode( $res['body'], true );
	if ( $res['code'] < 200 || $res['code'] >= 300 || ! is_array( $data ) ) {
		$why = is_array( $data ) && isset( $data['error']['message'] ) ? rk_builder_theme_text( $data['error']['message'], 200 ) : 'HTTP ' . $res['code'];
		return $fail( 'Google said: ' . $why );
	}
	$keep_hidden = array();
	foreach ( $s['items'] as $r ) { if ( 'google' === $r['source'] && ! empty( $r['hidden'] ) ) { $keep_hidden[ $r['id'] ] = true; } }
	$manual = array_values( array_filter( $s['items'], function ( $r ) { return 'google' !== $r['source']; } ) );
	$google = array();
	foreach ( isset( $data['reviews'] ) && is_array( $data['reviews'] ) ? $data['reviews'] : array() as $rv ) {
		$name = isset( $rv['name'] ) ? (string) $rv['name'] : '';
		$text = isset( $rv['text']['text'] ) ? $rv['text']['text'] : ( isset( $rv['originalText']['text'] ) ? $rv['originalText']['text'] : '' );
		$r = rk_builder_review_clean( array(
			'id'     => 'g' . substr( hash( 'sha256', $name ), 0, 16 ),
			'author' => isset( $rv['authorAttribution']['displayName'] ) ? $rv['authorAttribution']['displayName'] : '',
			'rating' => isset( $rv['rating'] ) ? $rv['rating'] : 0,
			'text'   => $text,
			'date'   => isset( $rv['relativePublishTimeDescription'] ) ? $rv['relativePublishTimeDescription'] : '',
			'source' => 'google',
			'url'    => isset( $rv['authorAttribution']['uri'] ) ? $rv['authorAttribution']['uri'] : '',
			'photo'  => isset( $rv['authorAttribution']['photoUri'] ) ? $rv['authorAttribution']['photoUri'] : '',
			'hidden' => isset( $keep_hidden[ 'g' . substr( hash( 'sha256', $name ), 0, 16 ) ] ),
		) );
		if ( null !== $r ) { $google[] = $r; }
	}
	$s['items']     = array_merge( $google, $manual );
	$s['summary']   = array( 'rating' => isset( $data['rating'] ) ? round( (float) $data['rating'], 1 ) : 0.0, 'count' => isset( $data['userRatingCount'] ) ? (int) $data['userRatingCount'] : 0 );
	$s['mapsUrl']   = isset( $data['googleMapsUri'] ) ? rk_builder_int_url( $data['googleMapsUri'] ) : '';
	$s['syncedAt']  = rk_builder_iso( rk_builder_now() );
	$s['lastError'] = '';
	update_option( 'rk_builder_reviews', $s, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return true;
}

function rk_builder_handle_sync_reviews( $req ) {
	$r = rk_builder_reviews_sync();
	return is_wp_error( $r ) ? $r : rk_builder_no_store( rk_builder_reviews_admin_payload() );
}

function rk_builder_reviews_schedule() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) { return; }
	$s  = rk_builder_reviews_store();
	$on = ! empty( $s['auto'] ) && '' !== $s['placeId'] && '' !== rk_builder_reviews_key();
	$at = wp_next_scheduled( 'rk_builder_reviews_cron' );
	if ( $on && ! $at ) { wp_schedule_event( time() + 3600, 'daily', 'rk_builder_reviews_cron' ); }
	if ( ! $on && $at && function_exists( 'wp_unschedule_event' ) ) { wp_unschedule_event( $at, 'rk_builder_reviews_cron' ); }
}

function rk_builder_handle_public_reviews( $req ) {
	$data = rk_builder_reviews_public( 50, 1 );
	$res  = rest_ensure_response( $data );
	$res->header( 'Cache-Control', 'public, max-age=0, s-maxage=300' );
	return $res;
}

/* ------------------------------------------------------------------ *
 * Redirects
 * ------------------------------------------------------------------ */

function rk_builder_redirects_list() {
	$l = get_option( 'rk_builder_redirects', array() );
	return is_array( $l ) ? $l : array();
}

/** A request path like "/old-page" (no query, no trailing slash except "/"). */
function rk_builder_redirect_path( $p ) {
	$p = is_string( $p ) ? trim( $p ) : '';
	$u = function_exists( 'wp_parse_url' ) ? wp_parse_url( $p ) : parse_url( $p );
	$path = is_array( $u ) && isset( $u['path'] ) ? $u['path'] : '';
	if ( '' === $path || '/' !== $path[0] ) { return ''; }
	$path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
	return strtolower( substr( $path, 0, 300 ) );
}

function rk_builder_redirect_clean( $r ) {
	if ( ! is_array( $r ) ) { return null; }
	$from = rk_builder_redirect_path( isset( $r['from'] ) ? $r['from'] : '' );
	$to   = isset( $r['to'] ) && is_string( $r['to'] ) ? trim( $r['to'] ) : '';
	if ( '' !== $to && '/' === $to[0] ) {
		$qp = strpos( $to, '?' );
		$q  = false !== $qp ? substr( $to, $qp ) : '';
		$tp = rk_builder_redirect_path( $to );
		$to = '' === $tp ? '' : $tp . $q;
	} else {
		$to = rk_builder_int_url( $to );
	}
	if ( '' === $from || '' === $to ) { return null; }
	if ( '/' === $to[0] && rk_builder_redirect_path( $to ) === $from ) { return null; }
	$code = isset( $r['code'] ) && 302 === (int) $r['code'] ? 302 : 301;
	return array( 'from' => $from, 'to' => $to, 'code' => $code );
}

function rk_builder_handle_get_redirects( $req ) { return rk_builder_no_store( array( 'items' => rk_builder_redirects_list() ) ); }

function rk_builder_handle_set_redirects( $req ) {
	$body = rk_builder_dash_body( $req, array( 'items' ), 'rk_invalid_redirects' );
	if ( is_wp_error( $body ) ) { return $body; }
	if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) || count( $body['items'] ) > 300 ) { return rk_builder_invalid( 'rk_invalid_redirects', array( array( 'path' => 'items', 'message' => 'Send up to 300 redirects.' ) ) ); }
	$out  = array();
	$seen = array();
	foreach ( $body['items'] as $i => $raw ) {
		$r = rk_builder_redirect_clean( $raw );
		if ( null === $r ) { return rk_builder_invalid( 'rk_invalid_redirects', array( array( 'path' => 'items.' . $i, 'message' => 'Each rule needs an old path like /old-page and a different new path or https:// address.' ) ) ); }
		if ( isset( $seen[ $r['from'] ] ) ) { continue; }
		$seen[ $r['from'] ] = true;
		$out[] = $r;
	}
	update_option( 'rk_builder_redirects', $out, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( array( 'items' => $out ) );
}

/** The redirect for a request path, or null. */
function rk_builder_redirect_match( $path ) {
	$path = rk_builder_redirect_path( $path );
	if ( '' === $path ) { return null; }
	foreach ( rk_builder_redirects_list() as $r ) { if ( $r['from'] === $path ) { return $r; } }
	return null;
}

function rk_builder_redirect_run() {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! isset( $_SERVER['REQUEST_URI'] ) ) { return; }
	$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and lowercased below
	$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ), PHP_URL_PATH ) : '/';
	$home = is_string( $home ) ? rtrim( $home, '/' ) : '';
	$path = rk_builder_redirect_path( $uri );
	if ( '' !== $home && 0 === strpos( $path, strtolower( $home ) ) ) { $path = substr( $path, strlen( $home ) ); if ( '' === $path ) { $path = '/'; } }
	$m = rk_builder_redirect_match( $path );
	if ( null === $m ) { return; }
	$to = '/' === $m['to'][0] ? home_url( $m['to'] ) : $m['to'];
	wp_redirect( $to, $m['code'], 'RK Builder' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is a validated path or http(s) address set by an administrator
	exit;
}

/* ------------------------------------------------------------------ *
 * Hooks and routes
 * ------------------------------------------------------------------ */

add_action( 'wp_head', 'rk_builder_code_print_head', 2 );
add_action( 'wp_body_open', 'rk_builder_code_print_body', 1 );
add_action( 'wp_footer', 'rk_builder_code_print_footer', 99 );
add_action( 'template_redirect', 'rk_builder_redirect_run', 1 );
add_action( 'rk_builder_reviews_cron', 'rk_builder_reviews_sync' );

function rk_builder_register_integration_routes( $ns ) {
	$admin = 'rk_builder_perm_theme_write';
	register_rest_route( $ns, '/builder/code', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_code', 'permission_callback' => $admin ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_code', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/builder/reviews-admin', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_reviews_admin', 'permission_callback' => $admin ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_reviews_admin', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/builder/reviews-admin/items', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_reviews_items', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/places/search', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_places_search', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/places/link', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_places_link', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/places/import', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_places_import', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/reviews-admin/sync', array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_sync_reviews', 'permission_callback' => $admin ) );
	register_rest_route( $ns, '/builder/redirects', array(
		array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_get_redirects', 'permission_callback' => $admin ),
		array( 'methods' => 'POST', 'callback' => 'rk_builder_handle_set_redirects', 'permission_callback' => $admin ),
	) );
	register_rest_route( $ns, '/reviews', array( 'methods' => 'GET', 'callback' => 'rk_builder_handle_public_reviews', 'permission_callback' => '__return_true' ) );
}
