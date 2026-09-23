<?php
/**
 * Plugin Name:       Contentrain Bridge
 * Plugin URI:        https://contentrain.io/wordpress
 * Description:       Model and export WordPress content and interface text as Contentrain JSON/Markdown, with optional GitHub delivery.
 * Version:           0.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Contentrain
 * Author URI:        https://contentrain.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentrain-bridge
 *
 * @package ContentrainBridge
 */

defined( 'ABSPATH' ) || exit;
define( 'CONTENTRAIN_BRIDGE_VERSION', '0.2.1' );
define( 'CONTENTRAIN_BRIDGE_FILE', __FILE__ );

foreach ( array( 'policy', 'files', 'exporter', 'source', 'inventory', 'delta', 'seo', 'redirects', 'routing', 'scanner', 'text', 'coverage', 'integrations', 'acf', 'models', 'validator', 'rawir', 'jobs', 'github', 'remote', 'admin' ) as $contentrain_bridge_class ) {
	require_once __DIR__ . '/includes/class-contentrain-bridge-' . $contentrain_bridge_class . '.php';
}
unset( $contentrain_bridge_class );

\Contentrain\Bridge\Admin::register();
foreach ( array( 'save_post', 'deleted_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'created_term', 'edited_term', 'delete_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'comment_post', 'edit_comment', 'deleted_comment', 'transition_comment_status', 'profile_update', 'switch_theme' ) as $contentrain_bridge_hook ) {
	add_action( $contentrain_bridge_hook, array( '\Contentrain\Bridge\Source', 'changed' ), 10, 3 );
}
unset( $contentrain_bridge_hook );
add_action( 'updated_option', array( '\Contentrain\Bridge\Source', 'option_changed' ), 10, 3 );
add_action( 'added_option', array( '\Contentrain\Bridge\Source', 'option_changed' ), 10, 3 );
add_action( 'deleted_option', array( '\Contentrain\Bridge\Source', 'option_changed' ), 10, 1 );
add_action( 'contentrain_bridge_cleanup', array( '\Contentrain\Bridge\Files', 'cleanup' ) );
register_activation_hook( __FILE__, 'contentrain_bridge_activate' );
register_deactivation_hook( __FILE__, 'contentrain_bridge_deactivate' );

/** Schedule cleanup of private export files. */
function contentrain_bridge_activate() {
	if ( ! wp_next_scheduled( 'contentrain_bridge_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'contentrain_bridge_cleanup' );
	}
}

/** Remove the recurring hook; unfinished exports remain until expiry/uninstall. */
function contentrain_bridge_deactivate() {
	wp_clear_scheduled_hook( 'contentrain_bridge_cleanup' );
}
