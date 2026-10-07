<?php
/** SEO tags for published RK pages: output, filters, duplicate suppression. */

function rk_seo_head( $id ) {
	rk_test_hooks();
	rk_test_set_query( array( 'singular' => true, 'id' => $id, 'loop' => true ) );
	do_action( 'wp' );
	ob_start();
	do_action( 'wp_head' );
	return ob_get_clean();
}
function rel_canonical() { echo '<link rel="canonical" href="CORE">'; }

function rk_seo_page( array $blocks, $extra = array() ) { return rk_pub_page( rk_test_layout( $blocks ), 'about', $extra ); }

rk_test( 'seo: title, description (excerpt), canonical, Open Graph, Twitter for a published RK page', function () {
	$GLOBALS['RK']['attachments'][70] = array( 'url' => 'https://cms.example.com/u/feat.jpg', 'w' => 100, 'h' => 50, 'title' => 'f' );
	$id = rk_seo_page( array( rk_test_block( 'hero', array( 'heading' => 'Hero heading', 'sub' => 'Hero sub', 'cta' => '', 'ctaHref' => '' ) ) ), array( 'post_title' => 'About "us"', 'post_excerpt' => 'The <b>excerpt</b> & more' ) );
	$GLOBALS['RK']['meta'][ $id ]['_thumbnail_id'] = '70';
	add_action( 'wp_head', 'rel_canonical' ); // core's own canonical, registered BEFORE `wp`
	$head = rk_seo_head( $id );
	t_assert( false !== strpos( $head, '<meta name="description" content="The excerpt &amp; more">' ), $head );
	t_assert( false !== strpos( $head, '<link rel="canonical" href="https://cms.example.com/about/">' ) );
	t_assert( false === strpos( $head, 'CORE' ), 'core rel_canonical removed so there is exactly one canonical' );
	t_eq( substr_count( $head, 'rel="canonical"' ), 1 );
	foreach ( array(
		'<meta property="og:type" content="website">', '<meta property="og:site_name" content="Test Site">', '<meta property="og:title" content="About &quot;us&quot;">',
		'<meta property="og:description" content="The excerpt &amp; more">', '<meta property="og:url" content="https://cms.example.com/about/">',
		'<meta property="og:image" content="https://cms.example.com/u/feat.jpg">', '<meta name="twitter:card" content="summary_large_image">',
		'<meta name="twitter:title" content="About &quot;us&quot;">', '<meta name="twitter:description" content="The excerpt &amp; more">', '<meta name="twitter:image" content="https://cms.example.com/u/feat.jpg">',
	) as $needle ) { t_assert( false !== strpos( $head, $needle ), 'missing ' . $needle . "\n" . $head ); }
	t_assert( false === strpos( $head, 'robots' ), 'published pages are not noindex' );
	t_eq( apply_filters( 'document_title_parts', array( 'title' => 'About "us"', 'site' => 'Test Site' ) ), array( 'title' => 'About "us"', 'site' => 'Test Site' ) );
} );

rk_test( 'seo: description falls back to hero sub then text, <=160 chars; og:image falls back to the first image block; card is "summary" without an image', function () {
	$id = rk_seo_page( array( rk_test_block( 'hero', array( 'heading' => 'Hero heading', 'sub' => str_repeat( 'word ', 60 ), 'cta' => '', 'ctaHref' => '' ) ) ) );
	$head = rk_seo_head( $id );
	preg_match( '/<meta name="description" content="([^"]*)"/', $head, $m );
	t_assert( isset( $m[1] ) && mb_strlen( html_entity_decode( $m[1] ) ) <= 160 && mb_strlen( html_entity_decode( $m[1] ) ) > 100, 'truncated: ' . ( isset( $m[1] ) ? mb_strlen( $m[1] ) : 'none' ) );
	t_assert( false !== strpos( $head, 'content="summary"' ) && false === strpos( $head, 'og:image' ) );
	$GLOBALS['RK']['attachments'][71] = array( 'url' => 'https://cms.example.com/u/first.jpg', 'w' => 10, 'h' => 10, 'title' => 'f' );
	$id2 = rk_pub_page( rk_test_layout( array(
		rk_test_block( 'image', array( 'mediaId' => 71, 'url' => 'https://cms.example.com/old.jpg', 'alt' => 'x', 'decorative' => false ), 'i1' ),
		rk_test_block( 'text', array( 'text' => 'Body copy here.' ), 't1' ),
	) ), 'two' );
	$head = rk_seo_head( $id2 );
	t_assert( false !== strpos( $head, '<meta property="og:image" content="https://cms.example.com/u/first.jpg">' ), $head );
	t_assert( false !== strpos( $head, '<meta name="description" content="Body copy here.">' ) );
	t_assert( false !== strpos( $head, 'summary_large_image' ) );
} );

rk_test( 'seo: filters rk_builder_seo_title/description/canonical_url/og_image apply (and unsafe URLs are dropped)', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_excerpt' => 'x' ) );
	add_filter( 'rk_builder_seo_title', function ( $t, $pid ) { return 'Filtered title ' . $pid; }, 10, 2 );
	add_filter( 'rk_builder_seo_description', function () { return 'Filtered description'; } );
	add_filter( 'rk_builder_canonical_url', function () { return 'https://canon.example/x?a=1&b=2'; } );
	add_filter( 'rk_builder_og_image', function () { return '/relative/og.png'; } );
	$head = rk_seo_head( $id );
	t_assert( false !== strpos( $head, 'og:title" content="Filtered title ' . $id . '"' ), $head );
	t_assert( false !== strpos( $head, 'name="description" content="Filtered description"' ) );
	t_assert( false !== strpos( $head, '<link rel="canonical" href="https://canon.example/x?a=1&amp;b=2">' ) );
	t_assert( false !== strpos( $head, 'og:image" content="https://cms.example.com/relative/og.png"' ), 'relative image made absolute' );
	t_eq( apply_filters( 'document_title_parts', array( 'title' => 'orig' ) ), array( 'title' => 'Filtered title ' . $id ) );
	add_filter( 'rk_builder_og_image', function () { return 'javascript:alert(1)'; }, 20 );
	add_filter( 'rk_builder_canonical_url', function () { return 'javascript:alert(1)'; }, 20 );
	$head = rk_seo_head( $id );
	t_assert( false === strpos( $head, 'javascript' ) && false === strpos( $head, 'og:image' ) && false === strpos( $head, 'rel="canonical"' ), $head );
} );

rk_test( 'seo: an active SEO plugin owns the tags - we print nothing and keep core canonical/title', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ) );
	add_filter( 'rk_builder_active_seo_plugin', function () { return 'yoast'; } );
	add_action( 'wp_head', 'rel_canonical' );
	$head = rk_seo_head( $id );
	t_eq( $head, '<link rel="canonical" href="CORE">', 'only core output, no RK tags' );
	t_eq( apply_filters( 'document_title_parts', array( 'title' => 'orig' ) ), array( 'title' => 'orig' ) );
	t_eq( rk_builder_active_seo_plugin(), 'yoast' );
} );

rk_test( 'seo: non-RK requests (draft page, archive, disabled) print nothing', function () {
	$d = rk_test_page( 'draft', 'dr' );
	$GLOBALS['RK']['meta'][ $d ]['_rk_layout_published'] = json_encode( rk_test_layout( array() ) );
	t_eq( rk_seo_head( $d ), '' );
	$id = rk_seo_page( array() );
	update_option( 'rk_builder_settings', array( 'enabled' => false ) );
	t_eq( rk_seo_head( $id ), '' );
} );

rk_test( 'seo: each known SEO plugin constant is detected and silences our tags (fresh processes)', function () {
	foreach ( array( 'WPSEO_VERSION' => 'yoast', 'RANK_MATH_VERSION' => 'rank-math', 'SEOPRESS_VERSION' => 'seopress', 'AIOSEO_VERSION' => 'aioseo', 'THE_SEO_FRAMEWORK_VERSION' => 'seo-framework' ) as $const => $slug ) {
		$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/subprocess-constants.php' ) . ' ' . escapeshellarg( 'seo:' . $const ) . ' 2>&1' );
		$d   = json_decode( (string) $out, true );
		t_assert( is_array( $d ), $const . ': ' . $out );
		t_eq( $d['plugin'], $slug, $const );
		t_eq( $d['head'], '', $const . ' -> no RK head tags' );
		t_eq( $d['title'], array( 'title' => 'T' ), $const . ' -> title untouched' );
	}
} );

rk_test( 'seo: per-page title, description, image, noindex and the schema graph', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_title' => 'Products', 'post_excerpt' => 'Excerpt' ) );
	rk_builder_seo_write( $id, rk_builder_seo_clean( array( 'title' => 'Hardwood <b>Products</b>', 'description' => 'Custom   description.', 'image' => '/images/og.jpg', 'noindex' => true, 'service' => 'Short service text', 'parent' => 'Services|/services', 'bogus' => 'x' ) ) );
	rk_builder_seo_organization_save( array( 'name' => 'Acme Floors', 'telephone' => '+1555', 'email' => 'a@b.test', 'logo' => 'javascript:alert(1)' ) );
	$head = rk_seo_head( $id );
	t_assert( false !== strpos( $head, '<meta name="description" content="Custom description.">' ), 'description from the SEO field' );
	t_assert( false !== strpos( $head, '<meta property="og:title" content="Hardwood Products">' ), 'title from the SEO field, tags stripped' );
	t_assert( false !== strpos( $head, 'og:image" content="' ), 'image from the SEO field' );
	$parts = apply_filters( 'document_title_parts', array( 'title' => 'orig' ) );
	t_eq( $parts['title'], 'Hardwood Products' );
	t_eq( apply_filters( 'document_title_separator', '-' ), '|' );
	$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
	t_eq( ! empty( $robots['noindex'] ) && ! empty( $robots['follow'] ), true, 'noindex, follow' );
	t_eq( false !== strpos( $head, 'ld+json' ), false, 'noindex pages carry no schema' );
	$seo = rk_builder_seo_read( $id );
	unset( $seo['noindex'] );
	rk_builder_seo_write( $id, $seo );
	$head = rk_seo_head( $id );
	t_assert( preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m ) === 1, 'JSON-LD printed' );
	$g = json_decode( $m[1], true );
	$types = array_map( function ( $n ) { return $n['@type']; }, $g['@graph'] );
	t_eq( $types, array( 'Organization', 'WebSite', 'WebPage', 'Service', 'BreadcrumbList' ) );
	t_eq( $g['@graph'][0]['name'], 'Acme Floors' );
	t_eq( isset( $g['@graph'][0]['logo'] ), false, 'unsafe logo dropped' );
	t_eq( count( $g['@graph'][4]['itemListElement'] ), 3, 'Home > Services > page' );
	t_eq( isset( rk_builder_seo_read( $id )['noindex'] ), false );
	rk_builder_seo_write( $id, array() );
	t_eq( rk_builder_seo_read( $id ), array(), 'cleared' );
} );

function rk_seo_graph_for( $id ) {
	$head = rk_seo_head( $id );
	t_assert( preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m ) === 1, 'JSON-LD printed' );
	return json_decode( $m[1], true )['@graph'];
}
function rk_seo_types( array $graph ) { return array_map( function ( $n ) { return $n['@type']; }, $graph ); }

rk_test( 'schema: a page that never opened the panel is unchanged; defaults store nothing', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_title' => 'About', 'post_excerpt' => 'Excerpt' ) );
	t_eq( rk_seo_types( rk_seo_graph_for( $id ) ), array( 'Organization', 'WebSite', 'WebPage', 'BreadcrumbList' ) );
	t_eq( rk_builder_schema_encode( rk_builder_schema_defaults() ), '', 'all defaults: nothing stored' );
	t_eq( rk_builder_seo_clean( array( 'schema' => array( 'pageType' => 'WebPage' ) ) ), array(), 'a default schema adds no field' );
} );

rk_test( 'schema: page type, breadcrumb off, article, service, product, FAQ and rating build one valid graph', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_title' => 'Guide', 'post_excerpt' => 'A guide' ) );
	rk_builder_seo_write( $id, rk_builder_seo_clean( array( 'schema' => array(
		'pageType' => 'AboutPage', 'breadcrumb' => false,
		'article' => array( 'on' => true, 'type' => 'BlogPosting' ),
		'service' => array( 'on' => true, 'name' => 'Deck care' ),
		'product' => array( 'on' => true, 'price' => '49.5', 'currency' => 'usd', 'availability' => 'InStock', 'brand' => 'Acme' ),
		'faq' => array( 'on' => true, 'items' => "Is it durable?|Yes, very.\nbroken line\nHow long?|Two days." ),
		'review' => array( 'on' => true, 'rating' => '4.8', 'count' => '120' ),
		'bogus' => array( 'on' => true ),
	) ) ) );
	$g = rk_seo_graph_for( $id );
	t_eq( rk_seo_types( $g ), array( 'Organization', 'WebSite', 'AboutPage', 'BlogPosting', 'Service', 'Product', 'FAQPage' ), 'no breadcrumb; each requested node once' );
	$by = array();
	foreach ( $g as $n ) { $by[ $n['@type'] ] = $n; }
	t_eq( $by['Product']['offers']['priceCurrency'], 'USD' );
	t_eq( $by['Product']['offers']['availability'], 'https://schema.org/InStock' );
	t_eq( $by['Product']['brand']['name'], 'Acme' );
	t_eq( $by['Product']['aggregateRating']['ratingValue'], '4.8', 'rating sits on the product' );
	t_eq( isset( $by['Service']['aggregateRating'] ), false, 'one rating only' );
	t_eq( $by['Service']['name'], 'Deck care' );
	t_eq( count( $by['FAQPage']['mainEntity'] ), 2, 'a line without an answer is skipped' );
	t_eq( $by['BlogPosting']['mainEntityOfPage']['@id'], $by['AboutPage']['@id'] );
	t_eq( $by['BlogPosting']['headline'], 'Guide' );
} );

rk_test( 'schema: unsafe or incomplete values never reach the output', function () {
	$c = rk_builder_schema_sanitize( array( 'pageType' => 'Evil', 'product' => array( 'on' => true, 'price' => '<b>9</b>', 'currency' => 'dollars' ), 'review' => array( 'on' => true, 'rating' => '9', 'count' => '-3' ), 'article' => array( 'type' => 'Hack' ) ) );
	t_eq( $c['pageType'], 'WebPage' );
	t_eq( $c['product']['price'], '', 'price must be a plain number' );
	t_eq( $c['product']['currency'], 'USD' );
	t_eq( $c['review']['rating'], '' );
	t_eq( $c['review']['count'], '' );
	t_eq( $c['article']['type'], 'Article' );
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_title' => 'Shop' ) );
	rk_builder_seo_write( $id, rk_builder_seo_clean( array( 'schema' => array( 'product' => array( 'on' => true ), 'review' => array( 'on' => true, 'rating' => '5', 'count' => '3' ), 'faq' => array( 'on' => true, 'items' => '' ) ) ) ) );
	t_eq( rk_seo_types( rk_seo_graph_for( $id ) ), array( 'Organization', 'WebSite', 'WebPage', 'BreadcrumbList' ), 'a product without a price, an empty FAQ and an orphan rating output nothing' );
} );

rk_test( 'schema: the legacy service text still outputs a Service; custom types plug in by filter', function () {
	$id = rk_seo_page( array( rk_test_block( 'spacer', array( 'h' => 8 ) ) ), array( 'post_title' => 'Decks' ) );
	rk_builder_seo_write( $id, rk_builder_seo_clean( array( 'service' => 'Deck staining' ) ) );
	t_eq( rk_seo_types( rk_seo_graph_for( $id ) ), array( 'Organization', 'WebSite', 'WebPage', 'Service', 'BreadcrumbList' ) );
	add_filter( 'rk_builder_schema_types', function ( $t ) { $t['event'] = array( 'label' => 'Event', 'help' => '', 'build' => function ( $c, $ctx ) { return array( '@type' => 'Event', 'name' => $ctx['name'] ); } ); return $t; } );
	t_eq( array_keys( rk_builder_schema_types() ), array( 'article', 'service', 'product', 'faq', 'event' ) );
} );

rk_test( 'robots.txt: a Sitemap line for another website is found and replaced, the rest is kept', function () {
	$txt = "User-agent: *\r\nAllow: /\r\n\r\nSitemap: https://www.rhodeshardwoodflooring.com/sitemap_index.xml";
	t_eq( array( 'https://www.rhodeshardwoodflooring.com/sitemap_index.xml' ), rk_builder_robots_foreign_sitemaps( $txt, 'https://peoriahardwoodfloors.com/' ) );
	t_eq( array(), rk_builder_robots_foreign_sitemaps( "Sitemap: https://www.peoriahardwoodfloors.com/wp-sitemap.xml", 'https://peoriahardwoodfloors.com/' ) );
	$fixed = rk_builder_robots_fixed( $txt, 'https://peoriahardwoodfloors.com/wp-sitemap.xml', 'https://peoriahardwoodfloors.com/' );
	t_eq( "User-agent: *\nAllow: /\n\nSitemap: https://peoriahardwoodfloors.com/wp-sitemap.xml\n", $fixed );
	t_eq( $fixed, rk_builder_robots_fixed( $fixed, 'https://peoriahardwoodfloors.com/wp-sitemap.xml', 'https://peoriahardwoodfloors.com/' ) );
} );
