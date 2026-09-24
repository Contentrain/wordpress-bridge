<?php
/** The head a page serves, read the way a crawler reads it. Shared by the SEO acceptance scripts. */

/** Ask the running site, the way a crawler would: no redirects followed. */
function fetch( $path ) {
	$context = stream_context_create( array( 'http' => array( 'header' => 'Host: ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) . "\r\n", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20 ) ) );
	$body = file_get_contents( 'http://127.0.0.1' . $path, false, $context ); // phpcs:ignore
	$status = 0;
	$location = null;
	foreach ( $http_response_header as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d{3})#', $line, $m ) ) { $status = (int) $m[1]; }
		if ( preg_match( '#^Location:\s*(.+)$#i', $line, $m ) ) { $location = trim( $m[1] ); }
	}
	return array( 'status' => $status, 'location' => $location, 'html' => (string) $body );
}
function decode( $value ) { return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
/** The head as served, in the same shape as the exported `yoast` block. */
function head( $html ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	$out = array( 'open_graph' => array(), 'twitter' => array(), 'types' => array() );
	foreach ( $doc->getElementsByTagName( 'title' ) as $node ) {
		if ( ! isset( $out['title'] ) && ! ( $node->parentNode && 'svg' === $node->parentNode->nodeName ) ) { $out['title'] = trim( $node->textContent ); }
	}
	foreach ( $doc->getElementsByTagName( 'meta' ) as $node ) {
		$name = $node->getAttribute( 'name' ) ?: $node->getAttribute( 'property' );
		$content = $node->getAttribute( 'content' );
		if ( 'description' === $name ) { $out['description'] = $content; }
		elseif ( 'robots' === $name ) { $out['robots'] = array_map( 'trim', explode( ',', $content ) ); sort( $out['robots'] ); }
		elseif ( in_array( $name, array( 'og:title', 'og:description', 'og:type', 'og:url', 'og:image', 'og:site_name' ), true ) && ! isset( $out['open_graph'][ substr( $name, 3 ) ] ) ) { $out['open_graph'][ substr( $name, 3 ) ] = $content; }
		elseif ( in_array( $name, array( 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image' ), true ) ) { $out['twitter'][ substr( $name, 8 ) ] = $content; }
	}
	foreach ( $doc->getElementsByTagName( 'link' ) as $node ) {
		if ( 'canonical' === $node->getAttribute( 'rel' ) ) { $out['canonical'] = $node->getAttribute( 'href' ); }
	}
	foreach ( $doc->getElementsByTagName( 'script' ) as $node ) {
		if ( 'application/ld+json' === $node->getAttribute( 'type' ) ) {
			$out['types'] = array_merge( $out['types'], \Contentrain\Bridge\Seo::schema_types( json_decode( $node->textContent, true ) ) );
		}
	}
	$out['types'] = array_values( array_unique( $out['types'] ) );
	sort( $out['types'] );
	ksort( $out['open_graph'] );
	ksort( $out['twitter'] );
	return $out;
}
