<?php
/**
 * The wp-admin export against requests the host kills (BR-25, the export that never got past media).
 *
 * Each "request" is a child PHP process (tests/export-hang-child.php) that dies, deterministically, the
 * moment a chosen attachment or post is read inside the phase under test — what a time or memory limit
 * does to a real request, with nothing saved and no `finally` run. Proven here:
 *   1. the single-tick request wp-admin used to send repeats the same batch for ever (step and cursor
 *      never move), which is the hang;
 *   2. `Jobs::run` with the per-request try count shrinks the batch, leaves the attachment that keeps
 *      killing the request out with a warning, and the export reaches the end in a bounded number of
 *      requests;
 *   3. a post that kills every request ends the export as failed, named, after MAX_ATTEMPTS, and later
 *      requests refuse;
 *   4. a clean export finishes under a 64 MB memory_limit in short requests, with no retry left behind.
 * Executed inside the dedicated WordPress test container, never a production site.
 */
define( 'WP_ADMIN', true );
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Models;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}

$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
require_once ABSPATH . 'wp-admin/includes/image.php';

$child = __DIR__ . '/export-hang-child.php';
/** One request: `array( exit code, decoded stdout|null, stderr )`. */
function request( $id, $mode, $poison, $frame, $memory = '128M', $budget = 12 ) {
	global $child;
	$command = sprintf( 'php -d memory_limit=%s -d max_execution_time=30 %s %s %s %d %s %s', $memory, escapeshellarg( $child ), escapeshellarg( $id ), $mode, $poison, $frame, $budget );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );
	return array( $code, json_decode( trim( $out ), true ), trim( $err ) );
}
function state( $id ) {
	return json_decode( Files::read( Files::dir( $id ), 'state.json' ), true );
}
/**
 * Every warning reason the export wrote so far, by source (the aggregated bridge/warnings.json only exists at
 * the end; a row written by a request that then died is on disk too, so one source can carry several).
 */
function warnings( $id ) {
	$rows = array();
	foreach ( glob( Files::dir( $id ) . '/rows/' . hash( 'sha256', 'bridge/warnings.json' ) . '/*.json' ) ?: array() as $file ) {
		$row = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test harness reading its own output.
		$rows[ $row['source'] ][] = $row['reason'];
	}
	return $rows;
}
/** Drives an export to its end with fresh requests; returns the summaries, or stops after `$most` requests. */
function drive( $id, $poison, $frame, $most, $memory = '128M', $budget = 12 ) {
	$runs = array();
	for ( $i = 0; $i < $most; $i++ ) {
		$runs[] = request( $id, 'advance', $poison, $frame, $memory, $budget );
		$phase = state( $id )['phase'];
		if ( in_array( $phase, array( 'review', 'ready', 'failed' ), true ) ) {
			break;
		}
	}
	return $runs;
}
$deaths = static function ( $runs ) {
	return count( array_filter( $runs, static function ( $run ) { return 137 === $run[0]; } ) );
};

// Enough attachments for the 25 → 5 → 1 batches to be told apart, created before the export so its
// snapshot sees them; removed again at the end, so the later gates that count this site's content are
// unaffected.
$uploads = wp_get_upload_dir();
wp_mkdir_p( $uploads['path'] );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$added = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$path = $uploads['path'] . sprintf( '/bridge-hang-%02d.png', $i );
	file_put_contents( $path, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	$a = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => "Bridge hang $i", 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $a, wp_generate_attachment_metadata( $a, $path ) );
	$added[] = (int) $a;
}
$jobs = array();
try {
	global $wpdb;
	// Every attachment, in the order the media phase walks them; the poison is the fourth, inside the first
	// batch of 25 and of 5 but not first, so the three before it have to be saved one by one before a request
	// can start at the poison itself. It must be one the export reads (not excluded by status or parent), or
	// nothing would read its meta. Runs right after the acceptance fixture, so the fourth is the first of the
	// thirty added here.
	$order = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC" ) );
	$poison = $added[0];
	$at = array_search( $poison, $order, true );
	check( $at >= 1 && $at <= 4, "the first attachment added here is the " . ( $at + 1 ) . "th the export walks: inside the first batch of 5, not first (the fixture has $at before it; the counts below assume 1–4)" );

	// 1. The single tick per request: the hang.
	$input = array( 'types' => array( 'attachment', 'post' ), 'private' => false, 'comments' => false );
	$hang = Jobs::create( $input, true )['id'];
	$jobs[] = $hang;
	$before = state( $hang );
	for ( $i = 0; $i < 4; $i++ ) {
		list( $code ) = request( $hang, 'step', $poison, 'media' );
		check( 137 === $code, "single-tick request $i is killed at attachment $poison" );
	}
	$after = state( $hang );
	check( $before['step'] === $after['step'] && $before['cursor'] === $after['cursor'] && 'media' === $after['phase'], 'and four requests later the export has not moved: same step, cursor and phase (the hang)' );
	check( ! is_file( Files::dir( $hang ) . '/' . Jobs::ATTEMPT_FILE ), 'the single tick records no try, so nothing would ever shrink its batch' );

	// 2. The same site, the same poison, through Jobs::run: the batch shrinks, the attachment is left out, the export ends.
	$fixed = Jobs::create( $input, true )['id'];
	$jobs[] = $fixed;
	$runs = drive( $fixed, $poison, 'media', 14 );
	$final = state( $fixed );
	check( in_array( $final['phase'], array( 'review', 'ready' ), true ), 'the export reaches the end in ' . count( $runs ) . ' requests, ' . $deaths( $runs ) . ' of them killed' );
	// Three requests die at the saved start (batches 25, 5, then 1 with each attachment before the poison saved
	// as it goes), four at the poison itself (25, 5, 1, 1 without its files), and the fifth leaves it out.
	check( 7 === $deaths( $runs ), 'the poison kills exactly 7 requests: 3 from the start, 4 at the poison, then it is left out' );
	$mine = warnings( $fixed )[ 'attachment/' . $poison ] ?? array();
	$skipped = preg_grep( '/^media-skipped-after-repeated-failure/', $mine );
	check( 1 === count( $skipped ), 'the attachment that kept killing the request is named in a warning with its reason (' . implode( ' | ', $mine ) . ')' );
	check( false !== strpos( reset( $skipped ), get_post_field( 'guid', $poison ) ), 'and the warning carries its WordPress URL' );
	check( $final['counts']['media_kept_remote'] >= 1, 'the kept-remote count includes it' );
	check( $final['counts']['media'] >= count( $order ) - 2, 'every other attachment was exported (' . $final['counts']['media'] . ' of ' . count( $order ) . ': the poison left out, the fixture\'s draft child excluded)' );
	check( ! is_file( Files::dir( $fixed ) . '/' . Jobs::ATTEMPT_FILE ), 'no try is left recorded once the export has ended' );

	// 3. A post that kills every request, with nothing left to shrink: the export fails, says why, and stays failed.
	$post = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC LIMIT 1" );
	check( $post > 0, "a published post to poison ($post)" );
	$doomed = Jobs::create( array( 'types' => array( 'post' ), 'private' => false, 'comments' => false ), true )['id'];
	$jobs[] = $doomed;
	$runs = drive( $doomed, $post, 'posts', 12 );
	$final = state( $doomed );
	check( 'failed' === $final['phase'], 'the export is failed after ' . count( $runs ) . ' requests (' . $deaths( $runs ) . ' killed)' );
	// The first death is counted against the saved start; the second request saves the media → posts move before
	// dying; from there MAX_ATTEMPTS requests start from the same saved state and die, and the next gives up.
	check( $deaths( $runs ) >= Jobs::MAX_ATTEMPTS && $deaths( $runs ) <= Jobs::MAX_ATTEMPTS + 2, 'MAX_ATTEMPTS requests from the same saved state were killed before it gave up (' . $deaths( $runs ) . ' in all)' );
	check( 0 === end( $runs )[0] && 'failed' === ( end( $runs )[1]['phase'] ?? '' ), 'the request that gave up answered normally, with the failed export' );
	check( 'step_repeatedly_killed' === ( $final['error']['code'] ?? '' ), 'with the stable error code' );
	check( preg_match( '/stage "posts".*' . Jobs::MAX_ATTEMPTS . ' times.*time or memory limit.*smaller scope/s', $final['error']['message'] ), 'and a message naming the stage, the count and what to do: ' . $final['error']['message'] );
	list( $code, $summary ) = request( $doomed, 'advance', 0, 'posts' );
	check( 3 === $code && false !== strpos( $summary['error'], 'Export failed' ), 'a later request refuses with the failure, instead of trying again' );
	list( $code, $summary ) = request( $doomed, 'advance', 0, 'posts' );
	check( 3 === $code, 'and so does the next' );

	// 4. Nothing poisoned, 64 MB, four-second requests: the export finishes, with no retry on the way.
	$clean = Jobs::create( $input, true )['id'];
	$jobs[] = $clean;
	$runs = drive( $clean, 0, 'media', 150, '64M', 4 );
	$final = state( $clean );
	$peak = max( array_map( static function ( $run ) { return (int) ( $run[1]['peak'] ?? 0 ); }, $runs ) );
	check( in_array( $final['phase'], array( 'review', 'ready' ), true ), 'a clean export under memory_limit=64M reaches the end in ' . count( $runs ) . ' requests of 4 s (peak ' . round( $peak / 1048576 ) . ' MB)' );
	foreach ( $runs as $i => $run ) {
		check( 0 === $run[0], "request $i exited 0 (" . ( $run[2] ?: 'no stderr' ) . ')' );
	}
	check( '64M' === ( $runs[0][1]['memory_limit'] ?? '' ), 'the limit really was 64M inside the request' );
	$reasons = array_merge( array(), ...array_values( warnings( $clean ) ?: array( array() ) ) );
	check( ! preg_grep( '/skipped-after-repeated-failure/', $reasons ), 'no attachment was left out or kept remote for a retry' );
	check( ! is_file( Files::dir( $clean ) . '/' . Jobs::ATTEMPT_FILE ), 'no try is left recorded' );
} finally {
	foreach ( $jobs as $id ) {
		Files::remove( Files::dir( $id ) );
	}
	foreach ( $added as $a ) {
		wp_delete_attachment( $a, true );
	}
}
echo "OK: $checks checks\n";
