<?php
/** Google Places search and import for the local business card. */

function rk_places_fake( $search_seen = null ) {
	add_filter( 'rk_builder_http', function ( $pre, $method, $url, $args ) {
		$GLOBALS['rk_places_seen'] = array( $method, $url, $args );
		if ( false !== strpos( $url, 'places:searchText' ) ) {
			return array( 'code' => 200, 'body' => json_encode( array( 'places' => array(
				array( 'id' => 'ChIJabcdefghijk123', 'displayName' => array( 'text' => 'Peoria <b>Hardwood</b> Floors' ), 'formattedAddress' => '1 Main St, Peoria, IL', 'rating' => 4.86, 'userRatingCount' => 120 ),
				array( 'id' => 'bad id', 'displayName' => array( 'text' => 'Junk' ) ),
			) ) ) );
		}
		return array( 'code' => 200, 'body' => json_encode( array(
			'id' => 'ChIJabcdefghijk123', 'displayName' => array( 'text' => 'Peoria Hardwood Floors' ), 'nationalPhoneNumber' => '(309) 555-0100',
			'googleMapsUri' => 'https://maps.google.com/?cid=42', 'rating' => 4.86, 'userRatingCount' => 120,
			'addressComponents' => array(
				array( 'longText' => '1', 'types' => array( 'street_number' ) ),
				array( 'longText' => 'Main Street', 'types' => array( 'route' ) ),
				array( 'longText' => 'Peoria', 'types' => array( 'locality' ) ),
				array( 'longText' => 'Illinois', 'shortText' => 'IL', 'types' => array( 'administrative_area_level_1' ) ),
				array( 'longText' => '61602', 'types' => array( 'postal_code' ) ),
				array( 'longText' => 'United States', 'shortText' => 'US', 'types' => array( 'country' ) ),
			),
			'regularOpeningHours' => array( 'periods' => array(
				array( 'open' => array( 'day' => 1, 'hour' => 8, 'minute' => 0 ), 'close' => array( 'day' => 1, 'hour' => 17, 'minute' => 0 ) ),
				array( 'open' => array( 'day' => 2, 'hour' => 8, 'minute' => 0 ), 'close' => array( 'day' => 2, 'hour' => 17, 'minute' => 0 ) ),
				array( 'open' => array( 'day' => 3, 'hour' => 8, 'minute' => 0 ), 'close' => array( 'day' => 3, 'hour' => 17, 'minute' => 0 ) ),
				array( 'open' => array( 'day' => 6, 'hour' => 9, 'minute' => 0 ), 'close' => array( 'day' => 6, 'hour' => 13, 'minute' => 0 ) ),
			) ),
		) ) );
	}, 10, 4 );
}

rk_test( 'places: needs an admin, a key and a real query', function () {
	rk_test_login( 'anon' );
	t_err( rk_post( '/rk/v1/builder/places/search', array( 'query' => 'Peoria' ) ), 'rk_unauthorized', 401 );
	rk_test_login( 'admin' );
	delete_option( 'rk_builder_reviews_key' );
	t_err( rk_post( '/rk/v1/builder/places/search', array( 'query' => 'Peoria' ) ), 'rk_conflict', 409 );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123' ) ) );
	t_err( rk_post( '/rk/v1/builder/places/search', array( 'query' => 'ab' ) ), 'rk_invalid_places', 400 );
	t_err( rk_post( '/rk/v1/builder/places/import', array( 'placeId' => '../x' ) ), 'rk_invalid_places', 400 );
} );

rk_test( 'places: search returns clean matches, key in a header', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123' ) ) );
	rk_places_fake();
	$r = t_ok( rk_post( '/rk/v1/builder/places/search', array( 'query' => 'Peoria Hardwood' ) ) );
	t_eq( $GLOBALS['rk_places_seen'][0], 'POST' );
	t_eq( $GLOBALS['rk_places_seen'][2]['headers']['X-Goog-Api-Key'], 'AIzaFakeKeyFakeKeyFakeKeyFake123' );
	t_eq( count( $r['results'] ), 1, 'the entry with an invalid id is dropped' );
	t_eq( $r['results'][0]['name'], 'Peoria Hardwood Floors' );
	t_eq( $r['results'][0]['count'], 120 );
} );

rk_test( 'places: import fills the fields, stores rating and feeds the LocalBusiness JSON-LD', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123' ) ) );
	rk_places_fake();
	$r = t_ok( rk_post( '/rk/v1/builder/places/import', array( 'placeId' => 'ChIJabcdefghijk123' ) ) );
	t_eq( $GLOBALS['rk_places_seen'][1], 'https://places.googleapis.com/v1/places/ChIJabcdefghijk123' );
	$f = $r['fields'];
	t_eq( $f['street'], '1 Main Street' );
	t_eq( $f['city'], 'Peoria' );
	t_eq( $f['region'], 'IL' );
	t_eq( $f['postal'], '61602' );
	t_eq( $f['country'], 'US' );
	t_eq( $f['telephone'], '(309) 555-0100' );
	t_eq( $f['hours'], "Mon-Wed 08:00-17:00\nSat 09:00-13:00" );
	t_eq( count( rk_builder_parse_hours( $f['hours'] ) ), 2, 'the hours text round-trips through the schema parser' );
	t_eq( $r['rating'], 4.9 );
	$store = rk_builder_reviews_store();
	t_eq( $store['placeId'], 'ChIJabcdefghijk123' );
	t_eq( $store['summary'], array( 'rating' => 4.9, 'count' => 120 ) );
	// the owner saves the imported fields, then the structured data carries rating and map link
	t_ok( rk_post( '/rk/v1/builder/site', array( 'organization' => array( 'name' => $f['name'], 'street' => $f['street'], 'city' => $f['city'], 'region' => $f['region'], 'postal' => $f['postal'], 'country' => $f['country'], 'hours' => $f['hours'], 'profiles' => array( 'googleBusiness' => $f['googleBusiness'] ) ) ) ) );
	$nodes = rk_builder_seo_site_nodes( array( 'site_name' => 'Test' ) )['graph'];
	$lb = null;
	foreach ( $nodes as $n ) { if ( isset( $n['@id'] ) && '#localbusiness' === substr( $n['@id'], -14 ) ) { $lb = $n; } }
	t_assert( null !== $lb, 'LocalBusiness node exists' );
	t_eq( $lb['aggregateRating']['ratingValue'], 4.9 );
	t_eq( $lb['aggregateRating']['reviewCount'], 120 );
	t_eq( $lb['hasMap'], 'https://maps.google.com/?cid=42' );
} );

rk_test( 'places: a rate limit and an upstream error are reported kindly', function () {
	rk_test_login( 'admin' );
	t_ok( rk_post( '/rk/v1/builder/reviews-admin', array( 'apiKey' => 'AIzaFakeKeyFakeKeyFakeKeyFake123' ) ) );
	add_filter( 'rk_builder_http', function () { return array( 'code' => 429, 'body' => '{}' ); }, 30, 4 );
	$e = rk_post( '/rk/v1/builder/places/search', array( 'query' => 'Peoria Hardwood' ) );
	t_err( $e, 'rk_upstream', 429 );
} );

rk_test( 'places link: reads name, CID and Place ID from a pasted Maps link without a key', function () {
	rk_test_login( 'admin' );
	delete_option( 'rk_builder_reviews_key' );
	$p = rk_builder_places_parse_link( 'https://www.google.com/maps/place/Peoria+Hardwood+Floors/@40.69,-89.58,17z/data=!3m1!4b1!4m6!3m5!1s0x880a5b7d0d1d1d1d:0x1f4a1b0c2d3e4f50!8m2' );
	t_eq( $p['name'], 'Peoria Hardwood Floors' );
	t_eq( $p['cid'], '2254644302564970320', 'hex CID converted without overflow' );
	t_eq( rk_builder_places_hex2dec( 'ff' ), '255' );
	t_eq( rk_builder_places_parse_link( 'https://www.google.com/maps/search/?api=1&query=x&query_place_id=ChIJabcdefghijk123' )['placeId'], 'ChIJabcdefghijk123' );
	t_eq( rk_builder_places_parse_link( 'https://maps.google.com/?cid=12345678901234' )['cid'], '12345678901234' );
} );

rk_test( 'places link: short links are followed, only between Google hosts; other hosts are refused', function () {
	rk_test_login( 'admin' );
	t_err( rk_post( '/rk/v1/builder/places/link', array( 'url' => 'https://evil.example/maps/place/X' ) ), 'rk_invalid_places', 400 );
	$hits = array();
	add_filter( 'rk_builder_http', function ( $pre, $method, $url ) use ( &$hits ) {
		$hits[] = $url;
		return array( 'code' => 302, 'body' => '', 'location' => 'https://www.google.com/maps/place/Peoria+Hardwood+Floors/@1,2,17z/data=!1s0x1:0xff' );
	}, 10, 4 );
	$r = t_ok( rk_post( '/rk/v1/builder/places/link', array( 'url' => 'https://maps.app.goo.gl/abc123' ) ) );
	t_eq( $hits, array( 'https://maps.app.goo.gl/abc123' ) );
	t_eq( $r['name'], 'Peoria Hardwood Floors' );
	t_eq( $r['profileUrl'], 'https://maps.google.com/?cid=255' );
	t_eq( rk_builder_reviews_store()['profileUrl'], 'https://maps.google.com/?cid=255' );
	$r2 = t_ok( rk_post( '/rk/v1/builder/places/link', array( 'url' => 'ChIJabcdefghijk123' ) ) );
	t_eq( $r2['writeUrl'], 'https://search.google.com/local/writereview?placeid=ChIJabcdefghijk123' );
} );

rk_test( 'places link: a share.google link ends at a search page with a knowledge-panel id', function () {
	rk_test_login( 'admin' );
	$n = 0;
	add_filter( 'rk_builder_http', function ( $pre, $method, $url ) use ( &$n ) {
		$n++;
		$to = false === strpos( $url, 'share.google?' ) ? 'https://www.google.com/share.google?q=Up' : 'https://www.google.com/search?kgmid=/g/1tdxkj4m&q=Peoria+Hardwood+Floors&source=sh';
		return array( 'code' => 301, 'body' => '', 'location' => $to );
	}, 10, 4 );
	$r = t_ok( rk_post( '/rk/v1/builder/places/link', array( 'url' => 'https://share.google/UpNJyIQ4JPjrhxPWo' ) ) );
	t_eq( $r['name'], 'Peoria Hardwood Floors' );
	t_eq( $r['profileUrl'], 'https://www.google.com/search?kgmid=/g/1tdxkj4m&q=Peoria%20Hardwood%20Floors' );
} );

rk_test( 'places link: a short link Google will not open is explained and nothing is saved', function () {
	rk_test_login( 'admin' );
	update_option( 'rk_builder_reviews', array( 'profileUrl' => 'https://old.example/p' ), false );
	$n = 0;
	add_filter( 'rk_builder_http', function () use ( &$n ) {
		return 0 === $n++ ? array( 'code' => 302, 'body' => '', 'location' => 'https://www.google.com/share.google?q=Up' ) : array( 'code' => 403, 'body' => 'Sorry', 'location' => '' );
	}, 10, 4 );
	t_err( rk_post( '/rk/v1/builder/places/link', array( 'url' => 'https://share.google/Zzzzzzzz' ) ), 'rk_upstream', 422 );
	t_eq( rk_builder_reviews_store()['profileUrl'], 'https://old.example/p' );
} );

rk_test( 'places: a Maps link with a feature id gives the Place ID without any key', function () {
	t_eq( 'ChIJOz6gO_Uts1IRYXYcjtXKV4Q', rk_builder_places_fid_to_place_id( '52b32df53ba03e3b', '8457cad58e1c7661' ) );
	t_eq( 'ChIJN1t_tDeuEmsR', substr( rk_builder_places_fid_to_place_id( '6b12ae37b47f5b37', '8eaddfcd1b32ca52' ), 0, 16 ) );
	$i = rk_builder_places_parse_link( 'https://www.google.com/maps/place/Peoria+Hardwood+Floors/@45.9,-91.7,6z/data=!3m1!4b1!4m6!3m5!1s0x52b32df53ba03e3b:0x8457cad58e1c7661!8m2!3d45.9!4d-91.7' );
	t_eq( 'ChIJOz6gO_Uts1IRYXYcjtXKV4Q', $i['placeId'] );
	t_eq( '', rk_builder_places_fid_to_place_id( 'zz', '12' ) );
} );

rk_test( 'reviews widget: header, rating, cards with photo or initial, write link, carousel buttons; plain grid is unchanged', function () {
	rk_test_login( 'admin' );
	update_option( 'rk_builder_reviews', array( 'placeId' => 'ChIJabcdefghijk123', 'profileUrl' => 'https://maps.google.com/?cid=1', 'summary' => array( 'rating' => 5.0, 'count' => 18 ), 'items' => array(
		array( 'id' => 'g1', 'author' => 'Ann Lee', 'rating' => 5, 'text' => 'Great floors', 'date' => '2 weeks ago', 'source' => 'google', 'url' => '', 'photo' => 'https://lh3.googleusercontent.com/a/x', 'hidden' => false ),
		array( 'id' => 'm1', 'author' => 'bob', 'rating' => 5, 'text' => '', 'date' => '', 'source' => 'manual', 'url' => '', 'photo' => '', 'hidden' => false ),
	) ) );
	$p = array( 'eyebrow' => '', 'heading' => '', 'intro' => '', 'limit' => 8, 'minRating' => 1, 'cols' => 3, 'showSummary' => true, 'showLinks' => true, 'tone' => 'light', 'layout' => 'carousel' );
	$h = rk_builder_render_reviews( $p );
	t_assert( false !== strpos( $h, 'rv-widget rv-carousel rv-c3' ) && false !== strpos( $h, 'class="rv-wm"' ) && false !== strpos( $h, '<strong>5.0</strong>' ) && false !== strpos( $h, '(18)' ) );
	t_assert( false !== strpos( $h, 'Review us on Google' ) && false !== strpos( $h, 'rv-nav prev' ) && false !== strpos( $h, 'referrerpolicy="no-referrer"' ) );
	t_assert( false !== strpos( $h, '>B</span>' ) && 1 === substr_count( $h, 'class="rv-more"' ) );
	$c = rk_builder_render_reviews( array_merge( $p, array( 'layout' => 'cards' ) ) );
	t_assert( false === strpos( $c, 'rv-nav' ) && false !== strpos( $c, 'rv-cards' ) );
	$g = rk_builder_render_reviews( array_diff_key( $p, array( 'layout' => 1 ) ) );
	t_assert( false === strpos( $g, 'rv-widget' ) && false !== strpos( $g, 'pf-rv-grid' ) );
	foreach ( array( 'masonry', 'list' ) as $l ) { $x = rk_builder_render_reviews( array_merge( $p, array( 'layout' => $l ) ) ); t_assert( false !== strpos( $x, 'rv-' . $l . ' rv-c3' ) && false === strpos( $x, 'rv-nav' ) ); }
	$sl = rk_builder_render_reviews( array_merge( $p, array( 'layout' => 'slider' ) ) );
	t_assert( false !== strpos( $sl, 'rv-slider' ) && false !== strpos( $sl, 'rv-nav next' ) );
	$bd = rk_builder_render_reviews( array_merge( $p, array( 'layout' => 'badge' ) ) );
	t_assert( false !== strpos( $bd, 'Excellent on Google' ) && false !== strpos( $bd, '18 reviews' ) && false === strpos( $bd, 'rv-card' ) );
} );
