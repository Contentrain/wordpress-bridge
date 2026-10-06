<?php
/**
 * One wp-admin "step" request of an export, as its own PHP process, for tests/export-hang.php.
 *
 * Usage: php [-d memory_limit=…] export-hang-child.php <job id> <step|advance> <poison id> <media|posts> [budget seconds]
 *
 * With a poison id, reading that post's meta from inside `Jobs::<frame>` ends the process at once with
 * exit 137, the way the host's time or memory limit ends a request: no `finally`, no save, the lock
 * released only by the process ending. Without one the request runs to its budget and prints the summary.
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

list( , $id, $mode, $poison, $frame ) = array_pad( $argv, 5, '' );
$budget = (float) ( $argv[5] ?? 12 );
$poison = (int) $poison;

$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );

if ( $poison > 0 ) {
	add_filter( 'get_post_metadata', static function ( $value, $object_id ) use ( $poison, $frame ) {
		if ( (int) $object_id !== $poison ) {
			return $value;
		}
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $call ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Test harness.
			if ( Jobs::class === ( $call['class'] ?? '' ) && $frame === $call['function'] ) {
				fwrite( STDERR, "killed in Jobs::$frame at post $poison\n" );
				exit( 137 );
			}
		}
		return $value;
	}, 10, 2 );
}

$state = json_decode( Files::read( Files::dir( $id ), 'state.json' ), true );
try {
	if ( 'step' === $mode ) {
		// What wp-admin sent before this fix: one tick per request.
		$summary = Jobs::step( $id, $state['step'] );
	} else {
		$summary = Jobs::run( $id, microtime( true ) + $budget, true, (int) $state['step'] );
	}
} catch ( Throwable $error ) {
	echo wp_json_encode( array( 'error' => $error->getMessage(), 'code' => $error->getCode() ) ), "\n";
	exit( 3 );
}
echo wp_json_encode( array( 'phase' => $summary['phase'], 'step' => $summary['step'], 'cursor' => $summary['cursor'], 'counts' => $summary['counts'], 'error' => $summary['error'] ?? null, 'memory_limit' => ini_get( 'memory_limit' ), 'peak' => memory_get_peak_usage( true ) ) ), "\n";
