<?php
/**
 * Google Places lookup for the "Local business & Google Business Profile" card.
 *
 *   POST /builder/places/search   { query }    -> up to 5 matching businesses
 *   POST /builder/places/import   { placeId }  -> business fields to fill in, plus rating and review count
 *
 * Uses the Places API (New): places:searchText and places/{id}. The key is the one the Reviews screen already
 * stores (rk_builder_reviews_key()), sent in a header and never returned to the browser.
 * Import saves only the Google metadata (Place ID, rating, review count, map link, used for the JSON-LD
 * aggregateRating and hasMap); the business fields come back for the form and are saved with "Save".
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function rk_builder_places_place_id( $v ) {
	$v = is_string( $v ) ? trim( $v ) : '';
	if ( 0 === strpos( $v, 'places/' ) ) { $v = substr( $v, 7 ); }
	return 1 === preg_match( '/^[A-Za-z0-9_-]{10,200}\z/', $v ) ? $v : '';
}

/** Calls Google and returns the decoded body, or a WP_Error that names the problem in plain words. */
function rk_builder_places_call( $method, $url, $mask, $body = null ) {
	$key = rk_builder_reviews_key();
	if ( '' === $key ) { return rk_builder_error( 'rk_conflict', 'Add a Google Places API key first.', 409 ); }
	$args = array( 'timeout' => 15, 'headers' => array( 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => $mask ) );
	if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
	$res = rk_builder_int_http( $method, $url, $args );
	if ( is_wp_error( $res ) ) { return rk_builder_error( 'rk_upstream', 'Could not reach Google: ' . $res->get_error_message(), 502 ); }
	$data = json_decode( $res['body'], true );
	if ( $res['code'] < 200 || $res['code'] >= 300 || ! is_array( $data ) ) {
		if ( 429 === $res['code'] ) { return rk_builder_error( 'rk_upstream', 'Google\'s request limit has been reached. Try again in a little while.', 429 ); }
		$why = is_array( $data ) && isset( $data['error']['message'] ) ? rk_builder_theme_text( $data['error']['message'], 200 ) : 'HTTP ' . $res['code'];
		return rk_builder_error( 'rk_upstream', 'Google said: ' . $why, 502 );
	}
	return $data;
}

/** Google's opening periods -> RK Builder's "Mon-Fri 08:00-17:00" lines. */
function rk_builder_places_hours_text( $periods ) {
	$names = array( 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' );
	$order = array( 1, 2, 3, 4, 5, 6, 0 );
	$fmt   = function ( $p ) { return sprintf( '%02d:%02d', (int) ( isset( $p['hour'] ) ? $p['hour'] : 0 ), (int) ( isset( $p['minute'] ) ? $p['minute'] : 0 ) ); };
	$by_range = array(); // "08:00-17:00" => [days]
	foreach ( is_array( $periods ) ? $periods : array() as $p ) {
		if ( ! isset( $p['open']['day'] ) ) { continue; }
		$d = (int) $p['open']['day'];
		if ( ! isset( $names[ $d ] ) ) { continue; }
		$from = $fmt( $p['open'] );
		$to   = isset( $p['close'] ) && isset( $p['close']['day'] ) && (int) $p['close']['day'] === $d ? $fmt( $p['close'] ) : '23:59';
		if ( $to <= $from ) { $to = '23:59'; }
		$by_range[ $from . '-' . $to ][ $d ] = true;
	}
	$lines = array();
	foreach ( $by_range as $range => $days ) {
		$runs = array();
		$cur  = array();
		foreach ( $order as $d ) {
			if ( isset( $days[ $d ] ) ) { $cur[] = $d; continue; }
			if ( $cur ) { $runs[] = $cur; $cur = array(); }
		}
		if ( $cur ) { $runs[] = $cur; }
		$parts = array();
		foreach ( $runs as $run ) { $parts[] = count( $run ) > 2 ? $names[ $run[0] ] . '-' . $names[ end( $run ) ] : implode( ',', array_map( function ( $d ) use ( $names ) { return $names[ $d ]; }, $run ) ); }
		$lines[ array_search( $runs[0][0], $order, true ) . $range ] = implode( ',', $parts ) . ' ' . $range;
	}
	ksort( $lines );
	return implode( "\n", array_values( $lines ) );
}

/** Address pieces out of Google's addressComponents. */
function rk_builder_places_address( $components ) {
	$out = array( 'street' => '', 'city' => '', 'region' => '', 'postal' => '', 'country' => '' );
	$num = '';
	$route = '';
	$pick = function ( $c, $short ) { return rk_builder_theme_text( isset( $c[ $short ? 'shortText' : 'longText' ] ) ? $c[ $short ? 'shortText' : 'longText' ] : '', 120 ); };
	foreach ( is_array( $components ) ? $components : array() as $c ) {
		$t = isset( $c['types'] ) && is_array( $c['types'] ) ? $c['types'] : array();
		if ( in_array( 'street_number', $t, true ) ) { $num = $pick( $c, false ); }
		elseif ( in_array( 'route', $t, true ) ) { $route = $pick( $c, false ); }
		elseif ( in_array( 'locality', $t, true ) || ( '' === $out['city'] && ( in_array( 'postal_town', $t, true ) || in_array( 'sublocality', $t, true ) ) ) ) { $out['city'] = $pick( $c, false ); }
		elseif ( in_array( 'administrative_area_level_1', $t, true ) ) { $out['region'] = $pick( $c, true ); }
		elseif ( in_array( 'postal_code', $t, true ) ) { $out['postal'] = $pick( $c, false ); }
		elseif ( in_array( 'country', $t, true ) ) { $out['country'] = strtoupper( $pick( $c, true ) ); }
	}
	$out['street'] = trim( $num . ' ' . $route );
	if ( 1 !== preg_match( '/^[A-Z]{2}\z/', $out['country'] ) ) { $out['country'] = ''; }
	return $out;
}

function rk_builder_handle_places_search( $req ) {
	$body = rk_builder_dash_body( $req, array( 'query' ), 'rk_invalid_places' );
	if ( is_wp_error( $body ) ) { return $body; }
	$q = isset( $body['query'] ) && is_string( $body['query'] ) ? trim( wp_strip_all_tags( $body['query'] ) ) : '';
	if ( rk_builder_strlen( $q ) < 3 || rk_builder_strlen( $q ) > 150 ) { return rk_builder_invalid( 'rk_invalid_places', array( array( 'path' => 'query', 'message' => 'Type at least 3 letters of the business name.' ) ) ); }
	$data = rk_builder_places_call( 'POST', 'https://places.googleapis.com/v1/places:searchText', 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount', array( 'textQuery' => $q, 'pageSize' => 5 ) );
	if ( is_wp_error( $data ) ) { return $data; }
	$results = array();
	foreach ( isset( $data['places'] ) && is_array( $data['places'] ) ? $data['places'] : array() as $p ) {
		$id = rk_builder_places_place_id( isset( $p['id'] ) ? $p['id'] : '' );
		if ( '' === $id ) { continue; }
		$results[] = array(
			'placeId' => $id,
			'name'    => rk_builder_theme_text( isset( $p['displayName']['text'] ) ? $p['displayName']['text'] : '', 120 ),
			'address' => rk_builder_theme_text( isset( $p['formattedAddress'] ) ? $p['formattedAddress'] : '', 200 ),
			'rating'  => isset( $p['rating'] ) ? round( (float) $p['rating'], 1 ) : 0.0,
			'count'   => isset( $p['userRatingCount'] ) ? (int) $p['userRatingCount'] : 0,
		);
	}
	return rk_builder_no_store( array( 'results' => $results ) );
}

function rk_builder_handle_places_import( $req ) {
	$body = rk_builder_dash_body( $req, array( 'placeId' ), 'rk_invalid_places' );
	if ( is_wp_error( $body ) ) { return $body; }
	$id = rk_builder_places_place_id( isset( $body['placeId'] ) ? $body['placeId'] : '' );
	if ( '' === $id ) { return rk_builder_invalid( 'rk_invalid_places', array( array( 'path' => 'placeId', 'message' => 'Pick a business from the list.' ) ) ); }
	$mask = 'id,displayName,nationalPhoneNumber,addressComponents,regularOpeningHours,googleMapsUri,rating,userRatingCount';
	$d = rk_builder_places_call( 'GET', 'https://places.googleapis.com/v1/places/' . rawurlencode( $id ), $mask );
	if ( is_wp_error( $d ) ) { return $d; }
	$maps   = isset( $d['googleMapsUri'] ) ? rk_builder_int_url( $d['googleMapsUri'] ) : '';
	$rating = isset( $d['rating'] ) && is_numeric( $d['rating'] ) ? max( 0, min( 5, round( (float) $d['rating'], 1 ) ) ) : 0.0;
	$count  = isset( $d['userRatingCount'] ) && is_numeric( $d['userRatingCount'] ) ? max( 0, min( 1000000, (int) $d['userRatingCount'] ) ) : 0;
	$s = rk_builder_reviews_store();
	$s['placeId']   = $id;
	$s['mapsUrl']   = $maps;
	$s['summary']   = array( 'rating' => (float) $rating, 'count' => $count );
	$s['syncedAt']  = rk_builder_iso( rk_builder_now() );
	$s['lastError'] = '';
	if ( '' === $s['profileUrl'] ) { $s['profileUrl'] = $maps; }
	update_option( 'rk_builder_reviews', $s, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	$fields = array_merge(
		array( 'name' => rk_builder_theme_text( isset( $d['displayName']['text'] ) ? $d['displayName']['text'] : '', 120 ), 'telephone' => rk_builder_theme_text( isset( $d['nationalPhoneNumber'] ) ? $d['nationalPhoneNumber'] : '', 40 ) ),
		rk_builder_places_address( isset( $d['addressComponents'] ) ? $d['addressComponents'] : array() ),
		array( 'hours' => rk_builder_places_hours_text( isset( $d['regularOpeningHours']['periods'] ) ? $d['regularOpeningHours']['periods'] : array() ), 'googleBusiness' => $maps )
	);
	return rk_builder_no_store( array( 'placeId' => $id, 'fields' => $fields, 'rating' => (float) $rating, 'count' => $count ) );
}

/* ------------------------------------------------------------------ *
 * No API key: read what a pasted Google Maps link already says
 * ------------------------------------------------------------------ */

/** Hosts a pasted link may point at, and the only ones a short link is followed to. */
function rk_builder_places_link_host_ok( $url ) {
	$h = strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ) ) );
	return in_array( $h, array( 'maps.app.goo.gl', 'goo.gl', 'g.page', 'share.google', 'google.com', 'www.google.com', 'maps.google.com', 'search.google.com' ), true );
}

/** Hex digits -> decimal string (CIDs do not fit a signed 64-bit integer). */
function rk_builder_places_hex2dec( $hex ) {
	$dec = '0';
	foreach ( str_split( strtolower( $hex ) ) as $ch ) {
		$carry = hexdec( $ch );
		$out   = '';
		for ( $i = strlen( $dec ) - 1; $i >= 0; $i-- ) {
			$v     = (int) $dec[ $i ] * 16 + $carry;
			$out   = ( $v % 10 ) . $out;
			$carry = intdiv( $v, 10 );
		}
		while ( $carry > 0 ) { $out = ( $carry % 10 ) . $out; $carry = intdiv( $carry, 10 ); }
		$dec = ltrim( $out, '0' );
		if ( '' === $dec ) { $dec = '0'; }
	}
	return $dec;
}

/** What a Google Maps address contains: name, CID, Place ID (when it carries one). Pure, no network. */
/**
 * A Place ID (ChIJ…) from the two hex halves of a Maps feature id ("0x52b32df53ba03e3b:0x8457cad58e1c7661"): the ID is
 * those two 64-bit numbers, little-endian, in a tiny protobuf, written as URL-safe base64. Needs no key and no request.
 */
function rk_builder_places_fid_to_place_id( $hex_a, $hex_b ) {
	$a = str_pad( strtolower( ltrim( (string) $hex_a, '0' ) ), 16, '0', STR_PAD_LEFT );
	$b = str_pad( strtolower( ltrim( (string) $hex_b, '0' ) ), 16, '0', STR_PAD_LEFT );
	if ( 1 !== preg_match( '/^[0-9a-f]{16}\z/', $a ) || 1 !== preg_match( '/^[0-9a-f]{16}\z/', $b ) ) { return ''; }
	$raw = "\x0a\x12\x09" . strrev( hex2bin( $a ) ) . "\x11" . strrev( hex2bin( $b ) );
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

function rk_builder_places_parse_link( $url ) {
	$out = array( 'name' => '', 'placeId' => '', 'cid' => '', 'kgmid' => '' );
	$u   = rawurldecode( (string) $url );
	if ( preg_match( '#/maps/place/([^/@?]+)#', $u, $m ) ) { $out['name'] = rk_builder_theme_text( str_replace( '+', ' ', $m[1] ), 120 ); }
	elseif ( preg_match( '~google\.com/(?:maps/)?search[^?]*\?(?:[^#]*&)?q=([^&]+)~', $u, $m ) && 0 !== strpos( $m[1], 'place_id:' ) ) { $out['name'] = rk_builder_theme_text( str_replace( '+', ' ', $m[1] ), 120 ); }
	if ( preg_match( '#(?:place_id[:=]|query_place_id=|!1s)(ChIJ[A-Za-z0-9_-]{10,200})#', $u, $m ) ) { $out['placeId'] = $m[1]; }
	if ( '' === $out['placeId'] && preg_match( '#!1s(0x[0-9a-f]{1,16}):(0x[0-9a-f]{1,16})#i', $u, $m ) ) { $out['placeId'] = rk_builder_places_fid_to_place_id( substr( $m[1], 2 ), substr( $m[2], 2 ) ); }
	if ( preg_match( '#[?&]kgmid=(/[gm]/[A-Za-z0-9_]{3,20})#', $u, $m ) ) { $out['kgmid'] = $m[1]; }
	if ( preg_match( '#[?&]cid=(\d{5,25})#', $u, $m ) ) { $out['cid'] = $m[1]; }
	elseif ( preg_match( '#!1s0x[0-9a-f]+:0x([0-9a-f]{1,16})#i', $u, $m ) ) { $out['cid'] = rk_builder_places_hex2dec( $m[1] ); }
	return $out;
}

/** Follows a short link (a few hops, only between Google hosts) and returns the final address. */
function rk_builder_places_resolve( $url ) {
	for ( $i = 0; $i < 4; $i++ ) {
		if ( ! rk_builder_places_link_host_ok( $url ) ) { return $url; }
		if ( false !== strpos( $url, '/maps/place/' ) || false !== strpos( $url, 'kgmid=' ) ) { return $url; }
		$res = rk_builder_int_http( 'GET', $url, array( 'timeout' => 10, 'redirection' => 0, 'headers' => array( 'Accept-Language' => 'en' ) ) );
		if ( is_wp_error( $res ) || $res['code'] < 300 || $res['code'] >= 400 || empty( $res['location'] ) ) { return $url; }
		$next = $res['location'];
		if ( 0 === strpos( $next, '/' ) ) { $next = 'https://' . strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . $next; }
		$url = $next;
	}
	return $url;
}

/** POST /builder/places/link { url }: a Maps link (or a ChIJ Place ID) -> profile link, name, Place ID. Needs no key. */
function rk_builder_handle_places_link( $req ) {
	$body = rk_builder_dash_body( $req, array( 'url' ), 'rk_invalid_places' );
	if ( is_wp_error( $body ) ) { return $body; }
	$raw = isset( $body['url'] ) && is_string( $body['url'] ) ? trim( $body['url'] ) : '';
	$bad = rk_builder_invalid( 'rk_invalid_places', array( array( 'path' => 'url', 'message' => 'Paste a Google Maps link (maps.app.goo.gl, g.page or google.com/maps) or a Place ID starting with ChIJ.' ) ) );
	$info = array( 'name' => '', 'placeId' => '', 'cid' => '', 'kgmid' => '' );
	$link = '';
	if ( 1 === preg_match( '/^ChIJ[A-Za-z0-9_-]{10,200}\z/', $raw ) ) {
		$info['placeId'] = $raw;
	} else {
		$u = rk_builder_int_url( $raw );
		if ( '' === $u || ! rk_builder_places_link_host_ok( $u ) ) { return $bad; }
		$link = rk_builder_places_resolve( $u );
		$info = rk_builder_places_parse_link( $link );
		if ( '' === $info['cid'] && '' === $info['placeId'] && '' === $info['kgmid'] && '' === $info['name'] ) { $info = array_merge( $info, rk_builder_places_parse_link( $u ) ); }
		if ( '' === $info['cid'] && '' === $info['placeId'] && '' === $info['kgmid'] && '' === $info['name'] ) {
			return rk_builder_error( 'rk_upstream', 'Google would not let this site open that short link. Open it in your browser, then copy the long address from the address bar (it contains /maps/place/… or kgmid=) and paste that instead.', 422 );
		}
	}
	$profile = '' !== $info['cid'] ? 'https://maps.google.com/?cid=' . $info['cid'] : ( '' !== $info['kgmid'] ? 'https://www.google.com/search?kgmid=' . $info['kgmid'] . ( '' !== $info['name'] ? '&q=' . rawurlencode( $info['name'] ) : '' ) : ( '' !== $link ? rk_builder_int_url( $link ) : '' ) );
	$s = rk_builder_reviews_store();
	if ( '' !== $info['placeId'] ) { $s['placeId'] = $info['placeId']; }
	if ( '' !== $profile ) { $s['profileUrl'] = $profile; $s['mapsUrl'] = $profile; }
	update_option( 'rk_builder_reviews', $s, false );
	if ( function_exists( 'rk_builder_purge_all_public_cache' ) ) { rk_builder_purge_all_public_cache(); }
	return rk_builder_no_store( array( 'profileUrl' => $profile, 'name' => $info['name'], 'placeId' => $info['placeId'], 'writeUrl' => rk_builder_reviews_write_url( $info['placeId'] ) ) );
}
