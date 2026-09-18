<?php
/** Remove private snapshots and bookkeeping when WordPress uninstalls Bridge.
 * @package ContentrainBridge
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( ! class_exists( '\Contentrain\Bridge\Files' ) ) {
	require_once __DIR__ . '/includes/class-contentrain-bridge-files.php';
}
$contentrain_bridge_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
foreach ( $contentrain_bridge_sites as $contentrain_bridge_site ) {
	if ( is_multisite() ) {
		switch_to_blog( $contentrain_bridge_site );
	}
	wp_clear_scheduled_hook( 'contentrain_bridge_cleanup' );
	delete_option( 'contentrain_bridge_revision' );
	delete_option( 'contentrain_bridge_changes' );
	delete_metadata( 'user', 0, 'contentrain_bridge_job_' . $contentrain_bridge_site, '', true );
	try {
		\Contentrain\Bridge\Files::remove( \Contentrain\Bridge\Files::root() );
	} catch ( \RuntimeException $contentrain_bridge_error ) {
		// An unavailable temporary volume cannot be removed by this process.
		// The directory is private and contains no stored GitHub credentials.
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
}
unset( $contentrain_bridge_sites, $contentrain_bridge_site, $contentrain_bridge_error );
