<?php
/**
 * Redirection → RawRedirect parity with @contentrain/wp-import: every case of
 * tests/fixtures/redirect-parity.json (copied unchanged from Contentrain/ai,
 * the commit in redirect-parity.pin) through `Redirects::redirection_rules()`,
 * fed the fixture's Redirection rows as its tables hold them. wp-import reads
 * the same rules over REST and holds to the same file.
 *
 * Included by tests/integration.php, which provides `check()`.
 *
 * @package ContentrainBridge
 */

use Contentrain\Bridge\Redirects;

( static function () {
	$parity = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/redirect-parity.json' ), true );
	check( is_array( $parity ) && 'contentrain-redirect-parity@1' === ( $parity['format'] ?? null ) && ! empty( $parity['cases'] ), 'the redirect parity fixture is readable' );

	// The fixture's origin is this site's home.
	$here = array( $parity['origin'] => untrailingslashit( home_url() ) );
	$groups = array_column( $parity['groups'], null, 'id' );
	// As Redirection's tables hold them: flags and condition data are JSON, booleans are 0/1.
	$rows = array_map(
		static function ( $item ) use ( $here ) {
			$item['regex'] = $item['regex'] ? 1 : 0;
			$item['action_data'] = is_string( $item['action_data'] ) ? strtr( $item['action_data'], $here ) : wp_json_encode( $item['action_data'] );
			$item['match_data'] = null === $item['match_data'] ? null : wp_json_encode( $item['match_data'] );
			return $item;
		},
		$parity['items']
	);
	/** Order-free comparison, as the fixture says: keys sorted at every level. */
	$canonical = static function ( $value ) use ( &$canonical ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( $canonical, $value );
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	};

	foreach ( $parity['cases'] as $case ) {
		if ( 'rest' === ( $case['only'] ?? null ) ) {
			continue; // The Bridge always reads Redirection's options.
		}
		$doc = array( 'redirects' => array(), 'excluded' => array() );
		Redirects::redirection_rules( $doc, $groups, $rows, null === $case['options'] ? false : $case['options'], true );
		foreach ( array( 'redirects', 'excluded' ) as $list ) {
			usort( $doc[ $list ], static function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
		}
		$got = $canonical( array( 'redirects' => $doc['redirects'], 'excluded' => $doc['excluded'], 'rules' => count( $rows ) ) );
		$want = $canonical( $case['expect'] );
		check( wp_json_encode( $want ) === wp_json_encode( $got ), 'redirect parity: ' . $case['name'] . ' — want ' . wp_json_encode( $want ) . ' got ' . wp_json_encode( $got ) );
	}
} )();
