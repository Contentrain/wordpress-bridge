<?php
/**
 * WPML through its own filters, in the test container only. WPML is a paid
 * plugin with no licence here, so its filters are FAKED below with the
 * shapes WPML documents; this proves the Bridge asks WPML the right
 * questions and shapes the answers like Polylang's, not that a real WPML
 * site answers this way. Run with Polylang skipped:
 *   wp eval-file tests/languages-wpml.php --skip-plugins=polylang,acf-options-for-polylang
 */
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
use Contentrain\Bridge\Languages;
use Contentrain\Bridge\Source;

// `wp eval-file` runs this file inside a function, so the count lives in $GLOBALS, not a file-scope variable.
$GLOBALS['bridge_wpml_checks'] = 0;
function check( $value, $message ) {
	if ( ! $value ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
	++$GLOBALS['bridge_wpml_checks'];
}
check( ! function_exists( 'pll_languages_list' ), 'Polylang is skipped for this run' );
check( null === Languages::plugin() && null === Languages::document() && array() === Languages::strings(), 'without a multilingual plugin there is no language data' );

$post_en = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'WPML article', 'post_status' => 'publish' ) );
$post_de = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'WPML Artikel', 'post_status' => 'publish' ) );
$cat_en = wp_insert_term( 'WPML category ' . wp_generate_password( 6, false ), 'category' );
$cat_de = wp_insert_term( 'WPML Kategorie ' . wp_generate_password( 6, false ), 'category' );
$menu_en = wp_update_nav_menu_object( 0, array( 'menu-name' => 'WPML Main ' . wp_generate_password( 6, false ) ) );
$menu_de = wp_update_nav_menu_object( 0, array( 'menu-name' => 'WPML Haupt ' . wp_generate_password( 6, false ) ) );
$tt = static function ( $term_id, $taxonomy ) { return (int) get_term( $term_id, $taxonomy )->term_taxonomy_id; };

// What a WPML site with en (default, unprefixed) and de (/de/) answers.
$posts = array( $post_en => 'en', $post_de => 'de' );
$terms = array(
	$tt( $cat_en['term_id'], 'category' ) => array( 'en', 101, (int) $cat_en['term_id'] ),
	$tt( $cat_de['term_id'], 'category' ) => array( 'de', 101, (int) $cat_de['term_id'] ),
	$tt( $menu_en, 'nav_menu' ) => array( 'en', 202, (int) $menu_en ),
	$tt( $menu_de, 'nav_menu' ) => array( 'de', 202, (int) $menu_de ),
);
$current = 'en';
add_filter( 'wpml_active_languages', static function () {
	return array(
		'en' => array( 'code' => 'en', 'default_locale' => 'en_US', 'native_name' => 'English', 'url' => home_url( '/' ), 'tag' => 'en-US' ),
		'de' => array( 'code' => 'de', 'default_locale' => 'de_DE', 'native_name' => 'Deutsch', 'url' => home_url( '/de/' ), 'tag' => 'de-DE' ),
	);
} );
add_filter( 'wpml_default_language', static function () { return 'en'; } );
add_filter( 'wpml_current_language', static function () use ( &$current ) { return $current; } );
add_action( 'wpml_switch_language', static function ( $code ) use ( &$current ) { $current = $code ?: 'en'; } );
add_filter( 'wpml_setting', static function ( $default, $name ) {
	return array( 'language_negotiation_type' => 1, 'urls' => array( 'directory_for_default_language' => 0 ) )[ $name ] ?? $default;
}, 10, 2 );
add_filter( 'wpml_is_rtl', static function () { return false; } );
add_filter( 'wpml_object_id', static function ( $id ) { return $id; } );
add_filter( 'wpml_post_language_details', static function ( $details, $id ) use ( $posts ) {
	return isset( $posts[ $id ] ) ? array( 'language_code' => $posts[ $id ] ) : $details;
}, 10, 2 );
add_filter( 'wpml_element_trid', static function ( $trid, $id ) use ( $posts ) { return isset( $posts[ $id ] ) ? 909 : $trid; }, 10, 2 );
add_filter( 'wpml_element_language_details', static function ( $details, $args ) use ( $terms ) {
	$row = $terms[ (int) $args['element_id'] ] ?? null;
	return $row ? (object) array( 'language_code' => $row[0], 'trid' => $row[1] ) : $details;
}, 10, 2 );
add_filter( 'wpml_get_element_translations', static function ( $translations, $trid, $type ) use ( $posts, $terms ) {
	$out = array();
	if ( 909 === (int) $trid && 'post_post' === $type ) {
		foreach ( $posts as $id => $code ) { $out[ $code ] = (object) array( 'element_id' => $id, 'language_code' => $code ); }
	}
	foreach ( $terms as $element => $row ) {
		if ( (int) $trid === $row[1] ) { $out[ $row[0] ] = (object) array( 'element_id' => $element, 'term_id' => $row[2], 'language_code' => $row[0] ); }
	}
	return $out ?: $translations;
}, 10, 3 );
add_filter( 'wpml_translate_single_string', static function ( $value, $context, $name, $code ) {
	return 'WP' === $context && 'Blog Title' === $name && 'de' === $code ? 'Bridge Abnahme' : $value;
}, 10, 4 );

check( 'wpml' === Languages::plugin() && 'en' === Languages::default_code() && array( 'en', 'de' ) === Languages::codes(), 'WPML is read through its own filters: plugin, default, codes in its order' );
$doc = Languages::document();
$by_slug = array_column( $doc['list'], null, 'slug' );
check( 'wpml' === $doc['plugin'] && 'en' === $doc['default'] && 'directory' === $doc['scheme'] && true === $doc['hide_default'], 'WPML\'s negotiation type and default-language directory setting give the scheme and hide_default' );
check( 'de_DE' === $by_slug['de']['locale'] && 'de-DE' === $by_slug['de']['w3c'] && home_url( '/de/' ) === $by_slug['de']['home_url'] && false === $by_slug['de']['is_rtl'] && ! $by_slug['de']['is_default'] && $by_slug['en']['is_default'], 'a WPML language has the same fields as a Polylang one, WPML\'s own values' );
check( 'de' === Languages::post_code( get_post( $post_de ) ) && array( 'en' => $post_en, 'de' => $post_de ) === Source::translations( get_post( $post_de ) ), 'a post\'s code and its translation group come from WPML' );
check( 'de' === Languages::term_code( get_term( $cat_de['term_id'], 'category' ) ), 'a term\'s language comes from its tax_ element' );
$pair = Languages::term_translations( get_term( $cat_de['term_id'], 'category' ) );
check( array( 'en' => (int) $cat_en['term_id'], 'de' => (int) $cat_de['term_id'] ) === $pair && (int) $cat_en['term_id'] === Source::canonical( $pair ), 'a term translation group is keyed by code with term ids, its canonical member the default\'s' );
$menus = Languages::menus( array( array( 'id' => $menu_en ), array( 'id' => $menu_de ) ), array( 'primary' => $menu_en ) );
check( 'en' === $menus[ $menu_en ]['lang'] && 'de' === $menus[ $menu_de ]['lang'] && array( 'primary' ) === ( $menus[ $menu_de ]['locations'] ?? null ), 'a translated WPML menu shows where its default-language original is assigned' );
$strings = Languages::strings();
check( 1 === count( $strings ) && 'blogname' === $strings[0]['name'] && 'Bridge Acceptance' === $strings[0]['string'] && array( 'de' => 'Bridge Abnahme' ) === $strings[0]['translations'], 'the site title\'s WPML String Translation travels; an untranslated tagline does not' );
$seen = Languages::in_language( 'de', static function () { return apply_filters( 'wpml_current_language', null ); } );
check( 'de' === $seen && 'en' === apply_filters( 'wpml_current_language', null ), 'in_language switches WPML\'s language and puts it back' );

foreach ( array( $post_en, $post_de ) as $id ) { wp_delete_post( $id, true ); }
foreach ( array( $cat_en, $cat_de ) as $term ) { wp_delete_term( (int) $term['term_id'], 'category' ); }
foreach ( array( $menu_en, $menu_de ) as $menu ) { wp_delete_nav_menu( $menu ); }
echo 'WPML (faked filters): ' . $GLOBALS['bridge_wpml_checks'] . " checks passed\n";
