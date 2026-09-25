<?php
/**
 * B-04 without any SEO or redirect plugin: the export must say "none", not
 * emit nulls or empty blocks a consumer reads as "no metadata on the page".
 * Arguments: `none` on a fresh site, `inactive` after the plugins were deactivated.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Redirects;
use Contentrain\Bridge\Routing;
use Contentrain\Bridge\Seo;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
$mode = $argv[1] ?? 'none';
$seo = Seo::document();
$redirects = Redirects::document();
$sample = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1 ) )[0];

if ( 'none' === $mode ) {
	check( ! defined( 'WPSEO_VERSION' ) && ! defined( 'REDIRECTION_VERSION' ), 'no SEO or redirect plugin is loaded' );
	check( 'none' === $seo['status'] && 'wordpress-core' === $seo['serving'], 'the SEO document says none: WordPress core serves the head' );
	check( array( 'absent' ) === array_values( array_unique( array_column( $seo['providers'], 'status' ) ) ) && 4 === count( $seo['providers'] ), 'every known provider is reported absent, by name' );
	check( '{}' === wp_json_encode( $seo['settings'] ), 'settings are an empty object, not null' );
	check( null === Seo::post( $sample ), 'a post has no SEO entry to write' );
	foreach ( array( 'redirection', 'yoast_premium', 'rank_math', 'safe_redirect_manager', 'simple_301_redirects' ) as $source ) {
		check( array( 'status' => 'absent' ) === $redirects['sources'][ $source ], "redirect source $source is reported absent" );
	}
	check( 'active' === $redirects['sources']['wordpress_old_slug']['status'] && array() === $redirects['excluded'], 'WordPress core old-slug redirects are still read' );
	$routing = Routing::document();
	check( isset( $routing['permalink_structure'], $routing['post_types'] ) && is_bool( $routing['plain'] ), 'routing needs no plugin' );
} else {
	check( ! defined( 'WPSEO_VERSION' ) && ! defined( 'REDIRECTION_VERSION' ), 'Yoast and Redirection were deactivated' );
	check( 'inactive-with-data' === $seo['providers']['yoast']['status'] && 'wordpress-core' === $seo['serving'], 'deactivated Yoast is inactive-with-data; core serves the head now' );
	check( '|' === $seo['settings']['yoast']['separator'], 'the separator resolves from the stored key without Yoast running' );
	$fixture = json_decode( file_get_contents( '/tmp/bridge-seo/fixture.json' ), true );
	$entry = Seo::post( get_post( $fixture['posts']['custom_title'] ) )['yoast'];
	check( ! isset( $entry['resolved'] ) && 'Custom %%title%% %%sep%% %%sitename%%' === $entry['stored']['title'] && 'custom title keyword' === $entry['focus_keyword'] && 'bridge' === $entry['rendered_by'], 'stored Yoast values are still exported, rendered by Bridge and not claimed as resolved' );
	// BR-16 parity: what Bridge renders from Yoast's templates equals what Yoast itself served while active.
	$served = json_decode( file_get_contents( '/tmp/bridge-seo/seo-entries.json' ), true );
	$diffs = array();
	foreach ( $fixture['posts'] as $key => $id ) {
		// A static front page and posts page render as the home and blog pages: Yoast's own rules, not the page's.
		if ( in_array( $key, array( 'front', 'blog' ), true ) ) { continue; }
		$yoast = $served[ 'post:' . $id ]['yoast'];
		$rendered = Seo::post( get_post( $id ) )['yoast']['rendered'];
		$want = array( 'title' => $yoast['title'] ?? '', 'description' => $yoast['description'] ?? '', 'index' => $yoast['robots']['index'] ?? 'index', 'follow' => $yoast['robots']['follow'] ?? 'follow' );
		$got = array( 'title' => $rendered['title'], 'description' => $rendered['description'], 'index' => $rendered['robots']['index'], 'follow' => $rendered['robots']['follow'] );
		if ( $want !== $got ) { $diffs[] = $key . ': yoast ' . wp_json_encode( $want ) . ' / bridge ' . wp_json_encode( $got ); }
	}
	foreach ( $diffs as $diff ) { echo "  $diff\n"; }
	check( ! $diffs, 'Bridge renders Yoast\'s templates to what Yoast served, title, description and robots, for ' . ( count( $fixture['posts'] ) - 2 ) . ' posts and pages' );
	$show = get_option( 'show_on_front' );
	update_option( 'show_on_front', 'posts' );
	$home = Seo::document()['home']['yoast'] ?? null;
	update_option( 'show_on_front', $show );
	check( $home && 0 === strpos( $home['rendered']['title'], get_bloginfo( 'name' ) ) && false === strpos( $home['rendered']['title'], '%%' ) && home_url( '/' ) === $home['rendered']['canonical'], 'a home page that lists posts gets its own rendered head in seo.json: ' . ( $home['rendered']['title'] ?? '' ) );
	$served = array_filter( $redirects['redirects'], static function ( $r ) { return 'redirection' === $r['source']; } );
	$inactive = array_filter( $redirects['excluded'], static function ( $r ) { return 'redirection' === $r['source'] && 'source-inactive' === $r['reason']; } );
	check( ! $served && count( $inactive ) >= 5 && 'inactive-with-data' === $redirects['sources']['redirection']['status'], 'Redirection rules are kept, and no longer listed as served' );
}
echo "\n$checks checks passed ($mode).\n";
