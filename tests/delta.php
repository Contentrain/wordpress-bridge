<?php
/**
 * B-06 delta cursor, executed inside the dedicated WordPress test container.
 *
 * Takes a T0 inventory through a real export, mutates WordPress the ways a
 * site actually changes, and requires the delta to name exactly those records:
 * a missing entry is a lost edit, an extra one is noise a planner acts on.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Contentrain\Bridge\Delta;
use Contentrain\Bridge\Files;
use Contentrain\Bridge\GitHub;
use Contentrain\Bridge\Inventory;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Models;
use Contentrain\Bridge\Policy;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
/** `type:id:op[/kind]`, the identity of one delta entry. */
function ops( $plan ) {
	$out = array_map( static function ( $e ) { return $e['wp_type'] . ':' . $e['wp_id'] . ':' . $e['op'] . ( isset( $e['deleted_kind'] ) ? '/' . $e['deleted_kind'] : '' ); }, $plan['entries'] );
	sort( $out );
	return $out;
}
function entry_for( $plan, $type, $id ) {
	foreach ( $plan['entries'] as $entry ) {
		if ( $type === $entry['wp_type'] && $id === $entry['wp_id'] ) { return $entry; }
	}
	return null;
}
/**
 * The A-09 planner's fixture layout: a store's `.contentrain/` contents at the
 * top of `$to`, and `entry-source-map.json` beside them — copied unchanged into
 * `ai/packages/wp-import/src/fixtures/`.
 */
function planner_store( $job_id, $to ) {
	$from = Files::dir( $job_id ) . '/output';
	Files::remove( $to );
	foreach ( array_keys( Jobs::read( $job_id )['files'] ) as $path ) {
		if ( 0 === strpos( $path, '.contentrain/' ) || 'bridge/entry-source-map.json' === $path ) {
			$target = $to . '/' . ( 0 === strpos( $path, '.contentrain/' ) ? substr( $path, 13 ) : 'entry-source-map.json' );
			wp_mkdir_p( dirname( $target ) );
			copy( Files::path( $from, $path ), $target );
		}
	}
}
/** Run an export to `ready` the way the admin screen does. */
function export( $types, $private = false ) {
	$summary = Jobs::create( array( 'types' => $types, 'private' => $private, 'scan_sources' => false, 'selected_meta' => array( 'delta_api_key' ) ) );
	for ( $i = 0; $i < 500 && 'review' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
	$summary = Jobs::review( $summary['id'], array(), true );
	for ( $i = 0; $i < 500 && 'ready' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
	if ( 'ready' !== $summary['phase'] ) { throw new RuntimeException( 'FAIL: export did not finish' ); }
	return $summary['id'];
}
function drop_job( $id ) {
	Files::remove( Files::dir( $id ) );
	delete_user_meta( get_current_user_id(), 'contentrain_bridge_job_' . get_current_blog_id() );
}

$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
// The acceptance export's job pointer is restored at the end; its store has already been copied out.
$acceptance_job = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );

// Pretty permalinks (set by tests/delta.sh): with `?p=123` addresses a slug change moves nothing.
check( '/%postname%/' === get_option( 'permalink_structure' ) && false === strpos( get_term_link( 1, 'category' ), '?cat=' ), 'pretty permalinks are active for posts and terms' );
// A classic theme's custom post type as sites register it: public, with its own address base, and — `register_post_type()`'s
// default — hidden from the REST API (a WPBakery portfolio at /urunlerimiz/<slug>/, ps). Its edits must reach every cursor.
$cpt_args = array( 'public' => true, 'show_in_rest' => false, 'label' => 'Delta CPT', 'rewrite' => array( 'slug' => 'urunlerimiz' ), 'supports' => array( 'title', 'editor' ) );
register_post_type( 'bridge_delta_cpt', $cpt_args );
check( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/bridge_delta_cpt' ) )->is_error(), 'the fixture CPT has no REST route (the trap is real)' );
$has_acf = function_exists( 'acf_add_local_field_group' );
check( $has_acf, 'ACF is active, so the meta-only trap is tested against real ACF' );
acf_add_local_field_group( array(
	'key' => 'group_bridge_delta',
	'title' => 'Delta fields',
	'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	'fields' => array( array( 'key' => 'field_bridge_delta_tagline', 'name' => 'delta_tagline', 'label' => 'Tagline', 'type' => 'text' ) ),
) );

// ---- Fixture: records each mutation will act on, plus bystanders that must not appear. ----
$run = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
// Not `$post`: this runs in global scope, where that name is WordPress's current post.
$make = static function ( $slug, $args = array() ) use ( $admin, $run ) {
	return wp_insert_post( $args + array( 'post_type' => 'post', 'post_title' => $slug, 'post_name' => $slug . '-' . $run, 'post_content' => '<p>' . $slug . '</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ), true );
};
$f = array();
// The record that gets edited is the hidden CPT's, with a page builder's body as the editor saved it.
$vc_body = '[vc_row][vc_column width="1/2"][vc_column_text]<p>Mali koruma.</p>[/vc_column_text][vc_toggle title="Sigorta Yaptırmanın Faydaları?"]Cevap.[/vc_toggle][vc_single_image image="123" img_size="full"][/vc_column][/vc_row]';
$f['edit'] = $make( 'delta-edit', array( 'post_type' => 'bridge_delta_cpt', 'post_content' => $vc_body ) );
$f['acf'] = $make( 'delta-acf', array( 'post_type' => 'page' ) );
update_field( 'delta_tagline', 'Before', $f['acf'] );
$f['trash'] = $make( 'delta-trash' );
$f['purge'] = $make( 'delta-purge' );
$f['slug'] = $make( 'delta-slug' );
$f['parent_a'] = $make( 'delta-parent-a', array( 'post_type' => 'page' ) );
$f['parent_b'] = $make( 'delta-parent-b', array( 'post_type' => 'page' ) );
$f['child'] = $make( 'delta-child', array( 'post_type' => 'page', 'post_parent' => $f['parent_a'] ) );
$f['unpublish'] = $make( 'delta-unpublish' );
$f['draft'] = $make( 'delta-unreleased-launch', array( 'post_status' => 'draft' ) );
$f['twice'] = $make( 'delta-twice' );
$f['untouched'] = $make( 'delta-untouched' );
$f['cpt'] = $make( 'delta-cpt', array( 'post_type' => 'bridge_delta_cpt' ) );
foreach ( $f as $name => $id ) {
	if ( is_wp_error( $id ) || ! $id ) { throw new RuntimeException( 'FAIL: fixture ' . $name ); }
}
// Menus the way a classic theme links a product: the header three levels deep (top link > page > the CPT record) and a
// footer list naming the record directly. Their items are inventory bystanders: nothing below changes them.
register_nav_menus( array( 'bridge-delta-header' => 'Header', 'bridge-delta-footer' => 'Footer' ) );
$header_menu = wp_create_nav_menu( 'Delta header ' . $run );
$footer_menu = wp_create_nav_menu( 'Delta footer ' . $run );
$menu_top = wp_update_nav_menu_item( $header_menu, 0, array( 'menu-item-title' => 'Kurumsal', 'menu-item-url' => 'https://example.test/kurumsal', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
$menu_mid = wp_update_nav_menu_item( $header_menu, 0, array( 'menu-item-title' => 'Ürünlerimiz', 'menu-item-object' => 'page', 'menu-item-object-id' => $f['parent_a'], 'menu-item-parent-id' => $menu_top, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$menu_leaf = wp_update_nav_menu_item( $header_menu, 0, array( 'menu-item-title' => 'Mali Koruma', 'menu-item-object' => 'bridge_delta_cpt', 'menu-item-object-id' => $f['edit'], 'menu-item-parent-id' => $menu_mid, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$menu_footer = wp_update_nav_menu_item( $footer_menu, 0, array( 'menu-item-title' => 'Mali Koruma', 'menu-item-object' => 'bridge_delta_cpt', 'menu-item-object-id' => $f['edit'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
foreach ( array( $menu_top, $menu_mid, $menu_leaf, $menu_footer ) as $item_id ) {
	if ( is_wp_error( $item_id ) || ! $item_id ) { throw new RuntimeException( 'FAIL: fixture menu item' ); }
}
set_theme_mod( 'nav_menu_locations', array( 'bridge-delta-header' => $header_menu, 'bridge-delta-footer' => $footer_menu ) );
// A secret on a record that changes: the delta and inventory must not carry it.
update_post_meta( $f['edit'], 'delta_api_key', 'ghp_' . str_repeat( 'Q', 36 ) );
$cat = wp_insert_term( 'Delta category ' . $run, 'category', array( 'slug' => 'delta-cat-' . $run ) );
$f_cat = (int) $cat['term_id'];
// The bystander is filed under the category that gets renamed: renaming a term is that term's change, not every post's.
wp_set_post_categories( $f['untouched'], array( $f_cat ) );
$uploads = wp_get_upload_dir();
wp_mkdir_p( $uploads['path'] );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$media_path = $uploads['path'] . '/delta-' . $run . '.png';
file_put_contents( $media_path, $png );
$f_media = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Delta media', 'post_status' => 'inherit' ), $media_path );
wp_update_attachment_metadata( $f_media, wp_generate_attachment_metadata( $f_media, $media_path ) );

// Content that predates the cursor: without this, a record written in the same
// second as T0 would satisfy `modified_after` by accident and hide the trap.
global $wpdb;
$ids = implode( ',', array_map( 'intval', array_merge( array_values( $f ), array( $f_media ) ) ) );
$wpdb->query( "UPDATE {$wpdb->posts} SET post_date_gmt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), post_date = DATE_SUB(NOW(), INTERVAL 1 DAY), post_modified_gmt = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), post_modified = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE ID IN ($ids)" );
foreach ( explode( ',', $ids ) as $id ) { clean_post_cache( (int) $id ); }

// ---- T0: taken by a real export and delivered as bridge/inventory.json. ----
$types = array( 'post', 'page', 'attachment', 'bridge_delta_cpt' );
$t0_job = export( $types );
$t0_raw = Files::read( Files::dir( $t0_job ) . '/output', 'bridge/inventory.json' );
$t0_manifest = Files::read( Files::dir( $t0_job ) . '/output', 'bridge/manifest.json' );
$t0 = json_decode( $t0_raw, true );
check( Inventory::FORMAT === $t0['format'] && Inventory::verify( $t0 ), 'export writes a verifiable contentrain-bridge-inventory@2' );
check( isset( json_decode( $t0_manifest, true )['files']['bridge/inventory.json'] ), 'the manifest hashes the inventory, so a Git edit to it is detectable' );
check( 'contentrain-bridge-inventory@1' === $t0['environment']['format'], 'the site-level inventory (plugins, theme) is kept alongside the records' );
check( array( 'attachment', 'bridge_delta_cpt', 'nav_menu_item', 'page', 'post' ) === $t0['scope']['post_types'], 'scope lists the exported types and menu items' );
$t0_index = array();
foreach ( $t0['records'] as $record ) { $t0_index[ Inventory::key( $record ) ] = $record; }
foreach ( array( 'edit', 'acf', 'trash', 'purge', 'slug', 'child', 'unpublish', 'draft', 'twice', 'untouched', 'cpt' ) as $name ) {
	$type = get_post_type( $f[ $name ] );
	check( isset( $t0_index[ $type . ':' . $f[ $name ] ] ), 'T0 holds ' . $name );
}
check( isset( $t0_index[ 'category:' . $f_cat ], $t0_index[ 'attachment:' . $f_media ] ), 'T0 holds terms and attachments' );
$draft_row = $t0_index[ 'post:' . $f['draft'] ];
check( ! isset( $draft_row['slug'] ) && ! isset( $draft_row['path'] ) && isset( $draft_row['fingerprint'] ), 'a public-scope inventory keeps a draft\'s identity, not its slug or address' );
check( false === strpos( $t0_raw, 'delta-unreleased-launch' ), 'the unreleased draft slug is not in the delivered inventory' );
check( false === strpos( $t0_raw, 'ghp_' ), 'no credential reaches the inventory' );
$standalone = Inventory::build( $t0['scope'] );
check( $standalone['inventory_hash'] === $t0['inventory_hash'], 'a standalone walk of the same scope reproduces the export\'s inventory hash' );
// ---- The hidden CPT comes across whole: its body as saved, its address, its routing rule, its menu links. ----
$t0_out = Files::dir( $t0_job ) . '/output';
$cpt_row = json_decode( Files::read( Files::dir( $t0_job ), Models::row_file( 'bridge/raw-posts.json', $f['edit'] ) ), true );
check( $vc_body === $cpt_row['content'] && 'bridge_delta_cpt' === $cpt_row['type'], 'the hidden CPT\'s raw body is exported byte for byte, page-builder tags included' );
check( '/urunlerimiz/delta-edit-' . $run . '/' === $t0_index[ 'bridge_delta_cpt:' . $f['edit'] ]['path'] && '/urunlerimiz/delta-edit-' . $run . '/' === wp_make_link_relative( $cpt_row['link'] ), 'the hidden CPT record carries its public address under the rewrite slug' );
$routing_types = array_column( json_decode( Files::read( $t0_out, 'bridge/routing.json' ), true )['post_types'], null, 'name' );
check( 'urunlerimiz' === $routing_types['bridge_delta_cpt']['rewrite']['slug'] && '/urunlerimiz/%bridge_delta_cpt%' === $routing_types['bridge_delta_cpt']['permastruct'], 'routing names the hidden CPT\'s rewrite slug and permastruct' );
$t0_record = Jobs::read( $t0_job );
$menu_rows = json_decode( Files::read( $t0_out, Models::content_path( $t0_record, 'wp-menu-items', $t0_record['default_locale'] ) ), true );
$menu_id = static function ( $wp_item_id ) { return substr( hash( 'sha256', 'menu:' . $wp_item_id ), 0, 12 ); };
$leaf_row = $menu_rows[ $menu_id( $menu_leaf ) ];
$mid_row = $menu_rows[ $menu_id( $menu_mid ) ];
check( 'post' === $leaf_row['target_kind'] && true === $leaf_row['target_resolved'] && 'bridge_delta_cpt' === $leaf_row['target_post_type'] && 'delta-edit-' . $run === $leaf_row['target_slug'] && '/urunlerimiz/delta-edit-' . $run . '/' === wp_make_link_relative( $leaf_row['url'] ), 'a third-level header link to the hidden CPT resolves kind/post_type/slug and its address' );
check( $menu_id( $menu_mid ) === $leaf_row['parent'] && $menu_id( $menu_top ) === $mid_row['parent'] && ! isset( $menu_rows[ $menu_id( $menu_top ) ]['parent'] ) && 'bridge-delta-header' === $leaf_row['location'], 'the header link keeps its two-level parent chain and its theme location' );
$footer_row = $menu_rows[ $menu_id( $menu_footer ) ];
check( 'bridge_delta_cpt' === $footer_row['target_post_type'] && true === $footer_row['target_resolved'] && 'bridge-delta-footer' === $footer_row['location'] && ! isset( $footer_row['parent'] ), 'a footer link to the hidden CPT resolves under the footer location' );
drop_job( $t0_job );
// The same moment in private scope, for the planner fixture: drafts are records
// a planner has to place too, and a public-scope store does not hold them.
$fixture = sys_get_temp_dir() . '/bridge-delta-fixture';
Files::remove( $fixture );
$t0p_job = export( $types, true );
planner_store( $t0p_job, $fixture . '/t0-store' );
$t0p = json_decode( Files::read( Files::dir( $t0p_job ) . '/output', 'bridge/inventory.json' ), true );
drop_job( $t0p_job );
echo "T0 inventory: " . count( $t0['records'] ) . " records, hash {$t0['inventory_hash']}\n";

// ---- The twelve mutations (AO-3 §3). ----
$created = $make( 'delta-created' );
wp_update_post( array( 'ID' => $f['edit'], 'post_content' => '<p>edited body</p>' ) );
$acf_modified = get_post( $f['acf'] )->post_modified_gmt;
update_field( 'delta_tagline', 'After', $f['acf'] );
clean_post_cache( $f['acf'] );
check( get_post( $f['acf'] )->post_modified_gmt === $acf_modified, 'an ACF-only edit leaves post_modified unchanged (the trap is real)' );
wp_trash_post( $f['trash'] );
wp_delete_post( $f['purge'], true );
wp_delete_attachment( $f_media, true );
wp_update_post( array( 'ID' => $f['slug'], 'post_name' => 'delta-slug-renamed-' . $run ) );
check( in_array( 'delta-slug-' . $run, get_post_meta( $f['slug'], '_wp_old_slug' ), true ), 'WordPress recorded _wp_old_slug for the published slug change' );
wp_update_post( array( 'ID' => $f['child'], 'post_parent' => $f['parent_b'] ) );
check( ! get_post_meta( $f['child'], '_wp_old_slug' ), 'a parent change leaves no _wp_old_slug: only the inventory can see it' );
wp_update_term( $f_cat, 'category', array( 'slug' => 'delta-cat-renamed-' . $run ) );
wp_update_post( array( 'ID' => $f['unpublish'], 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $f['draft'], 'post_name' => 'delta-unreleased-launch-v2-' . $run ) );
// Two edits inside one second: start both at the top of a fresh second.
$second = time();
while ( time() === $second ) { usleep( 20000 ); }
wp_update_post( array( 'ID' => $f['twice'], 'post_content' => '<p>first</p>' ) );
wp_update_post( array( 'ID' => $f['twice'], 'post_content' => '<p>second</p>' ) );

// ---- Delta against T0. ----
$result = Delta::plan( $t0, $t0['inventory_hash'] );
$plan = $result['plan'];
$t1 = $result['inventory'];
$expected = array(
	'post:' . $created . ':created',
	'bridge_delta_cpt:' . $f['edit'] . ':updated',
	'page:' . $f['acf'] . ':updated',
	'post:' . $f['trash'] . ':deleted/trashed',
	'post:' . $f['purge'] . ':deleted/purged',
	'attachment:' . $f_media . ':deleted/purged',
	'post:' . $f['slug'] . ':moved',
	'page:' . $f['child'] . ':moved',
	'category:' . $f_cat . ':moved',
	'post:' . $f['unpublish'] . ':updated',
	'post:' . $f['draft'] . ':updated',
	'post:' . $f['twice'] . ':updated',
);
sort( $expected );
$actual = ops( $plan );
if ( $expected !== $actual ) {
	echo 'missing: ' . implode( ', ', array_diff( $expected, $actual ) ) . "\nextra: " . implode( ', ', array_diff( $actual, $expected ) ) . "\n";
}
check( $expected === $actual, 'delta names exactly the 12 mutated records, nothing more (' . count( $actual ) . ' entries)' );
check( true === $plan['deletions_detectable'] && ! isset( $plan['refused'] ), 'deletions are detectable against a verified, complete, same-scope T0' );
check( 'bridge_inventory' === $plan['cursor']['kind'] && $t0['inventory_hash'] === $plan['cursor']['inventory_hash'] && $t0['taken_at'] === $plan['cursor']['taken_at'], 'cursor is T0' );
check( $t1['inventory_hash'] === $plan['next_cursor']['inventory_hash'] && Inventory::verify( $t1 ), 'next_cursor is the T1 inventory hash' );
foreach ( $plan['entries'] as $entry ) {
	if ( ! $entry['wp_type'] || isset( $entry['model'] ) || isset( $entry['entry_id'] ) || isset( $entry['conflict'] ) ) {
		throw new RuntimeException( 'FAIL: entry shape ' . wp_json_encode( $entry ) );
	}
}
check( ! isset( $plan['redirects'] ), 'wp_type always set; model, entry_id, conflict and redirects left to the planner' );
$slug = entry_for( $plan, 'post', $f['slug'] );
check( 'delta-slug-' . $run === $slug['slug_before'] && 'delta-slug-renamed-' . $run === $slug['slug_after'] && '/delta-slug-' . $run . '/' === $slug['path_before'] && '/delta-slug-renamed-' . $run . '/' === $slug['path_after'], 'a slug move carries slug and path before/after' );
check( false !== strpos( $slug['detail'], 'old slug recorded' ), 'the move is corroborated by _wp_old_slug' );
$child = entry_for( $plan, 'page', $f['child'] );
check( $child['slug_before'] === $child['slug_after'] && false !== strpos( $child['path_after'], 'delta-parent-b-' . $run ) && false !== strpos( $child['detail'], 'parent or permalink' ), 'a parent change is a move with the same slug and a new path' );
$category = entry_for( $plan, 'category', $f_cat );
check( false !== strpos( $category['path_before'], 'delta-cat-' . $run ) && false !== strpos( $category['path_after'], 'delta-cat-renamed-' . $run ), 'a term slug change is a move by path' );
check( false !== strpos( entry_for( $plan, 'page', $f['acf'] )['detail'], 'meta-only' ), 'the ACF-only edit is found by fingerprint, with modified_at unchanged' );
check( 'status publish -> draft' === entry_for( $plan, 'post', $f['unpublish'] )['detail'], 'unpublishing is an update, not a deletion' );
check( 'trashed' === entry_for( $plan, 'post', $f['trash'] )['detail'] && 'purged' === entry_for( $plan, 'post', $f['purge'] )['detail'], 'detail repeats the deletion kind for types@1.16 readers' );
check( ! isset( entry_for( $plan, 'post', $f['purge'] )['fingerprint_after'] ) && isset( entry_for( $plan, 'post', $f['purge'] )['fingerprint_before'] ), 'a purged record has only a before fingerprint' );
check( ! isset( entry_for( $plan, 'post', $f['draft'] )['slug_after'] ), 'the draft\'s new slug is not published through the delta' );
check( false === strpos( wp_json_encode( $plan ) . wp_json_encode( $t1 ), 'ghp_' ), 'no credential reaches the delta or T1' );

// ---- Idempotency: T1 against itself. ----
$again = Delta::plan( $t1, $t1['inventory_hash'] );
check( array() === $again['plan']['entries'] && true === $again['plan']['deletions_detectable'], 'a second delta against T1 is empty' );
check( $t1['inventory_hash'] === $again['inventory']['inventory_hash'], 'the inventory is stable across walks' );
check( $plan['entries'] === Delta::compare( $t0, $t1 )['entries'], 'comparing the same inventories is deterministic' );

// ---- Negative: a tampered T0 is refused. ----
$tampered = $t0;
$tampered['records'][0]['status'] = 'publish' === ( $tampered['records'][0]['status'] ?? '' ) ? 'draft' : 'publish';
$refused = Delta::compare( $tampered, $t1 );
check( ! empty( $refused['refused'] ) && false === $refused['deletions_detectable'] && array() === $refused['entries'], 'a T0 edited without its hash is refused, deletions undetectable' );
$refused = Delta::plan( $t0, str_repeat( '0', 64 ) )['plan'];
check( ! empty( $refused['refused'] ) && false === $refused['deletions_detectable'], 'a T0 whose hash differs from the trusted cursor is refused' );
$legacy = Delta::compare( array( 'format' => 'contentrain-bridge-inventory@1' ), $t1 );
check( ! empty( $legacy['refused'] ) && false !== strpos( $legacy['warnings'][0], 'inventory-legacy' ), 'a pre-@2 delivery is named as legacy, not as tampering' );

// ---- Negative: scope loss is a warning, not a deletion. ----
unregister_post_type( 'bridge_delta_cpt' );
$lost = Delta::plan( $t1 )['plan'];
check( array() === array_filter( $lost['entries'], static function ( $e ) { return 'bridge_delta_cpt' === $e['wp_type']; } ), 'records of a type whose plugin went away are not reported deleted' );
check( array( 'bridge_delta_cpt' ) === $lost['deletions_undetectable_types'] && false === $lost['deletions_detectable'], 'the lost type is named and deletions are not claimed as detectable' );
check( 1 === count( preg_grep( '/^scope-lost:bridge_delta_cpt:/', $lost['warnings'] ) ), 'exactly one scope warning for the lost type' );
// The two menu links that pointed at the lost type did change — WordPress marks them invalid once their target type is
// gone — and the inventory says so; nothing else did.
// A reused test site keeps earlier runs' menus too (tests/run.sh), so: these two among them, and only such links.
$lost_ops = ops( $lost );
$not_menu = array_filter( $lost_ops, static function ( $op ) { return 0 !== strpos( $op, 'nav_menu_item:' ) || ':updated' !== substr( $op, -8 ); } );
check( array() === $not_menu && array() === array_diff( array( 'nav_menu_item:' . $menu_leaf . ':updated', 'nav_menu_item:' . $menu_footer . ':updated' ), $lost_ops ), 'the only other change is the menu links whose target type went away, reported as updated (' . implode( ', ', $lost_ops ) . ')' );
register_post_type( 'bridge_delta_cpt', $cpt_args );

// ---- Negative: a truncated walk proves no deletion. ----
$short = Delta::plan( $t1, null, 3 )['plan'];
check( false === $short['deletions_detectable'] && ! preg_grep( '/:deleted/', ops( $short ) ) && preg_grep( '/^inventory-truncated/', $short['warnings'] ), 'a truncated inventory reports no deletions and says why' );

// ---- Negative: REST modified_after alone. ----
$rest = Delta::rest_modified_after( $t0['taken_at'] );
$rest_ops = ops( $rest );
check( 'rest_modified_after' === $rest['cursor']['kind'] && false === $rest['deletions_detectable'], 'REST-only mode never claims deletions are detectable' );
check( in_array( 'post:' . $created . ':created', $rest_ops, true ) && in_array( 'post:' . $f['twice'] . ':updated', $rest_ops, true ), 'REST-only mode sees ordinary edits and new posts' );
check( in_array( 'bridge_delta_cpt:' . $f['edit'] . ':updated', $rest_ops, true ), 'REST-only mode sees the edit of a post type hidden from REST (show_in_rest false)' );
$rest_ids = array_column( $rest['entries'], 'wp_id' );
check( ! in_array( $f['acf'], $rest_ids, true ), 'REST-only mode misses the ACF-only edit (proven)' );
check( ! in_array( $f['purge'], $rest_ids, true ) && ! in_array( $f['trash'], $rest_ids, true ) && ! in_array( $f_media, $rest_ids, true ), 'REST-only mode misses purge, trash and attachment deletion (proven)' );
check( ! in_array( $f_cat, $rest_ids, true ), 'REST-only mode cannot see a term move' );
// A draft written after the cursor and never saved again: WordPress copies its floating date into post_modified_gmt
// (`0000-00-00`), so only the site-local post_modified column, which REST's `modified_after` filters, can find it.
$fresh_draft = $make( 'delta-fresh-draft', array( 'post_status' => 'draft' ) );
clean_post_cache( $fresh_draft );
check( '0000-00-00 00:00:00' === get_post( $fresh_draft )->post_modified_gmt, 'a never-saved draft has no GMT modified date (the trap is real)' );
$rest_again = Delta::rest_modified_after( $t0['taken_at'] );
$fresh_entry = entry_for( $rest_again, 'post', $fresh_draft );
check( $fresh_entry && 'created' === $fresh_entry['op'] && preg_match( '/^modified_gmt \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $fresh_entry['detail'] ), 'REST-only mode sees a never-saved draft as created, with a derived GMT modified date' );
// Purged before the delivery below: never in T0, so it leaves no entry behind.
wp_delete_post( $fresh_draft, true );

// ---- Delivery wiring: T0 read from the repository, delta written beside the export. ----
$t1_job = export( $types );
$remote = array( 'bridge/manifest.json' => $t0_manifest, 'bridge/inventory.json' => $t0_raw );
$github = static function ( $pre, $args, $url ) use ( &$remote ) {
	$base = 'https://api.github.com/repos/test-owner/delta-repo';
	if ( 0 !== strpos( $url, $base ) ) { throw new RuntimeException( 'Unexpected external request: ' . $url ); }
	$path = substr( $url, strlen( $base ) );
	$blobs = array();
	foreach ( $remote as $file => $content ) { $blobs[ sha1( 'blob ' . strlen( $content ) . "\0" . $content ) ] = $content; }
	if ( '' === $path ) { $data = array( 'private' => false, 'default_branch' => 'main' ); }
	elseif ( '/git/ref/heads/main' === $path ) { $data = array( 'object' => array( 'sha' => str_repeat( 'a', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/commits/' ) ) { $data = array( 'tree' => array( 'sha' => str_repeat( 'b', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/trees/' ) ) {
		$tree = array();
		foreach ( $blobs as $sha => $content ) { $tree[] = array( 'path' => array_search( $content, $remote, true ), 'type' => 'blob', 'sha' => $sha ); }
		$data = array( 'truncated' => false, 'tree' => $tree );
	} elseif ( 0 === strpos( $path, '/git/blobs/' ) && isset( $blobs[ substr( $path, 11 ) ] ) ) { $data = array( 'content' => chunk_split( base64_encode( $blobs[ substr( $path, 11 ) ] ), 60, "\n" ) ); }
	else { throw new RuntimeException( 'Unmocked GitHub endpoint: ' . $path ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $github, 10, 3 );
GitHub::start( $t1_job, 'test-only-token-123456', 'test-owner/delta-repo' );
$delivered = Jobs::read( $t1_job );
$delta_file = json_decode( Files::read( Files::dir( $t1_job ) . '/output', 'bridge/delta.json' ), true );
check( isset( $delivered['files']['bridge/delta.json'] ), 'delivery writes bridge/delta.json when the repository holds a T0 inventory' );
check( $expected === ops( $delta_file ) && true === $delta_file['deletions_detectable'], 'the delivered delta equals the standalone one' );
check( isset( json_decode( Files::read( Files::dir( $t1_job ) . '/output', 'bridge/manifest.json' ), true )['files']['bridge/delta.json'] ), 'the rewritten manifest lists the delta, so the next delivery trusts it' );
// Someone edits the inventory in Git: its bytes no longer match the delivered manifest.
Jobs::mutate( $t1_job, static function ( &$job ) { unset( $job['github'] ); } );
$remote['bridge/inventory.json'] = str_replace( '"complete": true', '"complete": true ', $t0_raw );
GitHub::start( $t1_job, 'test-only-token-123456', 'test-owner/delta-repo' );
$edited = json_decode( Files::read( Files::dir( $t1_job ) . '/output', 'bridge/delta.json' ), true );
check( ! empty( $edited['refused'] ) && false === $edited['deletions_detectable'], 'an inventory edited in Git after delivery is refused as a cursor' );
remove_filter( 'pre_http_request', $github, 10 );
check( false === strpos( Files::read( Files::dir( $t1_job ), 'state.json' ), 'test-only-token' ), 'the GitHub token is never persisted' );

// ---- Artifacts for the ai A-09 planner fixture and the host-side scans. ----
$out = sys_get_temp_dir() . '/bridge-delta';
Files::remove( $out );
wp_mkdir_p( $out );
file_put_contents( $out . '/delta.json', Policy::json( $plan ) );
file_put_contents( $out . '/inventory-t0.json', $t0_raw );
file_put_contents( $out . '/inventory-t1.json', Policy::json( $t1 ) );
file_put_contents( $out . '/delivered-delta.json', Policy::json( $delta_file ) );
file_put_contents( $out . '/rest-only.json', Policy::json( $rest ) );
file_put_contents( $out . '/scope-lost.json', Policy::json( $lost ) );
file_put_contents( $out . '/refused.json', Policy::json( Delta::compare( $tampered, $t1 ) ) );
file_put_contents( $out . '/expected.json', Policy::json( $expected ) );

drop_job( $t1_job );

// ---- Planner fixture: T0 store, T1 export and the delta between them, private scope. ----
$t1p_job = export( $types, true );
planner_store( $t1p_job, $fixture . '/t1-export' );
$t1p = json_decode( Files::read( Files::dir( $t1p_job ) . '/output', 'bridge/inventory.json' ), true );
$fixture_plan = Delta::compare( $t0p, $t1p );
check( $expected === ops( $fixture_plan ) && true === $fixture_plan['deletions_detectable'], 'private scope: the same twelve entries, deletions detectable' );
drop_job( $t1p_job );
file_put_contents( $fixture . '/t1.delta.json', Policy::json( $fixture_plan ) );
file_put_contents( $fixture . '/expected.json', Policy::json( $expected ) );
rename( $fixture, $out . '/planner' );
if ( $acceptance_job ) { update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $acceptance_job ); }
echo "\n$checks checks passed. Delta output: $out\n";
