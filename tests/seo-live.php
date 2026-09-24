<?php
/**
 * BR-22 acceptance: what Bridge renders from a plugin's templates is what that
 * plugin itself serves. Run with exactly one of Rank Math, AIOSEO or SEOPress
 * active (tests/seo.sh installs each in turn), after tests/seo-fixture.php.
 *
 *   php tests/seo-live.php rank_math|aioseo|seopress
 *
 * Title, description, robots (index/follow) and canonical of every fixture post
 * and page are compared with the page's head. The static front page and posts
 * page are left out: each plugin renders them by its home rules, not the page's.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
require __DIR__ . '/seo-head.php';
use Contentrain\Bridge\Seo;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}

$provider = $argv[1] ?? '';
$constant = array( 'rank_math' => 'RANK_MATH_VERSION', 'aioseo' => 'AIOSEO_VERSION', 'seopress' => 'SEOPRESS_VERSION' )[ $provider ] ?? null;
check( $constant && defined( $constant ), "$provider is the running SEO plugin (" . ( $constant && defined( $constant ) ? constant( $constant ) : 'not loaded' ) . ')' );
check( ! defined( 'WPSEO_VERSION' ), 'and no other SEO plugin is' );
$fixture = json_decode( file_get_contents( '/tmp/bridge-seo/fixture.json' ), true );
global $wpdb;

if ( 'aioseo' === $provider ) {
	// AIOSEO replaced the fixture's reduced table with its own on activation: put the fixture row back into it.
	$table = $wpdb->prefix . 'aioseo_posts';
	$post = $fixture['posts']['custom_title'];
	$wpdb->delete( $table, array( 'post_id' => $post ) );
	$wpdb->insert( $table, array( 'post_id' => $post, 'title' => '#post_title #separator_sa #site_title', 'description' => 'AIOSEO description', 'robots_default' => 0, 'robots_noindex' => 1, 'robots_nofollow' => 0, 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) ) );
	check( '' === $wpdb->last_error, 'the fixture row is in AIOSEO\'s own aioseo_posts table' );
	wp_cache_flush();
}
if ( 'seopress' === $provider ) {
	// SEOPress prints titles and meta only with its Titles module on.
	update_option( 'seopress_toggle', array_merge( (array) get_option( 'seopress_toggle', array() ), array( 'toggle-titles' => '1', 'toggle-social' => '1' ) ) );
}
Seo::reset();

$pages = array();
foreach ( $fixture['posts'] as $key => $id ) {
	if ( ! in_array( $key, array( 'front', 'blog' ), true ) ) {
		$pages[ $key ] = array( Seo::post( get_post( $id ) )[ $provider ] ?? null, wp_make_link_relative( get_permalink( $id ) ) );
	}
}
// A term archive, where the plugin keeps term values (Rank Math and SEOPress term meta; AIOSEO's are Pro-only).
if ( 'aioseo' !== $provider ) {
	$pages['topic'] = array( Seo::term( get_term( $fixture['terms']['topic'] ) )[ $provider ] ?? null, $fixture['paths']['topic'] );
}

$diffs = array();
foreach ( $pages as $key => list( $block, $path ) ) {
	$rendered = $block['rendered'] ?? null;
	if ( ! $rendered ) {
		$diffs[] = "$key: no rendered block";
		continue;
	}
	$page = fetch( $path );
	if ( 200 !== $page['status'] ) {
		$diffs[] = "$key: HTTP {$page['status']} at $path";
		continue;
	}
	$served = head( $page['html'] );
	$robots = $served['robots'] ?? array();
	$want = array(
		'title'       => decode( $served['title'] ?? '' ),
		'description' => decode( $served['description'] ?? '' ),
		'index'       => in_array( 'noindex', $robots, true ) ? 'noindex' : 'index',
		'follow'      => in_array( 'nofollow', $robots, true ) ? 'nofollow' : 'follow',
	);
	$got = array(
		'title'       => $rendered['title'],
		'description' => $rendered['description'],
		'index'       => $rendered['robots']['index'],
		'follow'      => $rendered['robots']['follow'],
	);
	// A noindex page may carry no canonical; where the plugin prints one, it must be Bridge's.
	if ( isset( $served['canonical'] ) ) {
		$want['canonical'] = $served['canonical'];
		$got['canonical'] = $rendered['canonical'];
	}
	if ( $want !== $got ) {
		$diffs[] = $key . ":\n    served " . wp_json_encode( $want, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n    bridge " . wp_json_encode( $got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
foreach ( $diffs as $diff ) { echo "  $diff\n"; }
check( ! $diffs, "Bridge renders $provider's templates to what $provider serves: title, description, robots and canonical, " . count( $pages ) . ' pages (' . count( $diffs ) . ' differ)' );
echo "\n$checks checks passed ($provider).\n";
