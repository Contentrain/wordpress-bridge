<?php
/** Executed inside the dedicated WordPress test container, never a production site. */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'Bridge Acceptance' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Refusing to run fixtures on a non-test site.' );
}
require_once WP_PLUGIN_DIR . '/contentrain-bridge/contentrain-bridge.php';
use Contentrain\Bridge\Admin;
use Contentrain\Bridge\Files;
use Contentrain\Bridge\GitHub;
use Contentrain\Bridge\Jobs;
use Contentrain\Bridge\Models;
use Contentrain\Bridge\Policy;
use Contentrain\Bridge\Scanner;
use Contentrain\Bridge\Source;
use Contentrain\Bridge\Validator;

$checks = 0;
function check( $value, $message ) {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks;
	echo "PASS: $message\n";
}
function rejects( $callback, $message ) {
	try { $callback(); } catch ( Throwable $error ) { check( true, $message . ' [' . $error->getMessage() . ']' ); return; }
	check( false, $message );
}
$admin = get_user_by( 'login', 'bridge-admin' );
wp_set_current_user( $admin->ID );
Files::cleanup();
$existing = get_user_meta( $admin->ID, 'contentrain_bridge_job_1', true );
if ( $existing ) { Files::remove( Files::dir( $existing ) ); delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' ); }
register_post_type( 'bridge_book', array( 'public' => true, 'show_in_rest' => false, 'label' => 'Books' ) );
$category = term_exists( 'bridge-fixture-category', 'category' );
if ( ! $category ) { $category = wp_insert_term( 'Bridge fixture category', 'category', array( 'slug' => 'bridge-fixture-category' ) ); }
$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Bridge article', 'post_content' => '<h2>Heading</h2><p>Hello <strong>world</strong>.</p><table><tr><td>Preserved</td></tr></table>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
wp_set_post_categories( $post, array( (int) $category['term_id'] ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'About Bridge', 'post_content' => '<p>Editable page body</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
$draft = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Private draft', 'post_content' => 'Should not be public', 'post_status' => 'draft', 'post_author' => $admin->ID ) );
$book = wp_insert_post( array( 'post_type' => 'bridge_book', 'post_title' => 'Hidden REST book', 'post_content' => '<p>Book body</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
update_post_meta( $post, 'api_token', 'never-export-me' );
update_post_meta( $post, 'selected_copy', 'Editable custom content' );
update_post_meta( $post, 'structured', array( 'title' => 'Hero title', 'api_key' => 'never-export-nested', 'items' => array( array( 'label' => 'First', 'enabled' => true ) ) ) );
// A real upload, so media transfer and relinking are exercised end to end.
require_once ABSPATH . 'wp-admin/includes/image.php';
$uploads = wp_get_upload_dir();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$image_path = $uploads['path'] . '/bridge-pixel.png';
wp_mkdir_p( $uploads['path'] );
file_put_contents( $image_path, $png );
$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Bridge pixel', 'post_status' => 'inherit' ), $image_path );
wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $image_path ) );
$image_url = wp_get_attachment_url( $attachment );
$missing_path = $uploads['path'] . '/bridge-ghost.png';
file_put_contents( $missing_path, $png );
$ghost = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Bridge ghost', 'post_status' => 'inherit' ), $missing_path );
unlink( $missing_path );
$illustrated = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Illustrated', 'post_content' => '<p>Before</p><img src="' . esc_url( $image_url ) . '" alt="Pixel" /><p>After</p>', 'post_status' => 'publish', 'post_author' => $admin->ID ) );

// An ACF group whose shape is the point: a repeater of records and a group of
// named fields must become models, not anonymous structured rows.
// Not `$acf`: this script runs in global scope and ACF keeps its instance in
// $GLOBALS['acf'], so that name would overwrite the plugin out from under itself.
$has_acf = function_exists( 'acf_add_local_field_group' );
if ( $has_acf ) {
	acf_add_local_field_group( array(
		'key' => 'group_bridge',
		'title' => 'Bridge fields',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields' => array(
			array( 'key' => 'field_bridge_tagline', 'name' => 'tagline', 'label' => 'Tagline', 'type' => 'text' ),
			array( 'key' => 'field_bridge_rank', 'name' => 'rank', 'label' => 'Rank', 'type' => 'number' ),
			array( 'key' => 'field_bridge_featured', 'name' => 'featured', 'label' => 'Featured', 'type' => 'true_false' ),
			array(
				'key' => 'field_bridge_hero', 'name' => 'hero', 'label' => 'Hero', 'type' => 'group',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_hero_title', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
					array( 'key' => 'field_bridge_hero_cta', 'name' => 'cta', 'label' => 'Call to action', 'type' => 'text' ),
				),
			),
			array(
				'key' => 'field_bridge_quotes', 'name' => 'quotes', 'label' => 'Testimonials', 'type' => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_bridge_quote_person', 'name' => 'person', 'label' => 'Person', 'type' => 'text' ),
					array( 'key' => 'field_bridge_quote_text', 'name' => 'quote', 'label' => 'Quote', 'type' => 'textarea' ),
				),
			),
		),
	) );
	update_field( 'tagline', 'Content you own', $page );
	update_field( 'rank', 3, $page );
	update_field( 'featured', true, $page );
	update_field( 'hero', array( 'heading' => 'Own your words', 'cta' => 'Start now' ), $page );
	update_field( 'quotes', array(
		array( 'person' => 'Ada', 'quote' => 'It reads like my site.' ),
		array( 'person' => 'Grace', 'quote' => 'The diff is the content.' ),
	), $page );
}

$comment = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Reviewer', 'comment_author_email' => 'private@example.test', 'comment_author_IP' => '192.0.2.1', 'comment_content' => 'Public comment', 'comment_approved' => 1 ) );
update_comment_meta( $comment, 'other_email', 'private-in-meta@example.test' );
$warnings = array();
$clean = Policy::meta( get_post_meta( $post ), array( 'selected_copy', 'structured', 'api_token' ), $warnings, 'test' );
check( ! isset( $clean['api_token'] ), 'explicit selection cannot export a sensitive key' );
check( ! isset( $clean['structured']['api_key'] ), 'nested secrets are excluded' );
check( 'Editable custom content' === $clean['selected_copy'], 'selected content metadata is decoded' );
rejects( static function () { Files::path( '/tmp', '../secret' ); }, 'path traversal is rejected' );
rejects( static function () { Files::dir( '../../' ); }, 'invalid job id is rejected' );

$fixture = sys_get_temp_dir() . '/bridge-scan-fixture.php';
file_put_contents( $fixture, '<h1>Visible heading</h1><input placeholder="Your name" aria-label="Full name"><script>var secret="Do not export this script"</script><?php __("Save changes", "fixture"); _n("One item", "%d items", 3, "fixture"); ?>' );
$scan = Scanner::scan( $fixture, Source::locale( get_locale() ) );
$values = array_column( $scan['candidates'], 'value' );
check( in_array( 'Save changes', $values, true ), 'PHP gettext literal is captured' );
check( in_array( '%d items', $values, true ), 'plural placeholder is preserved' );
check( ! in_array( 'fixture', $values, true ), 'gettext domain is not treated as content' );
check( in_array( 'Full name', $values, true ), 'accessible label is captured' );
check( ! in_array( 'Do not export this script', $values, true ), 'script text is not treated as rendered content' );
unlink( $fixture );

$summary = Jobs::create( array( 'types' => array( 'post', 'page', 'bridge_book', 'attachment' ), 'comments' => true, 'private' => false, 'scan_sources' => false, 'selected_meta' => array( 'selected_copy', 'structured', 'api_token' ) ) );
$id = $summary['id'];
check( Admin::permitted(), 'administrator with export capability is allowed' );
wp_set_current_user( 0 );
check( ! Admin::permitted(), 'anonymous user is denied' );
rejects( static function () use ( $id ) { Jobs::read( $id ); }, 'another user cannot read private export' );
wp_set_current_user( $admin->ID );
$before = $summary['step'];
$summary = Jobs::step( $id, $before );
$duplicate = Jobs::step( $id, $before );
check( $duplicate['step'] === $summary['step'], 'retried step is idempotent' );
for ( $i = 0; $i < 200 && 'review' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $id, $summary['step'] ); }
check( 'review' === $summary['phase'], 'resumable export reaches review' );
$job = Jobs::read( $id );
check( isset( $job['tables']['bridge/raw-posts.json'][ $book ] ), 'REST-hidden CPT is exported' );
check( ! isset( $job['tables']['bridge/raw-posts.json'][ $draft ] ), 'draft excluded in public scope' );
check( ! isset( $job['models']['wp-post']['fields']['slug'] ), 'document system slug is not declared as a field' );
check( isset( $job['models']['wp-structured-values'] ), 'nested content becomes editable related records' );
if ( $has_acf ) {
	// The point of modelling: a repeater of testimonials is a list of records
	// with a person and a quote, not an anonymous name/value bag.
	check( isset( $job['models'][\Contentrain\Bridge\Acf::model_id( 'quotes', 'field_bridge_quotes' )] ), 'a repeater becomes its own model' );
	$quotes = $job['models'][\Contentrain\Bridge\Acf::model_id( 'quotes', 'field_bridge_quotes' )];
	check( 'collection' === $quotes['kind'] && 'person' === $quotes['title_field'], 'the repeater model is titled by its first text field' );
	check( array( 'person', 'position', 'quote' ) === array_keys( $quotes['fields'] ), 'sub-fields become fields: ' . implode( ',', array_keys( $quotes['fields'] ) ) );
	check( 'text' === $quotes['fields']['quote']['type'] && 'integer' === $quotes['fields']['position']['type'], 'ACF types map onto Contentrain types' );
	check( isset( $job['models'][\Contentrain\Bridge\Acf::model_id( 'hero', 'field_bridge_hero' )] ) && array( 'cta', 'heading' ) === array_keys( $job['models'][\Contentrain\Bridge\Acf::model_id( 'hero', 'field_bridge_hero' )]['fields'] ), 'a group becomes its own model' );

}
// Adversarial ACF shapes must either model losslessly or use the explicit fallback.
$probe = $job;
$probe['id'] = bin2hex( random_bytes( 16 ) );
Files::mkdir( Files::dir( $probe['id'] ) );
$probe['models'] = array(); $probe['files'] = array(); $probe['tables'] = array();
$acf_class = '\Contentrain\Bridge\Acf';
check( $acf_class::model_id( 'hero', 'field_a' ) !== $acf_class::model_id( 'hero', 'field_b' ), 'same-name ACF models have stable distinct identities' );
check( '2026-09-11' === $acf_class::cast( array( 'type' => 'date' ), '20260911' ), 'ACF stored dates normalize to ISO date' );
check( null === $acf_class::scalar( array( 'type' => 'select', 'multiple' => true, 'choices' => array( 'a' => 'A', 'b' => 'B' ) ) ), 'multi-select cannot be treated as a scalar select' );
$result = $acf_class::field( $probe, array( 'type' => 'link' ), array( 'url' => 'https://example.test', 'title' => 'Read more', 'target' => '_blank' ), $job['default_locale'], 'link' );
check( null === $result, 'link label and target use lossless fallback, not URL-only cast' );
$nested_schema = array( 'key' => 'field_nested', 'name' => 'nested', 'type' => 'group', 'sub_fields' => array( array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ), array( 'key' => 'field_children', 'name' => 'children', 'type' => 'repeater' ) ) );
$result = $acf_class::field( $probe, $nested_schema, array( 'heading' => 'Hello', 'children' => array( array( 'text' => 'Must survive' ) ) ), $job['default_locale'], 'nested' );
check( null === $result && ! $probe['models'], 'unsupported nested ACF fields cannot be silently dropped from a successful model' );
$no_title_schema = array( 'key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => array( array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ) ) );
$result = $acf_class::field( $probe, $no_title_schema, array( array( 'heading' => 'One' ), array( 'heading' => '' ) ), $job['default_locale'], 'rows' );
check( null === $result && ! $probe['models'], 'untitled row keeps the entire value in fallback without orphan partial models' );
$redactions = array();
$private_schema = array( 'type' => 'group', 'name' => 'public_group', 'sub_fields' => array( array( 'key' => 'field_opaque', 'name' => 'connection', 'type' => 'password' ), array( 'key' => 'field_heading', 'name' => 'heading', 'type' => 'text' ) ) );
$clean_acf = Source::acf_value( $private_schema, array( 'field_opaque' => 'hidden-credential', 'field_heading' => 'Safe headline' ), $redactions, 'acf' );
check( ! isset( $clean_acf['field_opaque'] ) && 'Safe headline' === $clean_acf['field_heading'], 'nested ACF password is removed by its type even behind an opaque field key' );
Files::remove( Files::dir( $probe['id'] ) );

// A real candidate review round, independent of the installed theme's size.
Jobs::mutate( $id, static function ( &$j ) use ( $scan ) { $j['candidates'] = array_column( $scan['candidates'], null, 'id' ); } );
rejects( static function () use ( $id ) { Jobs::review( $id, array(), true ); }, 'unreviewed text blocks finalization' );
$decisions = array();
foreach ( $scan['candidates'] as $candidate ) { $decisions[ $candidate['id'] ] = array( 'decision' => 'include', 'key' => $candidate['key'] ); }
$summary = Jobs::review( $id, $decisions, true );
for ( $i = 0; $i < 300 && 'ready' !== $summary['phase']; ++$i ) { $summary = Jobs::step( $id, $summary['step'] ); }
check( 'ready' === $summary['phase'], 'validated JSON/Markdown export is ready' );
$job = Jobs::read( $id );
$dir = Files::dir( $id ) . '/output';
$address = Source::address( get_post( $post ) );
$doc = Files::read( $dir, Models::content_path( $job, 'wp-post', $address['locale'], $address['entry_id'] ) );
check( false !== strpos( $doc, '<table><tr><td>Preserved</td></tr></table>' ), 'Markdown retains complex source HTML without loss' );
check( false !== strpos( $doc, 'selected_copy' ), 'custom content is modelled in frontmatter' );
$raw_comments = json_decode( Files::read( $dir, 'bridge/raw-comments.json' ), true );
check( ! isset( $raw_comments[ $comment ]['email'] ), 'comment email field excluded' );
check( false === strpos( Policy::json( $raw_comments ), 'private-in-meta' ), 'comment metadata cannot leak personal information' );
// A single-language site must produce the store the rest of the toolchain
// writes for it: no locale in content file names, and no i18n models.
check( false === $job['i18n'], 'single-language site is not modelled as i18n' );
check( false === $job['models']['wp-post']['i18n'], 'models declare i18n false' );
check( isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '.md' ] ), 'monolingual document is {slug}.md' );
check( isset( $job['tables']['.contentrain/content/site/wp-page/data.json'] ), 'monolingual collection is data.json' );
check( isset( $job['files'][ '.contentrain/meta/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.json' ] ), 'meta keeps the locale even when content does not' );
check( ! isset( $job['files'][ '.contentrain/content/blog/wp-post/' . $address['entry_id'] . '/' . $address['locale'] . '.md' ] ), 'no locale directory is written for one language' );

// Model files must carry Contentrain's canonical key order, or the first write
// from Studio/MCP reorders every model and shows a whole-file diff.
$model_json = Files::read( $dir, '.contentrain/models/wp-post.json' );
$model_keys = array_keys( json_decode( $model_json, true ) );
check( array( 'id', 'name', 'kind', 'domain', 'i18n', 'title_field', 'fields' ) === $model_keys, 'model file uses MODEL_FIELD_ORDER: ' . implode( ',', $model_keys ) );
check( array( 'model', 'type' ) === array_keys( json_decode( $model_json, true )['fields']['author'] ), 'nested objects stay alphabetical' );
check( "\n" === substr( $model_json, -1 ) && false === strpos( $model_json, "\n    \"id\"" ), 'canonical two-space indent and trailing newline' );
check( array( 'description', 'name', 'source_slug', 'wp_id' ) === array_keys( json_decode( Files::read( $dir, '.contentrain/models/wp-tax-category.json' ), true )['fields'] ), 'the model key order does not leak into nested objects' );
// Contentrain drops null when it serializes, so a null in the store would make
// the file differ from what the toolchain writes back. Nothing may emit one.
foreach ( array_keys( $job['files'] ) as $path ) {
	if ( 0 !== strpos( $path, '.contentrain/' ) || '.json' !== substr( $path, -5 ) ) { continue; }
	$decoded = json_decode( Files::read( $dir, $path ), true );
	$has_null = static function ( $value ) use ( &$has_null ) {
		if ( null === $value ) { return true; }
		if ( ! is_array( $value ) ) { return false; }
		foreach ( $value as $item ) { if ( $has_null( $item ) ) { return true; } }
		return false;
	};
	if ( $has_null( $decoded ) ) { throw new RuntimeException( 'Null value in canonical store file: ' . $path ); }
}
check( true, 'no canonical store file contains a null value' );

// Media has to travel with the content: an export whose images still point at
// WordPress is not a delivery the user can turn WordPress off after.
$stored = 'media/' . ltrim( get_post_meta( $attachment, '_wp_attached_file', true ), '/' );
check( isset( $job['files'][ $stored ] ), 'the original upload is copied into the export' );
check( hash( 'sha256', $png ) === $job['files'][ $stored ]['sha256'], 'copied media is byte-identical' );
$media_entry = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-media', $job['default_locale'] ) ), true )[ substr( hash( 'sha256', 'media:' . $attachment ), 0, 12 ) ];
check( $stored === $media_entry['file'], 'the media record points at the stored path' );
check( $image_url === $media_entry['url'], 'the source URL is kept alongside it' );
$illustrated_body = Files::read( $dir, Models::content_path( $job, 'wp-post', $job['default_locale'], Source::address( get_post( $illustrated ) )['entry_id'] ) );
check( false !== strpos( $illustrated_body, 'src="/' . $stored . '"' ), 'content is relinked to the stored media path' );
check( false === strpos( $illustrated_body, $image_url ), 'no WordPress upload URL is left in the relinked body' );
// A file that is not in the export must keep its working WordPress URL.
$ghost_entry = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-media', $job['default_locale'] ) ), true )[ substr( hash( 'sha256', 'media:' . $ghost ), 0, 12 ) ];
check( ! isset( $ghost_entry['file'] ), 'a missing upload is not claimed as transferred' );
// A re-used test database accumulates fixtures, so this counts at least the
// ghost rather than pinning an exact total.
check( $job['counts']['media_kept_remote'] >= 1, 'media kept remote is counted' );
$manifest_media = json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['media'];
check( $manifest_media['transferred_files'] >= 1 && 'media/' === $manifest_media['stored_under'], 'the manifest reports real media counts' );


check( isset( json_decode( Files::read( $dir, 'bridge/manifest.json' ), true )['files']['CONTENTRAIN-EXPORT.md'] ), 'README hash is in the manifest for repeat Git delivery' );

// ACF content, once the tables have been written.
if ( $has_acf ) {
	$page_data = json_decode( Files::read( $dir, Models::content_path( $job, 'wp-page', $job['default_locale'] ) ), true );
	$page_entry = $page_data[ Source::address( get_post( $page ) )['entry_id'] ];
	check( 'Content you own' === $page_entry['acf_tagline'], 'a scalar ACF field is a scalar field' );
	check( 3 === $page_entry['acf_rank'] || 3.0 === $page_entry['acf_rank'], 'a number stays a number' );
	check( true === $page_entry['acf_featured'], 'true_false becomes a boolean' );
	check( 'relations' === $job['models']['wp-page']['fields']['acf_quotes']['type'], 'the post relates to its repeater rows' );
	check( 2 === count( $page_entry['acf_quotes'] ), 'every repeater row is exported' );
	check( 'relation' === $job['models']['wp-page']['fields']['acf_hero']['type'], 'a group is a single relation' );

	$rows = json_decode( Files::read( $dir, Models::content_path( $job, \Contentrain\Bridge\Acf::model_id( 'quotes', 'field_bridge_quotes' ), $job['default_locale'] ) ), true );
	// Only this page's rows: a re-used test database keeps earlier fixtures.
	$people = array_map( static function ( $ref ) use ( $rows ) { return $rows[ $ref ]['person']; }, $page_entry['acf_quotes'] );
	sort( $people );
	check( array( 'Ada', 'Grace' ) === $people, 'repeater rows carry their own named fields' );
	check( 'It reads like my site.' === $rows[ $page_entry['acf_quotes'][0] ]['quote'], 'each row keeps its own values' );
	check( 0 === $rows[ $page_entry['acf_quotes'][0] ]['position'], 'row order is preserved as data' );
	$hero = json_decode( Files::read( $dir, Models::content_path( $job, \Contentrain\Bridge\Acf::model_id( 'hero', 'field_bridge_hero' ), $job['default_locale'] ) ), true );
	check( 'Own your words' === $hero[ $page_entry['acf_hero'] ]['heading'], 'the group row is reachable through the relation' );
}

check( Validator::run( $job )['valid'], 'generated relation graph validates' );
$broken = $job;
$broken['tables'][ Models::content_path( $job, 'wp-authors', $address['locale'] ) ] = array();
rejects( static function () use ( $broken ) { Validator::run( $broken ); }, 'missing author relation fails validation' );
foreach ( $job['files'] as $path => $info ) {
	check( hash_file( 'sha256', Files::path( $dir, $path ) ) === $info['sha256'], 'file hash: ' . $path );
}
if ( class_exists( '\ZipArchive' ) ) {
	do { $zip = Admin::zip( $id ); } while ( ! $zip['done'] );
	$archive = new ZipArchive(); $archive->open( Files::dir( $id ) . '/export.zip' );
	check( false !== $archive->locateName( '.contentrain/config.json' ), 'ZIP includes dot-prefixed content store' );
	check( $archive->numFiles === count( $job['files'] ), 'ZIP contains each exported file once' );
	$archive->close();
}
// GitHub API fixture: all network traffic is intercepted. No repository is mutated.
$requests = array();
$github_filter = static function ( $pre, $args, $url ) use ( &$requests ) {
	$requests[] = array( 'url' => $url, 'method' => $args['method'] );
	if ( 0 !== strpos( $url, 'https://api.github.com/repos/test-owner/test-repo' ) ) { throw new RuntimeException( 'Unexpected external request: ' . $url ); }
	$path = substr( $url, strlen( 'https://api.github.com/repos/test-owner/test-repo' ) );
	if ( '' === $path ) { $data = array( 'private' => true, 'default_branch' => 'main' ); }
	elseif ( '/git/ref/heads/main' === $path ) { $data = array( 'object' => array( 'sha' => str_repeat( 'a', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/commits/' ) ) { $data = array( 'tree' => array( 'sha' => str_repeat( 'b', 40 ) ) ); }
	elseif ( 0 === strpos( $path, '/git/trees/' ) ) { $data = array( 'truncated' => false, 'tree' => array() ); }
	elseif ( '/git/blobs' === $path || '/git/trees' === $path || '/git/commits' === $path ) { $data = array( 'sha' => hash( 'sha1', $args['body'] ) ); }
	elseif ( '/git/refs' === $path ) { $data = array( 'ref' => 'ok' ); }
	else { throw new RuntimeException( 'Unmocked GitHub endpoint.' ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $github_filter, 10, 3 );
$summary = GitHub::start( $id, 'test-only-token-123456', 'test-owner/test-repo' );
for ( $i = 0; $i < 500 && 'done' !== $summary['github']['phase']; ++$i ) { $summary = GitHub::step( $id, 'test-only-token-123456', $summary['github']['cursor'] ); }
check( 'done' === $summary['github']['phase'], 'GitHub branch delivery completes through mocked HTTP API' );
$ref_writes = array_filter( $requests, static function ( $r ) { return 'POST' === $r['method'] && substr( $r['url'], -9 ) === '/git/refs'; } );
check( 1 === count( $ref_writes ), 'only one new Git branch is created' );
check( false === strpos( Files::read( Files::dir( $id ), 'state.json' ), 'test-only-token' ), 'GitHub token is never persisted' );
$repeat = GitHub::step( $id, 'test-only-token-123456', 0 );
check( 'done' === $repeat['github']['phase'], 'repeated delivery returns the existing receipt' );
remove_filter( 'pre_http_request', $github_filter, 10 );
file_put_contents( '/tmp/bridge-test-output-path', $dir );
// Authenticated media transfer must survive JSON encoding without corrupting PNG bytes.
$request = new WP_REST_Request( 'GET', '/contentrain-bridge/v1/exports/' . $id );
$request->set_param( 'id', $id ); $request->set_param( 'file', $stored );
$rest_file = Admin::read_export( $request );
check( ! is_wp_error( $rest_file ) && 'base64' === $rest_file->get_data()['encoding'], 'REST marks binary export content as base64' );
check( $png === base64_decode( $rest_file->get_data()['content'], true ), 'REST preserves media bytes' );
check( 'private, no-store' === $rest_file->get_headers()['Cache-Control'], 'export responses cannot be publicly cached' );
$request->set_param( 'file', '../state.json' );
check( is_wp_error( Admin::read_export( $request ) ), 'REST cannot read files outside the export manifest' );
$lock = fopen( Files::dir( $id ) . '/lock', 'c' );
flock( $lock, LOCK_EX );
rejects( static function () use ( $id ) { Jobs::delete( $id ); }, 'cancel cannot delete an actively locked export' );
flock( $lock, LOCK_UN ); fclose( $lock );
$expired = bin2hex( random_bytes( 16 ) );
Files::mkdir( Files::dir( $expired ) );
Files::put( Files::dir( $expired ), 'state.json', Policy::json( array( 'created_at' => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ) ) ) );
Files::cleanup();
check( ! is_dir( Files::dir( $expired ) ), 'expiry uses creation time even when state was just written' );
$mapping_job = array( 'uploads' => array( 'baseurl' => 'https://example.test/uploads' ), 'files' => array( 'media/safe.png' => array() ), 'media_paths' => array( 'ç.png' => 'media/safe.png' ) );
check( '/media/safe.png' === Models::relink( $mapping_job, 'https://example.test/uploads/%C3%A7.png', true ), 'encoded Unicode upload URLs map to safe exported paths' );

// Escape-bearing metadata is written, not refused. It used to be refused, because
// the published reader stripped a scalar's quotes without decoding its escapes;
// `@contentrain/types@1.14.0` fixed that and `tests/reader-compat.mjs` proves it
// against this writer's own output on every CI run.
$quoted = Policy::frontmatter( array( 'title' => 'A "quoted" title' ) );
check( 'title: "A ' . chr( 92 ) . '"quoted' . chr( 92 ) . '" title"' === $quoted, 'a quote in metadata is written as an escaped quote' );
$multiline = Policy::frontmatter( array( 'excerpt' => "line one\nline two" ) );
check( 'excerpt: "line one' . chr( 92 ) . 'nline two"' === $multiline, 'a newline in metadata is written as an escape' );
check( 1 === count( explode( "\n", $multiline ) ), 'a multi-line value occupies exactly one frontmatter line' );

// The fixture the reader gate reads: this writer's real output for
// escape-bearing values, with the values that went in beside it.
$compat = array(
	'title'    => 'A "quoted" title',
	'excerpt'  => "line one\nline two",
	'path'     => 'C:' . chr( 92 ) . 'Users' . chr( 92 ) . 'ada',
	'tabbed'   => "col1\tcol2",
	'slash'    => 'a/b/c',
	'unicode'  => 'İstanbul — café ✅ 日本語',
	'colon'    => 'Title: subtitle',
	'padded'   => '  padded  ',
	'blank'    => '',
	'sku'      => '007',
	'flagish'  => 'true',
	'count'    => 42,
	'flag'     => true,
);
Files::put( $dir, 'reader-compat.md', "---\n" . Policy::frontmatter( $compat ) . "\n---\nBody text." );
Files::put( $dir, 'reader-compat.json', Policy::json( $compat ) );
check( is_file( $dir . '/reader-compat.md' ), 'reader compatibility fixture written for escape-bearing metadata' );

// Snapshot consistency check on a separate job, preserving the finished artifact for external validation.
delete_user_meta( $admin->ID, 'contentrain_bridge_job_1' );
$other = Jobs::create( array( 'types' => array( 'page' ) ) );
Source::changed();
rejects( static function () use ( $other ) { Jobs::step( $other['id'], $other['step'] ); }, 'changed source snapshot blocks continuation' );
Files::remove( Files::dir( $other['id'] ) );
update_user_meta( $admin->ID, 'contentrain_bridge_job_1', $id );
echo "\n$checks checks passed. Output: $dir\n";
