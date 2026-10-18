<?php
/**
 * BR-27 acceptance: the Contentrain Migrate connection key. An export started,
 * advanced and read with the key alone — as a header, as a body field — and
 * every way the key is refused. Runs inside the test container after remote.php
 * (tests/coverage.sh), on the same site.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Key;
use Contentrain\Bridge\Remote;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
/**
 * One REST request as an anonymous caller (or `$as`), the key where `$via` says:
 * `header`, `body` (form fields), `json`, `both` or `none`.
 */
/**
 * What WordPress saw during every keyed request, recorded before and after the route callback: the user id,
 * whether anyone is signed in, and whether `manage_options` holds. With a key all three must stay 0 / false / false.
 */
$observed = array();
$observe = static function ( $response ) use ( &$observed ) {
	$observed[] = array( get_current_user_id(), is_user_logged_in(), current_user_can( 'manage_options' ) );
	return $response;
};
add_filter( 'rest_request_before_callbacks', $observe, 1 );
add_filter( 'rest_request_after_callbacks', $observe, 1 );
/**
 * Every capability question WordPress is asked while a keyed request runs: the answer must grant nothing to
 * the user-less request, and no plugin callback may sit on `user_has_cap` or `map_meta_cap` to change it.
 */
$granted = array();
$cap_questions = 0;
add_filter( 'user_has_cap', static function ( $allcaps, $caps, $args ) use ( &$granted, &$cap_questions ) {
	if ( ! Key::active() ) { return $allcaps; }
	++$cap_questions;
	foreach ( array( 'user_has_cap', 'map_meta_cap' ) as $hook ) {
		foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() ) as $set ) {
			foreach ( $set as $cb ) {
				$fn = $cb['function'];
				$name = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1] : ( is_string( $fn ) ? $fn : get_class( $fn ) );
				if ( false !== stripos( $name, 'Contentrain' ) ) { $granted[] = "$hook: $name"; }
			}
		}
	}
	if ( array_filter( $allcaps ) ) { $granted[] = 'granted ' . implode( ',', array_keys( array_filter( $allcaps ) ) ) . ' for ' . implode( ',', array_map( 'strval', (array) $args ) ); }
	return $allcaps;
}, PHP_INT_MAX, 3 );
$keyed_calls = 0;
function call( $method, $route, $params = array(), $key = null, $via = 'header', $as = 0 ) {
	global $observed, $keyed_calls;
	wp_set_current_user( $as );
	$observed = array();
	$request = new WP_REST_Request( $method, 0 === strpos( $route, '/wp/' ) ? $route : '/contentrain-bridge/v1' . $route );
	$body = $params;
	if ( null !== $key && in_array( $via, array( 'header', 'both' ), true ) ) { $request->set_header( 'X-Contentrain-Key', $key ); }
	if ( null !== $key && in_array( $via, array( 'body', 'json', 'both' ), true ) ) { $body['contentrain_key'] = $key; }
	if ( 'GET' === $method ) {
		$request->set_query_params( $body );
	} elseif ( 'json' === $via ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	} else {
		$request->set_body_params( $body );
	}
	$response = rest_do_request( $request );
	if ( null !== $key && 0 === $as ) {
		// The explicit-principal rule, checked on every keyed call rather than once: no WordPress user before, during or after.
		foreach ( $observed as $seen ) {
			if ( array( 0, false, false ) !== $seen ) {
				throw new RuntimeException( 'FAIL: a keyed request ran as a WordPress user: ' . wp_json_encode( $seen ) . " ($method $route)" );
			}
		}
		if ( 0 !== get_current_user_id() || is_user_logged_in() || Key::active() ) {
			throw new RuntimeException( "FAIL: a keyed request left a user or the key behind ($method $route)" );
		}
		++$keyed_calls;
	}
	return array( $response->get_status(), $response->get_data() );
}
function code( $data ) {
	return is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
}

$admin = get_user_by( 'login', 'bridge-admin' );
// A private post in scope: its address is the one place WordPress answers differently to a user-less request.
if ( ! get_posts( array( 'post_status' => 'private', 'post_type' => 'post', 'numberposts' => 1 ) ) ) {
	wp_insert_post( array( 'post_title' => 'Key private ' . wp_generate_password( 6, false ), 'post_content' => 'Private body.', 'post_status' => 'private', 'post_type' => 'post', 'post_author' => $admin->ID ) );
}
$meta = Remote::META . get_current_blog_id();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$reset = static function () use ( $admin, $meta ) {
	foreach ( array_merge( (array) get_user_meta( $admin->ID, $meta, true ), Key::exports() ) as $id ) { if ( is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { Files::remove( Files::dir( $id ) ); } }
	delete_user_meta( $admin->ID, $meta );
	foreach ( array( Key::OPTION, Key::SEEN, Key::EXPORTS ) as $option ) { delete_option( $option ); }
	delete_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.7' ) );
};
$reset();
$pair = 'order_test_0001';
$scope = array( 'types' => array( 'post', 'page' ), 'media_files' => false, 'contentrain_pairing' => $pair );

// ---- Discovery and creation. ----
list( $status, $about ) = call( 'GET', '/about' );
check( 200 === $status && CONTENTRAIN_BRIDGE_VERSION === $about['version'] && in_array( 'key', $about['auth'], true ), 'GET /about, signed out: the version and that a key is accepted' );
check( array( 'counts', 'robots_allow' ) === $about['capabilities'] && ! isset( $about['published'] ), 'GET /about names the counts and robots_allow capabilities and carries no site data' );
list( $status ) = call( 'GET', '/counts' );
check( in_array( $status, array( 401, 403 ), true ), "GET /counts signed out, no key: refused ($status), never public" );
wp_set_current_user( 0 );
try { Key::create(); $made = true; } catch ( RuntimeException $e ) { $made = false; }
check( ! $made, 'no key without an administrator' );
wp_set_current_user( $admin->ID );
$key = Key::create();
check( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $key ), 'a key is 32 random bytes, base64url (43 characters)' );
$stored = get_option( Key::OPTION );
check( hash( 'sha256', $key ) === $stored['hash'] && false === strpos( serialize( array( get_option( Key::OPTION ), get_option( Key::SEEN ), get_option( Key::EXPORTS ) ) ), $key ), 'only its SHA-256 is stored, never the key' );
check( 'no' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Key::OPTION ) ) || 'off' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Key::OPTION ) ), 'and not autoloaded' );
$status_view = Key::status();
check( true === $status_view['active'] && false === $status_view['used'] && ! isset( $status_view['hash'] ), 'the screen\'s status: active, unused, no hash' );

// ---- The key opens the export routes, header or body. ----
list( $status ) = call( 'POST', '/exports', $scope );
check( in_array( $status, array( 401, 403 ), true ), "without a key or a sign-in: refused ($status)" );
list( $status, $body ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ), $key );
check( 400 === $status && 'bridge_key_pairing_required' === code( $body ), 'a key without contentrain_pairing: 400 bridge_key_pairing_required' );
check( null === get_option( Key::OPTION )['pairing'], 'and it does not pair the key' );
$failures_before = (int) get_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.7' ) );
list( $status, $body ) = call( 'POST', '/exports', array( 'types' => array( 'post' ), 'contentrain_pairing' => $pair ), 'x' );
check( 401 === $status && 'bridge_key_invalid' === code( $body ) && (int) get_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.7' ) ) === $failures_before + 1, 'a malformed key (not 43 base64url characters): 401 bridge_key_invalid, counted as a wrong key' );
delete_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.7' ) );
list( $status ) = call( 'GET', '/wp/v2/users/me', array( 'contentrain_pairing' => $pair ), $key );
check( 401 === $status, "the key opens no WordPress route: GET /wp/v2/users/me with the key is $status" );
list( $status ) = call( 'GET', '/wp/v2/posts', array( 'status' => 'draft', 'contentrain_pairing' => $pair ), $key );
check( in_array( $status, array( 400, 401 ), true ), "nor GET /wp/v2/posts?status=draft ($status)" );
list( $status, $body ) = call( 'POST', '/exports/' . str_repeat( '0', 32 ) . '/read', array( 'contentrain_pairing' => $pair ), $key, 'json' );
check( 404 === $status && 'bridge_not_found' === code( $body ) && $pair === get_option( Key::OPTION )['pairing'], 'Migrate\'s access check (a read of no export): 404 bridge_not_found, and the key is now paired with the order' );
// ---- Counts before payment (Migrate gate item 10): the paired key reads WordPress's own published counts. ----
list( $status, $counts ) = call( 'GET', '/counts', array( 'contentrain_pairing' => $pair ), $key );
check( 200 === $status && 'contentrain-bridge-counts@1' === $counts['format'], "GET /counts with the paired key: 200 ($status)" );
check( (int) wp_count_posts( 'post' )->publish === $counts['published']['post'] && (int) wp_count_posts( 'page' )->publish === $counts['published']['page'] && (int) wp_count_posts( 'attachment' )->inherit === $counts['attachments'], 'published per type and media, as wp_count_posts counts them' );
check( array() === array_intersect( array_keys( $counts['published'] ), array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles', 'nav_menu_item' ) ), 'published lists content types only: no attachment (media is `attachments`), no blocks, templates, navigation, fonts, styles or menu items' );
check( array_keys( $counts ) === array( 'format', 'published', 'attachments' ) && array_filter( $counts['published'], 'is_int' ) === $counts['published'], 'numbers only: no titles, addresses or drafts' );
list( $status, $started ) = call( 'POST', '/exports', $scope, $key );
check( 201 === $status && false === $started['reused'], 'the header key starts an export: 201' );
$a = $started['export']['id'];
check( array( $a ) === Key::exports() && $pair === get_option( Key::OPTION )['pairing'], 'the export is the key\'s, and the key is paired with the order' );
check( '' === get_user_meta( $admin->ID, $meta, true ), 'and nothing was written to the administrator\'s user meta' );
$state = json_decode( Files::read( Files::dir( $a ), 'state.json' ), true );
check( $admin->ID === (int) $state['owner'] && 'key' === $state['via'] && hash( 'sha256', $key ) === $state['key'], 'its state names the owner explicitly: the key\'s administrator, via key' );
wp_set_current_user( $admin->ID );
try { Jobs::read( $a ); $read = true; } catch ( RuntimeException $e ) { $read = false; }
wp_set_current_user( 0 );
check( ! $read, 'signed in as that administrator, Jobs::read() refuses the key\'s export: the key is the only principal that reads it' );
list( $status, $again ) = call( 'POST', '/exports', $scope, $key, 'body' );
check( 200 === $status && $a === $again['export']['id'], 'the key as a body field: the same scope is the running export, reused' );
list( $status, $body ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ) + $scope, $key );
check( 409 === $status && 'bridge_export_live' === code( $body ), 'another scope while the key\'s export runs: 409 bridge_export_live' );
list( $status, $body ) = call( 'POST', '/exports', $scope, $key, 'both' );
check( 200 === $status, 'header and body together, the same key: accepted' );
list( $status, $body ) = call( 'POST', "/exports/$a/advance", array( 'contentrain_pairing' => $pair, 'contentrain_key' => 'x' . substr( $key, 1 ) ), $key );
check( 400 === $status && 'bridge_key_mismatch' === code( $body ), 'header and body with different keys: 400 bridge_key_mismatch' );
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair, 'contentrain_key' => $key ) );
check( in_array( $status, array( 401, 403 ), true ), "the key in the query string is not read ($status)" );
list( $status, $body ) = call( 'POST', "/exports/$a/advance", array( 'contentrain_pairing' => 'order_other_0002' ), $key );
check( 403 === $status && 'bridge_key_paired_elsewhere' === code( $body ), 'another order\'s pairing: 403 bridge_key_paired_elsewhere' );

// An export the administrator started with an application password is not the key's.
list( $status, $own ) = call( 'POST', '/exports', array( 'types' => array( 'page' ), 'media_files' => false ), null, 'none', $admin->ID );
check( 201 === $status, 'the administrator starts an export of their own' );
$b = $own['export']['id'];
$state = json_decode( Files::read( Files::dir( $b ), 'state.json' ), true );
check( $admin->ID === (int) $state['owner'] && 'app_password' === $state['via'] && array( $b ) === (array) get_user_meta( $admin->ID, $meta, true ), 'its state says via app_password, and it is listed in the administrator\'s user meta, not the key\'s option' );
list( $status, $body ) = call( 'POST', "/exports/$b/advance", array( 'contentrain_pairing' => $pair ), $key );
check( 404 === $status && 'bridge_not_found' === code( $body ), 'the key cannot advance it: 404' );
list( $status, $index ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 200 === $status && array( $a ) === array_column( $index['exports'], 'id' ), 'GET /exports with the key lists the key\'s export only' );

for ( $i = 0; $i < 60; ++$i ) {
	list( $status, $body ) = call( 'POST', "/exports/$a/advance", array( 'contentrain_pairing' => $pair ), $key, 0 === $i % 2 ? 'header' : 'json' );
	if ( in_array( $body['export']['phase'], array( 'ready', 'failed' ), true ) ) { break; }
}
check( 200 === $status && 'ready' === $body['export']['phase'], 'advanced to ready with the key, header and JSON body in turn (' . ( $i + 1 ) . ' calls)' );
list( $status, $list ) = call( 'GET', "/exports/$a", array( 'contentrain_pairing' => $pair ), $key );
$info = $list['files']['bridge/rawir.json'] ?? null;
check( 200 === $status && $info, 'GET /exports/{id} with the header key lists the snapshot' );
list( $status, $listed ) = call( 'POST', "/exports/$a/read", array( 'contentrain_pairing' => $pair ), $key, 'body' );
check( 200 === $status && $listed['files'] === $list['files'], 'POST /exports/{id}/read with the key in the body: the same list' );
$assembled = '';
for ( $offset = 0; $offset < $info['bytes']; ) {
	list( $status, $chunk ) = call( 'POST', "/exports/$a/read", array( 'contentrain_pairing' => $pair, 'file' => 'bridge/rawir.json', 'offset' => $offset, 'length' => 4096 ), $key, 'body' );
	if ( 200 !== $status || $chunk['offset'] !== $offset ) { break; }
	$assembled .= base64_decode( $chunk['content'] );
	$offset += $chunk['length'];
}
check( hash( 'sha256', $assembled ) === $info['sha256'], 'rawir.json read in chunks through the body-keyed route assembles to its hash' );
check( '' === get_user_meta( $admin->ID, $meta, true ) || array( $b ) === (array) get_user_meta( $admin->ID, $meta, true ), 'after start, advance and read with the key, the administrator\'s user meta holds only their own export' );

// ---- Parity: the same scope with the application password reads the same content (no capability-dependent read). ----
list( $status, $parity ) = call( 'POST', '/exports', array( 'types' => array( 'post', 'page' ), 'media_files' => false ), null, 'none', $admin->ID );
check( 201 === $status, 'the administrator starts the key\'s scope with the application password' );
$p = $parity['export']['id'];
for ( $i = 0; $i < 60; ++$i ) {
	list( $status, $body ) = call( 'POST', "/exports/$p/advance", array(), null, 'none', $admin->ID );
	if ( in_array( $body['export']['phase'], array( 'ready', 'failed' ), true ) ) { break; }
}
check( 'ready' === $body['export']['phase'], 'and advances it to ready' );
list( , $plist ) = call( 'GET', "/exports/$p", array(), null, 'none', $admin->ID );
$timed = array( 'bridge/manifest.json', 'bridge/site.json' );
// Two exports a second apart differ in their second-granular clocks and nothing else; compare what is left.
$untimed = static function ( $id, $path ) {
	$doc = json_decode( (string) file_get_contents( Files::dir( $id ) . '/output/' . $path ), true );
	$strip = static function ( &$node ) use ( &$strip ) {
		if ( ! is_array( $node ) ) { return; }
		foreach ( array( 'taken_at', 'fetched_at', 'export_date', 'generated_at', 'exported_at' ) as $clock ) { unset( $node[ $clock ] ); }
		foreach ( $node as &$child ) { $strip( $child ); }
	};
	$strip( $doc );
	return $doc;
};
$differ = array();
foreach ( $list['files'] + $plist['files'] as $path => $meta_row ) {
	if ( ! isset( $list['files'][ $path ], $plist['files'][ $path ] ) ) { $differ[] = $path; continue; }
	if ( $list['files'][ $path ]['sha256'] === $plist['files'][ $path ]['sha256'] ) { continue; }
	if ( in_array( $path, $timed, true ) || ( '.json' === substr( $path, -5 ) && null !== $untimed( $a, $path ) && $untimed( $a, $path ) === $untimed( $p, $path ) ) ) { continue; }
	$differ[] = $path;
}
check( array() === $differ, 'the key export and the application-password export have the same files and hashes apart from the timestamped manifest and site.json (differing: ' . implode( ', ', $differ ) . ')' );
$privates = static function ( $id ) {
	$doc = json_decode( (string) file_get_contents( Files::dir( $id ) . '/output/bridge/inventory.json' ), true );
	$out = array();
	foreach ( $doc['records'] ?? array() as $r ) { if ( 'private' === ( $r['status'] ?? '' ) ) { $out[ $r['wp_type'] . ':' . $r['wp_id'] ] = $r['fingerprint']; } }
	return $out;
};
check( array() !== $privates( $a ) && $privates( $a ) === $privates( $p ), 'a private record is inventoried with the same fingerprint, which covers its permalink, by the key and by the application password (' . count( $privates( $a ) ) . ' private)' );

// ---- Reuse isolation: an export the administrator started is never reused, bound or replaced by the key. ----
$scope2 = array( 'types' => array( 'page' ), 'media_files' => false, 'comments' => true );
list( $status, $theirs ) = call( 'POST', '/exports', $scope2, null, 'none', $admin->ID );
check( 201 === $status, 'the administrator starts an export with the application password' );
$s = $theirs['export']['id'];
list( $status, $mine ) = call( 'POST', '/exports', $scope2 + array( 'contentrain_pairing' => $pair ), $key );
check( 201 === $status && false === $mine['reused'] && $s !== $mine['export']['id'], 'the key asking for the same scope gets a new export (201), not the administrator\'s (reused)' );
check( ! in_array( $s, Key::exports(), true ) && in_array( $mine['export']['id'], Key::exports(), true ), 'the administrator\'s export is not bound to the key' );
list( $status, $renewed ) = call( 'POST', '/exports', $scope2 + array( 'fresh' => true, 'contentrain_pairing' => $pair ), $key );
check( 201 === $status && $mine['export']['id'] !== $renewed['export']['id'] && ! is_dir( Files::dir( $mine['export']['id'] ) ) && is_dir( Files::dir( $s ) ), 'fresh with the key replaces the key\'s own export and leaves the administrator\'s intact' );
list( $status, $index ) = call( 'GET', '/exports', array(), null, 'none', $admin->ID );
check( in_array( $s, array_column( $index['exports'], 'id' ), true ) && ! in_array( $renewed['export']['id'], array_column( $index['exports'], 'id' ), true ), 'the administrator still lists their export, and not the key\'s' );
$n = $renewed['export']['id'];
for ( $i = 0; $i < 60; ++$i ) {
	list( $status, $body ) = call( 'POST', "/exports/$n/advance", array( 'contentrain_pairing' => $pair ), $key );
	if ( in_array( $body['export']['phase'], array( 'ready', 'failed' ), true ) ) { break; }
}
check( 'ready' === $body['export']['phase'], 'the key\'s replacement export advances to ready' );
list( $status, $rerun ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ) + $scope, $key );
check( 201 === $status && $a !== $rerun['export']['id'] && array( $a, $n, $rerun['export']['id'] ) === Key::exports(), 'once it is ready, a rerun with another scope starts: the same key, the same order' );
$seen = get_option( Key::SEEN );
check( '203.0.113.7' === $seen['ip'] && time() - $seen['at'] < 60 && true === Key::status()['used'], 'the last use is recorded: when, and from where' );

// ---- Every refusal. ----
$record = get_option( Key::OPTION );
update_option( Key::OPTION, array( 'created_at' => time() - 3 * HOUR_IN_SECONDS ) + $record, false );
update_option( Key::SEEN, array( 'at' => time() - 2 * HOUR_IN_SECONDS - 1, 'ip' => '203.0.113.7' ), false );
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 200 === $status, 'paired, three hours old and unused for two: still works (a rerun can come days later)' );
update_option( Key::OPTION, array( 'created_at' => time() - Key::LIFETIME - 1 ) + $record, false );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 401 === $status && 'bridge_key_expired' === code( $body ), 'paired, but 14 days old: 401 bridge_key_expired' );
update_option( Key::OPTION, $record, false );
$demote = static function ( $caps ) { $caps['export'] = false; return $caps; };
add_filter( 'user_has_cap', $demote );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
remove_filter( 'user_has_cap', $demote );
check( 403 === $status && 'bridge_key_forbidden' === code( $body ), 'its administrator without the export capability: 403 bridge_key_forbidden' );
add_filter( 'contentrain_bridge_key_secure', '__return_false' );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
wp_set_current_user( $admin->ID );
try { Key::create(); $made = true; } catch ( RuntimeException $e ) { $made = false; }
remove_filter( 'contentrain_bridge_key_secure', '__return_false' );
check( 403 === $status && 'bridge_key_insecure' === code( $body ) && ! $made, 'without HTTPS: no key is accepted (403 bridge_key_insecure) and none is created' );
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 200 === $status, 'and the key still works once all of that is undone' );

wp_set_current_user( $admin->ID );
$replacement = Key::create();
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 401 === $status && 'bridge_key_revoked' === code( $body ), 'a new key retires the old one: 401 bridge_key_revoked' );
$record = get_option( Key::OPTION );
update_option( Key::OPTION, array( 'created_at' => time() - Key::UNPAIRED - 1 ) + $record, false );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 401 === $status && 'bridge_key_expired' === code( $body ) && null === get_option( Key::OPTION )['pairing'], 'unpaired an hour after it was created: 401 bridge_key_expired, and not paired by the refused call' );
update_option( Key::OPTION, $record, false );
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 200 === $status && array() === Key::exports(), 'within the hour the new key works, for a new order, with none of the old key\'s exports' );
check( false !== strpos( Key::status()['expires_at'], gmdate( 'Y-m-d', $record['created_at'] + Key::LIFETIME ) ), 'the screen shows when the paired key stops working: 14 days after it was created' );

// Migrate closes the key itself when the order is done.
list( $status, $body ) = call( 'DELETE', '/key', array( 'contentrain_pairing' => 'order_test_0003' ) );
check( 401 === $status && 'bridge_key_required' === code( $body ), 'DELETE /key without the key: 401 bridge_key_required' );
list( $status, $body ) = call( 'DELETE', '/key', array( 'contentrain_pairing' => 'order_other_0009' ), $replacement );
check( 403 === $status && 'bridge_key_paired_elsewhere' === code( $body ), 'nor for another order' );
list( $status, $started ) = call( 'POST', '/exports', array( 'types' => array( 'page' ), 'media_files' => false, 'contentrain_pairing' => 'order_test_0003' ), $replacement );
$r = $started['export']['id'];
check( 201 === $status && is_dir( Files::dir( $r ) ), 'the new key starts an export of its own' );
list( $status, $body ) = call( 'DELETE', '/key', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 200 === $status && true === $body['revoked'], 'DELETE /key with the key and its order: revoked' );
check( ! is_dir( Files::dir( $r ) ) && array() === Key::exports(), 'and the key\'s export is removed with it' );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 401 === $status && 'bridge_key_revoked' === code( $body ) && false === Key::status()['active'], 'then the key is answered as revoked, and the screen says no key' );
wp_set_current_user( $admin->ID );
$screen = Key::create();
Key::revoke();
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0005' ), $screen );
check( 401 === $status && 'bridge_key_revoked' === code( $body ), 'revoked on the Bridge screen: 401 bridge_key_revoked' );

for ( $i = 0; $i < Key::FAILURES; ++$i ) {
	list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), str_repeat( 'A', 43 ) );
	if ( 'bridge_key_invalid' !== code( $body ) ) { break; }
}
check( Key::FAILURES === $i, 'a wrong key: 401 bridge_key_invalid, ten times' );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), str_repeat( 'B', 43 ) );
check( 429 === $status && 'bridge_key_rate_limited' === code( $body ) && Key::WINDOW === $body['data']['retry_after'], 'the eleventh wrong key from that address: 429 bridge_key_rate_limited, retry after 15 minutes' );
wp_set_current_user( $admin->ID );
$fresh = Key::create();
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0004' ), $fresh );
check( 200 === $status, 'the right key from the same address still works: it is compared before the address is held back (a shared proxy cannot lock Migrate out)' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0004' ), str_repeat( 'A', 43 ) );
check( 401 === $status && 'bridge_key_invalid' === code( $body ), 'a wrong key from another address is not held back: 401, not 429' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---- The application-password path is unchanged. ----
list( $status, $index ) = call( 'GET', '/exports', array(), null, 'none', $admin->ID );
check( 200 === $status && in_array( $b, array_column( $index['exports'], 'id' ), true ) && in_array( $p, array_column( $index['exports'], 'id' ), true ) && ! in_array( $a, array_column( $index['exports'], 'id' ), true ), 'signed in as the administrator, their own remote exports are listed and the key\'s are not' );
list( $status, $body ) = call( 'GET', "/exports/$a", array(), null, 'none', $admin->ID );
check( 404 === $status && 'bridge_not_found' === code( $body ), 'the administrator cannot read the key\'s export through REST either: 404' );
check( $keyed_calls > 40, "every one of the $keyed_calls keyed requests ran with user 0, signed out, without manage_options, before and after its callback" );
check( array() === $granted, "no capability was granted to a keyed request and no plugin callback sat on user_has_cap or map_meta_cap during $cap_questions capability questions (" . implode( '; ', $granted ) . ')' );

$reset();
delete_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.8' ) );
echo "\n$checks checks passed.\n";
