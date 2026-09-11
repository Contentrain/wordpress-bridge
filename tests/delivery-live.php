<?php
/**
 * Real GitHub delivery, against a real repository.
 *
 * The acceptance suite delivers through a mocked HTTP API, which proves the
 * plugin's own state machine and nothing about GitHub. Delivery is this
 * plugin's headline feature, so the things that only break against the real
 * API — an empty repository, a branch that already exists, a tree GitHub
 * returns in its own order, a conflict with an edit a user made after the last
 * delivery — have to be exercised somewhere.
 *
 * This is that somewhere. It needs a repository it may write to and a token,
 * so it is not part of the default suite:
 *
 *   BRIDGE_TEST_REPO=owner/repo BRIDGE_TEST_TOKEN=... npm run test:delivery
 *
 * The token is read from the environment, used for the requests, and never
 * written anywhere. The repository is left with the branches this run created
 * so a failure can be inspected; the script prints them.
 *
 * @package ContentrainBridge
 */

require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';

use Contentrain\Bridge\Files;
use Contentrain\Bridge\GitHub;
use Contentrain\Bridge\Jobs;

$repository = getenv( 'BRIDGE_TEST_REPO' );
$token      = getenv( 'BRIDGE_TEST_TOKEN' );
if ( ! $repository || ! $token ) {
	throw new RuntimeException( 'Set BRIDGE_TEST_REPO=owner/repo and BRIDGE_TEST_TOKEN.' );
}

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	++$checks;
	echo "PASS: $message\n";
}
function rejects( $callback, $message ) {
	try {
		$callback();
	} catch ( Throwable $error ) {
		check( true, $message . ' [' . $error->getMessage() . ']' );
		return;
	}
	check( false, $message );
}

$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );

/** Run an export from nothing to `ready` and return its id. */
function ready_export( $admin, $suffix ) {
	$existing = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
	if ( $existing ) {
		Files::remove( Files::dir( $existing ) );
		delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
	}
	wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_title'   => 'Live delivery ' . $suffix,
			'post_content' => '<p>Body ' . $suffix . '</p>',
			'post_status'  => 'publish',
			'post_author'  => $admin->ID,
		)
	);
	$summary = Jobs::create( array( 'types' => array( 'post' ), 'comments' => false, 'private' => false, 'scan_sources' => false ) );
	$id      = $summary['id'];
	for ( $i = 0; $i < 400 && 'review' !== $summary['phase'] && 'ready' !== $summary['phase']; ++$i ) {
		$summary = Jobs::step( $id, $summary['step'] );
	}
	// The review gate is deliberate: nothing is finalized until a person accepts
	// the candidate text. With `scan_sources` off there is nothing to decide, but
	// the gate still has to be passed rather than stepped past.
	if ( 'review' === $summary['phase'] ) {
		$summary = Jobs::review( $id, array(), true );
	}
	for ( $i = 0; $i < 400 && 'ready' !== $summary['phase']; ++$i ) {
		$summary = Jobs::step( $id, $summary['step'] );
	}
	if ( 'ready' !== $summary['phase'] ) {
		throw new RuntimeException( 'Export did not reach ready: ' . $summary['phase'] );
	}
	return $id;
}

/** Drive delivery to completion, optionally pausing once to prove it resumes. */
function deliver( $id, $token, $repository, $pause_after = null ) {
	$summary = GitHub::start( $id, $token, $repository );
	$paused  = false;
	for ( $i = 0; $i < 2000 && 'done' !== $summary['github']['phase']; ++$i ) {
		if ( null !== $pause_after && ! $paused && $summary['github']['cursor'] >= $pause_after ) {
			$paused = true;
			// Nothing is held in memory between steps: the next call reads the
			// job back off disk and continues from the stored cursor.
			$summary = GitHub::start( $id, $token, $repository );
		}
		$summary = GitHub::step( $id, $token, $summary['github']['cursor'] );
	}
	return $summary;
}

echo "Repository: $repository\n\n";

// ─── First delivery ───

$first     = ready_export( $admin, 'one' );
$summary   = deliver( $first, $token, $repository, 2 );
$delivered = $summary['github'];
check( 'done' === $delivered['phase'], 'first delivery completes against the real GitHub API' );
check( ! empty( $delivered['branch'] ), 'delivery names the branch it created: ' . $delivered['branch'] );

$branch = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/ref/heads/' . rawurlencode( $delivered['branch'] ) );
check( $branch['object']['sha'] === $delivered['commit'], 'the branch on GitHub points at the commit the plugin reported' );

$job   = Jobs::summary( Jobs::read( $first ) );
$files = array_keys( Jobs::read( $first )['files'] );
$tree  = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/trees/' . $branch['object']['sha'] . '?recursive=1' );
$remote = array();
foreach ( $tree['tree'] as $entry ) {
	if ( 'blob' === $entry['type'] ) {
		$remote[ $entry['path'] ] = $entry['sha'];
	}
}
$missing = array_values( array_diff( $files, array_keys( $remote ) ) );
check( array() === $missing, count( $files ) . ' exported files are all present on the branch' );

$manifest_blob = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/blobs/' . $remote['bridge/manifest.json'] );
$manifest      = json_decode( base64_decode( str_replace( "\n", '', $manifest_blob['content'] ), true ), true );
check( 'contentrain-bridge@1' === ( $manifest['format'] ?? '' ), 'the delivered manifest identifies itself' );

// ─── The token is not kept ───

$state = Files::read( Files::dir( $first ), 'state.json' );
check( false === strpos( $state, $token ), 'the token is not written into the job state' );
check( false === strpos( wp_json_encode( get_user_meta( $admin->ID ) ), $token ), 'the token is not written into user meta' );

// ─── Repeat delivery ───

$repeat = deliver( $first, $token, $repository );
check( 'done' === $repeat['github']['phase'], 'repeating a finished delivery returns the existing receipt' );
check( $repeat['github']['commit'] === $delivered['commit'], 'repeating does not produce a second commit' );

$refs  = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/matching-refs/heads/contentrain/bridge-' . $first );
check( 1 === count( $refs ), 'repeating does not produce a second branch' );

// ─── A user edit, then a second export ───

// Merge the delivery into the default branch, which is what a user does after
// reviewing it — from here on the repository is the source the next export is
// checked against.
$repo_info = GitHub::request( $token, 'GET', '/repos/' . $repository );
GitHub::request(
	$token,
	'POST',
	'/repos/' . $repository . '/merges',
	array(
		'base' => $repo_info['default_branch'],
		'head' => $delivered['branch'],
		'commit_message' => 'Accept the Bridge export',
	)
);
check( true, 'the delivery merges into the default branch' );

// Now edit a managed file the way a user would, in the repository.
$edited = 'bridge/site.json';
$head   = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/ref/heads/' . rawurlencode( $repo_info['default_branch'] ) );
$head_commit = GitHub::request( $token, 'GET', '/repos/' . $repository . '/git/commits/' . $head['object']['sha'] );
$new_blob = GitHub::request( $token, 'POST', '/repos/' . $repository . '/git/blobs', array( 'content' => base64_encode( "{\n  \"edited_by\": \"a person\"\n}\n" ), 'encoding' => 'base64' ) );
$new_tree = GitHub::request( $token, 'POST', '/repos/' . $repository . '/git/trees', array( 'base_tree' => $head_commit['tree']['sha'], 'tree' => array( array( 'path' => $edited, 'mode' => '100644', 'type' => 'blob', 'sha' => $new_blob['sha'] ) ) ) );
$new_commit = GitHub::request( $token, 'POST', '/repos/' . $repository . '/git/commits', array( 'message' => 'A person edits the exported content', 'tree' => $new_tree['sha'], 'parents' => array( $head['object']['sha'] ) ) );
GitHub::request( $token, 'PATCH', '/repos/' . $repository . '/git/refs/heads/' . rawurlencode( $repo_info['default_branch'] ), array( 'sha' => $new_commit['sha'] ) );
check( true, 'a person edits ' . $edited . ' in the repository' );

$second = ready_export( $admin, 'two' );
rejects(
	static function () use ( $second, $token, $repository ) {
		deliver( $second, $token, $repository );
	},
	'the next delivery refuses to overwrite the edit instead of silently winning'
);

echo "\n$checks checks passed against $repository\n";
echo "Branches left for inspection: contentrain/bridge-$first\n";
