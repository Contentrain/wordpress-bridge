<?php
/** Run only via wp eval-file in the disposable acceptance site. */
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to uninstall outside the acceptance fixture.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
$root = \Contentrain\Bridge\Files::root();
\Contentrain\Bridge\Source::changed();
if ( ! is_dir( $root ) || ! get_option( 'contentrain_bridge_revision' ) ) {
	throw new RuntimeException( 'Uninstall fixture is not populated.' );
}
deactivate_plugins( 'contentrain-bridge/contentrain-bridge.php' );
uninstall_plugin( 'contentrain-bridge/contentrain-bridge.php' );
if ( is_dir( $root ) || get_option( 'contentrain_bridge_revision' ) || get_user_meta( $admin->ID, 'contentrain_bridge_job_' . get_current_blog_id(), true ) || wp_next_scheduled( 'contentrain_bridge_cleanup' ) ) {
	throw new RuntimeException( 'Uninstall left private snapshots or bookkeeping behind.' );
}
echo "PASS: WordPress uninstall removes snapshots, ownership, revision and cron.\n";
