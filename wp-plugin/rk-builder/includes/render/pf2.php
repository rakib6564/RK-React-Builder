<?php
/**
 * Contact band, panel, value cards, catalog, service detail and gallery blocks.
 * Mirror client/src/blocks/{contactband,panel,values,catalog,detail,gallery}/View.tsx and client/src/blocks/links.ts.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The exact inline SVG React emits for the lucide icons used here (size, aria-hidden). */
function rk_builder_icon( $name, $size = 16 ) {
	$inner = array(
		'check'        => array( 'lucide-check', '<path d="M20 6 9 17l-5-5"></path>' ),
		'circle-check' => array( 'lucide-circle-check', '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>' ),
		'phone'        => array( 'lucide-phone', '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>' ),
		'mail'         => array( 'lucide-mail', '<rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>' ),
		'map-pin'      => array( 'lucide-map-pin', '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"></path><circle cx="12" cy="10" r="3"></circle>' ),
		'arrow-right'  => array( 'lucide-arrow-right', '<path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path>' ),
		'quote'        => array( 'lucide-quote', '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"></path><path d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"></path>' ),
		'arrow-up-right' => array( 'lucide-arrow-up-right', '<path d="M7 7h10v10"></path><path d="M7 17 17 7"></path>' ),
	);
	$i = $inner[ $name ];
	return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide ' . $i[0] . '" aria-hidden="true">' . $i[1] . '</svg>';
}

/** Pipe-separated rows with exactly $n trimmed fields. Mirrors parseRows(). */
function rk_builder_parse_rows( $source, $n, $max = 12 ) {
	$out = array();
	foreach ( explode( "\n", (string) $source ) as $raw ) {
		$line = trim( $raw );
		if ( '' === $line ) { continue; }
		$parts = explode( '|', $line );
		$row   = array();
		for ( $i = 0; $i < $n - 1; $i++ ) { $row[] = isset( $parts[ $i ] ) ? trim( $parts[ $i ] ) : ''; }
		$row[] = trim( implode( '|', array_slice( $parts, $n - 1 ) ) );
		$out[] = $row;
		if ( count( $out ) >= $max ) { break; }
	}
	return $out;
}

/** Non-empty trimmed lines. */
function rk_builder_lines( $source, $max ) {
	$out = array();
	foreach ( explode( "\n", (string) $source ) as $raw ) {
		$l = trim( $raw );
		if ( '' !== $l ) { $out[] = $l; }
		if ( count( $out ) >= $max ) { break; }
	}
	return $out;
}

/** "a; b; c" -> list. Mirrors semi(). */
function rk_builder_semi( $source, $max = 12 ) {
	$out = array();
	foreach ( explode( ';', (string) $source ) as $s ) {
		$s = trim( $s );
		if ( '' !== $s ) { $out[] = $s; }
	}
	return array_slice( $out, 0, $max );
}

/** Escaped, safe href or '' (mirrors safeHref()). */
function rk_builder_safe_href( $url ) {
	return ( '' !== $url && rk_builder_is_safe_link( $url ) ) ? rk_builder_href( $url ) : '';
}

function rk_builder_is_hex( $v ) { return 1 === preg_match( '/^#[0-9a-fA-F]{6}\z/', (string) $v ); }

/** A label starting with "!" is a solid button. With no "!" anywhere, $solid_first makes the first one solid. Mirrors Actions(). */
function rk_builder_actions_html( $source, $solid_first, $buttons = false ) {
	$rows = array();
	foreach ( rk_builder_parse_rows( $source, 2, 6 ) as $r ) {
		if ( '' !== $r[0] && '' !== rk_builder_safe_href( $r[1] ) ) { $rows[] = $r; }
	}
	if ( ! $rows ) { return ''; }
	$marked = false;
	foreach ( $rows as $r ) { if ( 0 === strpos( $r[0], '!' ) ) { $marked = true; } }
	$html = '<div class="' . ( $buttons ? 'pf-links btns' : 'pf-links' ) . '">';
	foreach ( $rows as $i => $r ) {
		$bang  = 0 === strpos( $r[0], '!' );
		$solid = $marked ? $bang : ( $solid_first && 0 === $i );
		$text  = $bang ? trim( substr( $r[0], 1 ) ) : $r[0];
		$cls   = $solid ? 'pf-btn dark' : ( $buttons ? 'pf-btn outline' : 'pf-more' );
		$html .= '<a class="' . $cls . '" href="' . rk_builder_safe_href( $r[1] ) . '">' . rk_builder_h( $text ) . rk_builder_icon( 'arrow-right' ) . '</a>';
	}
	return $html . '</div>';
}

function rk_builder_checks_html( $source ) {
	$lines = rk_builder_lines( $source, 8 );
	if ( ! $lines ) { return ''; }
	$html = '<ul class="pf-checks">';
	foreach ( $lines as $l ) { $html .= '<li>' . rk_builder_icon( 'check' ) . rk_builder_h( $l ) . '</li>'; }
	return $html . '</ul>';
}

function rk_builder_render_contactband( array $p, array $context = array() ) {
	$tel  = rk_builder_phone_href( $p['phone'] );
	$mail = rk_builder_email_href( $p['email'] );
	$html = '<section ' . rk_builder_root_attrs( 'contactband', 'pf-band' ) . '><div class="pf-wrap pf-band-grid"><div><h2>' . rk_builder_h( $p['heading'] ) . '</h2>';
	if ( '' !== $p['sub'] ) { $html .= '<p>' . rk_builder_h( $p['sub'] ) . '</p>'; }
	$html .= '</div><div class="pf-band-cards">';
	if ( '' !== $p['phone'] && '' !== $tel ) {
		$html .= '<a class="pf-band-card solid" href="' . rk_builder_h( $tel ) . '"><span>' . rk_builder_icon( 'phone' ) . rk_builder_h( $p['phone'] ) . '</span><small>Call now</small></a>';
	}
	if ( '' !== $p['email'] && '' !== $mail ) {
		$html .= '<a class="pf-band-card" href="' . rk_builder_h( $mail ) . '"><span>' . rk_builder_icon( 'mail' ) . 'Email us</span><small>Reply-friendly</small></a>';
	}
	return $html . '</div></div></section>';
}

function rk_builder_render_values( array $p, array $context = array() ) {
	$html = '<section ' . rk_builder_root_attrs( 'values', 'pf-section pf-values ' . $p['tone'] . ( ! empty( $p['quote'] ) ? ' quote' : '' ) ) . '><div class="pf-wrap"><div class="pf-center">';
	if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	$html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2></div>';
	$html .= '<div class="pf-cardgrid" style="grid-template-columns:repeat(' . (int) $p['cols'] . ', minmax(0, 1fr))">';
	foreach ( rk_builder_parse_rows( $p['items'], 2, 8 ) as $r ) {
		$html .= '<article>' . ( ! empty( $p['quote'] ) ? rk_builder_icon( 'quote', 32 ) : '' ) . '<h3>' . rk_builder_h( $r[0] ) . '</h3>' . ( '' !== $r[1] ? '<p>' . rk_builder_h( $r[1] ) . '</p>' : '' ) . '</article>';
	}
	return $html . '</div></div></section>';
}

function rk_builder_render_panel( array $p, array $context = array() ) {
	$intro = 'intro' === $p['mode'];
	$copy  = '<div class="' . ( $p['box'] ? 'pf-panel-copy box' : 'pf-panel-copy' ) . '">';
	if ( '' !== $p['eyebrow'] ) { $copy .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	if ( '' !== $p['heading'] ) { $copy .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>'; }
	if ( ! $intro ) { $copy .= rk_builder_paragraphs_html( $p['body'] ); }
	$rows = rk_builder_parse_rows( $p['items'], 3, 8 );
	if ( ! $intro && ! $p['flip'] && $rows ) { $copy .= rk_builder_actions_html( $p['actions'], true ); }
	$copy .= '</div>';
	if ( $intro ) {
		$side = '<div class="pf-panel-rows">' . rk_builder_paragraphs_html( $p['body'] ) . rk_builder_checks_html( $p['checks'] ) . rk_builder_actions_html( $p['actions'], false ) . '</div>';
	} elseif ( ! $rows ) {
		$side = '<div class="pf-panel-rows">' . rk_builder_actions_html( $p['actions'], false, true ) . '</div>';
	} else {
		$side = '<div class="pf-panel-rows">';
		if ( $p['flip'] && '' !== $p['kicker'] ) { $side .= '<p class="pf-kicker">' . rk_builder_h( $p['kicker'] ) . '</p>'; }
		$side .= '<div class="pf-rows ' . $p['itemStyle'] . '">';
		foreach ( $rows as $r ) {
			$href  = rk_builder_safe_href( $r[2] );
			$inner = '<span class="pf-row-text"><strong>' . rk_builder_h( $r[0] ) . '</strong>' . ( '' !== $r[1] ? '<span>' . rk_builder_h( $r[1] ) . '</span>' : '' ) . '</span>' . ( '' !== $href ? rk_builder_icon( 'arrow-up-right' ) : '' );
			$side .= '' !== $href ? '<a class="pf-row" href="' . $href . '">' . $inner . '</a>' : '<div class="pf-row">' . $inner . '</div>';
		}
		$side .= '</div>';
		if ( $p['flip'] ) { $side .= rk_builder_actions_html( $p['actions'], false ); }
		$side .= '</div>';
	}
	$cls = 'pf-section pf-panel ' . $p['tone'] . ' ' . $p['mode'] . ( $p['flip'] ? ' flip' : '' );
	return '<section ' . rk_builder_root_attrs( 'panel', $cls ) . '><div class="pf-wrap pf-panel-grid">' . ( $p['flip'] ? $side . $copy : $copy . $side ) . '</div></section>';
}

/** The text after the last " · " in a card blurb, else "". Mirrors blurbTag() in blocks/links.ts. */
function rk_builder_blurb_tag( $blurb ) {
	$cut = strrpos( (string) $blurb, ' · ' );
	return false === $cut ? '' : trim( substr( (string) $blurb, $cut + strlen( ' · ' ) ) );
}

/** The filter button row shared by the catalog and gallery blocks. */
function rk_builder_filters_html( array $tags ) {
	if ( ! $tags ) { return ''; }
	$html = '<div class="pf-filters" role="group" aria-label="Filter">';
	foreach ( array_merge( array( 'All' ), $tags ) as $k => $t ) {
		$html .= '<button type="button"' . ( 0 === $k ? ' class="on"' : '' ) . ' data-filter="' . rk_builder_h( $t ) . '">' . rk_builder_h( $t ) . '</button>';
	}
	return $html . '</div>';
}

function rk_builder_render_catalog( array $p, array $context = array() ) {
	$rows_all = rk_builder_parse_rows( $p['items'], 7, 150 );
	$filters  = ! empty( $p['filters'] );
	$modals   = rk_builder_parse_rows( isset( $p['modals'] ) ? $p['modals'] : '', 4, 150 );
	$m_label  = ! empty( $p['modalLabel'] ) ? $p['modalLabel'] : 'View all products';
	$ctas     = array();
	foreach ( rk_builder_semi( isset( $p['modalCta'] ) ? $p['modalCta'] : '', 2 ) as $c ) {
		$r = rk_builder_parse_rows( $c, 2, 1 );
		if ( $r && '' !== $r[0][0] && '' !== rk_builder_safe_href( $r[0][1] ) ) { $ctas[] = $r[0]; }
	}
	$by_eyebrow = isset( $p['tagField'] ) && 'eyebrow' === $p['tagField'];
	$tag_of     = function ( array $r ) use ( $by_eyebrow ) { return $by_eyebrow ? $r[1] : rk_builder_blurb_tag( $r[3] ); };
	$page_size  = isset( $p['pageSize'] ) ? (int) $p['pageSize'] : 0;
	$paged      = $page_size > 0;
	$search     = ! empty( $p['search'] );
	$s_label    = ! empty( $p['searchLabel'] ) ? $p['searchLabel'] : 'Search';
	$pager_on   = $paged || $search;
	$tags = array();
	if ( $filters ) { foreach ( $rows_all as $r ) { $t = $tag_of( $r ); if ( '' !== $t && ! in_array( $t, $tags, true ) ) { $tags[] = $t; } } }
	$html = '<section ' . rk_builder_root_attrs( 'catalog', 'pf-section pf-catalog ' . $p['tone'] ) . ( $pager_on ? ' data-page="' . $page_size . '"' : '' ) . '><div class="pf-wrap">';
	if ( '' !== $p['eyebrow'] || '' !== $p['heading'] || '' !== $p['intro'] ) {
		$html .= '<div class="pf-catalog-head">';
		if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
		if ( '' !== $p['heading'] ) { $html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>'; }
		$html .= rk_builder_paragraphs_html( $p['intro'] ) . '</div>';
	}
	if ( $search ) { $html .= '<div class="pf-search"><input type="search" placeholder="' . rk_builder_h( $s_label ) . '" aria-label="' . rk_builder_h( $s_label ) . '" data-search=""/></div>'; }
	$html .= rk_builder_filters_html( $tags );
	$html .= '<div class="' . ( ! empty( $p['joined'] ) ? 'pf-cards joined' : 'pf-cards' ) . '" style="grid-template-columns:repeat(' . (int) $p['cols'] . ', minmax(0, 1fr))">';
	foreach ( $rows_all as $i => $r ) {
		list( $image, $eyebrow, $title, $blurb, $specs, $bullets, $link ) = $r;
		$swatch = rk_builder_is_hex( $image );
		$src    = $swatch ? '' : ( '' !== $image ? rk_builder_src( $image ) : '' );
		$href   = rk_builder_safe_href( $link );
		$body   = '';
		if ( $swatch ) { $body .= '<div class="pf-card-media swatch" style="background-color:' . rk_builder_h( $image ) . '"></div>'; }
		if ( '' !== $src ) {
			$body .= '<div class="pf-card-media"><img src="' . $src . '" alt="" decoding="async" loading="lazy"/>' . ( $p['numbered'] ? '<span class="pf-badge">' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) . '</span>' : '' ) . '</div>';
		}
		$body .= '<div class="pf-card-body">';
		if ( '' !== $eyebrow ) { $body .= '<p class="pf-card-eyebrow">' . rk_builder_h( $eyebrow ) . '</p>'; }
		$body .= '' !== $href ? '<div class="pf-card-title"><h3>' . rk_builder_h( $title ) . '</h3>' . rk_builder_icon( 'arrow-up-right', 20 ) . '</div>' : '<h3>' . rk_builder_h( $title ) . '</h3>';
		if ( '' !== $blurb ) { $body .= '<p>' . rk_builder_h( $blurb ) . '</p>'; }
		$spec_rows = array();
		foreach ( rk_builder_semi( $specs ) as $s ) {
			$cut = strpos( $s, ':' );
			if ( false === $cut || $cut < 1 ) { continue; }
			$k = trim( substr( $s, 0, $cut ) );
			$v = trim( substr( $s, $cut + 1 ) );
			if ( '' !== $k && '' !== $v ) { $spec_rows[] = array( $k, $v ); }
		}
		if ( $spec_rows ) {
			$body .= '<dl class="pf-specs">';
			foreach ( $spec_rows as $sr ) { $body .= '<div><dt>' . rk_builder_h( $sr[0] ) . '</dt><dd>' . rk_builder_h( $sr[1] ) . '</dd></div>'; }
			$body .= '</dl>';
		}
		$list = rk_builder_semi( $bullets );
		if ( $list ) {
			$body .= '<ul class="pf-bullets">';
			foreach ( $list as $b ) { $body .= '<li>' . rk_builder_icon( 'circle-check' ) . rk_builder_h( $b ) . '</li>'; }
			$body .= '</ul>';
		}
		if ( '' === $href && isset( $modals[ $i ] ) && '' !== $modals[ $i ][1] ) {
			$body .= '<button type="button" class="pf-open" data-modal-open="' . $i . '">' . rk_builder_h( $m_label ) . rk_builder_arrow_right_icon() . '</button>';
		}
		$body .= '</div>';
		$tag      = $filters ? $tag_of( $r ) : '';
		$tag_attr = ( '' !== $tag ? ' data-tag="' . rk_builder_h( $tag ) . '"' : '' ) . ( $paged && $i >= $page_size ? ' hidden=""' : '' );
		$html .= '' !== $href ? '<a class="pf-card link" href="' . $href . '"' . $tag_attr . '>' . $body . '</a>' : '<article class="pf-card"' . $tag_attr . '>' . $body . '</article>';
	}
	$html .= '</div>';
	if ( $pager_on ) { $html .= '<p class="pf-empty" hidden="">Nothing matches. Try a different word or category.</p>'; }
	if ( $paged && count( $rows_all ) > $page_size ) {
		$html .= '<div class="pf-more-wrap"><p class="pf-count" data-count="" aria-live="polite">Showing ' . $page_size . ' of ' . count( $rows_all ) . '</p><button type="button" class="pf-btn outline" data-more="">Load more</button></div>';
	}
	foreach ( $modals as $i => $m ) {
		list( $m_image, $m_title, $m_intro, $m_items ) = $m;
		if ( '' === $m_title ) { continue; }
		$m_src = '' !== $m_image ? rk_builder_src( $m_image ) : '';
		$html .= '<dialog class="pf-modal" data-modal="' . $i . '" aria-label="' . rk_builder_h( $m_title ) . '"><button type="button" class="pf-modal-x" aria-label="Close" data-modal-close="">×</button><div class="pf-modal-grid">';
		if ( '' !== $m_src ) { $html .= '<div class="pf-modal-img"><img src="' . $m_src . '" alt="" decoding="async" loading="lazy"/></div>'; }
		$html .= '<div class="pf-modal-body"><p class="pf-kicker">Product catalog</p><h2>' . rk_builder_h( $m_title ) . '</h2>';
		if ( '' !== $m_intro ) { $html .= '<p>' . rk_builder_h( $m_intro ) . '</p>'; }
		$html .= '<ul class="pf-modal-items">';
		foreach ( rk_builder_semi( $m_items, 40 ) as $it ) { $html .= '<li>' . rk_builder_h( $it ) . '</li>'; }
		$html .= '</ul>';
		if ( $ctas ) {
			$html .= '<div class="pf-modal-actions">';
			foreach ( $ctas as $k => $c ) {
				$html .= '<a class="pf-btn ' . ( 0 === $k ? 'dark' : 'outline' ) . '" href="' . rk_builder_safe_href( $c[1] ) . '">' . rk_builder_h( $c[0] ) . ( 0 === $k ? rk_builder_arrow_right_icon() : '' ) . '</a>';
			}
			$html .= '</div>';
		}
		$html .= '</div></div></dialog>';
	}
	return $html . '</div></section>';
}

function rk_builder_render_detail( array $p, array $context = array() ) {
	$steps   = rk_builder_lines( $p['steps'], 10 );
	$factors = rk_builder_lines( $p['factors'], 10 );
	$links   = array();
	foreach ( rk_builder_parse_rows( $p['links'], 2, 6 ) as $r ) { if ( '' !== $r[0] && '' !== rk_builder_safe_href( $r[1] ) ) { $links[] = $r; } }
	$faq = array();
	foreach ( rk_builder_parse_rows( $p['faq'], 2, 10 ) as $r ) { if ( '' !== $r[0] ) { $faq[] = $r; } }
	$tel = rk_builder_phone_href( $p['phone'] );

	$html = '<section ' . rk_builder_root_attrs( 'detail', 'pf-section pf-detail' ) . '><div class="pf-wrap pf-detail-grid"><div class="pf-detail-main"><div>';
	if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
	$html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>' . rk_builder_paragraphs_html( $p['body'] ) . '</div>';
	if ( '' !== $p['note'] ) { $html .= '<p class="pf-note">' . rk_builder_h( $p['note'] ) . '</p>'; }
	if ( $steps ) {
		$html .= '<div>' . ( '' !== $p['stepsTitle'] ? '<h3>' . rk_builder_h( $p['stepsTitle'] ) . '</h3>' : '' ) . '<ol class="pf-steps">';
		foreach ( $steps as $i => $s ) { $html .= '<li><span class="pf-step-n">' . ( $i + 1 ) . '</span><span>' . rk_builder_h( $s ) . '</span></li>'; }
		$html .= '</ol></div>';
	}
	if ( $factors ) {
		$html .= '<div>' . ( '' !== $p['factorsTitle'] ? '<h3>' . rk_builder_h( $p['factorsTitle'] ) . '</h3>' : '' ) . ( '' !== $p['factorsIntro'] ? '<p class="pf-small">' . rk_builder_h( $p['factorsIntro'] ) . '</p>' : '' ) . '<ul class="pf-checkgrid">';
		foreach ( $factors as $f ) { $html .= '<li>' . rk_builder_icon( 'check' ) . '<span>' . rk_builder_h( $f ) . '</span></li>'; }
		$html .= '</ul></div>';
	}
	if ( $links ) {
		$html .= '<div class="pf-linkbar">';
		foreach ( $links as $r ) { $html .= '<a href="' . rk_builder_safe_href( $r[1] ) . '">' . rk_builder_h( $r[0] ) . '</a>'; }
		$html .= '</div>';
	}
	if ( $faq ) {
		$html .= '<div>' . ( '' !== $p['faqTitle'] ? '<h3>' . rk_builder_h( $p['faqTitle'] ) . '</h3>' : '' ) . '<div class="pf-faq">';
		foreach ( $faq as $r ) { $html .= '<details><summary>' . rk_builder_h( $r[0] ) . '<span>+</span></summary>' . ( '' !== $r[1] ? '<p>' . rk_builder_h( $r[1] ) . '</p>' : '' ) . '</details>'; }
		$html .= '</div></div>';
	}
	$html .= '</div><aside class="pf-aside">';
	if ( '' !== $p['asideTitle'] ) { $html .= '<h3>' . rk_builder_h( $p['asideTitle'] ) . '</h3>'; }
	if ( '' !== $p['asideText'] ) { $html .= '<p>' . rk_builder_h( $p['asideText'] ) . '</p>'; }
	if ( '' !== $p['phone'] && '' !== $tel ) { $html .= '<a class="pf-btn dark wide" href="' . rk_builder_h( $tel ) . '">' . rk_builder_icon( 'phone' ) . rk_builder_h( $p['phone'] ) . '</a>'; }
	if ( '' !== $p['ctaLabel'] && '' !== $p['ctaHref'] ) { $html .= '<a class="pf-btn light wide" href="' . rk_builder_href( $p['ctaHref'] ) . '">' . rk_builder_h( $p['ctaLabel'] ) . rk_builder_icon( 'arrow-right' ) . '</a>'; }
	return $html . '</aside></div></section>';
}

/** The classes a gallery section carries for its options. Mirrors galleryClasses() in client/src/blocks/gallery/schema.ts. */
function rk_builder_gallery_classes( array $p ) {
	$c = array( 'pf-section', 'pf-gallery' );
	if ( isset( $p['columns'] ) && 3 !== (int) $p['columns'] ) { $c[] = 'cols-' . (int) $p['columns']; }
	if ( isset( $p['shape'] ) && 'rows' !== $p['shape'] ) { $c[] = 'shape-' . $p['shape']; }
	if ( isset( $p['gap'] ) && 'md' !== $p['gap'] ) { $c[] = 'gap-' . $p['gap']; }
	if ( isset( $p['featured'] ) && false === $p['featured'] ) { $c[] = 'no-feature'; }
	if ( isset( $p['captions'] ) && 'overlay' !== $p['captions'] ) { $c[] = 'cap-' . $p['captions']; }
	if ( ! empty( $p['lightbox'] ) ) { $c[] = 'has-lightbox'; }
	return implode( ' ', $c );
}

/** "hardwood-floors" -> "Hardwood Floors". Mirrors slugLabel() in blocks/gallery/schema.ts. */
function rk_builder_gallery_slug_label( $slug ) {
	$out = array();
	foreach ( preg_split( '/[-_\s]+/', (string) $slug, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
		$out[] = mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_substr( $w, 1 );
	}
	return implode( ' ', $out );
}

/**
 * The photos of an automatic gallery as "image|Category|Caption" rows: the newest images in the media library, or the
 * featured images of projects / services (title as caption, first category as the filter). Entries without a picture are skipped.
 */
function rk_builder_gallery_auto_rows( array $p, array $context = array() ) {
	$source = $p['source'];
	$limit  = isset( $p['limit'] ) ? (int) $p['limit'] : 12;
	$filter = isset( $p['filter'] ) && is_string( $p['filter'] ) ? trim( $p['filter'] ) : '';
	$rows   = array();
	try {
		if ( 'media' === $source ) {
			$args = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => max( 1, min( 40, $limit ) ), 'orderby' => 'date', 'order' => 'DESC' );
			if ( '' !== $filter ) { $args['s'] = $filter; }
			foreach ( get_posts( $args ) as $att ) {
				$item = rk_builder_media_item( $att );
				if ( null === $item ) { continue; }
				$rows[] = array( $item['url'], '', '' !== $item['alt'] ? $item['alt'] : $item['title'] );
			}
			return $rows;
		}
		$res = rk_builder_query_content( $source, array( 'limit' => min( 24, $limit ), 'category' => $filter, 'orderby' => 'date', 'order' => 'desc' ) );
		foreach ( null === $res ? array() : $res['items'] as $item ) {
			if ( empty( $item['image']['url'] ) ) { continue; }
			$cat    = ! empty( $item['categories'][0] ) ? rk_builder_gallery_slug_label( $item['categories'][0] ) : '';
			$rows[] = array( $item['image']['url'], $cat, $item['title'] );
		}
	} catch ( Throwable $e ) {
		rk_builder_log( 'warning', 'gallery_query_failed', array( 'source' => $source, 'error' => get_class( $e ) ) );
		rk_builder_record_render_error( isset( $context['page_id'] ) ? (int) $context['page_id'] : 0, 'rk_gallery_query_failed' );
	}
	return $rows;
}

function rk_builder_render_gallery( array $p, array $context = array() ) {
	$filters  = ! empty( $p['filters'] );
	$featured = ! ( isset( $p['featured'] ) && false === $p['featured'] );
	$lightbox = ! empty( $p['lightbox'] );
	$figs    = '';
	$tags    = array();
	$n       = 0;
	$page_size = isset( $p['pageSize'] ) ? (int) $p['pageSize'] : 0;
	$source = isset( $p['source'] ) ? $p['source'] : 'manual';
	$rows   = 'manual' === $source ? rk_builder_parse_rows( $p['items'], 3, 300 ) : rk_builder_gallery_auto_rows( $p, $context );
	foreach ( $rows as $r ) {
		if ( '' === $r[0] || '' === rk_builder_src( $r[0] ) ) { continue; }
		$cap = '' !== $r[1] && '' !== $r[2] ? $r[1] . ' · ' . $r[2] : ( '' !== $r[1] ? $r[1] : $r[2] );
		if ( $filters && '' !== $r[1] && ! in_array( $r[1], $tags, true ) ) { $tags[] = $r[1]; }
		$attrs = ( 0 === $n && $featured ? ' class="big"' : '' ) . ( $filters && '' !== $r[1] ? ' data-tag="' . rk_builder_h( $r[1] ) . '"' : '' ) . ( $lightbox ? ' tabindex="0"' : '' ) . ( $page_size > 0 && $n >= $page_size ? ' hidden=""' : '' );
		$figs .= '<figure' . $attrs . '><img src="' . rk_builder_src( $r[0] ) . '" alt="' . rk_builder_h( $r[2] ) . '" decoding="async" loading="lazy"/>' . ( '' !== $cap ? '<figcaption>' . rk_builder_h( $cap ) . '</figcaption>' : '' ) . '</figure>';
		$n++;
	}
	$html = '<section ' . rk_builder_root_attrs( 'gallery', rk_builder_gallery_classes( $p ) ) . ( $page_size > 0 ? ' data-page="' . $page_size . '"' : '' ) . '>';
	$more = $page_size > 0 && $n > $page_size ? '<div class="pf-more-wrap"><p class="pf-count" data-count="" aria-live="polite">Showing ' . $page_size . ' of ' . $n . '</p><button type="button" class="pf-btn outline" data-more="">Load more</button></div>' : '';
	if ( $tags ) { return $html . '<div class="pf-wrap">' . rk_builder_filters_html( $tags ) . '<div class="pf-gallery-grid">' . $figs . '</div>' . $more . '</div></section>'; }
	if ( '' !== $more ) { return $html . '<div class="pf-wrap"><div class="pf-gallery-grid">' . $figs . '</div>' . $more . '</div></section>'; }
	return $html . '<div class="pf-wrap pf-gallery-grid">' . $figs . '</div></section>';
}

/** "Label|rate|unit" lines; rows without a label or a positive rate are dropped. Mirrors calcTypes() in blocks/calculator/View.tsx. */
function rk_builder_calc_types( $source ) {
	$out = array();
	foreach ( rk_builder_parse_rows( $source, 3, 8 ) as $row ) {
		if ( '' === $row[0] || ! is_numeric( $row[1] ) ) { continue; }
		$rate = 0 + $row[1];
		if ( ! is_finite( (float) $rate ) || $rate <= 0 ) { continue; }
		$out[] = array( 'label' => $row[0], 'rate' => $rate, 'unit' => $row[2] );
	}
	return $out;
}

/** Whole dollars with thousands separators, e.g. "$4,400". Mirrors calcMoney(). */
function rk_builder_calc_money( $n ) {
	return '$' . number_format( round( $n ), 0, '.', ',' );
}

function rk_builder_render_calculator( array $p, array $context = array() ) {
	$types = rk_builder_calc_types( $p['types'] );
	$cur   = $types ? $types[0] : null;
	$href  = rk_builder_is_safe_link( $p['ctaHref'] ) ? $p['ctaHref'] : '';
	$html  = '<section ' . rk_builder_root_attrs( 'calculator', 'pf-section pf-calc' ) . '><div class="pf-wrap pf-calc-grid"><div class="pf-calc-form">';
	if ( '' !== $p['heading'] ) { $html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>'; }
	$html .= '<label>Project type<select data-calc-type="">';
	foreach ( $types as $k => $t ) {
		$html .= '<option value="' . $k . '" data-rate="' . rk_builder_h( (string) $t['rate'] ) . '" data-unit="' . rk_builder_h( $t['unit'] ) . '">' . rk_builder_h( $t['label'] ) . '</option>';
	}
	$html .= '</select></label><label><span data-calc-unit="">' . rk_builder_h( $cur ? $cur['unit'] : '' ) . '</span>';
	$html .= '<input type="number" min="1" data-calc-amount="" value="' . (int) $p['amount'] . '"/></label></div><div class="pf-calc-result">';
	if ( '' !== $p['resultLabel'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['resultLabel'] ) . '</p>'; }
	$html .= '<p class="pf-calc-total" data-calc-total="">' . rk_builder_h( rk_builder_calc_money( ( $cur ? $cur['rate'] : 0 ) * (int) $p['amount'] ) ) . '</p>';
	if ( '' !== $p['note'] ) { $html .= '<p class="pf-calc-note">' . rk_builder_h( $p['note'] ) . '</p>'; }
	if ( '' !== $p['ctaLabel'] && '' !== $href ) {
		$html .= '<a class="pf-btn dark" href="' . rk_builder_href( $href ) . '">' . rk_builder_h( $p['ctaLabel'] ) . rk_builder_arrow_right_icon() . '</a>';
	}
	return $html . '</div></div></section>';
}

function rk_builder_render_brandstrip( array $p, array $context = array() ) {
	$html = '<section ' . rk_builder_root_attrs( 'brandstrip', 'pf-section pf-brands' ) . '><div class="pf-wrap">';
	if ( '' !== $p['label'] ) { $html .= '<p class="pf-brands-label">' . rk_builder_h( $p['label'] ) . '</p>'; }
	$names = rk_builder_lines( $p['items'], 12 );
	if ( $names ) {
		$html .= '<ul>';
		foreach ( $names as $n ) { $html .= '<li>' . rk_builder_h( $n ) . '</li>'; }
		$html .= '</ul>';
	}
	return $html . '</div></section>';
}

/** Five rating stars. Mirrors Stars() in blocks/reviews/View.tsx. */
function rk_builder_stars( $rating ) {
	$on  = max( 0, min( 5, (int) round( (float) $rating ) ) );
	$out = '<span class="pf-stars" role="img" aria-label="' . $on . ' out of 5">';
	for ( $i = 0; $i < 5; $i++ ) {
		$out .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="' . ( $i < $on ? 'currentColor' : 'none' ) . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-star' . ( $i < $on ? ' on' : '' ) . '" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>';
	}
	return $out . '</span>';
}

function rk_builder_review_clip( $s, $n ) {
	return rk_builder_strlen( $s ) > $n ? rtrim( rk_builder_substr( $s, 0, $n - 1 ) ) . '…' : $s;
}

/** The Google reviews block. Mirrors blocks/reviews/View.tsx. */
function rk_builder_render_reviews( array $p, array $context = array() ) {
	$data    = rk_builder_reviews_public( 12, (int) $p['minRating'] );
	$items   = array_slice( $data['items'], 0, (int) $p['limit'] );
	$profile = rk_builder_safe_href( $data['links']['profile'] );
	$write   = rk_builder_safe_href( $data['links']['write'] );
	$html    = '<section ' . rk_builder_root_attrs( 'reviews', 'pf-section pf-reviews ' . $p['tone'] ) . '><div class="pf-wrap">';
	if ( '' !== $p['eyebrow'] || '' !== $p['heading'] || '' !== $p['intro'] ) {
		$html .= '<div class="pf-center">';
		if ( '' !== $p['eyebrow'] ) { $html .= '<p class="pf-kicker">' . rk_builder_h( $p['eyebrow'] ) . '</p>'; }
		if ( '' !== $p['heading'] ) { $html .= '<h2>' . rk_builder_h( $p['heading'] ) . '</h2>'; }
		if ( '' !== $p['intro'] ) { $html .= '<p class="pf-intro">' . rk_builder_h( $p['intro'] ) . '</p>'; }
		$html .= '</div>';
	}
	if ( ! empty( $p['showSummary'] ) && $data['summary']['count'] > 0 ) {
		$n = $data['summary']['count'];
		$html .= '<div class="pf-rv-summary">' . rk_builder_stars( $data['summary']['rating'] ) . '<strong>' . number_format( (float) $data['summary']['rating'], 1, '.', '' ) . '</strong><span>' . $n . ' Google review' . ( 1 === $n ? '' : 's' ) . '</span></div>';
	}
	if ( ! empty( $p['showLinks'] ) && ( '' !== $profile || '' !== $write ) ) {
		$html .= '<div class="pf-rv-links">';
		if ( '' !== $profile ) { $html .= '<a class="pf-btn outline" href="' . $profile . '" target="_blank" rel="noopener noreferrer">See all reviews on Google</a>'; }
		if ( '' !== $write ) { $html .= '<a class="pf-btn dark" href="' . $write . '" target="_blank" rel="noopener noreferrer">Leave a review</a>'; }
		$html .= '</div>';
	}
	$html .= '<div class="pf-cardgrid pf-rv-grid" style="grid-template-columns:repeat(' . (int) $p['cols'] . ', minmax(0, 1fr))">';
	foreach ( $items as $r ) {
		$html .= '<article>' . rk_builder_stars( $r['rating'] );
		if ( '' !== $r['text'] ) { $html .= '<p class="pf-rv-text">' . rk_builder_h( rk_builder_review_clip( $r['text'], 280 ) ) . '</p>'; }
		$html .= '<footer><strong>' . rk_builder_h( $r['author'] ) . '</strong>';
		if ( '' !== $r['date'] ) { $html .= '<small>' . rk_builder_h( $r['date'] ) . '</small>'; }
		if ( 'google' === $r['source'] ) { $html .= '<span class="pf-rv-src">Google</span>'; }
		$html .= '</footer></article>';
	}
	return $html . '</div></div></section>';
}
