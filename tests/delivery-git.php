<?php
/**
 * Repeat delivery against a repository with history: deletions proven by the
 * B-06 delta are delivered as removals, unexplained ones are refused, and an
 * edit made in the repository is reported with who and when, then resolved by
 * the person's choice.
 *
 * The GitHub Git Data API is emulated in-process with Git's own object model
 * (blob SHA-1 = git hash-object), stateful across calls, including commit
 * history for `GET /commits?path=`. Every state of the default branch is
 * written to /tmp/bridge-git/<n>-<label>/ so tests/delivery-git.mjs can replay
 * it into a real Git repository and check the diff with Git itself. The real
 * API is exercised by tests/delivery-live.sh and the e2e GitHub leg.
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

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}

/** A repository, as the parts of the GitHub API Bridge calls see it. */
final class Repo {
	public $blobs = array();
	public $trees = array();
	public $commits = array();
	public $refs = array();
	private $clock = 1790000000;

	public function blob( $content ) {
		$sha = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
		$this->blobs[ $sha ] = $content;
		return $sha;
	}
	public function tree( $map ) {
		ksort( $map );
		$sha = sha1( 'tree ' . wp_json_encode( $map ) );
		$this->trees[ $sha ] = $map;
		return $sha;
	}
	public function commit( $tree, $parents, $message, $author ) {
		$date = gmdate( 'Y-m-d\TH:i:s\Z', ++$this->clock );
		$sha = sha1( 'commit ' . wp_json_encode( array( $tree, $parents, $message, $author, $date ) ) );
		$this->commits[ $sha ] = array( 'tree' => $tree, 'parents' => $parents, 'message' => $message, 'author' => array( 'name' => $author, 'date' => $date ) );
		return $sha;
	}
	/** A person commits straight to main: `$changes` path => content, or null to delete. */
	public function edit( $changes, $author, $message ) {
		$head = $this->refs['main'];
		$map = $this->trees[ $this->commits[ $head ]['tree'] ];
		foreach ( $changes as $path => $content ) {
			if ( null === $content ) { unset( $map[ $path ] ); } else { $map[ $path ] = $this->blob( $content ); }
		}
		$this->refs['main'] = $this->commit( $this->tree( $map ), array( $head ), $message, $author );
		return $this->refs['main'];
	}
	public function files( $ref ) {
		$map = $this->trees[ $this->commits[ $this->refs[ $ref ] ]['tree'] ];
		return array_map( function ( $sha ) { return $this->blobs[ $sha ]; }, $map );
	}
	public function snapshot( $dir ) {
		Files::remove( $dir );
		foreach ( $this->files( 'main' ) as $path => $content ) {
			wp_mkdir_p( dirname( $dir . '/' . $path ) );
			file_put_contents( $dir . '/' . $path, $content );
		}
	}
	public function handle( $method, $path, $body ) {
		$ok = static function ( $data ) { return array( 200, $data ); };
		if ( '' === $path ) { return $ok( array( 'private' => true, 'default_branch' => 'main' ) ); }
		if ( preg_match( '#^/git/ref/heads/(.+)$#', $path, $m ) ) {
			$ref = rawurldecode( $m[1] );
			return isset( $this->refs[ $ref ] ) ? $ok( array( 'object' => array( 'sha' => $this->refs[ $ref ] ) ) ) : array( 404, array( 'message' => 'Not Found' ) );
		}
		if ( preg_match( '#^/git/commits/([0-9a-f]{40})$#', $path, $m ) ) { return $ok( array( 'sha' => $m[1], 'tree' => array( 'sha' => $this->commits[ $m[1] ]['tree'] ) ) ); }
		if ( preg_match( '#^/git/trees/([0-9a-f]{40})\?recursive=1$#', $path, $m ) ) {
			$tree = array();
			foreach ( $this->trees[ $m[1] ] as $p => $sha ) { $tree[] = array( 'path' => $p, 'type' => 'blob', 'sha' => $sha ); }
			return $ok( array( 'truncated' => false, 'tree' => $tree ) );
		}
		if ( preg_match( '#^/git/blobs/([0-9a-f]{40})$#', $path, $m ) ) { return $ok( array( 'content' => chunk_split( base64_encode( $this->blobs[ $m[1] ] ), 60, "\n" ) ) ); }
		if ( 'POST' === $method && '/git/blobs' === $path ) { return $ok( array( 'sha' => $this->blob( base64_decode( $body['content'] ) ) ) ); }
		if ( 'POST' === $method && '/git/trees' === $path ) {
			$map = $this->trees[ $body['base_tree'] ];
			foreach ( $body['tree'] as $node ) {
				if ( null === $node['sha'] ) { unset( $map[ $node['path'] ] ); } else { $map[ $node['path'] ] = $node['sha']; }
			}
			return $ok( array( 'sha' => $this->tree( $map ) ) );
		}
		if ( 'POST' === $method && '/git/commits' === $path ) { return $ok( array( 'sha' => $this->commit( $body['tree'], $body['parents'], $body['message'], $body['author']['name'] ) ) ); }
		if ( 'POST' === $method && '/git/refs' === $path ) {
			$ref = substr( $body['ref'], 11 );
			if ( isset( $this->refs[ $ref ] ) ) { return array( 422, array( 'message' => 'Reference already exists' ) ); }
			$this->refs[ $ref ] = $body['sha'];
			return $ok( array( 'ref' => $body['ref'] ) );
		}
		if ( 'POST' === $method && '/merges' === $path ) {
			// The branch is based on main's head in these runs: a fast-forward.
			if ( $this->commits[ $this->refs[ $body['head'] ] ]['parents'] !== array( $this->refs[ $body['base'] ] ) ) { return array( 409, array( 'message' => 'Merge conflict' ) ); }
			$this->refs[ $body['base'] ] = $this->refs[ $body['head'] ];
			return $ok( array( 'sha' => $this->refs[ $body['base'] ] ) );
		}
		if ( 'GET' === $method && preg_match( '#^/commits\?sha=([^&]+)&path=([^&]+)&per_page=1$#', $path, $m ) ) {
			$target = rawurldecode( $m[2] );
			$sha = rawurldecode( $m[1] );
			while ( $sha ) {
				$commit = $this->commits[ $sha ];
				$parent = $commit['parents'][0] ?? null;
				$now = $this->trees[ $commit['tree'] ][ $target ] ?? null;
				$before = $parent ? ( $this->trees[ $this->commits[ $parent ]['tree'] ][ $target ] ?? null ) : null;
				if ( $now !== $before ) { return $ok( array( array( 'sha' => $sha, 'commit' => array( 'author' => $commit['author'], 'message' => $commit['message'] ) ) ) ); }
				$sha = $parent;
			}
			return $ok( array() );
		}
		throw new RuntimeException( 'Unemulated GitHub endpoint: ' . $method . ' ' . $path );
	}
}

$repo = new Repo();
$repo->refs['main'] = $repo->commit( $repo->tree( array( 'README.md' => $repo->blob( "# Content\n" ) ) ), array(), 'Initial commit', 'Repo Owner' );
$filter = static function ( $pre, $args, $url ) use ( $repo ) {
	$base = 'https://api.github.com/repos/test-owner/content';
	if ( 0 !== strpos( $url, $base ) ) { throw new RuntimeException( 'Unexpected external request: ' . $url ); }
	list( $code, $data ) = $repo->handle( $args['method'], substr( $url, strlen( $base ) ), isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $filter, 10, 3 );

$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
$acceptance_job = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
$token = 'test-only-token-123456';
$out = '/tmp/bridge-git';
Files::remove( $out );
wp_mkdir_p( $out );
$snapshots = 0;
$receipts = array();

function export_ready() {
	$user = get_current_user_id();
	$old = get_user_meta( $user, 'contentrain_bridge_job_1', true );
	if ( $old ) { Files::remove( Files::dir( $old ) ); delete_user_meta( $user, 'contentrain_bridge_job_1' ); }
	$s = Jobs::create( array( 'types' => array( 'post', 'page', 'attachment' ), 'private' => false, 'scan_sources' => false ) );
	for ( $i = 0; $i < 2000 && 'review' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
	$s = Jobs::review( $s['id'], array(), true );
	for ( $i = 0; $i < 2000 && 'ready' !== $s['phase']; ++$i ) { $s = Jobs::step( $s['id'], $s['step'] ); }
	return $s['id'];
}
function deliver( $id, $token, $choice = 'refuse' ) {
	$s = GitHub::start( $id, $token, 'test-owner/content', $choice );
	for ( $i = 0; $i < 5000 && 'done' !== $s['github']['phase']; ++$i ) { $s = GitHub::step( $id, $token, $s['github']['cursor'] ); }
	return $s['github'];
}
$snap = static function ( $label, $extra = array() ) use ( $repo, $out, &$snapshots, &$receipts ) {
	$dir = sprintf( '%s/%02d-%s', $out, ++$snapshots, $label );
	$repo->snapshot( $dir );
	$receipts[ basename( $dir ) ] = $extra;
};
$make = static function ( $title, $args = array() ) use ( $admin ) {
	return wp_insert_post( $args + array( 'post_title' => $title, 'post_content' => '<p>' . $title . '</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
};

// ---- T0: first delivery into a repository with a README, then accepted. ----
$run = substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
$p = array( 'stable' => $make( 'Git stable ' . $run ), 'trash' => $make( 'Git trashed ' . $run ), 'purge' => $make( 'Git purged ' . $run ), 'late' => $make( 'Git late trash ' . $run ) );
$uploads = wp_get_upload_dir();
wp_mkdir_p( $uploads['path'] );
file_put_contents( $uploads['path'] . '/git-media-' . $run . '.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$media = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Git media', 'post_status' => 'inherit' ), $uploads['path'] . '/git-media-' . $run . '.png' );
wp_update_attachment_metadata( $media, wp_generate_attachment_metadata( $media, $uploads['path'] . '/git-media-' . $run . '.png' ) );
$t0 = export_ready();
$g = deliver( $t0, $token );
check( 'done' === $g['phase'] && array() === $g['removed'], 'first delivery: a branch, nothing removed' );
GitHub::request( $token, 'POST', '/repos/test-owner/content/merges', array( 'base' => 'main', 'head' => $g['branch'] ) );
$t0_files = Jobs::read( $t0 )['files'];
check( array() === array_diff_key( $t0_files, $repo->files( 'main' ) ) && isset( $repo->files( 'main' )['README.md'] ), 'main holds every exported file and keeps its README' );
$map = json_decode( $repo->files( 'main' )['bridge/entry-source-map.json'], true );
$doc = static function ( $id ) use ( $map ) { return '.contentrain/content/blog/wp-post/' . $map[ (string) $id ]['entry_id'] . '.md'; };
$meta = static function ( $id ) use ( $map ) { return '.contentrain/meta/wp-post/' . $map[ (string) $id ]['entry_id'] . '/'; };
$media_file = 'media/' . ltrim( get_post_meta( $media, '_wp_attached_file', true ), '/' );
check( isset( $repo->files( 'main' )[ $doc( $p['trash'] ) ], $repo->files( 'main' )[ $media_file ] ), 'the records about to be deleted are in the repository' );
$snap( 't0' );

// ---- T1: trash, purge, delete an attachment. The delta proves each; the delivery removes their files. ----
wp_trash_post( $p['trash'] );
wp_delete_post( $p['purge'], true );
wp_delete_attachment( $media, true );
$t1 = export_ready();
$g = deliver( $t1, $token );
check( 'done' === $g['phase'], 'a delivery with deletions completes instead of stopping at "reconcile removals"' );
$removed = array_keys( $g['removed'] );
$expect = array_values( array_filter( array_keys( $t0_files ), static function ( $path ) use ( $doc, $meta, $p, $media_file ) {
	return in_array( $path, array( $doc( $p['trash'] ), $doc( $p['purge'] ) ), true ) || 0 === strpos( $path, $meta( $p['trash'] ) ) || 0 === strpos( $path, $meta( $p['purge'] ) ) || $path === $media_file;
} ) );
sort( $removed );
sort( $expect );
check( $expect === $removed, 'removed exactly the trashed and purged posts\' documents and metadata and the attachment\'s file (' . count( $removed ) . ')' );
check( false !== strpos( $g['removed'][ $doc( $p['trash'] ) ], 'trashed' ) && false !== strpos( $g['removed'][ $doc( $p['purge'] ) ], 'purged' ), 'each removal says which record and why' );
$message = $repo->commits[ $g['commit'] ]['message'];
check( false !== strpos( $message, 'Removed, deleted in WordPress:' ) && false !== strpos( $message, $doc( $p['trash'] ) ), 'the commit message lists the removals' );
$branch_files = $repo->files( $g['branch'] );
check( ! array_intersect( $removed, array_keys( $branch_files ) ) && isset( $branch_files[ $doc( $p['stable'] ) ], $branch_files['README.md'] ), 'the branch no longer has them, and keeps everything else' );
GitHub::request( $token, 'POST', '/repos/test-owner/content/merges', array( 'base' => 'main', 'head' => $g['branch'] ) );
$snap( 't1-deletions', array( 'removed' => $removed ) );

// ---- No proof, no removal: the inventory is gone from the repository. ----
$before_refusal = $repo->refs['main'];
$repo->edit( array( 'bridge/inventory.json' => null ), 'Repo Owner', 'Tidy up' );
wp_trash_post( $p['late'] );
$t2 = export_ready();
$refs = count( $repo->refs );
try {
	deliver( $t2, $token );
	check( false, 'a removal without a verified delta is refused' );
} catch ( RuntimeException $error ) {
	check( false !== strpos( $error->getMessage(), 'no verified deletion explains them' ) && false !== strpos( $error->getMessage(), $doc( $p['late'] ) ), 'without a verified delta, a missing file is refused and named [' . substr( $error->getMessage(), 0, 90 ) . '…]' );
}
check( count( $repo->refs ) === $refs, 'nothing was written: no branch' );
$repo->refs['main'] = $before_refusal;

// ---- A person edits a managed file; the next delivery says who and when, then does what they choose. ----
$edited = $doc( $p['stable'] );
$ada = $repo->edit( array( $edited => "---\ntitle: \"Edited by hand\"\n---\n\nA person's words.\n" ), 'Ada Editor', 'Rewrite the stable post' );
$t3 = export_ready();
try {
	deliver( $t3, $token );
	check( false, 'an edited file is refused by default' );
} catch ( RuntimeException $error ) {
	$text = $error->getMessage();
	check( false !== strpos( $text, $edited ) && false !== strpos( $text, 'Ada Editor' ) && false !== strpos( $text, substr( $ada, 0, 7 ) ) && false !== strpos( $text, $repo->commits[ $ada ]['author']['date'] ), 'the conflict names the file, who changed it, when, and the commit' );
	check( false !== strpos( $text, 'keep the repository version' ) && false !== strpos( $text, 'use the WordPress version' ), 'the conflict offers both choices' );
}
$g = deliver( $t3, $token, 'keep-repository' );
check( 'done' === $g['phase'] && 'keep-repository' === $g['conflicts'][0]['resolution'] && 'Ada Editor' === $g['conflicts'][0]['author'], 'choosing to keep the repository version completes the same export' );
check( false !== strpos( $repo->files( $g['branch'] )[ $edited ], "A person's words." ), 'the branch keeps the person\'s edit' );
check( ! isset( $repo->files( $g['branch'] )[ $doc( $p['late'] ) ] ), 'the verified deletion from before is delivered alongside' );
check( false !== strpos( $repo->commits[ $g['commit'] ]['message'], $edited . ': repository version kept (edited by Ada Editor' ), 'the commit message lists the kept edit' );
$kept_branch = $g['branch'];
$kept_removed = array_keys( $g['removed'] );
$t4 = export_ready();
$g = deliver( $t4, $token, 'use-wordpress' );
check( 'done' === $g['phase'] && false === strpos( $repo->files( $g['branch'] )[ $edited ], "A person's words." ) && false !== strpos( $repo->commits[ $g['commit'] ]['message'], $edited . ': WordPress version delivered' ), 'choosing the WordPress version puts it on the branch, for review, and says so' );
check( $repo->refs['main'] === $ada, 'neither choice wrote the default branch' );
GitHub::request( $token, 'POST', '/repos/test-owner/content/merges', array( 'base' => 'main', 'head' => $kept_branch ) );
check( in_array( $doc( $p['late'] ), $kept_removed, true ), 'the late-trashed post is among the removals' );
$snap( 't3-kept-edit', array( 'kept' => $edited, 'removed' => $kept_removed ) );
check( false === strpos( Files::read( Files::dir( $t4 ), 'state.json' ), $token ), 'the token is never persisted' );

remove_filter( 'pre_http_request', $filter, 10 );
file_put_contents( $out . '/receipts.json', wp_json_encode( $receipts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$user = get_current_user_id();
$old = get_user_meta( $user, 'contentrain_bridge_job_1', true );
if ( $old ) { Files::remove( Files::dir( $old ) ); delete_user_meta( $user, 'contentrain_bridge_job_1' ); }
if ( $acceptance_job ) { update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $acceptance_job ); }
echo "\n$checks checks passed. Repository states: $out\n";
