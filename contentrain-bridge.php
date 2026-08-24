<?php
/**
 * Plugin Name:       Contentrain Bridge
 * Plugin URI:        https://contentrain.io/wordpress
 * Description:       Export WordPress content to a portable RawIR JSON file for migration.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Contentrain
 * Author URI:        https://contentrain.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentrain-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONTENTRAIN_BRIDGE_VERSION', '0.1.0' );
define( 'CONTENTRAIN_BRIDGE_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-contentrain-bridge-exporter.php';

/** Register the local export screen under Tools. */
function contentrain_bridge_register_tools_page() {
	add_management_page(
		__( 'Contentrain Bridge', 'contentrain-bridge' ),
		__( 'Contentrain Bridge', 'contentrain-bridge' ),
		'export',
		'contentrain-bridge',
		'contentrain_bridge_render_tools_page'
	);
}
add_action( 'admin_menu', 'contentrain_bridge_register_tools_page' );

/** Render a deliberately local-first export flow. */
function contentrain_bridge_render_tools_page() {
	if ( ! current_user_can( 'export' ) ) {
		wp_die( esc_html__( 'You do not have permission to export this site.', 'contentrain-bridge' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Contentrain Bridge', 'contentrain-bridge' ); ?></h1>
		<p><?php esc_html_e( 'Download a portable RawIR JSON export. This action does not contact Contentrain or any other external service.', 'contentrain-bridge' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="contentrain_bridge_export" />
			<?php wp_nonce_field( 'contentrain_bridge_export', 'contentrain_bridge_nonce' ); ?>
			<label>
				<input type="checkbox" name="include_comments" value="1" />
				<?php esc_html_e( 'Include comments (author name, URL, content, status, and user ID; email and IP address are excluded)', 'contentrain-bridge' ); ?>
			</label>
			<?php submit_button( __( 'Download RawIR JSON', 'contentrain-bridge' ) ); ?>
		</form>
	</div>
	<?php
}

/** Generate and download the export only after an explicit administrator action. */
function contentrain_bridge_handle_export() {
	if ( ! current_user_can( 'export' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to export this site.', 'contentrain-bridge' ),
			'',
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'contentrain_bridge_export', 'contentrain_bridge_nonce' );

	$include_comments = isset( $_POST['include_comments'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_comments'] ) );
	$payload          = \Contentrain\Bridge\Exporter::build( $include_comments );
	$filename         = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-contentrain-rawir.json' );

	nocache_headers();
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
	exit;
}
add_action( 'admin_post_contentrain_bridge_export', 'contentrain_bridge_handle_export' );

/** Explain the plugin's data behavior in WordPress' privacy-policy helper. */
function contentrain_bridge_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	wp_add_privacy_policy_content(
		__( 'Contentrain Bridge', 'contentrain-bridge' ),
		wp_kses_post(
			__( 'Contentrain Bridge creates a local JSON download only after an authorized administrator explicitly requests it. The plugin sends no telemetry and makes no external network requests. Comment export is off by default; when enabled, comment email addresses and IP addresses are excluded.', 'contentrain-bridge' )
		)
	);
}
add_action( 'admin_init', 'contentrain_bridge_add_privacy_policy_content' );
