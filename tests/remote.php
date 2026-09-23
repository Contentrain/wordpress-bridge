<?php
/**
 * BR-19 acceptance: an export started, advanced and read over REST, the way
 * Migrate drives it with an application password. Runs inside the test
 * container (tests/coverage.sh) after coverage.php, on the same site.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Remote;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function call( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/contentrain-bridge/v1' . $route );
	foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}
$admin = get_user_by( 'login', 'bridge-admin' );
$meta = Remote::META . get_current_blog_id();
$forget = static function () use ( $admin, $meta ) {
	foreach ( (array) get_user_meta( $admin->ID, $meta, true ) as $id ) { if ( is_string( $id ) ) { Files::remove( Files::dir( $id ) ); } }
	delete_user_meta( $admin->ID, $meta );
};
$forget();

// ---- Permission: the read API's own. ----
$reader = get_user_by( 'login', 'bridge-remote-reader' ) ?: get_user_by( 'id', wp_insert_user( array( 'user_login' => 'bridge-remote-reader', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) ) );
wp_set_current_user( $reader->ID );
list( $status ) = call( 'POST', '/exports', array( 'comments' => true ) );
check( in_array( $status, array( 401, 403 ), true ), "an editor cannot start an export ($status)" );
list( $status ) = call( 'GET', '/exports' );
check( in_array( $status, array( 401, 403 ), true ), "nor list them ($status)" );
wp_set_current_user( $admin->ID );

// ---- Start: idempotent per scope. ----
$scope = array( 'types' => array( 'post', 'page', 'attachment' ), 'comments' => true );
list( $status, $first ) = call( 'POST', '/exports', $scope );
check( 201 === $status && false === $first['reused'] && preg_match( '/^[a-f0-9]{32}$/', $first['export']['id'] ), 'POST /exports starts an export: 201, reused false' );
$a = $first['export']['id'];
check( isset( $first['export']['expires_at'] ) && strtotime( $first['export']['expires_at'] ) - strtotime( $first['export']['created_at'] ) === DAY_IN_SECONDS, 'the summary says when it expires: 24 hours after it started' );
check( array( 'attachment', 'page', 'post' ) === ( static function ( $t ) { sort( $t ); return $t; } )( $first['export']['scope']['types'] ) && true === $first['export']['scope']['comments'] && false === $first['export']['scope']['private'], 'and its scope' );
list( $status, $again ) = call( 'POST', '/exports', array( 'types' => array( 'attachment', 'post', 'page' ), 'comments' => true ) );
check( 200 === $status && true === $again['reused'] && $a === $again['export']['id'], 'the same scope again (types in any order) returns the running export: 200, reused true' );
list( $status, $other ) = call( 'POST', '/exports', $scope + array( 'private' => true ) );
check( 201 === $status && $a !== $other['export']['id'], 'a different scope (private) is a new export, never "yours"' );
$b = $other['export']['id'];
list( $status ) = call( 'POST', '/exports', array( 'types' => 'post' ) );
check( 400 === $status, 'types that are not a list are refused (400)' );
list( $status ) = call( 'POST', '/exports', array( 'types' => array( 'no_such_type' ) ) );
check( 400 === $status, 'no known type is refused (400)' );
check( ! in_array( get_user_meta( $admin->ID, 'contentrain_bridge_job_' . get_current_blog_id(), true ), array( $a, $b ), true ), 'a remote export is not the admin screen\'s export' );

// ---- Advance: lock-safe, to ready. ----
$lock = fopen( Files::dir( $a ) . '/lock', 'c' );
flock( $lock, LOCK_EX );
list( $status, $busy ) = call( 'POST', "/exports/$a/advance" );
check( 200 === $status && true === $busy['busy'] && 0 === $busy['export']['step'], 'an advance while another holds the export returns it as it is: busy true, nothing run' );
flock( $lock, LOCK_UN );
fclose( $lock );
$summary = null;
for ( $i = 0; $i < 60; ++$i ) {
	list( $status, $body ) = call( 'POST', "/exports/$a/advance" );
	$summary = $body['export'];
	if ( in_array( $summary['phase'], array( 'ready', 'failed' ), true ) ) { break; }
}
check( 200 === $status && 'ready' === $summary['phase'] && ! isset( $summary['error'] ), 'advancing reaches ready with no one reviewing (' . ( $i + 1 ) . ' calls)' );
list( , $body ) = call( 'POST', "/exports/$a/advance" );
check( 'ready' === $body['export']['phase'], 'advancing a ready export is a no-op' );

// ---- The snapshot: rawir.json, whole and in chunks. ----
list( $status, $list ) = call( 'GET', "/exports/$a" );
$info = $list['files']['bridge/rawir.json'] ?? null;
check( 200 === $status && $info && $info['bytes'] > 0, 'the snapshot lists bridge/rawir.json with its sha256 and size' );
$manifest = json_decode( Files::read( Files::dir( $a ) . '/output', 'bridge/manifest.json' ), true );
check( $manifest['files']['bridge/rawir.json'] === $info, 'the manifest lists it too, with the same hash' );
list( , $whole ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json' ) );
$raw = json_decode( $whole['content'], true );
$posts = json_decode( Files::read( Files::dir( $a ) . '/output', 'bridge/raw-posts.json' ), true );
check( 1 === $raw['version'] && 'bridge' === $raw['provenance']['kind'] && 'contentrain-bridge/' . CONTENTRAIN_BRIDGE_VERSION === $raw['provenance']['tool'] && count( $raw['posts'] ) === count( $posts ) && count( $posts ) > 0, 'rawir.json is RawIR v1 from Bridge, with every exported post (' . count( $posts ) . ')' );
$ids = array_column( $raw['posts'], 'id' );
$sorted = $ids;
sort( $sorted, SORT_NUMERIC );
check( $ids === $sorted, 'posts in id order, as a JSON reader lists the table' );
$assembled = '';
for ( $offset = 0; $offset < $info['bytes']; ) {
	list( $status, $chunk ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => $offset, 'length' => 1000 ) );
	if ( 200 !== $status || $chunk['offset'] !== $offset || $chunk['bytes'] !== $info['bytes'] || $chunk['sha256'] !== $info['sha256'] || 'base64' !== $chunk['encoding'] ) { break; }
	$assembled .= base64_decode( $chunk['content'] );
	$offset += $chunk['length'];
}
check( hash( 'sha256', $assembled ) === $info['sha256'] && $assembled === $whole['content'], 'read in 1000-byte chunks (offset, length, total bytes), it assembles to the manifest hash' );
list( $status, $end ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => $info['bytes'] ) );
check( 200 === $status && 0 === $end['length'] && '' === $end['content'], 'a chunk at the end is empty, not an error' );
list( $status, $zero ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => 0, 'length' => 0 ) );
check( 200 === $status && 0 === $zero['length'] && $info['bytes'] === $zero['bytes'], 'length 0 is an empty chunk (how a 0-byte file is read), with the total size' );
list( $status, $tail ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => $info['bytes'] - 10, 'length' => 10 ) );
check( 200 === $status && 10 === $tail['length'] && 10 === strlen( base64_decode( $tail['content'] ) ), 'the last chunk returns exactly the length asked when it fits' );
list( $s1 ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => $info['bytes'] + 1 ) );
list( $s2 ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => 0, 'length' => 8 * MB_IN_BYTES + 1 ) );
list( $s3 ) = call( 'GET', "/exports/$a", array( 'file' => 'bridge/rawir.json', 'offset' => '-1' ) );
list( $s4 ) = call( 'GET', "/exports/$a", array( 'file' => '../state.json', 'offset' => 0 ) );
check( array( 400, 400, 400, 400 ) === array( $s1, $s2, $s3, $s4 ), 'past the end, over 8 MiB, a negative offset and a path outside the snapshot are refused' );
$media = array_values( array_filter( array_keys( $list['files'] ), static function ( $p ) { return 0 === strpos( $p, 'media/' ); } ) )[0] ?? null;
if ( $media ) {
	list( , $m ) = call( 'GET', "/exports/$a", array( 'file' => $media, 'offset' => 0 ) );
	check( hash( 'sha256', base64_decode( $m['content'] ) ) === $list['files'][ $media ]['sha256'], 'a media file reads the same way' );
}

// ---- Failure is terminal, with a code; the same scope then starts afresh. ----
list( , $c ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ) );
$c = $c['export']['id'];
wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Changed under the snapshot ' . wp_generate_password( 6, false ) ) );
list( $status, $failed ) = call( 'POST', "/exports/$c/advance" );
check( 200 === $status && 'failed' === $failed['export']['phase'] && 'content_changed' === $failed['export']['error']['code'], 'WordPress changing under the snapshot fails it: phase failed, error.code content_changed' );
list( , $still ) = call( 'POST', "/exports/$c/advance" );
check( 'failed' === $still['export']['phase'], 'failed stays failed' );
list( $status, $fresh ) = call( 'POST', '/exports', array( 'types' => array( 'post' ) ) );
check( 201 === $status && $c !== $fresh['export']['id'], 'the same scope after a failure is a new export, not the failed one' );
list( $status, $index ) = call( 'GET', '/exports' );
check( 200 === $status && array( $a, $b, $c, $fresh['export']['id'] ) === array_column( $index['exports'], 'id' ), 'GET /exports lists the caller\'s remote exports, failed included, oldest first' );
list( $status ) = call( 'POST', '/exports', array( 'types' => array( 'page' ) ) );
check( 429 === $status, 'a fourth live export is refused (429): three at most' );

// ---- Only remote exports advance over REST; another user's is not there. ----
$key = 'contentrain_bridge_job_' . get_current_blog_id();
$previous = get_user_meta( $admin->ID, $key, true );
delete_user_meta( $admin->ID, $key );
$screen = Jobs::create( array( 'types' => array( 'post' ), 'scan_sources' => false ) );
list( $status ) = call( 'POST', '/exports/' . $screen['id'] . '/advance' );
check( 409 === $status, 'the admin screen\'s export is not advanced over REST (409)' );
Jobs::delete( $screen['id'] );
if ( $previous ) { update_user_meta( $admin->ID, $key, $previous ); }
list( $status ) = call( 'POST', '/exports/' . str_repeat( '0', 32 ) . '/advance' );
check( 404 === $status, 'an unknown export is a 404' );

$forget();
echo "\n$checks checks passed.\n";
