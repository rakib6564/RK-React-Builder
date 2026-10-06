<?php
/**
 * AI flooring visualizer backend.
 *
 *   GET  /rk/v1/visualizer/quota     where the visitor stands (free left, needs contact details, cooling down)
 *   POST /rk/v1/visualizer/generate  multipart: `image` + `options` (JSON). Starts a generation.
 *   GET  /rk/v1/visualizer/status    ?job=<id>. Polls an asynchronous generation.
 *   POST /rk/v1/visualizer/lead      {name, email, phone?} unlocks a bonus generation and stores the lead.
 *
 * The image comes from a provider chosen in Settings > Visualizer:
 *   huggingface  FLUX Kontext through the Hugging Face router (what the source site uses). Asynchronous.
 *   gemini       Google Gemini image editing (generateContent with the photo inline). One synchronous call.
 *   custom       your own backend API: the plugin POSTs {prompt, image, mimeType, options} and expects an image back.
 *   mock         echoes the uploaded photo, to test the page without any provider or cost.
 *
 * Visitors are anonymous. Limits are per browser (a cookie) and per IP, kept in transients. A generation is
 * counted when it starts and given back if the provider fails.
 *
 * @package RK_Builder
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'RK_BUILDER_VIZ_MAX_BYTES' ) ) { define( 'RK_BUILDER_VIZ_MAX_BYTES', 10 * 1024 * 1024 ); }
if ( ! defined( 'RK_BUILDER_VIZ_HF_URL' ) ) { define( 'RK_BUILDER_VIZ_HF_URL', 'https://router.huggingface.co/fal-ai/fal-ai/flux-kontext/dev?_subdomain=queue' ); }
if ( ! defined( 'RK_BUILDER_VIZ_MAX_LEADS' ) ) { define( 'RK_BUILDER_VIZ_MAX_LEADS', 500 ); }

/* ------------------------------------------------------------------ *
 * Settings
 * ------------------------------------------------------------------ */

function rk_builder_viz_defaults() {
	return array(
		'enabled'       => false,
		'provider'      => 'huggingface',
		'hf_token'      => '',
		'gemini_key'    => '',
		'gemini_model'  => 'gemini-2.5-flash-image',
		'custom_url'    => '',
		'custom_key'    => '',
		'custom_header' => 'Authorization',
		'free_count'    => 2,
		'bonus_count'   => 1,
		'cooldown_hours' => 24,
		'ip_per_hour'   => 10,
		'timeout'       => 120,
		'notify_email'  => '',
	);
}

function rk_builder_viz_providers() {
	return array(
		'huggingface' => 'Hugging Face (FLUX Kontext)',
		'gemini'      => 'Google Gemini (image editing)',
		'custom'      => 'My own backend API',
		'mock'        => 'Test mode (echo the uploaded photo)',
	);
}

function rk_builder_viz_settings() {
	$o = get_option( 'rk_builder_visualizer', array() );
	return array_merge( rk_builder_viz_defaults(), is_array( $o ) ? $o : array() );
}

/** The Hugging Face token: a constant, then the HF_TOKEN environment variable, then the saved setting. */
function rk_builder_viz_hf_token( array $s ) {
	if ( defined( 'RK_BUILDER_VIZ_HF_TOKEN' ) && '' !== (string) RK_BUILDER_VIZ_HF_TOKEN ) { return (string) RK_BUILDER_VIZ_HF_TOKEN; }
	$env = getenv( 'HF_TOKEN' );
	if ( is_string( $env ) && '' !== $env ) { return $env; }
	return (string) $s['hf_token'];
}

/** The Gemini API key: a constant, then the GEMINI_API_KEY / GOOGLE_API_KEY environment variables, then the saved setting. */
function rk_builder_viz_gemini_key( array $s ) {
	if ( defined( 'RK_BUILDER_VIZ_GEMINI_KEY' ) && '' !== (string) RK_BUILDER_VIZ_GEMINI_KEY ) { return (string) RK_BUILDER_VIZ_GEMINI_KEY; }
	foreach ( array( 'GEMINI_API_KEY', 'GOOGLE_API_KEY' ) as $name ) {
		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) { return $env; }
	}
	return (string) $s['gemini_key'];
}

/** Whether the chosen provider has what it needs. */
function rk_builder_viz_ready( array $s ) {
	if ( empty( $s['enabled'] ) ) { return false; }
	if ( 'mock' === $s['provider'] ) { return true; }
	if ( 'gemini' === $s['provider'] ) { return '' !== rk_builder_viz_gemini_key( $s ); }
	if ( 'custom' === $s['provider'] ) { return '' !== $s['custom_url'] && 1 === preg_match( '#^https?://#i', $s['custom_url'] ); }
	return '' !== rk_builder_viz_hf_token( $s );
}

function rk_builder_viz_clamp_int( $v, $min, $max, $fallback ) {
	if ( ! is_numeric( $v ) ) { return $fallback; }
	return max( $min, min( $max, (int) $v ) );
}

/** Sanitise the settings form. A blank secret keeps the stored one. */
function rk_builder_viz_sanitize( $in ) {
	$old = rk_builder_viz_settings();
	$in  = is_array( $in ) ? $in : array();
	$out = array_merge( rk_builder_viz_defaults(), array() );
	$out['enabled']  = ! empty( $in['enabled'] );
	$out['provider'] = isset( $in['provider'], rk_builder_viz_providers()[ $in['provider'] ] ) ? $in['provider'] : 'huggingface';
	foreach ( array( 'hf_token', 'custom_key', 'gemini_key' ) as $secret ) {
		$v = isset( $in[ $secret ] ) ? trim( (string) $in[ $secret ] ) : '';
		$out[ $secret ] = '' !== $v ? $v : $old[ $secret ];
		if ( ! empty( $in[ 'clear_' . $secret ] ) ) { $out[ $secret ] = ''; }
	}
	$model = isset( $in['gemini_model'] ) ? trim( (string) $in['gemini_model'] ) : '';
	$out['gemini_model'] = 1 === preg_match( '/^[A-Za-z0-9._-]{1,80}$/', $model ) ? $model : 'gemini-2.5-flash-image';
	$url = isset( $in['custom_url'] ) ? trim( (string) $in['custom_url'] ) : '';
	$out['custom_url'] = 1 === preg_match( '#^https?://[^\s]+$#i', $url ) ? $url : '';
	$hdr = isset( $in['custom_header'] ) ? trim( (string) $in['custom_header'] ) : 'Authorization';
	$out['custom_header'] = 1 === preg_match( '/^[A-Za-z0-9-]{1,60}$/', $hdr ) ? $hdr : 'Authorization';
	$out['free_count']     = rk_builder_viz_clamp_int( isset( $in['free_count'] ) ? $in['free_count'] : null, 0, 20, 2 );
	$out['bonus_count']    = rk_builder_viz_clamp_int( isset( $in['bonus_count'] ) ? $in['bonus_count'] : null, 0, 20, 1 );
	$out['cooldown_hours'] = rk_builder_viz_clamp_int( isset( $in['cooldown_hours'] ) ? $in['cooldown_hours'] : null, 1, 720, 24 );
	$out['ip_per_hour']    = rk_builder_viz_clamp_int( isset( $in['ip_per_hour'] ) ? $in['ip_per_hour'] : null, 1, 1000, 10 );
	$out['timeout']        = rk_builder_viz_clamp_int( isset( $in['timeout'] ) ? $in['timeout'] : null, 20, 600, 120 );
	$mail = isset( $in['notify_email'] ) ? trim( (string) $in['notify_email'] ) : '';
	$out['notify_email']   = '' === $mail || 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $mail ) ? $mail : '';
	return $out;
}

/* ------------------------------------------------------------------ *
 * Visitor identity, limits and quota
 * ------------------------------------------------------------------ */

function rk_builder_viz_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/** The visitor's browser id: the `rk_viz` cookie when it is well formed, else a new random one. */
function rk_builder_viz_visitor_id() {
	$c = isset( $_COOKIE['rk_viz'] ) ? (string) $_COOKIE['rk_viz'] : '';
	if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $c ) ) { return $c; }
	$id = bin2hex( random_bytes( 16 ) );
	$_COOKIE['rk_viz'] = $id;
	if ( ! headers_sent() ) {
		setcookie( 'rk_viz', $id, array( 'expires' => time() + 365 * 86400, 'path' => '/', 'secure' => function_exists( 'is_ssl' ) && is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}
	return $id;
}

function rk_builder_viz_state_key( $visitor_id ) { return 'rk_viz_v_' . substr( hash( 'sha256', $visitor_id ), 0, 40 ); }

function rk_builder_viz_state( $visitor_id ) {
	$st = get_transient( rk_builder_viz_state_key( $visitor_id ) );
	return array_merge( array( 'used' => 0, 'bonus_used' => 0, 'lead' => false, 'next_at' => 0 ), is_array( $st ) ? $st : array() );
}

function rk_builder_viz_save_state( $visitor_id, array $st ) {
	set_transient( rk_builder_viz_state_key( $visitor_id ), $st, 60 * 86400 );
}

/**
 * Where a visitor stands. Pure: no WordPress calls.
 * phase: free (the first few), needs_lead (used them up, no contact details yet), bonus (unlocked by the lead form),
 * cooldown (waiting), rolling (one more after each cooldown).
 */
function rk_builder_viz_quota( array $st, array $s, $now ) {
	$free  = (int) $s['free_count'];
	$bonus = (int) $s['bonus_count'];
	$base  = array( 'phase' => 'free', 'canGenerate' => false, 'freeRemaining' => max( 0, $free - (int) $st['used'] ), 'cooldownUntil' => null, 'hasLead' => (bool) $st['lead'] );
	if ( (int) $st['used'] < $free ) { $base['canGenerate'] = true; return $base; }
	if ( ! $st['lead'] && $bonus > 0 ) { $base['phase'] = 'needs_lead'; return $base; }
	if ( $st['lead'] && (int) $st['bonus_used'] < $bonus ) { $base['phase'] = 'bonus'; $base['canGenerate'] = true; return $base; }
	$next = (int) $st['next_at'];
	if ( $next <= $now ) { $base['phase'] = 'rolling'; $base['canGenerate'] = true; return $base; }
	$base['phase']         = 'cooldown';
	$base['cooldownUntil'] = gmdate( 'c', $next );
	return $base;
}

/** Spend one generation in the given phase. Pure. */
function rk_builder_viz_consume( array $st, $phase, array $s, $now ) {
	$cool = (int) $s['cooldown_hours'] * 3600;
	if ( 'free' === $phase ) { $st['used'] = (int) $st['used'] + 1; if ( (int) $s['bonus_count'] < 1 && (int) $st['used'] >= (int) $s['free_count'] ) { $st['next_at'] = $now + $cool; } }
	elseif ( 'bonus' === $phase ) { $st['bonus_used'] = (int) $st['bonus_used'] + 1; if ( (int) $st['bonus_used'] >= (int) $s['bonus_count'] ) { $st['next_at'] = $now + $cool; } }
	elseif ( 'rolling' === $phase ) { $st['next_at'] = $now + $cool; }
	return $st;
}

/** Give one back after a provider failure. Pure. */
function rk_builder_viz_refund( array $st, $phase, array $before ) {
	if ( 'free' === $phase ) { $st['used'] = max( 0, (int) $st['used'] - 1 ); }
	if ( 'bonus' === $phase ) { $st['bonus_used'] = max( 0, (int) $st['bonus_used'] - 1 ); }
	$st['next_at'] = (int) $before['next_at'];
	return $st;
}

function rk_builder_viz_quota_message( array $q ) {
	if ( 'free' === $q['phase'] ) { return $q['freeRemaining'] . ' free visualization' . ( 1 === $q['freeRemaining'] ? '' : 's' ) . ' remaining.'; }
	if ( 'needs_lead' === $q['phase'] ) { return 'You\'ve used your free visualizations — share your contact info to unlock more.'; }
	if ( 'bonus' === $q['phase'] ) { return 'You have 1 more free visualization available.'; }
	if ( 'rolling' === $q['phase'] ) { return 'You have 1 free visualization available today.'; }
	return 'You\'ve used today\'s free visualizations. Talk with us now, or come back later.';
}

/** Per-IP hourly cap. Returns true when the request may go ahead (and counts it). */
function rk_builder_viz_ip_allow( array $s, $now ) {
	$key = 'rk_viz_ip_' . substr( md5( rk_builder_viz_ip() ), 0, 24 );
	$rec = get_transient( $key );
	if ( ! is_array( $rec ) || ! isset( $rec['start'], $rec['n'] ) || $now - (int) $rec['start'] >= 3600 ) { $rec = array( 'start' => $now, 'n' => 0 ); }
	if ( (int) $rec['n'] >= (int) $s['ip_per_hour'] ) { return false; }
	$rec['n'] = (int) $rec['n'] + 1;
	set_transient( $key, $rec, 3600 );
	return true;
}

/* ------------------------------------------------------------------ *
 * Input
 * ------------------------------------------------------------------ */

function rk_builder_viz_error( $code, $message, $status ) {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/** The uploaded photo: { path, mime, width, height, size } or a WP_Error. */
function rk_builder_viz_read_upload( $req ) {
	$files = method_exists( $req, 'get_file_params' ) ? $req->get_file_params() : array();
	$f     = isset( $files['image'] ) && is_array( $files['image'] ) ? $files['image'] : null;
	if ( ! $f || ! isset( $f['tmp_name'] ) || ! empty( $f['error'] ) || ! is_string( $f['tmp_name'] ) || '' === $f['tmp_name'] || ! is_readable( $f['tmp_name'] ) ) {
		return rk_builder_viz_error( 'rk_viz_no_image', 'Please upload a room photo.', 400 );
	}
	$size = (int) filesize( $f['tmp_name'] );
	$cap  = function_exists( 'wp_max_upload_size' ) ? min( RK_BUILDER_VIZ_MAX_BYTES, (int) wp_max_upload_size() ) : RK_BUILDER_VIZ_MAX_BYTES;
	if ( $size <= 0 || $size > max( 1024, $cap ) ) { return rk_builder_viz_error( 'rk_viz_too_large', 'This image is too large. Please choose an image under 10 MB.', 413 ); }
	$info = @getimagesize( $f['tmp_name'] );
	$ok   = array( 'image/jpeg', 'image/png', 'image/webp' );
	if ( ! is_array( $info ) || ! isset( $info[0], $info[1], $info['mime'] ) || ! in_array( $info['mime'], $ok, true ) ) {
		return rk_builder_viz_error( 'rk_viz_bad_type', 'This file type isn\'t supported. Please upload a JPG, PNG, or WebP image.', 415 );
	}
	if ( (int) $info[0] < 640 || (int) $info[1] < 480 ) { return rk_builder_viz_error( 'rk_viz_too_small', 'This image is too small for a reliable preview. Please choose a higher-resolution room photo.', 400 ); }
	return array( 'path' => $f['tmp_name'], 'mime' => $info['mime'], 'width' => (int) $info[0], 'height' => (int) $info[1], 'size' => $size );
}

/** The chosen options, checked against the allowed values; or a WP_Error. */
function rk_builder_viz_read_options( $req ) {
	$raw = $req->get_param( 'options' );
	$in  = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
	if ( ! is_array( $in ) ) { return rk_builder_viz_error( 'rk_viz_bad_options', 'Please review the highlighted fields before generating.', 400 ); }
	$out = array();
	foreach ( rk_builder_viz_option_sets() as $name => $set ) {
		$v = isset( $in[ $name ] ) && is_string( $in[ $name ] ) ? $in[ $name ] : '';
		if ( ! isset( $set['options'][ $v ] ) ) { return rk_builder_viz_error( 'rk_viz_bad_options', 'Please choose an option for ' . strtolower( $set['legend'] ) . '.', 400 ); }
		$out[ $name ] = $v;
	}
	$custom = isset( $in['customStyleDescription'] ) && is_string( $in['customStyleDescription'] ) ? trim( preg_replace( '/\s+/', ' ', $in['customStyleDescription'] ) ) : '';
	if ( 'custom' === $out['preferredStyle'] && '' === $custom ) { return rk_builder_viz_error( 'rk_viz_bad_options', 'Please describe the style you want.', 400 ); }
	if ( function_exists( 'mb_substr' ) ) { $custom = mb_substr( $custom, 0, 500 ); } else { $custom = substr( $custom, 0, 500 ); }
	$out['customStyleDescription'] = $custom;
	$city = isset( $in['serviceCity'] ) && is_string( $in['serviceCity'] ) ? $in['serviceCity'] : '';
	$out['serviceCity'] = 1 === preg_match( '/^[a-z0-9_]{1,60}$/', $city ) ? $city : '';
	$sq = isset( $in['squareFootage'] ) && is_numeric( $in['squareFootage'] ) ? (int) $in['squareFootage'] : 0;
	$out['squareFootage'] = max( 0, min( 1000000, $sq ) );
	return $out;
}

/** The instruction sent to the image model. Same wording as the source site, plus the finish and sheen choices. */
function rk_builder_viz_prompt( array $o ) {
	$species = array(
		'oak' => 'natural white oak hardwood', 'maple' => 'light maple hardwood', 'hickory' => 'hickory hardwood with natural color variation',
		'mixed_unsure' => 'natural-toned hardwood', 'existing_floor' => 'hardwood that matches the existing floor\'s species',
	);
	$styles = array(
		'light_natural' => 'a light, natural tone', 'warm_traditional' => 'a warm, traditional medium-brown tone',
		'gray_weathered' => 'a gray, weathered tone', 'dark_modern' => 'a dark, modern tone',
	);
	$dirs = array(
		'parallel' => 'laid parallel to the longest wall', 'perpendicular' => 'laid perpendicular to the longest wall', 'diagonal' => 'laid in a diagonal pattern',
		'herringbone' => 'laid in a herringbone pattern', 'existing_direction' => 'following the same direction as the existing floor',
	);
	$sheens = array( 'matte' => 'a matte', 'satin' => 'a satin', 'semi_gloss' => 'a semi-gloss' );
	$finish = array( 'bona_traffic_hd' => 'a hard-wearing waterborne finish', 'rubio_monocoat' => 'a natural hardwax oil finish', 'polyurethane' => 'a polyurethane finish' );

	$sp    = isset( $species[ $o['woodSpecies'] ] ) ? $species[ $o['woodSpecies'] ] : 'natural hardwood';
	$style = 'custom' === $o['preferredStyle'] ? $o['customStyleDescription'] : ( isset( $styles[ $o['preferredStyle'] ] ) ? $styles[ $o['preferredStyle'] ] : '' );
	$dir   = isset( $dirs[ $o['floorDirection'] ] ) ? $dirs[ $o['floorDirection'] ] : '';
	$p     = "Edit the uploaded room photo to create a photorealistic hardwood flooring visualization.\n\n";
	$p    .= 'Replace ONLY the existing visible floor with realistic ' . $sp . ( '' !== $style ? ', in ' . $style : '' ) . ( '' !== $dir ? ', ' . $dir : '' ) . ".\n\n";
	$look  = array();
	if ( isset( $sheens[ $o['sheen'] ] ) ) { $look[] = $sheens[ $o['sheen'] ] . ' sheen'; }
	if ( isset( $finish[ $o['finishPreference'] ] ) ) { $look[] = $finish[ $o['finishPreference'] ]; }
	if ( $look ) { $p .= 'Give the floor ' . implode( ' and ', $look ) . ".\n\n"; }
	$p    .= "Preserve the exact room architecture, furniture, rug, walls, windows, doors, fireplace, decorations, lighting, shadows, reflections, camera position, perspective, and composition.\n\n";
	$p    .= "Do not redesign or regenerate the room.\n\n";
	$p    .= "The new hardwood floor must follow the existing floor plane, perspective, vanishing points, boundaries, and geometry naturally.\n\n";
	$p    .= "Keep all non-floor objects unchanged.\n\n";
	$p    .= "The final image should look like a professionally photographed real room after the hardwood flooring installation.\n\n";
	$p    .= "Do not add objects.\nDo not remove objects.\nDo not change the furniture.\nDo not change the walls.\nDo not change the lighting.\nDo not change the camera perspective.";
	return $p;
}

/* ------------------------------------------------------------------ *
 * Providers
 * ------------------------------------------------------------------ */

/** One HTTP call: array( code, body ) or WP_Error. Filterable so tests and hosts can intercept it. */
function rk_builder_viz_http( $method, $url, array $args = array() ) {
	$pre = apply_filters( 'rk_builder_viz_http', null, $method, $url, $args );
	if ( null !== $pre ) { return $pre; }
	$args['method'] = $method;
	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) { return $r; }
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) );
}

function rk_builder_viz_data_uri( $path, $mime ) { return 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $path ) ); }

function rk_builder_viz_https( $url ) { return is_string( $url ) && 1 === preg_match( '#^https://[^\s]+$#i', $url ); }

/** The fal queue URLs come back on queue.fal.run, which refuses our token: send them through the router instead. */
function rk_builder_viz_router_url( $fal_url ) {
	$u = function_exists( 'wp_parse_url' ) ? wp_parse_url( $fal_url ) : parse_url( $fal_url );
	if ( ! is_array( $u ) || empty( $u['host'] ) || empty( $u['path'] ) || ! preg_match( '/(^|\.)fal\.run$/', $u['host'] ) ) { return ''; }
	return 'https://router.huggingface.co/fal-ai' . $u['path'] . '?_subdomain=queue';
}

/** Save generated image bytes (or a base64 / data URI string) into uploads and return its URL, or ''. */
function rk_builder_viz_store_image( $bytes ) {
	if ( ! is_string( $bytes ) || '' === $bytes ) { return ''; }
	if ( 0 === strpos( $bytes, 'data:' ) ) { $bytes = (string) substr( $bytes, (int) strpos( $bytes, ',' ) + 1 ); }
	$bin  = base64_decode( $bytes, true );
	$bin  = false === $bin ? $bytes : $bin;
	$info = @getimagesizefromstring( $bin );
	$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
	if ( ! is_array( $info ) || ! isset( $ext[ $info['mime'] ] ) || ! function_exists( 'wp_upload_dir' ) ) { return ''; }
	$dir = wp_upload_dir();
	if ( ! empty( $dir['error'] ) ) { return ''; }
	$sub  = rtrim( $dir['basedir'], '/' ) . '/rk-visualizer';
	if ( ! is_dir( $sub ) ) { @mkdir( $sub, 0755, true ); }
	$name = bin2hex( random_bytes( 12 ) ) . '.' . $ext[ $info['mime'] ];
	if ( false === @file_put_contents( $sub . '/' . $name, $bin ) ) { return ''; }
	return rtrim( $dir['baseurl'], '/' ) . '/rk-visualizer/' . $name;
}

/** Delete stored results older than a week. Cheap: one directory listing. */
function rk_builder_viz_cleanup() {
	if ( ! function_exists( 'wp_upload_dir' ) ) { return; }
	$dir = wp_upload_dir();
	if ( ! empty( $dir['error'] ) ) { return; }
	$sub = rtrim( $dir['basedir'], '/' ) . '/rk-visualizer';
	if ( ! is_dir( $sub ) ) { return; }
	foreach ( (array) glob( $sub . '/*.{jpg,png,webp}', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) && time() - (int) filemtime( $f ) > 7 * 86400 ) { @unlink( $f ); }
	}
}

/**
 * Start a generation. Returns one of:
 *   array( 'status' => 'success', 'imageUrl' => ... )
 *   array( 'status' => 'pending', 'job' => array(...) )   // saved by the caller, polled by rk_builder_viz_poll()
 *   WP_Error
 */
function rk_builder_viz_start( array $s, array $upload, array $options ) {
	$prompt = rk_builder_viz_prompt( $options );
	$fail   = function ( $detail ) {
		if ( function_exists( 'error_log' ) ) { error_log( '[rk-builder visualizer] ' . $detail ); }
		$msg = 'We couldn\'t generate the visualization right now. Please try again.';
		// Only a signed-in administrator sees why (the provider's own words, never a key), so a wrong key or quota is easy to spot.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			foreach ( array( rk_builder_viz_gemini_key( $s ), rk_builder_viz_hf_token( $s ), isset( $s['custom_key'] ) ? (string) $s['custom_key'] : '' ) as $secret ) {
				if ( strlen( $secret ) > 6 ) { $detail = str_replace( $secret, '[key]', $detail ); }
			}
			$msg .= ' (Administrator detail: ' . substr( $detail, 0, 300 ) . ')';
		}
		return rk_builder_viz_error( 'rk_viz_provider', $msg, 502 );
	};
	if ( 'mock' === $s['provider'] ) {
		$url = rk_builder_viz_store_image( (string) file_get_contents( $upload['path'] ) );
		return '' === $url ? $fail( 'mock: could not store the image' ) : array( 'status' => 'success', 'imageUrl' => $url );
	}
	$uri = rk_builder_viz_data_uri( $upload['path'], $upload['mime'] );
	if ( 'gemini' === $s['provider'] ) {
		// generateContent with the photo inline: the model answers with an edited image in one (synchronous) call.
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $s['gemini_model'] ) . ':generateContent';
		$res = rk_builder_viz_http( 'POST', $url, array(
			'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => rk_builder_viz_gemini_key( $s ) ),
			'timeout' => (int) $s['timeout'],
			'body'    => wp_json_encode( array(
				'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ), array( 'inline_data' => array( 'mime_type' => $upload['mime'], 'data' => base64_encode( (string) file_get_contents( $upload['path'] ) ) ) ) ) ) ),
				'generationConfig' => array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) ),
			) ),
		) );
		if ( is_wp_error( $res ) ) { return $fail( 'gemini: ' . $res->get_error_message() ); }
		$j = json_decode( $res['body'], true );
		if ( $res['code'] < 200 || $res['code'] >= 300 || ! is_array( $j ) ) {
			$msg = is_array( $j ) && isset( $j['error']['message'] ) ? (string) $j['error']['message'] : substr( $res['body'], 0, 200 );
			return $fail( 'gemini: HTTP ' . $res['code'] . ' ' . $msg );
		}
		$data = rk_builder_viz_gemini_image( $j );
		if ( '' === $data ) {
			$why = isset( $j['promptFeedback']['blockReason'] ) ? 'blocked: ' . $j['promptFeedback']['blockReason'] : ( isset( $j['candidates'][0]['finishReason'] ) ? 'finish: ' . $j['candidates'][0]['finishReason'] : 'no image part' );
			return $fail( 'gemini: no image in the response (' . $why . ')' );
		}
		$url = rk_builder_viz_store_image( $data );
		return '' === $url ? $fail( 'gemini: could not store the image' ) : array( 'status' => 'success', 'imageUrl' => $url );
	}
	if ( 'custom' === $s['provider'] ) {
		$headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		if ( '' !== $s['custom_key'] ) { $headers[ $s['custom_header'] ] = 'Authorization' === $s['custom_header'] ? 'Bearer ' . $s['custom_key'] : $s['custom_key']; }
		$res = rk_builder_viz_http( 'POST', $s['custom_url'], array(
			'headers' => $headers, 'timeout' => (int) $s['timeout'],
			'body'    => wp_json_encode( array( 'prompt' => $prompt, 'image' => $uri, 'mimeType' => $upload['mime'], 'options' => $options ) ),
		) );
		if ( is_wp_error( $res ) ) { return $fail( 'custom: ' . $res->get_error_message() ); }
		$j = json_decode( $res['body'], true );
		if ( $res['code'] < 200 || $res['code'] >= 300 || ! is_array( $j ) ) { return $fail( 'custom: HTTP ' . $res['code'] . ' ' . substr( $res['body'], 0, 200 ) ); }
		$done = rk_builder_viz_custom_result( $j );
		if ( null !== $done ) { return '' === $done ? $fail( 'custom: no image in the response' ) : array( 'status' => 'success', 'imageUrl' => $done ); }
		$poll = isset( $j['statusUrl'] ) ? $j['statusUrl'] : ( isset( $j['status_url'] ) ? $j['status_url'] : '' );
		if ( rk_builder_viz_https( $poll ) ) { return array( 'status' => 'pending', 'job' => array( 'provider' => 'custom', 'status_url' => $poll ) ); }
		return $fail( 'custom: the response had neither an image nor a statusUrl' );
	}
	// Hugging Face router -> fal FLUX Kontext (queue).
	$token = rk_builder_viz_hf_token( $s );
	$res   = rk_builder_viz_http( 'POST', RK_BUILDER_VIZ_HF_URL, array(
		'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'timeout' => 45,
		'body'    => wp_json_encode( array( 'prompt' => $prompt, 'image_url' => $uri ) ),
	) );
	if ( is_wp_error( $res ) ) { return $fail( 'huggingface: ' . $res->get_error_message() ); }
	$j = json_decode( $res['body'], true );
	if ( $res['code'] < 200 || $res['code'] >= 300 || ! is_array( $j ) || empty( $j['status_url'] ) || empty( $j['response_url'] ) ) {
		return $fail( 'huggingface: submit failed, HTTP ' . $res['code'] . ' ' . substr( $res['body'], 0, 200 ) );
	}
	$st = rk_builder_viz_router_url( (string) $j['status_url'] );
	$rs = rk_builder_viz_router_url( (string) $j['response_url'] );
	if ( '' === $st || '' === $rs ) { return $fail( 'huggingface: unexpected queue URLs' ); }
	return array( 'status' => 'pending', 'job' => array( 'provider' => 'huggingface', 'status_url' => $st, 'response_url' => $rs ) );
}

/** The first inline image (base64) in a Gemini generateContent response, or ''. Accepts both inlineData and inline_data. */
function rk_builder_viz_gemini_image( array $j ) {
	if ( empty( $j['candidates'] ) || ! is_array( $j['candidates'] ) ) { return ''; }
	foreach ( $j['candidates'] as $c ) {
		if ( empty( $c['content']['parts'] ) || ! is_array( $c['content']['parts'] ) ) { continue; }
		foreach ( $c['content']['parts'] as $part ) {
			$inline = isset( $part['inlineData'] ) ? $part['inlineData'] : ( isset( $part['inline_data'] ) ? $part['inline_data'] : null );
			if ( is_array( $inline ) && isset( $inline['data'] ) && is_string( $inline['data'] ) && '' !== $inline['data'] ) { return $inline['data']; }
		}
	}
	return '';
}

/** An image URL out of a custom backend's JSON: a string, '' for "finished but no image", null for "not finished". */
function rk_builder_viz_custom_result( array $j ) {
	$state = isset( $j['status'] ) && is_string( $j['status'] ) ? strtolower( $j['status'] ) : '';
	if ( in_array( $state, array( 'pending', 'processing', 'queued', 'running', 'in_progress' ), true ) ) { return null; }
	foreach ( array( 'imageUrl', 'image_url', 'url' ) as $k ) {
		if ( isset( $j[ $k ] ) && rk_builder_viz_https( $j[ $k ] ) ) { return $j[ $k ]; }
	}
	foreach ( array( 'image', 'b64_json', 'imageBase64' ) as $k ) {
		if ( isset( $j[ $k ] ) && is_string( $j[ $k ] ) ) { return rk_builder_viz_store_image( $j[ $k ] ); }
	}
	if ( isset( $j['images'][0]['url'] ) && rk_builder_viz_https( $j['images'][0]['url'] ) ) { return $j['images'][0]['url']; }
	if ( isset( $j['statusUrl'] ) || isset( $j['status_url'] ) ) { return null; }
	if ( in_array( $state, array( 'completed', 'succeeded', 'success', 'done', 'failed', 'error' ), true ) ) { return ''; }
	return isset( $j['status'] ) ? null : '';
}

/** Check on a pending job: array( status = pending|success|error, imageUrl? ). */
function rk_builder_viz_poll( array $s, array $job ) {
	$err = array( 'status' => 'error' );
	if ( 'custom' === $job['provider'] ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $s['custom_key'] ) { $headers[ $s['custom_header'] ] = 'Authorization' === $s['custom_header'] ? 'Bearer ' . $s['custom_key'] : $s['custom_key']; }
		$res = rk_builder_viz_http( 'GET', $job['status_url'], array( 'headers' => $headers, 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) { return array( 'status' => 'pending' ); } // a blip: keep waiting until the deadline
		$j = json_decode( $res['body'], true );
		if ( ! is_array( $j ) ) { return $err; }
		$done = rk_builder_viz_custom_result( $j );
		if ( null === $done ) { return array( 'status' => 'pending' ); }
		return '' === $done ? $err : array( 'status' => 'success', 'imageUrl' => $done );
	}
	$headers = array( 'Authorization' => 'Bearer ' . rk_builder_viz_hf_token( $s ), 'Content-Type' => 'application/json' );
	$res = rk_builder_viz_http( 'GET', $job['status_url'], array( 'headers' => $headers, 'timeout' => 20 ) );
	if ( is_wp_error( $res ) ) { return array( 'status' => 'pending' ); }
	$j = json_decode( $res['body'], true );
	if ( ! is_array( $j ) ) { return $err; }
	$state = isset( $j['status'] ) ? (string) $j['status'] : '';
	if ( 'ERROR' === $state || 'FAILED' === $state ) { return $err; }
	if ( 'COMPLETED' !== $state ) { return array( 'status' => 'pending' ); }
	$res = rk_builder_viz_http( 'GET', $job['response_url'], array( 'headers' => $headers, 'timeout' => 30 ) );
	if ( is_wp_error( $res ) ) { return $err; }
	$r   = json_decode( $res['body'], true );
	$url = is_array( $r ) && isset( $r['images'][0]['url'] ) ? $r['images'][0]['url'] : '';
	return rk_builder_viz_https( $url ) ? array( 'status' => 'success', 'imageUrl' => $url ) : $err;
}

/* ------------------------------------------------------------------ *
 * Leads
 * ------------------------------------------------------------------ */

/** { name, email, phone } or an array of field => message. */
function rk_builder_viz_validate_lead( $in ) {
	$in    = is_array( $in ) ? $in : array();
	$errs  = array();
	$name  = isset( $in['name'] ) && is_string( $in['name'] ) ? trim( $in['name'] ) : '';
	$email = isset( $in['email'] ) && is_string( $in['email'] ) ? strtolower( trim( $in['email'] ) ) : '';
	$phone = isset( $in['phone'] ) && is_string( $in['phone'] ) ? trim( $in['phone'] ) : '';
	if ( '' === $name ) { $errs['name'] = 'Please enter your name.'; } elseif ( strlen( $name ) > 120 ) { $errs['name'] = 'Please shorten your name to 120 characters or fewer.'; }
	if ( '' === $email ) { $errs['email'] = 'Please enter your email.'; } elseif ( 1 !== preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email ) || strlen( $email ) > 200 ) { $errs['email'] = 'Please enter a valid email address.'; }
	if ( '' !== $phone ) {
		if ( strlen( $phone ) > 30 ) { $errs['phone'] = 'Please shorten your phone number to 30 characters or fewer.'; }
		elseif ( strlen( preg_replace( '/[^0-9]/', '', $phone ) ) < 7 ) { $errs['phone'] = 'Please enter a valid phone number, or leave it blank.'; }
	}
	return $errs ? array( 'errors' => $errs ) : array( 'lead' => array( 'name' => $name, 'email' => $email, 'phone' => $phone ) );
}

function rk_builder_viz_leads() {
	$l = get_option( 'rk_builder_viz_leads', array() );
	return is_array( $l ) ? $l : array();
}

/** Store (or refresh) a lead, newest first, capped. Returns true when it was new. */
function rk_builder_viz_store_lead( array $lead ) {
	$all = rk_builder_viz_leads();
	$new = true;
	foreach ( $all as $i => $row ) { if ( isset( $row['email'] ) && $row['email'] === $lead['email'] ) { unset( $all[ $i ] ); $new = false; } }
	array_unshift( $all, array_merge( $lead, array( 'at' => gmdate( 'c' ), 'ip' => rk_builder_viz_ip() ) ) );
	update_option( 'rk_builder_viz_leads', array_slice( array_values( $all ), 0, RK_BUILDER_VIZ_MAX_LEADS ), false );
	return $new;
}

/** Remove one lead (by email) or all of them. Returns how many were removed. */
function rk_builder_viz_delete_leads( $email, $all = false ) {
	$leads = rk_builder_viz_leads();
	$keep  = $all ? array() : array_values( array_filter( $leads, function ( $row ) use ( $email ) { return ! isset( $row['email'] ) || $row['email'] !== strtolower( trim( (string) $email ) ); } ) );
	update_option( 'rk_builder_viz_leads', $keep, false );
	return count( $leads ) - count( $keep );
}

function rk_builder_viz_handle_delete_lead() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to do that.', 'rk-builder' ), '', array( 'response' => 403 ) ); }
	check_admin_referer( 'rk_builder_viz_delete_lead' );
	$all   = ! empty( $_POST['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$email = isset( $_POST['email'] ) ? (string) wp_unslash( $_POST['email'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$n     = rk_builder_viz_delete_leads( $email, $all );
	wp_safe_redirect( add_query_arg( 'rk_leads_deleted', (int) $n, admin_url( 'options-general.php?page=rk-builder-visualizer' ) ) );
	exit;
}

/* ------------------------------------------------------------------ *
 * REST
 * ------------------------------------------------------------------ */

function rk_builder_viz_register_routes() {
	$ns = RK_BUILDER_NS;
	register_rest_route( $ns, '/visualizer/quota', array( 'methods' => 'GET', 'callback' => 'rk_builder_viz_handle_quota', 'permission_callback' => '__return_true' ) );
	register_rest_route( $ns, '/visualizer/generate', array( 'methods' => 'POST', 'callback' => 'rk_builder_viz_handle_generate', 'permission_callback' => '__return_true' ) );
	register_rest_route( $ns, '/visualizer/status', array( 'methods' => 'GET', 'callback' => 'rk_builder_viz_handle_status', 'permission_callback' => '__return_true' ) );
	register_rest_route( $ns, '/visualizer/lead', array( 'methods' => 'POST', 'callback' => 'rk_builder_viz_handle_lead', 'permission_callback' => '__return_true' ) );
}

/** A response that must never be cached by a browser, a CDN or a page-cache plugin. */
function rk_builder_viz_respond( array $body, $status = 200 ) {
	$r = new WP_REST_Response( $body, $status );
	if ( method_exists( $r, 'header' ) ) { $r->header( 'Cache-Control', 'no-store, private' ); }
	return $r;
}

function rk_builder_viz_public_quota( array $q ) {
	return array( 'phase' => $q['phase'], 'canGenerate' => $q['canGenerate'], 'freeRemaining' => $q['freeRemaining'], 'cooldownUntil' => $q['cooldownUntil'], 'hasLead' => $q['hasLead'], 'message' => rk_builder_viz_quota_message( $q ) );
}

function rk_builder_viz_handle_quota( $req ) {
	$s = rk_builder_viz_settings();
	if ( ! rk_builder_viz_ready( $s ) ) { return rk_builder_viz_respond( array( 'available' => false ) ); }
	$st = rk_builder_viz_state( rk_builder_viz_visitor_id() );
	return rk_builder_viz_respond( array_merge( array( 'available' => true ), rk_builder_viz_public_quota( rk_builder_viz_quota( $st, $s, time() ) ) ) );
}

function rk_builder_viz_handle_generate( $req ) {
	$s = rk_builder_viz_settings();
	if ( ! rk_builder_viz_ready( $s ) ) { return rk_builder_viz_error( 'rk_viz_unavailable', 'The visualizer isn\'t available right now. Please call us and we\'ll help directly.', 503 ); }
	$upload = rk_builder_viz_read_upload( $req );
	if ( is_wp_error( $upload ) ) { return $upload; }
	$options = rk_builder_viz_read_options( $req );
	if ( is_wp_error( $options ) ) { return $options; }

	$now = time();
	$vid = rk_builder_viz_visitor_id();
	$st  = rk_builder_viz_state( $vid );
	$q   = rk_builder_viz_quota( $st, $s, $now );
	if ( ! $q['canGenerate'] ) {
		return rk_builder_viz_respond( array_merge( array( 'kind' => 'quota_denied' ), rk_builder_viz_public_quota( $q ) ), 429 );
	}
	if ( ! rk_builder_viz_ip_allow( $s, $now ) ) {
		return rk_builder_viz_respond( array( 'kind' => 'quota_denied', 'phase' => 'rate_limited', 'canGenerate' => false, 'freeRemaining' => $q['freeRemaining'], 'cooldownUntil' => null, 'hasLead' => $q['hasLead'], 'message' => 'Too many requests from your network just now. Please try again in a little while.' ), 429 );
	}
	rk_builder_viz_cleanup();
	// Spend it now so parallel requests can't overspend; give it back if the provider fails.
	rk_builder_viz_save_state( $vid, rk_builder_viz_consume( $st, $q['phase'], $s, $now ) );
	$started = rk_builder_viz_start( $s, $upload, $options );
	if ( is_wp_error( $started ) ) {
		rk_builder_viz_save_state( $vid, rk_builder_viz_refund( rk_builder_viz_state( $vid ), $q['phase'], $st ) );
		return $started;
	}
	$after = rk_builder_viz_public_quota( rk_builder_viz_quota( rk_builder_viz_state( $vid ), $s, $now ) );
	if ( 'success' === $started['status'] ) { return rk_builder_viz_respond( array( 'status' => 'success', 'imageUrl' => $started['imageUrl'], 'quota' => $after ) ); }
	$id = bin2hex( random_bytes( 12 ) );
	set_transient( 'rk_viz_job_' . $id, array_merge( $started['job'], array( 'vid' => $vid, 'phase' => $q['phase'], 'before' => $st, 'started' => $now ) ), 3600 );
	return rk_builder_viz_respond( array( 'status' => 'pending', 'job' => $id, 'quota' => $after ) );
}

function rk_builder_viz_handle_status( $req ) {
	$s   = rk_builder_viz_settings();
	$id  = (string) $req->get_param( 'job' );
	$job = 1 === preg_match( '/^[a-f0-9]{24}$/', $id ) ? get_transient( 'rk_viz_job_' . $id ) : false;
	$vid = rk_builder_viz_visitor_id();
	if ( ! is_array( $job ) || ! isset( $job['vid'] ) || $job['vid'] !== $vid ) { return rk_builder_viz_error( 'rk_viz_no_job', 'That visualization isn\'t available any more. Please try again.', 404 ); }
	$res = rk_builder_viz_poll( $s, $job );
	if ( 'pending' === $res['status'] && time() - (int) $job['started'] > (int) $s['timeout'] ) { $res = array( 'status' => 'error', 'timeout' => true ); }
	if ( 'pending' === $res['status'] ) { return rk_builder_viz_respond( array( 'status' => 'pending' ) ); }
	delete_transient( 'rk_viz_job_' . $id );
	if ( 'success' === $res['status'] ) { return rk_builder_viz_respond( array( 'status' => 'success', 'imageUrl' => $res['imageUrl'] ) ); }
	rk_builder_viz_save_state( $vid, rk_builder_viz_refund( rk_builder_viz_state( $vid ), $job['phase'], $job['before'] ) );
	if ( ! empty( $res['timeout'] ) ) { return rk_builder_viz_error( 'rk_viz_timeout', 'The visualization is taking longer than expected. Please try again.', 504 ); }
	return rk_builder_viz_error( 'rk_viz_provider', 'We couldn\'t generate the visualization right now. Please try again.', 502 );
}

function rk_builder_viz_handle_lead( $req ) {
	$s = rk_builder_viz_settings();
	if ( ! rk_builder_viz_ready( $s ) ) { return rk_builder_viz_error( 'rk_viz_unavailable', 'The visualizer isn\'t available right now.', 503 ); }
	if ( ! rk_builder_viz_ip_allow( $s, time() ) ) { return rk_builder_viz_error( 'rk_viz_rate', 'Too many requests. Please try again in a little while.', 429 ); }
	$in  = $req->get_json_params();
	$chk = rk_builder_viz_validate_lead( is_array( $in ) ? $in : array() );
	if ( isset( $chk['errors'] ) ) { return new WP_Error( 'rk_viz_lead_invalid', 'Please check the highlighted fields.', array( 'status' => 400, 'fields' => $chk['errors'] ) ); }
	$vid = rk_builder_viz_visitor_id();
	$st  = rk_builder_viz_state( $vid );
	$new = rk_builder_viz_store_lead( $chk['lead'] );
	if ( ! $st['lead'] ) { $st['lead'] = true; rk_builder_viz_save_state( $vid, $st ); }
	if ( $new ) { rk_builder_viz_notify_lead( $s, $chk['lead'] ); }
	return rk_builder_viz_respond( array_merge( array( 'ok' => true ), rk_builder_viz_public_quota( rk_builder_viz_quota( $st, $s, time() ) ) ) );
}

function rk_builder_viz_notify_lead( array $s, array $lead ) {
	if ( ! function_exists( 'wp_mail' ) ) { return; }
	$to = '' !== $s['notify_email'] ? $s['notify_email'] : ( function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '' );
	if ( '' === $to ) { return; }
	$clean = function ( $v ) { return str_replace( array( "\r", "\n" ), ' ', (string) $v ); };
	$body  = "A visitor unlocked another visualization.\n\nName: " . $clean( $lead['name'] ) . "\nEmail: " . $clean( $lead['email'] ) . "\nPhone: " . ( '' !== $lead['phone'] ? $clean( $lead['phone'] ) : '-' ) . "\n";
	wp_mail( $to, 'New visualizer lead', $body );
}

/* ------------------------------------------------------------------ *
 * Page script: only on pages that carry the block
 * ------------------------------------------------------------------ */

/** Whether a layout has a visualizer block, directly or inside a reusable block. */
function rk_builder_layout_has_visualizer( array $layout ) {
	if ( empty( $layout['blocks'] ) || ! is_array( $layout['blocks'] ) ) { return false; }
	foreach ( $layout['blocks'] as $b ) {
		if ( is_array( $b ) && isset( $b['type'] ) && 'visualizer' === $b['type'] ) { return true; }
	}
	return false;
}

function rk_builder_viz_enqueue( array $layout ) {
	if ( ! rk_builder_layout_has_visualizer( $layout ) ) { return; }
	wp_register_script( 'rk-builder-viz', false, array(), RK_BUILDER_VERSION, true );
	wp_enqueue_script( 'rk-builder-viz' );
	wp_add_inline_script( 'rk-builder-viz', 'window.rkViz=' . wp_json_encode( array( 'api' => rest_url( RK_BUILDER_NS . '/visualizer' ) ) ) . ';' . rk_builder_viz_script() );
}

/** Nowdoc so the JavaScript needs no PHP escaping. */
function rk_builder_viz_script() {
	$js = <<<'JS'
(function(){
var cfg=window.rkViz;if(!cfg)return;
document.querySelectorAll("[data-viz-form]").forEach(function(form){
var root=form.closest(".pf-viz"),$=function(s){return root.querySelector(s)};
var hint=$("[data-viz-hint]"),err=$("[data-viz-error]"),quota=$("[data-viz-quota]"),btn=$("[data-viz-submit]"),img=$("[data-viz-img]"),empty=$("[data-viz-empty]"),badge=$("[data-viz-badge]"),busy=$("[data-viz-busy]"),done=$("[data-viz-done]"),orig=$("[data-viz-orig]"),custom=$("[data-viz-custom]"),lead=$("[data-viz-lead]"),leadForm=$("[data-viz-leadform]"),leadErr=$("[data-viz-lead-error]"),file=form.querySelector('input[type=file]');
var photo=null,label=btn.firstChild.textContent,loading=false,preview=null;
var names=["roomType","projectType","preferredStyle","woodSpecies","floorDirection","finishPreference","sheen"];
function show(el,on){if(el)el.hidden=!on}
function say(el,msg){el.textContent=msg||"";show(el,!!msg)}
function pick(n){var c=form.querySelector('input[name="'+n+'"]:checked');return c?c.value:""}
function setBusy(on){loading=on;show(busy,on);btn.disabled=on;btn.firstChild.textContent=on?"Creating your floor visualization…":label;file.disabled=on}
function setQuota(q){if(!q)return;quota.textContent=q.message||"";quota.setAttribute("data-phase",q.phase||"")}
function api(path,opt){return fetch(cfg.api+path,Object.assign({credentials:"same-origin"},opt||{}))}
function problem(r,j){return (j&&j.message)||"We couldn't generate the visualization right now. Please try again."}
api("/quota").then(function(r){return r.json()}).then(function(q){if(q&&q.available===false){btn.disabled=true;quota.textContent="The visualizer isn't available right now. Please call us and we'll help directly."}else setQuota(q)}).catch(function(){});
form.addEventListener("change",function(e){if(e.target.name==="preferredStyle")show(custom,pick("preferredStyle")==="custom")});
file.addEventListener("change",function(){
 say(err,"");done.hidden=true;photo=null;var f=file.files&&file.files[0];if(!f)return;
 if(["image/jpeg","image/png","image/webp"].indexOf(f.type)<0){say(err,"This file type isn't supported. Please upload a JPG, PNG, or WebP image.");file.value="";return}
 if(f.size>10*1024*1024){say(err,"This image is too large. Please choose an image under 10 MB.");file.value="";return}
 hint.textContent="Checking your photo…";var u=URL.createObjectURL(f),t=new Image();
 t.onload=function(){if(t.naturalWidth<640||t.naturalHeight<480){URL.revokeObjectURL(u);hint.textContent="Your photo stays in this browser preview until you generate a visualization.";say(err,"This image is too small for a reliable preview. Please choose a higher-resolution room photo.");file.value="";return}
  if(preview)URL.revokeObjectURL(preview);preview=u;photo=f;hint.textContent="Selected: "+f.name+" ("+t.naturalWidth+"×"+t.naturalHeight+")";img.src=u;img.alt="Preview of your uploaded room photo";show(img,true);show(empty,false);badge.textContent="Approximate color preview"};
 t.onerror=function(){URL.revokeObjectURL(u);hint.textContent="Your photo stays in this browser preview until you generate a visualization.";say(err,"We couldn't read this image. Please choose another photo.");file.value=""};t.src=u});
function collect(){var o={};names.forEach(function(n){o[n]=pick(n)});o.serviceCity=pick("serviceCity");o.customStyleDescription=(form.elements.customStyleDescription.value||"").trim();var sq=form.elements.squareFootage.value;o.squareFootage=sq===""?0:Number(sq);return o}
function validate(o){if(!photo)return"Please upload a room photo.";for(var i=0;i<names.length;i++)if(!o[names[i]])return"Please choose an option for each question before generating.";if(o.preferredStyle==="custom"&&!o.customStyleDescription)return"Please describe the style you want.";if(o.squareFootage<0||isNaN(o.squareFootage))return"Please enter a valid square footage, or leave it blank.";return""}
function finish(url){var im=new Image();im.onload=function(){setBusy(false);img.src=url;img.alt="AI-assisted visualization of your room with new hardwood flooring";show(img,true);show(empty,false);badge.textContent="Generated visualization";if(orig&&photo){orig.src=URL.createObjectURL(photo)}done.hidden=false};im.onerror=function(){setBusy(false);say(err,"The visualization was generated, but it could not be displayed. Please try again.")};im.src=url}
function poll(job,t0){if(Date.now()-t0>300000){setBusy(false);say(err,"The visualization is taking longer than expected. Please try again.");return}
 setTimeout(function(){api("/status?job="+encodeURIComponent(job)).then(function(r){return r.json().then(function(j){return{r:r,j:j}})}).then(function(x){if(x.j&&x.j.status==="pending")return poll(job,t0);if(x.j&&x.j.status==="success")return finish(x.j.imageUrl);setBusy(false);say(err,problem(x.r,x.j));api("/quota").then(function(r){return r.json()}).then(setQuota)}).catch(function(){poll(job,t0)})},3000)}
function generate(){var o=collect(),bad=validate(o);if(bad){say(err,bad);return}say(err,"");done.hidden=true;setBusy(true);
 var fd=new FormData();fd.append("image",photo);fd.append("options",JSON.stringify(o));
 api("/generate",{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{r:r,j:j}})}).then(function(x){var j=x.j||{};
  if(j.kind==="quota_denied"){setBusy(false);setQuota(j);if(j.phase==="needs_lead"){say(leadErr,"");if(lead.showModal)lead.showModal()}else say(err,j.message);return}
  if(j.quota)setQuota(j.quota);
  if(j.status==="success")return finish(j.imageUrl);
  if(j.status==="pending")return poll(j.job,Date.now());
  setBusy(false);say(err,problem(x.r,j))}).catch(function(){setBusy(false);say(err,"We couldn't reach the visualizer. Please check your connection and try again.")})}
form.addEventListener("submit",function(e){e.preventDefault();if(!loading)generate()});
if(leadForm)leadForm.addEventListener("submit",function(e){e.preventDefault();say(leadErr,"");
 var body={name:leadForm.elements.name.value,email:leadForm.elements.email.value,phone:leadForm.elements.phone.value};
 api("/lead",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)}).then(function(r){return r.json().then(function(j){return{r:r,j:j}})}).then(function(x){
  if(x.r.ok&&x.j&&x.j.ok){setQuota(x.j);lead.close();generate();return}
  var f=x.j&&x.j.data&&x.j.data.fields;say(leadErr,f?Object.keys(f).map(function(k){return f[k]}).join(" "):problem(x.r,x.j))}).catch(function(){say(leadErr,"We couldn't send that. Please try again.")})})
});
})();
JS;
	return trim( $js );
}

/* ------------------------------------------------------------------ *
 * Settings screen (Settings > Visualizer) and leads list
 * ------------------------------------------------------------------ */

function rk_builder_viz_register_admin() {
	add_options_page( 'RK Builder Visualizer', 'RK Visualizer', 'manage_options', 'rk-builder-visualizer', 'rk_builder_viz_render_admin' );
}

function rk_builder_viz_register_setting() {
	register_setting( 'rk_builder_viz', 'rk_builder_visualizer', array( 'type' => 'array', 'sanitize_callback' => 'rk_builder_viz_sanitize', 'default' => rk_builder_viz_defaults(), 'show_in_rest' => false ) );
}

function rk_builder_viz_field_row( $label, $html, $help = '' ) {
	return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
}

function rk_builder_viz_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$s   = rk_builder_viz_settings();
	$n   = 'rk_builder_visualizer';
	$out = '<div class="wrap"><h1>' . esc_html__( 'AI flooring visualizer', 'rk-builder' ) . '</h1>';
	$out .= '<p>' . esc_html__( 'Add the "AI flooring visualizer" block to a page, then choose where the images come from. Visitors are limited per browser and per IP address.', 'rk-builder' ) . '</p>';
	$out .= '<form method="post" action="options.php">';
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
	settings_fields( 'rk_builder_viz' );
	$rows  = rk_builder_viz_field_row( 'Visualizer', '<label><input type="checkbox" name="' . $n . '[enabled]" value="1"' . ( $s['enabled'] ? ' checked' : '' ) . '> ' . esc_html__( 'Turn the visualizer on', 'rk-builder' ) . '</label>' );
	$opts  = '';
	foreach ( rk_builder_viz_providers() as $k => $label ) { $opts .= '<option value="' . esc_attr( $k ) . '"' . ( $s['provider'] === $k ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>'; }
	$rows .= rk_builder_viz_field_row( 'Image backend', '<select name="' . $n . '[provider]">' . $opts . '</select>', 'Hugging Face and Gemini each need their own key. "My own backend API" posts the photo to a URL you control. Test mode costs nothing and returns the uploaded photo.' );
	$has_hf = '' !== rk_builder_viz_hf_token( $s );
	$rows .= rk_builder_viz_field_row( 'Hugging Face token', '<input type="password" class="regular-text" autocomplete="new-password" name="' . $n . '[hf_token]" placeholder="' . ( $has_hf ? '•••••••• (saved)' : '' ) . '"> <label><input type="checkbox" name="' . $n . '[clear_hf_token]" value="1"> ' . esc_html__( 'Remove the saved token', 'rk-builder' ) . '</label>', 'Or define RK_BUILDER_VIZ_HF_TOKEN in wp-config.php, or set the HF_TOKEN environment variable. Those win over this field.' );
	$has_gem = '' !== rk_builder_viz_gemini_key( $s );
	$rows .= rk_builder_viz_field_row( 'Gemini API key', '<input type="password" class="regular-text" autocomplete="new-password" name="' . $n . '[gemini_key]" placeholder="' . ( $has_gem ? '•••••••• (saved)' : '' ) . '"> <label><input type="checkbox" name="' . $n . '[clear_gemini_key]" value="1"> ' . esc_html__( 'Remove the saved key', 'rk-builder' ) . '</label>', 'From Google AI Studio. Or define RK_BUILDER_VIZ_GEMINI_KEY in wp-config.php, or set GEMINI_API_KEY in the environment; those win over this field.' );
	$rows .= rk_builder_viz_field_row( 'Gemini model', '<input type="text" class="regular-text" name="' . $n . '[gemini_model]" value="' . esc_attr( $s['gemini_model'] ) . '">', 'An image-editing model that accepts a photo and returns an image, for example gemini-2.5-flash-image.' );
	$rows .= rk_builder_viz_field_row( 'Backend API URL', '<input type="url" class="regular-text" name="' . $n . '[custom_url]" value="' . esc_attr( $s['custom_url'] ) . '" placeholder="https://api.example.com/visualize">', 'Receives JSON {prompt, image (data URI), mimeType, options}. Reply with {imageUrl}, {image: base64 or data URI}, or {statusUrl} to be polled until it returns one of those.' );
	$rows .= rk_builder_viz_field_row( 'Backend API key', '<input type="password" class="regular-text" autocomplete="new-password" name="' . $n . '[custom_key]" placeholder="' . ( '' !== $s['custom_key'] ? '•••••••• (saved)' : '' ) . '"> <label><input type="checkbox" name="' . $n . '[clear_custom_key]" value="1"> ' . esc_html__( 'Remove the saved key', 'rk-builder' ) . '</label>' );
	$rows .= rk_builder_viz_field_row( 'Key header', '<input type="text" class="regular-text" name="' . $n . '[custom_header]" value="' . esc_attr( $s['custom_header'] ) . '">', 'Authorization sends "Bearer <key>"; any other header name sends the key as is (for example X-API-Key).' );
	$rows .= rk_builder_viz_field_row( 'Free visualizations', '<input type="number" min="0" max="20" name="' . $n . '[free_count]" value="' . (int) $s['free_count'] . '">', 'Per visitor, before they are asked for contact details.' );
	$rows .= rk_builder_viz_field_row( 'Bonus after contact details', '<input type="number" min="0" max="20" name="' . $n . '[bonus_count]" value="' . (int) $s['bonus_count'] . '">', 'Set to 0 to skip the contact form and go straight to the waiting period.' );
	$rows .= rk_builder_viz_field_row( 'Waiting period (hours)', '<input type="number" min="1" max="720" name="' . $n . '[cooldown_hours]" value="' . (int) $s['cooldown_hours'] . '">', 'After that, a visitor gets one more each time it passes.' );
	$rows .= rk_builder_viz_field_row( 'Requests per IP per hour', '<input type="number" min="1" max="1000" name="' . $n . '[ip_per_hour]" value="' . (int) $s['ip_per_hour'] . '">' );
	$rows .= rk_builder_viz_field_row( 'Give up after (seconds)', '<input type="number" min="20" max="600" name="' . $n . '[timeout]" value="' . (int) $s['timeout'] . '">' );
	$rows .= rk_builder_viz_field_row( 'Email new leads to', '<input type="email" class="regular-text" name="' . $n . '[notify_email]" value="' . esc_attr( $s['notify_email'] ) . '">', 'Leave blank to use the site admin email.' );
	echo '<table class="form-table" role="presentation">' . $rows . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput
	submit_button();
	echo '</form>';
	echo '<h2>' . esc_html__( 'Leads', 'rk-builder' ) . '</h2>';
	if ( isset( $_GET['rk_leads_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$n = (int) $_GET['rk_leads_deleted']; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d lead deleted.', '%d leads deleted.', $n, 'rk-builder' ), $n ) ) . '</p></div>';
	}
	$all_leads = rk_builder_viz_leads();
	$leads     = array_slice( $all_leads, 0, 50 );
	if ( ! $leads ) { echo '<p>' . esc_html__( 'No leads yet.', 'rk-builder' ) . '</p></div>'; return; }
	$action = esc_url( admin_url( 'admin-post.php' ) );
	echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>When (UTC)</th><th></th></tr></thead><tbody>';
	foreach ( $leads as $l ) {
		echo '<tr><td>' . esc_html( $l['name'] ) . '</td><td>' . esc_html( $l['email'] ) . '</td><td>' . esc_html( $l['phone'] ) . '</td><td>' . esc_html( isset( $l['at'] ) ? $l['at'] : '' ) . '</td><td>';
		echo '<form method="post" action="' . $action . '" style="margin:0"><input type="hidden" name="action" value="rk_builder_viz_delete_lead"><input type="hidden" name="email" value="' . esc_attr( $l['email'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'rk_builder_viz_delete_lead' );
		echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Delete this lead?\')">' . esc_html__( 'Delete', 'rk-builder' ) . '</button></form></td></tr>';
	}
	echo '</tbody></table>';
	if ( count( $all_leads ) > count( $leads ) ) { echo '<p class="description">' . esc_html( sprintf( 'Showing the newest %d of %d.', count( $leads ), count( $all_leads ) ) ) . '</p>'; }
	echo '<form method="post" action="' . $action . '" style="margin-top:12px"><input type="hidden" name="action" value="rk_builder_viz_delete_lead"><input type="hidden" name="all" value="1">'; // phpcs:ignore WordPress.Security.EscapeOutput
	wp_nonce_field( 'rk_builder_viz_delete_lead' );
	echo '<button type="submit" class="button" onclick="return confirm(\'Delete ALL leads? This cannot be undone.\')">' . esc_html__( 'Delete all leads', 'rk-builder' ) . '</button></form></div>';
}

add_action( 'rest_api_init', 'rk_builder_viz_register_routes' );
add_action( 'admin_menu', 'rk_builder_viz_register_admin' );
add_action( 'admin_init', 'rk_builder_viz_register_setting' );
add_action( 'admin_post_rk_builder_viz_delete_lead', 'rk_builder_viz_handle_delete_lead' );
