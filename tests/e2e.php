<?php
/**
 * B-11 end-to-end chain, WordPress side. Executed inside the throwaway test
 * container by tests/e2e.sh, one stage per call:
 *
 *   php e2e.php fixture        the richest site the suite has: ACF, Yoast,
 *                              Redirection, a menu, media, terms
 *   php e2e.php export <label> a real export to `ready`, copied to /tmp/bridge-e2e/<label>
 *   php e2e.php mutate         three edits after T0: body, slug, trash
 *
 *   php e2e.php github-prepare  (GitHub leg only) this run's own base branch
 *   php e2e.php github-cleanup  (GitHub leg only) delete every branch the run made
 *
 * With BRIDGE_TEST_REPO and BRIDGE_TEST_TOKEN set, `export` also delivers to
 * that repository (the B-10 path) against this run's own base branch,
 * `e2e/<run>`, cut from the repository's first commit, and merges the delivery
 * into it, so the next export's delta is measured against what GitHub holds.
 * The default branch is never written after its first commit (made here only
 * when the repository is still empty), so every run starts from the same clean
 * state and no reset is needed. The token is read from the environment only
 * and never written or printed.
 */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Contentrain\Bridge\Files;
use Contentrain\Bridge\GitHub;
use Contentrain\Bridge\Jobs;

$stage = $argv[1] ?? '';
$dir = '/tmp/bridge-e2e';
wp_mkdir_p( $dir );
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
// The acceptance fixture's ACF group, registered again in this process: a
// local field group exists only in the request that registers it.
if ( function_exists( 'acf_add_local_field_group' ) ) {
	acf_add_local_field_group( array(
		'key' => 'group_bridge_e2e',
		'title' => 'E2E fields',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_e2e_tagline', 'name' => 'e2e_tagline', 'label' => 'Tagline', 'type' => 'text' ),
			array( 'key' => 'field_e2e_steps', 'name' => 'e2e_steps', 'label' => 'Steps', 'type' => 'repeater', 'sub_fields' => array(
				array( 'key' => 'field_e2e_step_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array( 'key' => 'field_e2e_step_detail', 'name' => 'detail', 'label' => 'Detail', 'type' => 'textarea' ),
			) ),
		),
	) );
}

if ( 'fixture' === $stage ) {
	if ( ! defined( 'WPSEO_VERSION' ) || ! defined( 'REDIRECTION_VERSION' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		throw new RuntimeException( 'FAIL: ACF, Yoast SEO and Redirection must be active.' );
	}
	$run = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
	$make = static function ( $title, $args = array() ) use ( $admin, $run ) {
		$id = wp_insert_post( $args + array( 'post_type' => 'post', 'post_title' => $title, 'post_name' => sanitize_title( $title ) . '-' . $run, 'post_content' => '<p>' . $title . ' original body.</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'FAIL: ' . $id->get_error_message() ); }
		return $id;
	};
	$cat = wp_insert_term( 'E2E Guides ' . $run, 'category', array( 'slug' => 'e2e-guides-' . $run ) );
	$uploads = wp_get_upload_dir();
	wp_mkdir_p( $uploads['path'] );
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
	file_put_contents( $uploads['path'] . '/e2e-cover-' . $run . '.png', $png );
	$media = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'E2E cover', 'post_status' => 'inherit' ), $uploads['path'] . '/e2e-cover-' . $run . '.png' );
	wp_update_attachment_metadata( $media, wp_generate_attachment_metadata( $media, $uploads['path'] . '/e2e-cover-' . $run . '.png' ) );
	$f = array( 'run' => $run );
	$f['edit'] = $make( 'E2E edited post', array( 'post_category' => array( (int) $cat['term_id'] ), 'tags_input' => array( 'e2e-tag-' . $run ), 'meta_input' => array( '_thumbnail_id' => $media, '_yoast_wpseo_metadesc' => 'E2E description' ) ) );
	$f['rename'] = $make( 'E2E renamed post', array( 'post_category' => array( (int) $cat['term_id'] ) ) );
	$f['trash'] = $make( 'E2E trashed post' );
	$f['stable'] = $make( 'E2E stable post', array( 'post_category' => array( (int) $cat['term_id'] ) ) );
	$f['page'] = $make( 'E2E guide page', array( 'post_type' => 'page' ) );
	update_field( 'e2e_tagline', 'Guides you own', $f['page'] );
	update_field( 'e2e_steps', array( array( 'title' => 'Export', 'detail' => 'Run Bridge' ), array( 'title' => 'Build', 'detail' => 'Run Astro' ) ), $f['page'] );
	$f['media'] = $media;
	$f['category'] = (int) $cat['term_id'];
	// A menu pointing at a page, a category and an external URL: none of the records the delta will touch.
	$menu = wp_create_nav_menu( 'E2E menu ' . $run );
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Guide', 'menu-item-object' => 'page', 'menu-item-object-id' => $f['page'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Guides', 'menu-item-object' => 'category', 'menu-item-object-id' => $f['category'], 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Contentrain', 'menu-item-url' => 'https://contentrain.io/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	$f['menu'] = $menu;
	foreach ( array( 'e2e-old-guide' => get_permalink( $f['page'] ), 'e2e-old-post' => get_permalink( $f['stable'] ) ) as $from => $to ) {
		Red_Item::create( array( 'url' => '/' . $from . '-' . $run, 'action_data' => array( 'url' => wp_make_link_relative( $to ) ), 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'url', 'group_id' => 1 ) );
	}
	file_put_contents( $dir . '/fixture.json', wp_json_encode( $f, JSON_PRETTY_PRINT ) );
	echo "E2E fixture $run: 4 posts, 1 ACF page, 1 image, 1 category, 1 tag, 3 menu items, 2 redirects\n";
	exit;
}

$github_state = $dir . '/github.json';
if ( 'github-prepare' === $stage ) {
	$repository = (string) getenv( 'BRIDGE_TEST_REPO' );
	$token = (string) getenv( 'BRIDGE_TEST_TOKEN' );
	$prefix = '/repos/' . $repository;
	$info = GitHub::request( $token, 'GET', $prefix );
	try {
		$head = GitHub::request( $token, 'GET', $prefix . '/git/ref/heads/' . $info['default_branch'] );
	} catch ( RuntimeException $error ) {
		if ( 409 !== $error->getCode() ) { throw $error; }
		// Still empty: its first commit is the one thing a test may put on the
		// default branch, exactly as a person would when creating it with a README.
		// Delivery itself refuses an empty repository (see tests/integration.php).
		GitHub::request( $token, 'PUT', $prefix . '/contents/README.md', array( 'message' => 'Initialize the Bridge e2e test repository', 'content' => base64_encode( "# Bridge e2e\n\nTarget of wordpress-bridge's tests/e2e.sh. Each run delivers to its own `e2e/<run>` branch and deletes it afterwards.\n" ) ) );
		$head = GitHub::request( $token, 'GET', $prefix . '/git/ref/heads/' . $info['default_branch'] );
		echo "GitHub: {$repository} was empty; created its first commit on {$info['default_branch']}\n";
	}
	// The run's base is the repository's first commit, not whatever the default
	// branch holds now: a run never inherits another run's content.
	$root = $head['object']['sha'];
	for ( $i = 0; $i < 100; ++$i ) {
		$commit = GitHub::request( $token, 'GET', $prefix . '/git/commits/' . $root );
		if ( ! $commit['parents'] ) { break; }
		$root = $commit['parents'][0]['sha'];
	}
	if ( $commit['parents'] ) { throw new RuntimeException( 'FAIL: the test repository has more than 100 commits on its default branch; use a dedicated repository.' ); }
	$run = 'e2e/' . preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) ( getenv( 'BRIDGE_E2E_RUN' ) ?: gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) ) );
	GitHub::request( $token, 'POST', $prefix . '/git/refs', array( 'ref' => 'refs/heads/' . $run, 'sha' => $root ) );
	file_put_contents( $github_state, wp_json_encode( array( 'repository' => $repository, 'base_branch' => $run, 'root' => $root, 'branches' => array( $run ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	echo "GitHub: this run delivers against {$run} (from first commit " . substr( $root, 0, 7 ) . ")\n";
	exit;
}
if ( 'github-cleanup' === $stage ) {
	if ( ! is_file( $github_state ) ) { exit; }
	$state = json_decode( file_get_contents( $github_state ), true );
	$token = (string) getenv( 'BRIDGE_TEST_TOKEN' );
	$left = array();
	foreach ( $state['branches'] as $branch ) {
		try {
			GitHub::request( $token, 'DELETE', '/repos/' . $state['repository'] . '/git/refs/heads/' . $branch );
		} catch ( RuntimeException $error ) {
			if ( ! in_array( $error->getCode(), array( 404, 422 ), true ) ) { $left[] = $branch; }
		}
	}
	unlink( $github_state );
	if ( $left ) { throw new RuntimeException( 'FAIL: could not delete ' . implode( ', ', $left ) . ' in ' . $state['repository'] ); }
	echo 'GitHub: deleted ' . count( $state['branches'] ) . " branch(es) this run made; the default branch is untouched\n";
	exit;
}

if ( 'export' === $stage ) {
	$label = preg_replace( '/[^a-z0-9]/', '', $argv[2] ?? 't0' );
	$previous = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
	if ( $previous ) { Files::remove( Files::dir( $previous ) ); delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' ); }
	$summary = Jobs::create( array( 'types' => array( 'post', 'page', 'attachment' ), 'private' => false, 'scan_sources' => false, 'comments' => true ) );
	for ( $i = 0; $i < 2000 && 'review' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
	$summary = Jobs::review( $summary['id'], array(), true );
	for ( $i = 0; $i < 2000 && 'ready' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $summary['id'], $summary['step'] ); }
	if ( 'ready' !== $summary['phase'] ) { throw new RuntimeException( 'FAIL: export did not reach ready' ); }
	$id = $summary['id'];
	$receipt = array( 'label' => $label, 'job' => $id, 'files' => count( Jobs::read( $id )['files'] ), 'github' => null );
	$repository = getenv( 'BRIDGE_TEST_REPO' );
	$token = getenv( 'BRIDGE_TEST_TOKEN' );
	if ( $repository && $token ) {
		$state = json_decode( (string) file_get_contents( $github_state ), true );
		if ( ! $state || $state['repository'] !== $repository ) { throw new RuntimeException( 'FAIL: run `e2e.php github-prepare` before a GitHub export.' ); }
		$step = GitHub::start( $id, $token, $repository, 'refuse', $state['base_branch'] );
		// Recorded before delivery finishes, so cleanup removes it even if a step fails.
		$state['branches'][] = $step['github']['branch'];
		file_put_contents( $github_state, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		for ( $i = 0; $i < 5000 && 'done' !== $step['github']['phase']; ++$i ) { $step = GitHub::step( $id, $token, $step['github']['cursor'] ); }
		if ( 'done' !== $step['github']['phase'] ) { throw new RuntimeException( 'FAIL: GitHub delivery did not finish' ); }
		// Accept the delivery into this run's base branch, as a person would, so the next export's delta is measured against it.
		GitHub::request( $token, 'POST', '/repos/' . $repository . '/merges', array( 'base' => $state['base_branch'], 'head' => $step['github']['branch'], 'commit_message' => 'Accept Bridge export ' . $label ) );
		$receipt['github'] = array( 'repository' => $repository, 'branch' => $step['github']['branch'], 'commit' => $step['github']['commit'], 'base_branch' => $state['base_branch'] );
	}
	// What the repository holds after this export: downloaded from GitHub when
	// the leg ran, so Astro builds what GitHub has, and checked file by file
	// against the export; otherwise the export itself.
	$out = $dir . '/' . $label;
	Files::remove( $out );
	wp_mkdir_p( $out );
	$source = Files::dir( $id ) . '/output';
	$files = Jobs::read( $id )['files'];
	if ( $receipt['github'] ) {
		$prefix = '/repos/' . $repository;
		$head = GitHub::request( $token, 'GET', $prefix . '/git/ref/heads/' . $receipt['github']['base_branch'] );
		$commit = GitHub::request( $token, 'GET', $prefix . '/git/commits/' . $head['object']['sha'] );
		$tree = GitHub::request( $token, 'GET', $prefix . '/git/trees/' . $commit['tree']['sha'] . '?recursive=1' );
		$fetched = 0;
		foreach ( $tree['tree'] as $entry ) {
			if ( 'blob' !== $entry['type'] || ! preg_match( '#^(\.contentrain|bridge|media)/#', $entry['path'] ) ) { continue; }
			$blob = GitHub::request( $token, 'GET', $prefix . '/git/blobs/' . $entry['sha'] );
			$content = base64_decode( str_replace( "\n", '', $blob['content'] ), true );
			wp_mkdir_p( dirname( $out . '/' . $entry['path'] ) );
			file_put_contents( $out . '/' . $entry['path'], $content );
			if ( isset( $files[ $entry['path'] ] ) && hash( 'sha256', $content ) !== $files[ $entry['path'] ]['sha256'] ) {
				throw new RuntimeException( 'FAIL: GitHub holds a different ' . $entry['path'] . ' than the export' );
			}
			++$fetched;
		}
		$managed = array_filter( array_keys( $files ), static function ( $p ) { return (bool) preg_match( '#^(\.contentrain|bridge|media)/#', $p ); } );
		if ( $fetched !== count( $managed ) ) {
			throw new RuntimeException( "FAIL: the run's base branch on GitHub holds $fetched managed files, the export " . count( $managed ) );
		}
		$receipt['github']['fetched'] = $fetched;
		$receipt['github']['removed'] = array_keys( (array) ( $step['github']['removed'] ?? array() ) );
	} else {
		foreach ( array_keys( $files ) as $path ) {
			wp_mkdir_p( dirname( $out . '/' . $path ) );
			copy( Files::path( $source, $path ), $out . '/' . $path );
		}
	}
	file_put_contents( $dir . '/' . $label . '.receipt.json', wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	echo "Export $label: {$receipt['files']} files" . ( $receipt['github'] ? ", delivered to {$repository} as {$receipt['github']['commit']}" : ', GitHub skipped (no BRIDGE_TEST_REPO/BRIDGE_TEST_TOKEN)' ) . "\n";
	exit;
}

if ( 'mutate' === $stage ) {
	$f = json_decode( file_get_contents( $dir . '/fixture.json' ), true );
	wp_update_post( array( 'ID' => $f['edit'], 'post_content' => '<p>E2E edited post changed body ' . $f['run'] . '.</p>' ) );
	wp_update_post( array( 'ID' => $f['rename'], 'post_name' => 'e2e-renamed-new-' . $f['run'] ) );
	wp_trash_post( $f['trash'] );
	file_put_contents( $dir . '/mutation.json', wp_json_encode( array( 'updated' => $f['edit'], 'moved' => $f['rename'], 'trashed' => $f['trash'], 'new_slug' => 'e2e-renamed-new-' . $f['run'], 'new_text' => 'E2E edited post changed body ' . $f['run'] ) ) );
	echo "Mutated: body of {$f['edit']}, slug of {$f['rename']}, trashed {$f['trash']}\n";
	exit;
}

if ( 'delta' === $stage ) {
	// The delivered delta when GitHub held T0; otherwise the same comparison, of the same two files.
	$delivered = $dir . '/t1/bridge/delta.json';
	$plan = is_file( $delivered ) ? json_decode( file_get_contents( $delivered ), true ) : \Contentrain\Bridge\Delta::compare( json_decode( file_get_contents( $dir . '/t0/bridge/inventory.json' ), true ), json_decode( file_get_contents( $dir . '/t1/bridge/inventory.json' ), true ) );
	file_put_contents( $dir . '/t1.delta.json', wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	echo 'Delta ' . ( is_file( $delivered ) ? 'as delivered' : 'computed locally from the two delivered inventories' ) . ': ' . count( $plan['entries'] ) . " entries\n";
	exit;
}

throw new RuntimeException( 'Usage: e2e.php fixture | export <label> | mutate | delta' );
