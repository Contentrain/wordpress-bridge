<?php
/**
 * Builder export acceptance: page-builder layout meta and the site's design system reach RawIR,
 * secrets inside them do not, and a password-protected post's builder tree stays out.
 * Runs inside the test container (tests/builder.sh) and removes what it creates.
 */
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Exporter;
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Models;
use Contentrain\Bridge\Source;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}

$tree = array(
	array( 'id' => 'a1', 'elType' => 'container', 'settings' => array( 'flex_direction' => 'row' ), 'elements' => array(
		array( 'id' => 'b1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Welcome', 'header_size' => 'h1' ), 'elements' => array() ),
		array( 'id' => 'b2', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => array(
			'form_name' => 'Contact', 'email_to' => 'owner@example.test', 'mailchimp_api_key' => 'abc123-us1',
			'webhooks' => 'https://hooks.zapier.com/hooks/catch/123/planted-zap/',
			'discord_webhook' => 'https://discord.com/api/webhooks/1/planted-discord',
			'submit_actions' => array( 'webhook', 'email' ),
			'redirect_to' => 'https://hooks.slack.com/services/T0/B0/planted-slack',
		), 'elements' => array() ),
	) ),
);
// A widget six containers deep keeps its settings (Elementor: two levels per step).
$deep = array( 'id' => 'd6', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Deep', 'typography' => array( 'size' => 20 ) ), 'elements' => array() );
for ( $i = 0; $i < 6; $i++ ) {
	$deep = array( 'id' => 'c' . $i, 'elType' => 'container', 'settings' => array(), 'elements' => array( $deep ) );
}
$tree[] = $deep;
// A golden Elementor page nests far deeper than content modelling goes: twelve containers.
$golden = array( 'id' => 'g12', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Golden deep' ), 'elements' => array() );
for ( $i = 0; $i < 12; $i++ ) {
	$golden = array( 'id' => 'gc' . $i, 'elType' => 'container', 'settings' => array(), 'elements' => array( $golden ) );
}
$tree[] = $golden;
// Selected, non-builder meta twelve arrays deep: past the modelling depth (the array at depth eleven).
$deep_meta = 'bottom';
for ( $i = 11; $i >= 0; $i-- ) {
	$deep_meta = array( 'l' . $i => $deep_meta );
}
$created = array();
$open = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Builder page', 'post_content' => '<h1>Welcome</h1>', 'post_status' => 'publish' ) );
$locked = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Builder locked', 'post_content' => '<p>Members</p>', 'post_status' => 'publish', 'post_password' => 'let-me-in' ) );
$divi = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Divi page', 'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]', 'post_status' => 'publish' ) );
$kit = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Default Kit', 'post_status' => 'draft' ) );
$created = array( $open, $locked, $divi, $kit );
foreach ( array( $open, $locked ) as $id ) {
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
}
update_post_meta( $divi, '_et_pb_use_builder', 'on' );
update_post_meta( $divi, 'bridge_deep_meta', $deep_meta );
update_post_meta( $divi, '_et_pb_old_content', '<p>Before Divi</p>' );
update_post_meta( $kit, '_elementor_page_settings', array( 'system_colors' => array( array( '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ) ), 'container_width' => array( 'size' => 1140 ), 'recaptcha_secret_key' => 'shh' ) );
$previous_kit  = get_option( 'elementor_active_kit' );
$previous_divi = get_option( 'et_divi' );
update_option( 'elementor_active_kit', $kit );
update_option( 'et_divi', array( 'accent_color' => '#2ea3f2', 'divi_mailchimp_api_key' => 'planted-key' ) );

try {
	$excluded = array();
	$raw = Exporter::map_post( get_post( $open ), array(), $excluded );
	check( is_array( $raw['meta']['_elementor_data'] ?? null ), 'Elementor tree exported without selection, decoded' );
	check( 'heading' === ( $raw['meta']['_elementor_data'][0]['elements'][0]['widgetType'] ?? '' ), 'widget types survive' );
	$form = $raw['meta']['_elementor_data'][0]['elements'][1]['settings'] ?? array();
	check( 'Contact' === ( $form['form_name'] ?? '' ) && ! isset( $form['email_to'] ) && ! isset( $form['mailchimp_api_key'] ) && ! isset( $form['webhooks'] ) && ! isset( $form['discord_webhook'] ), 'secrets inside widget settings are filtered' );
	check( array_key_exists( 'redirect_to', $form ) && null === $form['redirect_to'], 'a webhook URL under an innocent key is dropped by value' );
	$node = $raw['meta']['_elementor_data'][1] ?? array();
	for ( $i = 0; $i < 6; $i++ ) {
		$node = $node['elements'][0] ?? array();
	}
	check( 'Deep' === ( $node['settings']['title'] ?? '' ) && 20 === ( $node['settings']['typography']['size'] ?? null ), 'a widget six containers deep keeps its settings' );
	check( 'builder' === ( $raw['meta']['_elementor_edit_mode'] ?? '' ) && 'yes' === ( $raw['meta']['_elementor_page_settings']['hide_title'] ?? '' ), 'Elementor page settings exported' );

	$excluded = array();
	$raw = Exporter::map_post( get_post( $locked ), array(), $excluded );
	check( ! isset( $raw['meta']['_elementor_data'] ) && ! isset( $raw['meta']['_elementor_page_settings'] ), 'a protected post carries no builder tree' );
	check( in_array( array( 'source' => 'post/' . $locked . '/_elementor_data', 'reason' => 'password-protected' ), $excluded, true ), 'and the exclusion is reported' );
	check( Exporter::PROTECTED_PASSWORD === $raw['password'] && 'publish' === $raw['status'], 'status and protection marker unchanged (wp-import parity)' );

	$excluded = array();
	$raw = Exporter::map_post( get_post( $divi ), array(), $excluded );
	check( 'on' === ( $raw['meta']['_et_pb_use_builder'] ?? '' ) && ! isset( $raw['meta']['_et_pb_old_content'] ), 'Divi builder switch exported, the pre-builder backup not' );
	check( false !== strpos( $raw['content'], '[et_pb_text]' ), 'Divi shortcodes stay in the raw body' );

	$options = Exporter::options();
	check( get_stylesheet() === $options['stylesheet'] && get_template() === $options['template'], 'active theme named' );
	check( is_array( $options['global_settings'] ?? null ) && isset( $options['global_settings']['color'] ), 'global settings (theme.json merged) exported' );
	check( is_array( $options['global_styles'] ?? null ), 'global styles exported' );
	check( '#6EC1E4' === ( $options['elementor_kit']['system_colors'][0]['color'] ?? '' ) && ! isset( $options['elementor_kit']['recaptcha_secret_key'] ), 'Elementor kit globals exported, secrets filtered' );
	check( '#2ea3f2' === ( $options['et_divi']['accent_color'] ?? '' ) && ! isset( $options['et_divi']['divi_mailchimp_api_key'] ), 'Divi options exported, API key filtered' );
	check( array_key_exists( 'show_on_front', $options ) && array_key_exists( 'permalink_structure', $options ), 'existing options unchanged' );
	if ( wp_is_block_theme() ) {
		check( ! empty( $options['block_templates'] ) && isset( $options['block_templates'][0]['content'] ), 'block templates exported for a block theme' );
	} else {
		check( ! isset( $options['block_templates'] ), 'no block templates for a classic theme' );
	}
	// A full export: builder meta stays raw evidence, never content fields; too-deep content is kept as JSON text.
	$admin = get_user_by( 'login', 'bridge-admin' );
	wp_set_current_user( $admin->ID );
	$previous_job = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
	delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
	$export = null;
	try {
		$s = Jobs::create( array( 'types' => array( 'page' ), 'private' => false, 'scan_sources' => false, 'selected_meta' => array( 'bridge_deep_meta' ) ) );
		$export = $s['id'];
		for ( $i = 0; $i < 500 && ! in_array( $s['phase'], array( 'review', 'failed' ), true ); ++$i ) { $s = Jobs::step( $export, $s['step'] ); }
		check( 'review' === $s['phase'], 'a page with a twelve-container Elementor tree exports without error' );
		$job = Jobs::read( $export );
		$address = Source::address( get_post( $open ) );
		$page_fields = $job['models'][ $address['model_id'] ]['fields'];
		$builder_fields = array_filter( array_keys( $page_fields ), static function ( $f ) { return 0 === strpos( $f, 'meta_elementor_' ) || 0 === strpos( $f, 'meta_et_pb_' ); } );
		check( ! $builder_fields, 'builder meta is not modelled as content fields (wp-import parity)' );
		$raw_open = json_decode( Files::read( Files::dir( $export ), Models::row_file( 'bridge/raw-posts.json', $open ) ), true );
		$node = $raw_open['meta']['_elementor_data'][2] ?? array();
		for ( $i = 0; $i < 12; $i++ ) {
			$node = $node['elements'][0] ?? array();
		}
		check( 'Golden deep' === ( $node['settings']['title'] ?? '' ), 'the whole Elementor tree still travels in raw-posts' );
		$raw_divi = json_decode( Files::read( Files::dir( $export ), Models::row_file( 'bridge/raw-posts.json', $divi ) ), true );
		check( 'on' === ( $raw_divi['meta']['_et_pb_use_builder'] ?? '' ), 'Divi builder switch stays in raw-posts' );
		$divi_address = Source::address( get_post( $divi ) );
		$source = $divi_address['model_id'] . '/' . $divi_address['entry_id'] . '/bridge_deep_meta';
		check( 'wp-structured-values' === ( $page_fields['meta_bridge_deep_meta']['model'] ?? null ), 'deep non-builder meta is still modelled as structured values' );
		$path = $source;
		for ( $i = 0; $i <= 10; $i++ ) {
			$path .= '/l' . $i;
		}
		$row = json_decode( Files::read( Files::dir( $export ), Models::row_file( Models::content_path( $job, 'wp-structured-values', $divi_address['locale'] ), substr( hash( 'sha256', $path ), 0, 12 ) ) ), true );
		check( '{"l11":"bottom"}' === ( $row['text'] ?? null ), 'past the modelling depth the subtree is kept as JSON text' );
		$warnings = array();
		foreach ( array_keys( $job['tables']['bridge/warnings.json'] ?? array() ) as $key ) {
			$warnings[] = json_decode( Files::read( Files::dir( $export ), Models::row_file( 'bridge/warnings.json', $key ) ), true );
		}
		$gap = array_filter( $warnings, static function ( $w ) use ( $path ) { return $path === ( $w['source'] ?? '' ) && 'nesting-too-deep: kept as JSON text' === ( $w['reason'] ?? '' ); } );
		check( 1 === count( $gap ), 'and the gap is reported as a warning' );
	} finally {
		if ( $export ) {
			Jobs::delete( $export );
		}
		if ( $previous_job ) {
			update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $previous_job );
		}
	}

	$json = wp_json_encode( $options ) . wp_json_encode( Exporter::map_post( get_post( $open ) ) );
	$planted = array( 'planted-key', 'abc123-us1', 'owner@example.test', 'let-me-in', 'planted-zap', 'planted-discord', 'planted-slack' );
	check( array() === array_filter( $planted, static function ( $p ) use ( $json ) { return false !== strpos( $json, $p ); } ), 'no planted secret anywhere in the output' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
	false === $previous_kit ? delete_option( 'elementor_active_kit' ) : update_option( 'elementor_active_kit', $previous_kit );
	false === $previous_divi ? delete_option( 'et_divi' ) : update_option( 'et_divi', $previous_divi );
}
echo "Builder checks: $checks\n";
