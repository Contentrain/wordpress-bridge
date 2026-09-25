<?php
/**
 * Block-theme menus parity with @contentrain/wp-import: every case of
 * tests/fixtures/menu-parity.json (copied unchanged from Contentrain/ai, the
 * commit in menu-parity.pin) through `Menus::from_blocks()`, with the
 * fixture's public posts, terms and pages standing in for the site. Both
 * sides hold to the same file, so a rule change on either side shows here or
 * in wp-import's own menu parity test.
 *
 * Included by tests/integration.php, which provides `check()`.
 *
 * @package ContentrainBridge
 */

use Contentrain\Bridge\Menus;

( static function () {
	$parity = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/menu-parity.json' ), true );
	check( is_array( $parity ) && 'contentrain-menu-parity@1' === ( $parity['format'] ?? null ) && ! empty( $parity['cases'] ), 'the menu parity fixture is readable' );

	// The fixture's origin is this site's home: an address on it (www. or not) is one here.
	$home = untrailingslashit( home_url() );
	$home_parts = wp_parse_url( $home );
	$origin_parts = wp_parse_url( $parity['origin'] );
	$here = array(
		$origin_parts['scheme'] . '://www.' . $origin_parts['host'] => $home_parts['scheme'] . '://www.' . $home_parts['host'] . ( isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '' ),
		$parity['origin'] => $home,
	);
	$localize = static function ( $value ) use ( &$localize, $here ) {
		if ( is_array( $value ) ) {
			return array_map( $localize, $value );
		}
		return is_string( $value ) ? strtr( $value, $here ) : $value;
	};
	$public = $localize( $parity['public'] );

	$lookup = array(
		'post' => static function ( $id ) use ( $public ) {
			foreach ( $public['posts'] as $post ) {
				if ( (int) $post['id'] === (int) $id ) {
					return array( 'type' => $post['type'], 'slug' => $post['slug'], 'public' => true, 'link' => $post['link'] );
				}
			}
			return null;
		},
		'term' => static function ( $taxonomy, $id ) use ( $public ) {
			foreach ( $public['terms'] as $term ) {
				if ( (int) $term['id'] === (int) $id && $term['taxonomy'] === $taxonomy ) {
					return array( 'slug' => $term['slug'], 'link' => $term['link'] );
				}
			}
			return null;
		},
	);
	$pages = array_values( array_filter( $public['posts'], static function ( $post ) { return 'page' === $post['type']; } ) );
	$target = static function ( $attrs ) use ( $lookup ) { return Menus::target( $attrs, $lookup ); };

	/** A menu as the fixture compares it: slug, name, locations, items { title, url, parent as ancestor titles }. */
	$shape = static function ( $menu ) {
		$by_id = array_column( $menu['items'], null, 'id' );
		$out = array( 'slug' => $menu['slug'], 'name' => $menu['name'] );
		if ( ! empty( $menu['locations'] ) ) {
			$out['locations'] = $menu['locations'];
		}
		$out['items'] = array_map(
			static function ( $item ) use ( $by_id ) {
				$path = array();
				for ( $p = $item['parent']; null !== $p && isset( $by_id[ $p ] ); $p = $by_id[ $p ]['parent'] ) {
					array_unshift( $path, $by_id[ $p ]['title'] );
				}
				return array( 'title' => $item['title'], 'url' => $item['url'], 'parent' => $path );
			},
			$menu['items']
		);
		return $out;
	};

	foreach ( $parity['cases'] as $case ) {
		$case = $localize( $case );
		$navigations = array_map(
			static function ( $nav ) {
				return array( 'id' => $nav['id'], 'slug' => $nav['slug'], 'title' => $nav['title'], 'date' => $nav['date_gmt'], 'status' => $nav['status'], 'content' => $nav['content'] );
			},
			$case['navigations']
		);
		$result = Menus::from_blocks( $case['templates'], $case['parts'], $navigations, $case['taken'] ?? array(), $target, $pages );
		$got = array( 'menus' => array_map( $shape, $result['menus'] ), 'dropped' => $result['dropped'] );
		check( wp_json_encode( $case['expect'] ) === wp_json_encode( $got ), 'menu parity: ' . $case['name'] . ' — want ' . wp_json_encode( $case['expect'] ) . ' got ' . wp_json_encode( $got ) );
	}
} )();
