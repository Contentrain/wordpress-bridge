<?php
/**
 * B-07 acceptance: the services a site is connected to are reported with their
 * evidence and a reconnect flag, and no credential reaches the export. Runs in
 * the test container (tests/integrations.sh) with Mailchimp for WP installed
 * and a must-use plugin printing a tracking script.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
global $wpdb;
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
$home = untrailingslashit( home_url() );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $home ) {
	if ( 0 !== strpos( $url, $home ) || ! empty( $args['_bridge_routed'] ) ) { return $pre; }
	$args['_bridge_routed'] = true;
	$args['headers']['Host'] = wp_parse_url( $home, PHP_URL_HOST ) . ':' . wp_parse_url( $home, PHP_URL_PORT );
	return wp_remote_get( 'http://127.0.0.1' . substr( $url, strlen( $home ) ), $args );
}, 10, 3 );

// ---- Settings as the services' plugins store them, with deliberately fake credentials. ----
$secrets = array(
	'mailchimp' => 'never-export-mailchimp-0a1b2c3d4e5f-us21',
	'hubspot'   => 'sk-' . str_repeat( 'H', 32 ),
	'akismet'   => 'never-export-akismet-9f8e7d',
	'recaptcha' => 'never-export-recaptcha-secret',
	'wpforms'   => 'never-export-wpforms-apikey',
);
update_option( 'mc4wp', array( 'api_key' => $secrets['mailchimp'], 'allow_usage_tracking' => 0 ) );
update_option( 'leadin_portalId', '4711' );
update_option( 'leadin_access_token', $secrets['hubspot'] );
update_option( 'wordpress_api_key', $secrets['akismet'] );
update_option( 'hotjar_site_id', '3100' );
update_option( 'jetpack_active_modules', array( 'stats', 'photon', 'related-posts' ) );
update_option( 'wpcf7', array( 'recaptcha' => array( 'site-key-public' => array( 'secret' => $secrets['recaptcha'] ) ) ) );
update_option( 'wpforms_providers', array( 'mailchimpv3' => array( 'account-1' => array( 'apikey' => $secrets['wpforms'], 'label' => 'Main list' ) ) ) );
update_option( 'ihaf_insert_header', '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-BRIDGE"></script>' );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gf_addon_feed (id int unsigned NOT NULL AUTO_INCREMENT, form_id int NOT NULL, is_active tinyint NOT NULL DEFAULT 1, feed_order int NOT NULL DEFAULT 0, meta longtext, addon_slug varchar(50), PRIMARY KEY (id))" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}gf_addon_feed" );
$wpdb->insert( $wpdb->prefix . 'gf_addon_feed', array( 'form_id' => 3, 'is_active' => 1, 'meta' => wp_json_encode( array( 'apiKey' => 'never-export-gf-feed-key' ) ), 'addon_slug' => 'gravityformshubspot' ) );
$embed = wp_insert_post( array( 'post_title' => 'Integrations embed', 'post_content' => "<p>Watch</p>\nhttps://www.youtube.com/watch?v=aqz-KE-bpKQ\n", 'post_status' => 'publish', 'post_author' => $admin->ID ) );
// The canaries are really in the database: a secret test that finds nothing because nothing was there proves nothing.
check( $secrets['mailchimp'] === get_option( 'mc4wp' )['api_key'] && $secrets['hubspot'] === get_option( 'leadin_access_token' ), 'the fake credentials are stored where the services keep them' );
check( is_plugin_active( 'mailchimp-for-wp/mailchimp-for-wp.php' ), 'Mailchimp for WP is active (a real catalog plugin)' );

// A theme's first front-end load writes its theme_mods with no text; that is not a content change.
$before = \Contentrain\Bridge\Source::revision();
delete_option( 'theme_mods_bridge-probe-theme' );
add_option( 'theme_mods_bridge-probe-theme', array( 'custom_css_post_id' => -1 ) );
update_option( 'theme_mods_bridge-probe-theme', array( 'custom_css_post_id' => 12 ) );
check( $before === \Contentrain\Bridge\Source::revision(), 'a theme_mods write with no text in it does not count as a content change' );
update_option( 'theme_mods_bridge-probe-theme', array( 'custom_css_post_id' => 12, 'footer_note' => 'New words' ) );
check( 'updated_option:theme_mods_bridge-probe-theme' === \Contentrain\Bridge\Source::changed_by(), 'a text change does, and says which option: ' . \Contentrain\Bridge\Source::changed_by() );
delete_option( 'theme_mods_bridge-probe-theme' );

// A change to a post type this export does not read cannot make its snapshot inconsistent; one it reads can.
$old = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
if ( $old ) { Files::remove( Files::dir( $old ) ); delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' ); }
$probe = Jobs::create( array( 'types' => array( 'post', 'page' ), 'private' => false ) );
$nav = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_title' => 'Fallback navigation', 'post_status' => 'publish', 'post_content' => '' ) );
$probe = Jobs::step( $probe['id'], $probe['step'] );
check( 1 === $probe['step'], 'a navigation created mid-export (outside its post and page scope) does not refuse the snapshot' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Mid-export page', 'post_status' => 'publish' ) );
try {
	Jobs::step( $probe['id'], $probe['step'] );
	check( false, 'a page created mid-export refuses the snapshot' );
} catch ( RuntimeException $e ) {
	check( false !== strpos( $e->getMessage(), 'save_post:page' ), 'a page created mid-export refuses the snapshot and says so: ' . $e->getMessage() );
}
wp_delete_post( $nav, true );
wp_delete_post( $page, true );
Files::remove( Files::dir( $probe['id'] ) );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );

// ---- Export with the render scan on, so the page head is read too. ----
$old = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
if ( $old ) { Files::remove( Files::dir( $old ) ); delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' ); }
$s = Jobs::create( array( 'types' => array( 'post', 'page' ), 'private' => false, 'scan_sources' => true, 'scan_render' => true ) );
for ( $i = 0; $i < 3000 && 'review' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
$s = Jobs::review( $s['id'], array_map( static function ( $c ) { return array( 'decision' => 'exclude', 'key' => $c['key'] ); }, array_filter( Jobs::read( $s['id'] )['candidates'], static function ( $c ) { return 'review' === $c['decision']; } ) ), true );
for ( $i = 0; $i < 3000 && 'ready' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
check( 'ready' === $s['phase'], 'the export finishes' );
$job = Jobs::read( $s['id'] );
$dir = Files::dir( $job['id'] ) . '/output';
$raw = Files::read( $dir, 'bridge/integrations.json' );
$report = json_decode( $raw, true );
$by = array_column( $report['services'], null, 'service' );
$kinds = static function ( $id ) use ( $by ) { return array_values( array_unique( array_column( $by[ $id ]['evidence'] ?? array(), 'kind' ) ) ); };
check( 'contentrain-bridge-integrations@1' === $report['format'] && isset( json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['files']['bridge/integrations.json'] ), 'bridge/integrations.json is in the export and its manifest' );

// ---- Every fixture service, found by the evidence it left. ----
check( array( 'plugin', 'option-key', 'form-config' ) === array_values( array_intersect( array( 'plugin', 'option-key', 'form-config' ), $kinds( 'mailchimp' ) ) ) && true === $by['mailchimp']['secret_present'], 'Mailchimp: active plugin, its settings option, a WPForms provider; a key is set' );
check( in_array( 'option-key', $kinds( 'hubspot' ), true ) && in_array( 'form-config', $kinds( 'hubspot' ), true ) && true === $by['hubspot']['secret_present'] && 'crm' === $by['hubspot']['category'], 'HubSpot (CRM): settings options and a Gravity Forms feed; a token is set' );
check( true === $by['akismet']['secret_present'] && 'other' === $by['akismet']['category'], 'Akismet: its key option, set' );
check( in_array( 'script-domain', $kinds( 'hotjar' ), true ) && in_array( 'option-key', $kinds( 'hotjar' ), true ) && false === $by['hotjar']['secret_present'], 'Hotjar: its script on the rendered page and its site-id option (not a credential)' );
check( (bool) preg_grep( '/render:home/', array_column( $by['hotjar']['evidence'], 'detail' ) ), 'the rendered page head is where the Hotjar script was seen' );
check( in_array( 'script-domain', $kinds( 'google-tag-manager' ), true ) && 'analytics' === $by['google-tag-manager']['category'], 'Google Tag Manager: a script in a header-injection setting' );
check( isset( $by['jetpack-stats'], $by['jetpack-photon'] ) && 'cdn' === $by['jetpack-photon']['category'] && ! isset( $by['jetpack-related-posts'] ), 'Jetpack: its Stats and CDN modules as services of their own' );
check( in_array( 'form-config', $kinds( 'recaptcha' ), true ) && true === $by['recaptcha']['secret_present'] && 'captcha' === $by['recaptcha']['category'], 'reCAPTCHA: wired into Contact Form 7, with a secret set' );
check( isset( $by['embed-youtube'] ) && false === $by['embed-youtube']['reconnect_required'], 'a YouTube embed is listed, with nothing to reconnect' );
$categories = array( 'analytics', 'crm', 'newsletter', 'ads', 'comments', 'captcha', 'cdn', 'other' );
check( ! array_filter( $report['services'], static function ( $s ) use ( $categories ) { return ! in_array( $s['category'], $categories, true ) || ! is_bool( $s['reconnect_required'] ) || ! is_bool( $s['secret_present'] ) || '' === $s['notes'] || ! $s['evidence']; } ), 'every service: a known category, boolean flags, notes and evidence (' . count( $report['services'] ) . ' services)' );

// ---- No credential anywhere. ----
foreach ( $secrets + array( 'gf' => 'never-export-gf-feed-key' ) as $name => $value ) {
	check( false === strpos( $raw, $value ), "the $name credential is not in integrations.json" );
}
$all = '';
foreach ( array_keys( $job['files'] ) as $path ) { if ( 0 !== strpos( $path, 'media/' ) ) { $all .= Files::read( $dir, $path ); } }
check( ! array_filter( $secrets, static function ( $v ) use ( $all ) { return false !== strpos( $all, $v ); } ) && false === strpos( $all, 'never-export-gf-feed-key' ), 'no credential in any of ' . count( $job['files'] ) . ' exported files' );
check( (bool) preg_grep( '/^mc4wp \(api_key\)$/', array_column( $by['mailchimp']['evidence'], 'detail' ) ), 'evidence names the setting that holds the key ("mc4wp (api_key)"), never its value' );

// ---- What the admin screen and Migrate get. ----
$summary = Jobs::summary( $job );
check( in_array( 'HubSpot', array_column( array_filter( $summary['integrations'], static function ( $s ) { return $s['reconnect_required']; } ), 'name' ), true ), 'the export summary lists the services to reconnect for the admin screen' );
$copy = '/tmp/bridge-integrations';
Files::remove( $copy );
foreach ( array_keys( $job['files'] ) as $path ) {
	wp_mkdir_p( dirname( "$copy/store/$path" ) );
	copy( Files::path( $dir, $path ), "$copy/store/$path" );
}
wp_delete_post( $embed, true );
Files::remove( Files::dir( $job['id'] ) );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
echo "\n$checks checks passed. Integrations output: $copy\n";
