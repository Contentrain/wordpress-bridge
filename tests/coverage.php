<?php
/**
 * B-02 acceptance: every source WordPress keeps content in has one outcome per
 * record, and the report's counts equal the database's own. Runs inside the
 * test container (tests/coverage.sh) with the fixture CPT as a must-use plugin.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Admin;
use Contentrain\Bridge\Files;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Source;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
if ( ! post_type_exists( 'bridge_cov_cpt' ) || get_post_type_object( 'bridge_cov_cpt' )->show_in_rest ) {
	throw new RuntimeException( 'FAIL: the non-REST fixture CPT is not registered' );
}
global $wpdb;
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
$run = substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
$make = static function ( $key, $args ) use ( $admin, $run ) {
	$id = wp_insert_post( $args + array( 'post_type' => 'post', 'post_title' => 'Coverage ' . $key . ' ' . $run, 'post_content' => '<p>Coverage ' . $key . '</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'FAIL: fixture ' . $key . ': ' . $id->get_error_message() ); }
	return $id;
};

// ---- The sources the acceptance fixture did not have. ----
$f = array();
$f['block'] = $make( 'reusable block', array( 'post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>Shared call to action</p><!-- /wp:paragraph -->' ) );
$f['uses_block'] = $make( 'uses block', array( 'post_content' => '<!-- wp:block {"ref":' . $f['block'] . '} /-->' ) );
$f['navigation'] = $make( 'navigation', array( 'post_type' => 'wp_navigation', 'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' ) );
$f['future'] = $make( 'future', array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
$f['private'] = $make( 'private', array( 'post_status' => 'private' ) );
$f['pending'] = $make( 'pending', array( 'post_status' => 'pending' ) );
$f['draft'] = $make( 'draft', array( 'post_status' => 'draft' ) );
$f['password'] = $make( 'password', array( 'post_password' => 'letmein' ) );
$f['sticky'] = $make( 'sticky', array() );
stick_post( $f['sticky'] );
$f['cpt'] = $make( 'non-REST CPT', array( 'post_type' => 'bridge_cov_cpt' ) );
$f['shortcode'] = $make( 'shortcode', array( 'post_content' => '<p>Before</p>[gallery ids="1"]<p>After</p>' ) );
$f['embed'] = $make( 'embed', array( 'post_content' => "<p>Watch:</p>\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n" ) );
$f['template'] = $make( 'template page', array( 'post_type' => 'page', 'meta_input' => array( '_wp_page_template' => 'templates/wide.php' ) ) );
$f['revised'] = $make( 'revised', array() );
wp_update_post( array( 'ID' => $f['revised'], 'post_content' => '<p>Coverage revised, second version</p>' ) );
$f['trashed'] = $make( 'trashed', array() );
wp_trash_post( $f['trashed'] );
// A post type nothing registers any more: its plugin was removed, its rows remain.
$wpdb->insert( $wpdb->posts, array( 'post_type' => 'ghost_type', 'post_title' => 'Orphaned ' . $run, 'post_status' => 'publish', 'post_name' => 'orphaned-' . $run, 'post_author' => $admin->ID, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ), 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
$f['ghost'] = (int) $wpdb->insert_id;
$term = wp_insert_term( 'Coverage topic ' . $run, 'category' );
$f['term'] = (int) $term['term_id'];
add_term_meta( $f['term'], 'color', 'teal' );
add_term_meta( $f['term'], 'api_key', 'never-export-term-secret' );
// A plugin table this export does not read: listed with its rows, not passed over.
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bridge_custom_log (id int unsigned NOT NULL AUTO_INCREMENT, note varchar(64), PRIMARY KEY (id))" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}bridge_custom_log" );
$wpdb->query( "INSERT INTO {$wpdb->prefix}bridge_custom_log (note) VALUES ('a'), ('b'), ('c')" );

// ---- Inventory: the B-02 list. ----
acf_add_local_field_group( array( 'key' => 'group_bridge_coverage', 'title' => 'Coverage fields', 'show_in_rest' => 1, 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'bridge_cov_cpt' ) ) ), 'fields' => array( array( 'key' => 'field_cov_note', 'name' => 'cov_note', 'label' => 'Note', 'type' => 'text' ) ) ) );
$inventory = Source::inventory();
check( isset( $inventory['plugins'] ) && $inventory['plugins'] && isset( $inventory['theme']['name'], $inventory['theme']['version'] ), 'inventory: active plugins with versions, and the theme' );
check( false === $inventory['post_types']['bridge_cov_cpt']['rest'] && true === $inventory['post_types']['post']['rest'], 'inventory: post types say whether REST can see them' );
$group = array_values( array_filter( $inventory['acf_groups'], static function ( $g ) { return 'group_bridge_coverage' === $g['key']; } ) )[0] ?? null;
check( $group && true === $group['active'] && true === $group['show_in_rest'] && 'bridge_cov_cpt' === $group['location'][0][0]['value'], 'inventory: ACF field groups with their visibility (active, REST, where they show)' );
check( $inventory['media']['count'] >= 1 && $inventory['media']['bytes'] > 0, 'inventory: media count and size (' . $inventory['media']['count'] . ' files, ' . $inventory['media']['bytes'] . ' bytes)' );
check( isset( $inventory['comments']['total_comments'], $inventory['capabilities']['forms'], $inventory['capabilities']['seo'], $inventory['capabilities']['languages'] ) && false === $inventory['multisite'], 'inventory: comment counts, and form, SEO and language plugins by name' );

/** Export everything selectable, to ready, in one scope. */
function export( $private ) {
	return export_once( $private );
}
function export_once( $private ) {
	$user = get_current_user_id();
	$old = get_user_meta( $user, 'contentrain_bridge_job_1', true );
	if ( $old ) { Files::remove( Files::dir( $old ) ); delete_user_meta( $user, 'contentrain_bridge_job_1' ); }
	$s = Jobs::create( array( 'types' => array_keys( Source::inventory()['post_types'] ), 'private' => $private, 'comments' => true, 'scan_sources' => false ) );
	for ( $i = 0; $i < 5000 && 'review' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
	$s = Jobs::review( $s['id'], array(), true );
	for ( $i = 0; $i < 5000 && 'ready' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
	if ( 'ready' !== $s['phase'] ) { throw new RuntimeException( 'FAIL: export did not finish' ); }
	return $s['id'];
}
$source_of = static function ( $report, $name ) {
	foreach ( $report['sources'] as $s ) { if ( $name === $s['source'] ) { return $s; } }
	return null;
};

// Caches are not content: reading a post may write an oEmbed cache, and that must not refuse the export.
$before = Source::revision();
$cache = wp_insert_post( array( 'post_type' => 'oembed_cache', 'post_status' => 'publish', 'post_title' => 'cache ' . $run, 'post_name' => md5( $run ) ) );
update_post_meta( $f['embed'], '_oembed_' . md5( $run ), '{{unknown}}' );
check( $before === Source::revision(), 'an oEmbed cache post and an _oembed_ meta row do not count as a content change' );
wp_update_post( array( 'ID' => $f['draft'], 'post_title' => 'Coverage draft retitled ' . $run ) );
check( $before !== Source::revision() && 'save_post' === Source::changed_by(), 'a real edit does, and says which hook: ' . Source::changed_by() );
$previous = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
$out = '/tmp/bridge-coverage';
Files::remove( $out );
foreach ( array( 'public' => false, 'private' => true ) as $scope => $private ) {
	$id = export( $private );
	$job = Jobs::read( $id );
	$dir = Files::dir( $id ) . '/output';
	$report = json_decode( Files::read( $dir, 'bridge/coverage.json' ), true );
	echo "\n-- $scope scope --\n";

	// Zero silent skips: every source adds up, every posts row and every table is somewhere.
	$unbalanced = array_filter( $report['sources'], static function ( $s ) { return ! $s['balanced']; } );
	foreach ( $unbalanced as $s ) { echo '  unbalanced: ' . $s['source'] . ' count ' . $s['count'] . ' outcomes ' . wp_json_encode( $s['outcomes'] ) . "\n"; }
	check( ! $unbalanced && 0 === $report['totals']['unbalanced'], "$scope: every one of {$report['totals']['sources']} sources adds up: its outcomes equal its database count" );
	$post_rows = array_sum( array_map( static function ( $s ) { return 0 === strpos( $s['source'], 'posts:' ) ? $s['count'] : 0; }, $report['sources'] ) );
	check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $post_rows, "$scope: every row of the posts table ($post_rows) is in a posts:<type> source" );
	$tables = $source_of( $report, 'database_tables' );
	check( count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) ) === $tables['count'] && 3 === $tables['not_read']['bridge_custom_log'], "$scope: every table is listed; the unread plugin table shows its 3 rows" );
	check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ) === $source_of( $report, 'term_meta' )['count'], "$scope: term meta counted row by row" );
	check( isset( $job['files']['bridge/coverage.json'] ) && isset( json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['files']['bridge/coverage.json'] ), "$scope: bridge/coverage.json is in the export and its manifest" );
	check( false === $report['complete'] && $report['totals']['unsupported'] > 0, "$scope: the export does not call itself complete while something is unsupported ({$report['totals']['unsupported']} records)" );

	// The fixture's sources, each where it belongs.
	$raw = json_decode( Files::read( $dir, 'bridge/raw-posts.json' ), true );
	$exported = static function ( $post_id ) use ( $raw ) { return isset( $raw[ (string) $post_id ] ); };
	$post = $source_of( $report, 'posts:post' );
	check( $exported( $f['block'] ) && isset( $source_of( $report, 'posts:wp_block' )['outcomes']['exported'] ), "$scope: the reusable block (wp_block) is exported" );
	check( $exported( $f['navigation'] ) && isset( $source_of( $report, 'posts:wp_navigation' )['outcomes']['exported'] ), "$scope: the navigation (wp_navigation) is exported" );
	check( $exported( $f['cpt'] ) && false === $source_of( $report, 'posts:bridge_cov_cpt' )['show_in_rest'], "$scope: the post type hidden from REST is exported" );
	check( 'excluded:revision' === array_keys( $source_of( $report, 'posts:revision' )['outcomes'] )[0], "$scope: revisions are excluded as revisions" );
	$ghost = $source_of( $report, 'posts:ghost_type' );
	check( array( 'unsupported:type-not-registered' => $ghost['count'] ) === $ghost['outcomes'] && $ghost['count'] >= 1 && false === $ghost['registered'] && ! $exported( $f['ghost'] ), "$scope: rows of an unregistered type are unsupported, not skipped ({$ghost['count']})" );
	check( ( $post['outcomes']['excluded:trash'] ?? 0 ) >= 1 && ! $exported( $f['trashed'] ), "$scope: trash is excluded as trash" );
	if ( ! $private ) {
		check( ! $exported( $f['future'] ) && ! $exported( $f['private'] ) && ! $exported( $f['pending'] ) && ! $exported( $f['draft'] ) && ( $post['outcomes']['excluded:status-scope'] ?? 0 ) >= 4, 'public: future, private, pending and draft posts are excluded by scope' );
		check( ! $exported( $f['password'] ) && ( $post['outcomes']['excluded:password-protected'] ?? 0 ) >= 1, 'public: a password-protected post is excluded as such' );
	} else {
		check( $exported( $f['future'] ) && $exported( $f['private'] ) && $exported( $f['pending'] ) && $exported( $f['draft'] ) && $exported( $f['password'] ), 'private: future, private, pending, draft and password-protected posts are exported' );
		check( null === $raw[ (string) $f['password'] ]['password'], 'private: the password itself is never exported' );
	}
	check( $raw[ (string) $f['sticky'] ]['sticky'] && ( $source_of( $report, 'sticky' )['outcomes']['exported:flag'] ?? 0 ) >= 1, "$scope: the sticky flag is exported" );
	check( 'templates/wide.php' === $raw[ (string) $f['template'] ]['meta']['_wp_page_template'] && ( $source_of( $report, 'page_templates' )['outcomes']['exported:meta'] ?? 0 ) >= 1, "$scope: the page template is exported" );
	check( ( $source_of( $report, 'shortcodes' )['outcomes']['unsupported:kept-as-source-needs-renderer'] ?? 0 ) >= 1 && false !== strpos( $raw[ (string) $f['shortcode'] ]['content'], '[gallery ids="1"]' ), "$scope: a shortcode body is kept verbatim and reported as needing a renderer" );
	check( ( $source_of( $report, 'embeds' )['outcomes']['unsupported:embed-url-kept-needs-renderer'] ?? 0 ) >= 1, "$scope: an oEmbed URL is kept and reported as needing a renderer" );
	check( ( $source_of( $report, 'reusable_block_references' )['outcomes']['exported:target-in-export'] ?? 0 ) >= 1, "$scope: a reference to a reusable block resolves inside the export" );
	$terms = json_decode( Files::read( $dir, 'bridge/raw-terms.json' ), true );
	check( 'teal' === $terms[ (string) $f['term'] ]['meta']['color'] && ! isset( $terms[ (string) $f['term'] ]['meta']['api_key'] ) && ( $source_of( $report, 'term_meta' )['outcomes']['excluded:sensitive-key'] ?? 0 ) >= 1, "$scope: term meta is exported, its secret key is not" );
	check( 0 === ( $source_of( $report, 'post_meta' )['outcomes']['unsupported:unclassified'] ?? 0 ), "$scope: every meta key of every exported post has a named outcome" );
	check( isset( $source_of( $report, 'users' )['outcomes']['exported:author-public-fields'] ) && isset( $source_of( $report, 'options' )['outcomes']['exported:site-identity'] ) && isset( $source_of( $report, 'network_sites' )['count'] ), "$scope: users, options and the network are reported" );
	check( 'contentrain-bridge-coverage@1' === $report['format'] && isset( Jobs::summary( $job )['coverage']['sources'] ), "$scope: the export's summary carries the coverage totals the admin screen shows" );
	if ( class_exists( '\ZipArchive' ) ) {
		do { $zip = Admin::zip( $id ); } while ( ! $zip['done'] );
		$archive = new ZipArchive();
		$archive->open( Files::dir( $id ) . '/export.zip' );
		check( false !== $archive->locateName( 'bridge/coverage.json' ) && false !== $archive->locateName( '.contentrain/config.json' ), "$scope: the local ZIP carries the store and its coverage report" );
		$archive->close();
	}
	$all = '';
	foreach ( array_keys( $job['files'] ) as $path ) { if ( 0 !== strpos( $path, 'media/' ) ) { $all .= Files::read( $dir, $path ); } }
	check( false === strpos( $all, 'never-export-term-secret' ) && false === strpos( $all, 'letmein' ), "$scope: no term secret and no post password in any exported file" );

	foreach ( array_keys( $job['files'] ) as $path ) {
		wp_mkdir_p( dirname( "$out/$scope/store/$path" ) );
		copy( Files::path( $dir, $path ), "$out/$scope/store/$path" );
	}
	Files::remove( Files::dir( $id ) );
	delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
}
if ( $previous ) { update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $previous ); }
file_put_contents( "$out/fixture.json", wp_json_encode( $f ) );
echo "\n$checks checks passed. Coverage output: $out\n";
