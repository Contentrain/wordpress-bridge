<?php
/**
 * The owner's robots.txt switch for Contentrain Migrate: off by default; on, WordPress's own robots.txt gains one group
 * for ContentrainMigrate that repeats the `*` rules without the Disallows closing `/` or `/wp-json/`; off removes it; a
 * robots.txt file on disk turns it off and is reported as `physical`. Runs inside the test container (tests/coverage.sh).
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Robots;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
/** robots.txt as WordPress serves it (do_robots), with the site public or not. */
function robots( $public = '1' ) {
	update_option( 'blog_public', $public );
	ob_start();
	do_robots();
	return (string) ob_get_clean();
}
/** The rules robots.txt gives one agent's group (the first group naming it). */
function rules_for( $robots, $agent ) {
	foreach ( Robots::groups( $robots ) as $group ) {
		if ( in_array( $agent, $group['agents'], true ) ) { return $group['rules']; }
	}
	return null;
}
function about() {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/contentrain-bridge/v1/about' ) );
	return $response->get_data();
}

$file = ABSPATH . 'robots.txt';
check( ! file_exists( $file ), 'the test site has no robots.txt on disk' );
Robots::set( false );

// Off by default: robots.txt is untouched.
$plain = robots();
check( ! Robots::chosen() && false === stripos( $plain, 'ContentrainMigrate' ), 'off by default: no Migrate group' );
check( array( 'allow' => false, 'file' => 'virtual' ) === about()['robots'] && in_array( 'robots_allow', about()['capabilities'], true ), '/about: robots_allow capability, off, virtual' );

// On, public site: the `*` rules repeated, wp-admin still closed, plus Allow: /.
Robots::set( true );
$on = robots();
$rules = rules_for( $on, 'ContentrainMigrate' );
check( null !== $rules, 'on: a ContentrainMigrate group is served' );
check( in_array( array( 'disallow', '/wp-admin/' ), $rules, true ) && in_array( array( 'allow', '/wp-admin/admin-ajax.php' ), $rules, true ), 'on: wp-admin stays closed to Migrate (the * rules repeated)' );
check( in_array( array( 'allow', '/' ), $rules, true ), 'on: Allow: / for Migrate' );
check( rules_for( $on, '*' ) === rules_for( $plain, '*' ), 'on: the * group is unchanged for every other crawler' );
check( 0 === strpos( $on, rtrim( $plain, "\n" ) ), 'on: what robots.txt had stays first, byte for byte' );
check( array( 'allow' => true, 'file' => 'virtual' ) === about()['robots'], '/about: on, virtual' );

// A robots.txt closed to every crawler (`Disallow: /`, as an SEO plugin or a staging setup writes it; WordPress itself
// only adds a noindex meta for "Discourage search engines"): closed for `*`, open for Migrate.
$all = static function ( $output ) { return $output . "Disallow: /\n"; };
add_filter( 'robots_txt', $all, 10 );
$closed = robots();
check( in_array( array( 'disallow', '/' ), rules_for( $closed, '*' ), true ), 'closed site: * keeps Disallow: /' );
check( ! in_array( array( 'disallow', '/' ), rules_for( $closed, 'ContentrainMigrate' ), true ) && in_array( array( 'disallow', '/wp-admin/' ), rules_for( $closed, 'ContentrainMigrate' ), true ), 'closed site: Migrate does not get Disallow: /, wp-admin stays closed' );
remove_filter( 'robots_txt', $all, 10 );

// Another plugin's rules: /wp-json/ and wildcard closers dropped for Migrate, a private path kept.
$extra = static function ( $output ) { return $output . "Disallow: /wp-json/\nDisallow: /*\nDisallow: /private/\nDisallow: /w\n"; };
add_filter( 'robots_txt', $extra, 10 );
$rules = rules_for( robots(), 'ContentrainMigrate' );
check( ! in_array( array( 'disallow', '/wp-json/' ), $rules, true ) && ! in_array( array( 'disallow', '/*' ), $rules, true ) && ! in_array( array( 'disallow', '/w' ), $rules, true ), 'closers of / and /wp-json/ are dropped for Migrate (incl. /* and a prefix of /wp-json/)' );
check( in_array( array( 'disallow', '/private/' ), $rules, true ), 'a path the owner closes stays closed to Migrate' );
remove_filter( 'robots_txt', $extra, 10 );

// The owner's own ContentrainMigrate group wins: nothing added.
$own = static function ( $output ) { return $output . "\nUser-agent: ContentrainMigrate\nDisallow: /shop/\n"; };
add_filter( 'robots_txt', $own, 10 );
$served = robots();
check( 1 === substr_count( $served, 'User-agent: ContentrainMigrate' ) && array( array( 'disallow', '/shop/' ) ) === rules_for( $served, 'ContentrainMigrate' ), "the owner's own Migrate group is left as written" );
remove_filter( 'robots_txt', $own, 10 );

// RFC 9309 matching and grouping.
check( Robots::matches( '/', '/x' ) && Robots::matches( '/*', '/' ) && Robots::matches( '/wp-*', '/wp-json/' ) && ! Robots::matches( '/$', '/x' ) && Robots::matches( '/$', '/' ) && ! Robots::matches( '/wp-admin/', '/wp-json/' ), 'path patterns: prefix, * and $' );
$groups = Robots::groups( "User-agent: a\nUser-agent: *\nDisallow: /x # c\n\nSitemap: https://e/s.xml\nUser-agent: b\nAllow: /\n" );
check( 2 === count( $groups ) && array( 'a', '*' ) === $groups[0]['agents'] && array( array( 'disallow', '/x' ) ) === $groups[0]['rules'], 'groups: consecutive agents share rules; comments and sitemap are not rules' );

// A file on disk: the switch is off whatever was chosen, and /about says physical.
file_put_contents( $file, "User-agent: *\nDisallow: /\n" );
try {
	check( array( 'allow' => false, 'file' => 'physical' ) === about()['robots'], '/about: a robots.txt on disk is physical, and off' );
	check( "User-agent: *\nDisallow: /\n" === Robots::filter( "User-agent: *\nDisallow: /\n" ), 'a file on disk: the filter changes nothing' );
	check( false !== strpos( Robots::group( Robots::groups( Robots::physical_text() ) ), "User-agent: ContentrainMigrate\nAllow: /" ), 'a file on disk: the lines to add by hand' );
} finally {
	unlink( $file );
}

// Off again: the group is gone.
Robots::set( false );
check( robots() === $plain && false === get_option( Robots::OPTION, false ), 'off: the group is removed and the option deleted' );

// The admin handler refuses a visitor without the export permission.
wp_set_current_user( 0 );
$refused = false;
add_filter( 'wp_die_handler', static function () { return static function () { throw new RuntimeException( 'died' ); }; } );
try { Robots::toggle(); } catch ( RuntimeException $e ) { $refused = 'died' === $e->getMessage(); }
check( $refused && ! Robots::chosen(), 'the switch refuses a visitor without permission' );

echo "Robots checks: $checks\n";
