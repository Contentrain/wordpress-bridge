<?php
/**
 * ACF → Contentrain parity with @contentrain/wp-import: every case of
 * tests/fixtures/acf-parity.json (copied unchanged from Contentrain/ai, the
 * commit in acf-parity.pin) through `Acf::field()` as a collection entry
 * writes it. Both sides hold to the same file, so a mapping change on either
 * side shows here or in wp-import's own acf.parity.test.ts.
 *
 * Included by tests/integration.php, which provides `check()` and `$probe`.
 *
 * @package ContentrainBridge
 */

use Contentrain\Bridge\Acf;
use Contentrain\Bridge\Policy;

$parity = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/acf-parity.json' ), true );
check( is_array( $parity ) && 'contentrain-acf-parity@1' === ( $parity['format'] ?? null ) && ! empty( $parity['cases'] ), 'the ACF parity fixture is readable' );

/** The fixture's field as ACF's own schema states it: layouts are a list of { name, sub_fields }. */
$parity_schema = static function ( $field, $name ) use ( &$parity_schema ) {
	$schema = array( 'type' => $field['type'], 'name' => $name, 'key' => 'field_parity_' . $name );
	if ( isset( $field['choices'] ) ) {
		$schema['choices'] = array_combine( $field['choices'], $field['choices'] );
	}
	if ( ! empty( $field['multiple'] ) ) {
		$schema['multiple'] = 1;
	}
	foreach ( $field['sub_fields'] ?? array() as $sub ) {
		$schema['sub_fields'][] = $parity_schema( $sub, $sub['name'] );
	}
	foreach ( $field['layouts'] ?? array() as $layout => $subs ) {
		$schema['layouts'][] = array(
			'name' => $layout,
			'sub_fields' => array_map( static function ( $sub ) use ( $parity_schema ) { return $parity_schema( $sub, $sub['name'] ); }, $subs ),
		);
	}
	return $schema;
};

/** A definition as the fixture compares it: type, options, fields (order-free), items, required. */
$parity_def = static function ( $def ) use ( &$parity_def ) {
	if ( ! is_array( $def ) ) {
		return $def;
	}
	$out = array_intersect_key( $def, array_flip( array( 'type', 'options', 'required' ) ) );
	if ( isset( $def['fields'] ) ) {
		$out['fields'] = array_map( $parity_def, $def['fields'] );
		ksort( $out['fields'] );
	}
	if ( isset( $def['items'] ) ) {
		$out['items'] = $parity_def( $def['items'] );
	}
	ksort( $out );
	return $out;
};

// A date-time is written in the site's zone (`wp_timezone()`): each case says which site it is.
$parity_zone = get_option( 'timezone_string' );
$parity_offset = get_option( 'gmt_offset' );
foreach ( $parity['cases'] as $i => $case ) {
	$name = 'p' . $i;
	update_option( 'timezone_string', $case['site_timezone'] ?? $parity['site_timezone'] ?? 'UTC' );
	$before = $probe['counts']['warnings'];
	$result = Acf::field( $probe, $parity_schema( $case['field'], $name ), $case['value'] ?? null, $job['default_locale'], 'parity/' . $name, null, true );
	$dropped = $probe['counts']['warnings'] - $before;
	$expect = $case['expect'];
	list( $def, $value ) = is_array( $result ) ? $result : array( 'fallback', null );
	$got = array( 'def' => $parity_def( $def ), 'value' => null === $value ? null : Policy::canonical( $value ), 'dropped' => $dropped );
	$want = array( 'def' => $parity_def( $expect['def'] ), 'value' => array_key_exists( 'value', $expect ) ? Policy::canonical( $expect['value'] ) : null, 'dropped' => $expect['dropped'] ?? 0 );
	// Strict, through the canonical JSON: `true == 1` in PHP, not in a store.
	check( wp_json_encode( $want ) === wp_json_encode( $got ), 'ACF parity: ' . $case['name'] . ' — want ' . wp_json_encode( $want ) . ' got ' . wp_json_encode( $got ) );
}
update_option( 'timezone_string', $parity_zone );
update_option( 'gmt_offset', $parity_offset );
