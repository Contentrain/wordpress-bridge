<?php
/**
 * B-08 acceptance: every piece of hardcoded interface text is accounted for.
 * Runs inside the test container with tests/fixtures/bridge-text-theme active
 * (tests/text.sh). Rendered pages are fetched from the running site.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Scanner;
use Contentrain\Bridge\Text;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
if ( 'bridge-text-theme' !== get_option( 'stylesheet' ) ) {
	throw new RuntimeException( 'FAIL: the fixture theme is not active' );
}
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );

// The site answers on port 80 inside the container, not on the host port in home_url.
$home = untrailingslashit( home_url() );
$route = static function ( $pre, $args, $url ) use ( $home ) {
	if ( 0 !== strpos( $url, $home ) || ! empty( $args['_bridge_routed'] ) ) {
		return $pre;
	}
	$args['_bridge_routed'] = true;
	$args['headers']['Host'] = wp_parse_url( $home, PHP_URL_HOST ) . ':' . wp_parse_url( $home, PHP_URL_PORT );
	$args['redirection'] = 0;
	return wp_remote_get( 'http://127.0.0.1' . substr( $url, strlen( $home ) ), $args );
};
add_filter( 'pre_http_request', $route, 10, 3 );
// One render state that cannot be fetched: an error outcome, not a silent gap.
add_filter( 'contentrain_bridge_render_states', static function ( $states ) { $states['unreachable'] = 'http://127.0.0.1:1/'; return $states; } );

// ---- Settings the scan reads: tagline, widgets, a menu, Customizer values. ----
update_option( 'blogdescription', 'Fresh food, told simply' );
update_option( 'widget_text', array( 2 => array( 'title' => 'Opening hours', 'text' => '<p>Weekdays from nine</p>', 'filter' => true, 'visual' => true ), '_multiwidget' => 1 ) );
update_option( 'widget_block', array( 3 => array( 'content' => '<!-- wp:heading --><h2>Find us</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Main street, next to the park</p><!-- /wp:paragraph -->' ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'footer' => array( 'text-2', 'block-3' ), 'array_version' => 3 ) );
set_theme_mod( 'footer_note', 'Crafted with care in Istanbul' );
set_theme_mod( 'cta_label', 'Book a table' );
set_theme_mod( 'header_textcolor', '333333' );
$menu = wp_get_nav_menu_object( 'Bridge text menu' ) ?: get_term( wp_create_nav_menu( 'Bridge text menu' ), 'nav_menu' );
if ( ! wp_get_nav_menu_items( $menu->term_id ) ) {
	wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => 'Our menu', 'menu-item-url' => home_url( '/our-menu/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => 'Contact', 'menu-item-url' => home_url( '/contact/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
}
set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu->term_id ) );

// ---- An independent count of every occurrence, straight from the scanners. ----
$locale = \Contentrain\Bridge\Source::locale( get_locale() );
$files = Scanner::files( false );
$found = 0;
$errors = 0;
foreach ( $files as $file ) {
	$r = Scanner::scan( $file, $locale );
	$found += count( $r['candidates'] ) + array_sum( $r['excluded_counts'] );
	$errors += count( $r['errors'] );
}
$r = Text::settings( $locale );
$found += count( $r['candidates'] ) + array_sum( $r['excluded_counts'] );
foreach ( Text::render_states() as $state => $url ) {
	$r = Text::render( $state, $url, $locale );
	$found += count( $r['candidates'] ) + array_sum( $r['excluded_counts'] );
	$errors += count( $r['errors'] );
}
check( count( $files ) >= 9 && $found > 50, count( $files ) . ' theme files, ' . $found . ' occurrences found by the scanners themselves' );

// ---- The export: scan, review, finish. ----
$previous = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
$s = Jobs::create( array( 'types' => array( 'post', 'page' ), 'private' => false, 'scan_sources' => true, 'scan_render' => true ) );
for ( $i = 0; $i < 3000 && 'review' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
check( 'review' === $s['phase'], 'the scan reaches review' );
$job = Jobs::read( $s['id'] );
$candidates = $job['candidates'];
$doc = Text::document( $candidates, $job['text']['errors'], $job['text']['counts'] );

// ---- Zero loss: the inventory accounts for every occurrence, and every candidate has one outcome. ----
check( $found === $doc['totals']['occurrences'], "zero loss: the inventory accounts for all $found occurrences (" . $doc['totals']['occurrences_listed'] . ' listed, ' . $doc['totals']['unlisted_excluded'] . ' counted beyond the listing cap)' );
check( $errors === count( $doc['errors'] ) && $doc['totals']['by_outcome']['error'] === $errors, "$errors unreadable sources are reported as errors" );
$bad = array_filter( $candidates, static function ( $c ) {
	return ! ( ( 'transfer' === $c['outcome'] && ! empty( $c['target'] ) && ! isset( $c['reason'] ) ) || ( 'exclude' === $c['outcome'] && ! empty( $c['reason'] ) && ! isset( $c['target'] ) ) );
} );
check( ! $bad, 'every one of ' . count( $candidates ) . ' candidates carries exactly one outcome: transfer with a target, or exclude with a reason' );
check( $doc['totals']['by_outcome']['transfer'] + $doc['totals']['by_outcome']['exclude'] === count( $candidates ), 'outcome totals add up to the candidate count' );

// ---- Every fixture string, where it should be. ----
$find = static function ( $text, $context = null ) use ( $candidates ) {
	return array_values( array_filter( $candidates, static function ( $c ) use ( $text, $context ) { return $text === $c['value'] && ( null === $context || $context === $c['context'] ); } ) );
};
$expect = array(
	// text, context, outcome, reason-or-target
	array( 'Skip to content', 'gettext', 'transfer', 'dictionary:ui-strings' ),
	array( 'Nothing matched your search.', 'gettext', 'transfer', 'dictionary:ui-strings' ),
	array( 'Try a search instead.', 'gettext', 'transfer', 'dictionary:ui-strings' ),
	array( 'All rights reserved.', 'gettext', 'transfer', 'dictionary:ui-strings' ),
	array( 'One guest', 'gettext', 'transfer', 'dictionary:ui-strings' ),
	array( '%s guests', 'gettext:plural', 'transfer', 'dictionary:ui-strings' ),
	array( 'Menu', 'button', 'transfer', 'dictionary:ui-strings' ),
	array( 'Search the menu', 'label', 'transfer', 'dictionary:ui-strings' ),
	array( 'Type a dish', 'input@placeholder', 'transfer', 'dictionary:ui-strings' ),
	array( 'Find', 'input@value', 'transfer', 'dictionary:ui-strings' ),
	array( 'Main navigation', 'nav@aria-label', 'transfer', 'dictionary:ui-strings' ),
	array( 'Follow us', 'p', 'transfer', 'dictionary:ui-strings' ),
	array( 'Closed on Mondays', 'p', 'transfer', 'dictionary:ui-strings' ),
	array( 'Page not found', 'h1', 'transfer', 'dictionary:ui-strings' ),
	array( 'Book a table today', 'h2', 'transfer', 'dictionary:ui-strings' ),
	array( 'Walk-ins welcome', 'p', 'transfer', 'dictionary:ui-strings' ),
	array( 'Show more', 'js', 'transfer', 'dictionary:ui-strings' ),
	array( 'Loading your table…', 'js', 'transfer', 'dictionary:ui-strings' ),
	array( 'Thanks for subscribing!', 'js', 'transfer', 'dictionary:ui-strings' ),
	array( 'Opening hours', 'widget:title', 'transfer', 'dictionary:ui-strings' ),
	array( 'Weekdays from nine', 'widget:p', 'transfer', 'dictionary:ui-strings' ),
	array( 'Find us', 'widget:h2', 'transfer', 'dictionary:ui-strings' ),
	array( 'Fresh food, told simply', 'option:blogdescription', 'transfer', 'site.description' ),
	array( 'Our menu', 'menu', 'transfer', 'content:wp-menu-items' ),
	array( 'Crafted with care in Istanbul', 'customizer:footer_note', 'transfer', 'theme-settings.footer_note' ),
	array( 'Book a table', 'customizer:cta_label', 'transfer', 'theme-settings.cta_label' ),
	array( '333333', 'customizer:header_textcolor', 'exclude', 'number' ),
	array( '.menu-toggle', 'js', 'exclude', 'code' ),
	array( 'data-toggle', 'js', 'exclude', 'code' ),
	array( 'https://example.org/api/v1', 'js', 'exclude', 'url' ),
	array( '2026', 'js', 'exclude', 'number' ),
	array( '[redacted]', 'js', 'exclude', 'secret' ),
	array( '$label', 'gettext', 'exclude', 'dynamic' ),
	array( '%s', 'gettext', 'exclude', 'placeholder-only' ),
	array( 'https://example.org/privacy', 'a', 'exclude', 'url' ),
);
foreach ( $expect as list( $text, $context, $outcome, $detail ) ) {
	$hits = $find( $text, $context );
	$got = $hits ? $hits[0]['outcome'] . ':' . ( $hits[0]['target'] ?? $hits[0]['reason'] ) : 'missing';
	check( 1 === count( $hits ) && $outcome === $hits[0]['outcome'] && $detail === ( $hits[0]['target'] ?? $hits[0]['reason'] ), sprintf( '%-32s %-26s -> %s', '"' . $text . '"', $context, $got ) );
}
check( count( $expect ) >= 20, count( $expect ) . ' fixture strings checked by exact text, context and outcome' );

// ---- Deduplication: only the same text, locale and context merge. ----
$hours = $find( 'Opening hours', 'h2' );
check( 1 === count( $hours ) && 2 === count( array_unique( array_column( $hours[0]['occurrences'], 'source' ) ) ), 'the same text in the same context in two files is one candidate with both occurrences' );
check( 1 === count( $find( 'Read more', 'a' ) ) && 1 === count( $find( 'Read more', 'button' ) ), '"Read more" in a link and on a button are two candidates' );
check( 1 === count( $find( 'Post', 'gettext:noun' ) ) && 1 === count( $find( 'Post', 'gettext:verb' ) ), 'gettext context keeps _x( "Post", "noun" ) and _x( "Post", "verb" ) apart' );
$hours_all = $find( 'Opening hours' );
$hours_transfer = array_values( array_filter( $hours_all, static function ( $c ) { return 'transfer' === $c['outcome']; } ) );
$hours_render = array_values( array_filter( $hours_all, static function ( $c ) { return 'render' === $c['kind']; } ) );
check( array( 'h2', 'widget:title' ) === array_values( array_unique( array_map( static function ( $c ) { return $c['context']; }, $hours_transfer ) ) ) && 2 === count( $hours_transfer ), '"Opening hours" is transferred twice: as a template heading and as a widget title' );
check( 3 === count( $hours_render ) && array( 'rendered-from-source' ) === array_values( array_unique( array_column( $hours_render, 'reason' ) ) ) && array( 'footer>h2', 'footer>h3', 'header>h2' ) === ( static function ( $l ) { sort( $l ); return $l; } )( array_column( $hours_render, 'context' ) ), 'and seen on the page in header, footer and widget, each linked to its source, none transferred again' );
$rendered = array_values( array_filter( $candidates, static function ( $c ) { return 'render' === $c['kind'] && 'Skip to content' === $c['value']; } ) );
check( $rendered && 'rendered-from-source' === $rendered[0]['reason'] && in_array( $find( 'Skip to content', 'gettext' )[0]['id'], $rendered[0]['related'], true ), 'a source string seen on the page links to its source instead of being transferred twice' );
$title = array_values( array_filter( $candidates, static function ( $c ) { return 'render' === $c['kind'] && 'Hello world!' === $c['value']; } ) );
check( $title && 'content' === $title[0]['reason'], 'a post title on the page is content, excluded as such' );
$transfers = array_filter( $candidates, static function ( $c ) { return 'transfer' === $c['outcome'] && 0 === strpos( $c['target'], 'dictionary:' ); } );
$pairs = array();
foreach ( $transfers as $c ) { $pairs[ $c['locale'] . '|' . $c['context'] . '|' . $c['value'] ][] = $c['id']; }
check( count( $pairs ) === count( $transfers ), 'no two dictionary candidates share text, locale and context (no false duplicates)' );

// ---- Render states, and errors as outcomes. ----
$by_state = array();
foreach ( $candidates as $c ) { foreach ( $c['occurrences'] as $o ) { if ( 'render' === $o['kind'] ) { $by_state[ $o['source'] ] = true; } } }
check( isset( $by_state['render:home'], $by_state['render:not-found'], $by_state['render:search'], $by_state['render:single'] ), 'home, single, search and not-found pages were read: ' . implode( ', ', array_keys( $by_state ) ) );
$render_only = array_filter( $candidates, static function ( $c ) { return 'render' === $c['kind'] && 'transfer' === $c['outcome']; } );
check( 1 === count( array_filter( $render_only, static function ( $c ) { return 'Search for:' === $c['value']; } ) ), count( $render_only ) . ' text(s) found only on rendered pages, among them core\'s search form label "Search for:", transferred' );
$year = array_values( array_filter( $candidates, static function ( $c ) { return 'render' === $c['kind'] && 'All rights reserved. 2026' === $c['value']; } ) );
check( $year && 'rendered-from-source' === $year[0]['reason'] && in_array( $find( 'All rights reserved.', 'gettext' )[0]['id'], $year[0]['related'], true ), 'a source string rendered with a run-time year beside it links to its source' );
check( in_array( 'render:unreachable', array_column( $doc['errors'], 'source' ), true ), 'an unreachable render state is an error, not a gap' );
check( in_array( 'themes/bridge-text-theme/assets/huge.js', array_column( $doc['errors'], 'source' ), true ), 'a source file over the size limit is an error, not a gap' );

// ---- Keys: stable, collision-free, independent of file and line. ----
$again = Text::merge( $job['text']['occurrences'], Text::content_texts() );
check( array_column( $again, 'key', 'id' ) === array_column( $candidates, 'key', 'id' ), 'keys are identical on a second merge' );
$moved = array_map( static function ( $o ) { $o['source'] = 'elsewhere.php'; $o['line'] = 999; return $o; }, $job['text']['occurrences'] );
check( array_column( Text::merge( $moved, Text::content_texts() ), 'key', 'id' ) === array_column( $candidates, 'key', 'id' ), 'moving every string to another file and line changes no key' );
check( count( array_unique( array_column( $candidates, 'key' ) ) ) === count( $candidates ), 'every key is unique' );
$invalid = array_filter( array_column( $candidates, 'key' ), static function ( $k ) { return ! preg_match( '/^[a-z][a-z0-9_.-]{1,120}$/D', $k ); } );
check( ! $invalid && (bool) preg_match( '/^ui\.p\.guncel-icerik-[0-9a-f]{6}$/', $find( 'Güncel içerik → Истории', 'p' )[0]['key'] ?? '' ), 'every key is a valid dictionary key, non-Latin text included: ' . ( $find( 'Güncel içerik → Истории', 'p' )[0]['key'] ?? 'missing' ) . ( $invalid ? ' — invalid: ' . implode( ', ', $invalid ) : '' ) );
check( (bool) preg_match( '/^ui\.button\.menu-[0-9a-f]{6}$/', $find( 'Menu', 'button' )[0]['key'] ), 'keys read as group.context.words-digest: ' . $find( 'Menu', 'button' )[0]['key'] );

// ---- Finish: dictionary, Customizer singleton, the inventory file; no secret anywhere. ----
$decisions = array();
foreach ( $candidates as $id => $c ) { if ( 'review' === $c['decision'] ) { $decisions[ $id ] = array( 'decision' => 'include', 'key' => $c['key'] ); } }
$secret = array_values( array_filter( $candidates, static function ( $c ) { return 'secret' === ( $c['reason'] ?? '' ); } ) )[0];
try {
	Jobs::review( $s['id'], array( $secret['id'] => array( 'decision' => 'include', 'key' => $secret['key'] ) ) );
	check( false, 'a redacted secret cannot be included' );
} catch ( RuntimeException $e ) {
	check( false !== strpos( $e->getMessage(), 'cannot be included' ), 'a redacted secret cannot be included' );
}
$s = Jobs::review( $s['id'], $decisions, true );
for ( $i = 0; $i < 3000 && 'ready' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
check( 'ready' === $s['phase'], 'the export finishes with ' . count( $decisions ) . ' reviewed strings' );
$job = Jobs::read( $s['id'] );
$out = Files::dir( $job['id'] ) . '/output';
$dictionary = json_decode( Files::read( $out, '.contentrain/content/system/ui-strings/data.json' ), true );
check( count( $dictionary ) === count( $decisions ) && $dictionary[ $find( 'Menu', 'button' )[0]['key'] ] === 'Menu', 'every reviewed string is a ui-strings dictionary entry under its key (' . count( $dictionary ) . ')' );
$settings = json_decode( Files::read( $out, '.contentrain/content/system/theme-settings/data.json' ), true );
check( 'Crafted with care in Istanbul' === $settings['footer_note'] && 'Book a table' === $settings['cta_label'], 'Customizer text is a theme-settings singleton, a field per setting' );
$inventory = json_decode( Files::read( $out, 'bridge/hardcoded-text.json' ), true );
check( 'contentrain-bridge-hardcoded-text@1' === $inventory['format'] && $found === $inventory['totals']['occurrences'], 'bridge/hardcoded-text.json carries the inventory and its totals' );
$all = '';
foreach ( array_keys( $job['files'] ) as $path ) { $all .= Files::read( $out, $path ); }
check( false === strpos( $all, 'ghp_' ), 'the planted token is in no exported file' );

// ---- Artifacts for the canonical store check. ----
$copy = '/tmp/bridge-text';
Files::remove( $copy );
foreach ( array_keys( $job['files'] ) as $path ) {
	wp_mkdir_p( dirname( $copy . '/store/' . $path ) );
	copy( Files::path( $out, $path ), $copy . '/store/' . $path );
}
Files::remove( Files::dir( $job['id'] ) );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
if ( $previous ) { update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $previous ); }
echo "\n$checks checks passed. Text output: $copy\n";
