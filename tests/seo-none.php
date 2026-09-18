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
	check( array( 'absent' ) === array_values( array_unique( array_column( $seo['providers'], 'status' ) ) ) && 3 === count( $seo['providers'] ), 'every known provider is reported absent, by name' );
	check( '{}' === wp_json_encode( $seo['settings'] ), 'settings are an empty object, not null' );
	check( null === Seo::post( $sample ), 'a post has no SEO entry to write' );
	foreach ( array( 'redirection', 'yoast_premium', 'rank_math', 'safe_redirect_manager' ) as $source ) {
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
	check( ! isset( $entry['resolved'] ) && 'Custom %%title%% %%sep%% %%sitename%%' === $entry['stored']['title'] && 'custom title keyword' === $entry['focus_keyword'], 'stored Yoast values are still exported, without claiming rendered ones' );
	$served = array_filter( $redirects['redirects'], static function ( $r ) { return 'redirection' === $r['source']; } );
	$inactive = array_filter( $redirects['excluded'], static function ( $r ) { return 'redirection' === $r['source'] && 'source-inactive' === $r['reason']; } );
	check( ! $served && count( $inactive ) >= 5 && 'inactive-with-data' === $redirects['sources']['redirection']['status'], 'Redirection rules are kept, and no longer listed as served' );
}
echo "\n$checks checks passed ($mode).\n";
