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
/**
 * Whether robots.txt lets an agent read a path, as RFC 9309 says: every group naming the agent (else `*`), the longest
 * matching rule wins, an Allow wins a tie, no match is allowed.
 */
function can( $robots, $agent, $path ) {
	$named = array();
	$star = array();
	foreach ( Robots::groups( $robots ) as $group ) {
		foreach ( $group['agents'] as $a ) {
			if ( 0 === strcasecmp( $a, $agent ) ) { $named = array_merge( $named, $group['rules'] ); }
			if ( '*' === $a ) { $star = array_merge( $star, $group['rules'] ); }
		}
	}
	$best = null;
	foreach ( $named ? $named : $star as $rule ) {
		if ( ! Robots::matches( $rule[1], $path ) ) { continue; }
		$length = strlen( $rule[1] );
		if ( null === $best || $length > $best[0] || ( $length === $best[0] && 'allow' === $rule[0] ) ) { $best = array( $length, $rule[0] ); }
	}
	return null === $best || 'allow' === $best[1];
}
/** robots.txt served with another plugin's lines appended (an SEO plugin, a security plugin, a staging setup). */
function robots_with( $lines ) {
	// On a line of their own: an SEO plugin's block may end without a newline (Yoast's `# END YOAST BLOCK`).
	$f = static function ( $output ) use ( $lines ) { return rtrim( $output, "\n" ) . "\n" . $lines; };
	add_filter( 'robots_txt', $f, AFTER_PLUGINS );
	$served = robots();
	remove_filter( 'robots_txt', $f, AFTER_PLUGINS );
	return $served;
}
function about() {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/contentrain-bridge/v1/about' ) );
	return $response->get_data();
}
/** The test's own robots.txt lines go after every plugin's (an SEO plugin rewrites the output; CI has Yoast on) and before ours. */
const AFTER_PLUGINS = PHP_INT_MAX - 1;
const MIGRATE = 'ContentrainMigrate';
const READS = array( '/', '/blog/a-post/', '/wp-json/contentrain-bridge/v1/about', '/wp-json/wp/v2/posts', '/wp-content/uploads/2026/10/a.jpg' );

$file = ABSPATH . 'robots.txt';
check( ! file_exists( $file ), 'the test site has no robots.txt on disk' );
Robots::set( false );

// Off by default: robots.txt is untouched.
$plain = robots();
check( ! Robots::chosen() && false === stripos( $plain, MIGRATE ), 'off by default: no Migrate group' );
check( array( 'allow' => false, 'file' => 'virtual', 'owner_group' => false ) === about()['robots'] && in_array( 'robots_allow', about()['capabilities'], true ), '/about: robots_allow capability, off, virtual, no owner group' );

// On, public site: the `*` rules repeated and the reads opened; wp-admin still closed.
Robots::set( true );
$on = robots();
check( null !== rules_for( $on, MIGRATE ), 'on: a ContentrainMigrate group is served' );
check( ! can( $on, MIGRATE, '/wp-admin/' ) && can( $on, MIGRATE, '/wp-admin/admin-ajax.php' ), 'on: wp-admin stays closed to Migrate (the * rules repeated)' );
check( rules_for( $on, '*' ) === rules_for( $plain, '*' ), 'on: the * group is unchanged for every other crawler' );
check( 0 === strpos( $on, rtrim( $plain, "\n" ) ), 'on: what robots.txt had stays first, byte for byte' );
check( array( 'allow' => true, 'file' => 'virtual', 'owner_group' => false ) === about()['robots'], '/about: on, virtual' );

/**
 * ork2's fixtures (#57 review): for each owner `*` set, Migrate reads the site, the REST API and the uploads, wp-admin
 * stays closed wherever the owner closed it, and every other agent's verdict is what it was with the switch off.
 */
$fixtures = array(
	'Disallow: /' => "Disallow: /\n",
	'Disallow: /*' => "Disallow: /*\n",
	'Disallow: /wp-' => "Disallow: /wp-\n",
	'Disallow: /wp-content/' => "Disallow: /wp-content/\n",
	'uploads + plugins closed, Googlebot group' => "Disallow: /wp-content/uploads/\nDisallow: /wp-content/plugins/\n\nUser-agent: Googlebot\nDisallow: /wp-content/\n",
	'Disallow: /wp-json/ + /private/' => "Disallow: /wp-json/\nDisallow: /private/\n",
	'Cloudflare managed' => "\n" . file_get_contents( __DIR__ . '/fixtures/robots/cloudflare-managed.txt' ),
);
// An SEO plugin that replaces robots.txt whole (Yoast: an empty `Disallow:`, wp-admin open to everyone; CI runs Yoast).
$replaced = array(
	'Yoast (wp-admin open to all)' => "User-agent: *\nDisallow:\n",
	'no * group at all' => "User-agent: Googlebot\nDisallow: /private/\n",
	// Yoast's block ends without a newline: our User-agent line must still start a line of its own (or the group is invisible).
	'output ending without a newline' => "User-agent: *\nDisallow: /wp-admin/\n# END YOAST BLOCK",
);
/** robots.txt served when a plugin's filter replaces WordPress's own lines rather than appending to them. */
function robots_replaced( $text ) {
	$f = static function () use ( $text ) { return $text; };
	add_filter( 'robots_txt', $f, AFTER_PLUGINS );
	$served = robots();
	remove_filter( 'robots_txt', $f, AFTER_PLUGINS );
	return $served;
}
foreach ( $fixtures + $replaced as $name => $lines ) {
	$read = isset( $replaced[ $name ] ) ? 'robots_replaced' : 'robots_with';
	Robots::set( false );
	$off = $read( $lines );
	Robots::set( true );
	$served = $read( $lines );
	$reads = array_filter( READS, static function ( $path ) use ( $served ) { return ! can( $served, MIGRATE, $path ); } );
	check( ! $reads, "$name: Migrate reads " . implode( ', ', READS ) . ( $reads ? ' (closed: ' . implode( ', ', $reads ) . ')' : '' ) );
	// The reads would pass on the `*` rules alone: the group itself must be there (not glued to a line before it).
	check( null !== rules_for( $served, MIGRATE ), "$name: the Migrate group stands as a group of its own" );
	check( ! can( $served, MIGRATE, '/wp-admin/' ), "$name: wp-admin stays closed to Migrate" );
	$same = true;
	foreach ( array( '*', 'Googlebot', 'GPTBot', 'ClaudeBot' ) as $agent ) {
		foreach ( array_merge( READS, array( '/wp-admin/', '/private/x', '/wp-content/plugins/x.js' ) ) as $path ) {
			$same = $same && can( $served, $agent, $path ) === can( $off, $agent, $path );
		}
	}
	check( $same && 0 === strpos( $served, rtrim( $off, "\n" ) ), "$name: every other agent's verdicts unchanged, the original bytes first" );
	// For tests/robots-parity.mjs: the same pair, read by Contentrain Migrate's own robots parser.
	$slug = sprintf( '%02d-%s', array_search( $name, array_keys( $fixtures + $replaced ), true ), sanitize_title( $name ) );
	wp_mkdir_p( '/tmp/bridge-robots' );
	file_put_contents( "/tmp/bridge-robots/$slug.off.txt", $off );
	file_put_contents( "/tmp/bridge-robots/$slug.on.txt", $served );
}
// "Discourage search engines" (blog_public 0) with its `Disallow: /`: the owner's switch still lets Migrate read the content,
// and wp-admin stays closed (the group's own closure, not the `*` rules).
$closed = static function ( $output ) { return rtrim( $output, "\n" ) . "\nDisallow: /\n"; };
add_filter( 'robots_txt', $closed, AFTER_PLUGINS );
Robots::set( true );
$hidden = robots( '0' );
remove_filter( 'robots_txt', $closed, AFTER_PLUGINS );
update_option( 'blog_public', '1' );
check( ! array_filter( READS, static function ( $path ) use ( $hidden ) { return ! can( $hidden, MIGRATE, $path ); } ) && ! can( $hidden, MIGRATE, '/wp-admin/' ) && ! can( $hidden, '*', '/' ), 'blog_public 0: Migrate reads the content, wp-admin stays closed, every other crawler is still shut out' );
$private = robots_with( "Disallow: /private/\nDisallow: /wp-content/plugins/\n" );
check( ! can( $private, MIGRATE, '/private/x' ) && ! can( $private, MIGRATE, '/wp-content/plugins/x.js' ), 'a path the owner closes (outside the reads) stays closed to Migrate' );
check( ! in_array( array( 'disallow', '/' ), rules_for( robots_with( "Disallow: /\n" ), MIGRATE ), true ), 'only a whole-site closure is left out of the Migrate group' );
check( in_array( array( 'disallow', '/wp-' ), rules_for( robots_with( "Disallow: /wp-\n" ), MIGRATE ), true ), 'a shorter closer such as /wp- is kept (the Allows open the reads)' );

// The owner's own ContentrainMigrate group wins: nothing added, and /about says so.
$own = static function ( $output ) { return rtrim( $output, "\n" ) . "\n\nUser-agent: ContentrainMigrate\nDisallow: /shop/\n"; };
add_filter( 'robots_txt', $own, AFTER_PLUGINS );
$served = robots();
check( 1 === substr_count( $served, 'User-agent: ContentrainMigrate' ) && array( array( 'disallow', '/shop/' ) ) === rules_for( $served, MIGRATE ), "the owner's own Migrate group is left as written" );
check( true === about()['robots']['owner_group'], "/about: owner_group when the owner wrote one" );
remove_filter( 'robots_txt', $own, AFTER_PLUGINS );

// RFC 9309 matching and grouping.
check( Robots::matches( '/', '/x' ) && Robots::matches( '/*', '/' ) && Robots::matches( '/wp-*', '/wp-json/' ) && ! Robots::matches( '/$', '/x' ) && Robots::matches( '/$', '/' ) && ! Robots::matches( '/wp-admin/', '/wp-json/' ), 'path patterns: prefix, * and $' );
$groups = Robots::groups( "User-agent: a\nUser-agent: *\nDisallow: /x # c\n\nSitemap: https://e/s.xml\nUser-agent: b\nAllow: /\n" );
check( 2 === count( $groups ) && array( 'a', '*' ) === $groups[0]['agents'] && array( array( 'disallow', '/x' ) ) === $groups[0]['rules'], 'groups: consecutive agents share rules; comments and sitemap are not rules' );

// A file on disk: the switch is off whatever was chosen, and /about says physical.
file_put_contents( $file, "User-agent: *\nDisallow: /\n" );
try {
	check( array( 'allow' => false, 'file' => 'physical' ) === array_intersect_key( about()['robots'], array( 'allow' => 1, 'file' => 1 ) ), '/about: a robots.txt on disk is physical, and off' );
	check( "User-agent: *\nDisallow: /\n" === Robots::filter( "User-agent: *\nDisallow: /\n" ), 'a file on disk: the filter changes nothing' );
	$by_hand = "User-agent: *\nDisallow: /\n\n" . Robots::group( Robots::groups( Robots::physical_text() ) ) . "\n";
	check( ! array_filter( READS, static function ( $path ) use ( $by_hand ) { return ! can( $by_hand, MIGRATE, $path ); } ), 'a file on disk: the lines to add by hand open the reads' );
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
