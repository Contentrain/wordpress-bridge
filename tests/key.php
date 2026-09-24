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
function call( $method, $route, $params = array(), $key = null, $via = 'header', $as = 0 ) {
	wp_set_current_user( $as );
	$request = new WP_REST_Request( $method, '/contentrain-bridge/v1' . $route );
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
	return array( $response->get_status(), $response->get_data() );
}
function code( $data ) {
	return is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
}

$admin = get_user_by( 'login', 'bridge-admin' );
$meta = Remote::META . get_current_blog_id();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$reset = static function () use ( $admin, $meta ) {
	foreach ( (array) get_user_meta( $admin->ID, $meta, true ) as $id ) { if ( is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { Files::remove( Files::dir( $id ) ); } }
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
list( $status, $started ) = call( 'POST', '/exports', $scope, $key );
check( 201 === $status && false === $started['reused'], 'the header key starts an export: 201' );
$a = $started['export']['id'];
check( array( $a ) === Key::exports() && $pair === get_option( Key::OPTION )['pairing'], 'the export is the key\'s, and the key is paired with the order' );
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
list( $status, $rerun ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ) + $scope, $key );
check( 201 === $status && $a !== $rerun['export']['id'] && array( $a, $rerun['export']['id'] ) === Key::exports(), 'once it is ready, a rerun with another scope starts: the same key, the same order' );
$seen = get_option( Key::SEEN );
check( '203.0.113.7' === $seen['ip'] && time() - $seen['at'] < 60 && true === Key::status()['used'], 'the last use is recorded: when, and from where' );

// ---- Every refusal. ----
$saved = get_option( Key::SEEN );
update_option( Key::SEEN, array( 'at' => time() - Key::IDLE - 1, 'ip' => $saved['ip'] ), false );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 401 === $status && 'bridge_key_expired' === code( $body ), 'an hour unused: 401 bridge_key_expired' );
update_option( Key::SEEN, $saved, false );
$record = get_option( Key::OPTION );
update_option( Key::OPTION, array( 'created_at' => time() - Key::LIFETIME - 1 ) + $record, false );
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), $key );
check( 401 === $status && 'bridge_key_expired' === code( $body ), 'used, but 14 days old: 401 bridge_key_expired' );
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
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 200 === $status && array() === Key::exports(), 'the new key works, for a new order, with none of the old key\'s exports' );
wp_set_current_user( $admin->ID );
Key::revoke();
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0003' ), $replacement );
check( 401 === $status && 'bridge_key_revoked' === code( $body ) && false === Key::status()['active'], 'revoked on the screen: 401 bridge_key_revoked, and the screen says no key' );

for ( $i = 0; $i < Key::FAILURES; ++$i ) {
	list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => $pair ), str_repeat( 'A', 43 ) );
	if ( 'bridge_key_invalid' !== code( $body ) ) { break; }
}
check( Key::FAILURES === $i, 'a wrong key: 401 bridge_key_invalid, ten times' );
wp_set_current_user( $admin->ID );
$fresh = Key::create();
list( $status, $body ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0004' ), $fresh );
check( 429 === $status && 'bridge_key_rate_limited' === code( $body ) && Key::WINDOW === $body['data']['retry_after'], 'the eleventh try from that address, even with a good key: 429 bridge_key_rate_limited, retry after 15 minutes' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
list( $status ) = call( 'GET', '/exports', array( 'contentrain_pairing' => 'order_test_0004' ), $fresh );
check( 200 === $status, 'another address is not held back' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---- The application-password path is unchanged. ----
list( $status, $index ) = call( 'GET', '/exports', array(), null, 'none', $admin->ID );
check( 200 === $status && in_array( $b, array_column( $index['exports'], 'id' ), true ) && in_array( $a, array_column( $index['exports'], 'id' ), true ), 'signed in as the administrator, every remote export is listed as before' );

$reset();
delete_transient( 'contentrain_bridge_key_fail_' . md5( '203.0.113.8' ) );
echo "\n$checks checks passed.\n";
