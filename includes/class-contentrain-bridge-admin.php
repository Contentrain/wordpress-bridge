<?php
/** WordPress admin and authenticated export read API. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_ajax_contentrain_bridge', array( self::class, 'ajax' ) );
		add_action( 'admin_post_contentrain_bridge_download', array( self::class, 'download' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
	}

	public static function permitted() {
		return current_user_can( 'export' ) && current_user_can( 'manage_options' );
	}

	public static function menu() {
		add_management_page( __( 'Contentrain Bridge', 'contentrain-bridge' ), __( 'Contentrain Bridge', 'contentrain-bridge' ), 'manage_options', 'contentrain-bridge', array( self::class, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_contentrain-bridge' !== $hook || ! self::permitted() ) {
			return;
		}
		wp_enqueue_style( 'contentrain-bridge', plugins_url( 'assets/admin.css', CONTENTRAIN_BRIDGE_FILE ), array(), CONTENTRAIN_BRIDGE_VERSION );
		wp_enqueue_script( 'contentrain-bridge', plugins_url( 'assets/admin.js', CONTENTRAIN_BRIDGE_FILE ), array( 'wp-i18n' ), CONTENTRAIN_BRIDGE_VERSION, true );
		wp_set_script_translations( 'contentrain-bridge', 'contentrain-bridge' );
		wp_localize_script( 'contentrain-bridge', 'ContentrainBridge', array( 'secure' => is_ssl() || 'local' === wp_get_environment_type(), 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'contentrain_bridge' ), 'download' => admin_url( 'admin-post.php' ), 'downloadNonce' => wp_create_nonce( 'contentrain_bridge_download' ), 'stages' => self::stages() ) );
	}

	/** The export's stages in order, named for the screen: what the progress list shows and what the script announces. */
	public static function stages() {
		return array(
			array( 'id' => 'media', 'label' => __( 'Media', 'contentrain-bridge' ) ),
			array( 'id' => 'posts', 'label' => __( 'Content', 'contentrain-bridge' ) ),
			array( 'id' => 'terms', 'label' => __( 'Categories and tags', 'contentrain-bridge' ) ),
			array( 'id' => 'inventory', 'label' => __( 'Inventory', 'contentrain-bridge' ) ),
			array( 'id' => 'comments', 'label' => __( 'Comments', 'contentrain-bridge' ) ),
			array( 'id' => 'sources', 'label' => __( 'Interface text', 'contentrain-bridge' ) ),
			array( 'id' => 'tables', 'label' => __( 'Files', 'contentrain-bridge' ) ),
			array( 'id' => 'review', 'label' => __( 'Your review', 'contentrain-bridge' ) ),
			array( 'id' => 'ready', 'label' => __( 'Ready', 'contentrain-bridge' ) ),
		);
	}

	/** A card heading with a Dashicon in front of it. */
	private static function heading( $icon, $text ) {
		printf( '<h2 class="cr-card-title"><span class="dashicons dashicons-%s" aria-hidden="true"></span> %s</h2>', esc_attr( $icon ), esc_html( $text ) );
	}

	public static function page() {
		if ( ! self::permitted() ) {
			wp_die( esc_html__( 'Export and administrator permissions are required.', 'contentrain-bridge' ) );
		}
		?>
		<div class="wrap cr-bridge">
			<h1><?php esc_html_e( 'Contentrain Bridge', 'contentrain-bridge' ); ?></h1>
			<p><?php esc_html_e( 'Turn your WordPress content into editable Contentrain JSON and Markdown. Local export and GitHub delivery are free.', 'contentrain-bridge' ); ?></p>
			<p><?php esc_html_e( 'Your WordPress site stays as it is. Review the content models and interface text before downloading or sending anything to GitHub.', 'contentrain-bridge' ); ?></p>
			<section id="cr-connect" class="card cr-card">
				<?php self::heading( 'admin-links', __( 'Connect to Contentrain Migrate', 'contentrain-bridge' ) ); ?>
				<p><?php esc_html_e( 'Moving this site with Contentrain Migrate? Create a connection key and paste it into Migrate. It lets Migrate start and read content exports of this site as you, nothing else, and works where your host blocks application passwords.', 'contentrain-bridge' ); ?></p>
				<p><?php esc_html_e( 'The key is shown once; only a fingerprint of it is stored. Paste it into Migrate within an hour. Once Migrate has used it, it works for that one move until 14 days after it was created, until Migrate closes it when the move is done, or until you revoke it or create a new one.', 'contentrain-bridge' ); ?></p>
				<p id="cr-key-state" role="status" aria-live="polite"></p>
				<div id="cr-key-new" hidden>
					<label for="cr-key"><?php esc_html_e( 'Your connection key — copy it now, it will not be shown again', 'contentrain-bridge' ); ?></label>
					<input id="cr-key" type="text" class="large-text code" readonly autocomplete="off" spellcheck="false" />
					<button type="button" id="cr-key-copy" class="button"><?php esc_html_e( 'Copy key', 'contentrain-bridge' ); ?></button>
				</div>
				<button type="button" id="cr-key-create" class="button"><?php esc_html_e( 'Create connection key', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-key-revoke" class="button" hidden><?php esc_html_e( 'Revoke key', 'contentrain-bridge' ); ?></button>
			</section>
			<?php self::robots_card(); ?>
			<div id="cr-error" class="notice notice-error inline cr-error" role="alert" hidden>
				<h2 id="cr-error-title" class="cr-error-title" tabindex="-1"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <span id="cr-error-heading"></span></h2>
				<p id="cr-error-message"></p>
				<p id="cr-error-hint" hidden></p>
				<p class="cr-error-actions">
					<button type="button" id="cr-retry" class="button button-primary" hidden><?php esc_html_e( 'Retry', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-restart" class="button" hidden><?php esc_html_e( 'Delete export and start again', 'contentrain-bridge' ); ?></button>
				</p>
			</div>
			<section id="cr-progress" class="card cr-card" hidden>
				<?php self::heading( 'update', __( 'Export progress', 'contentrain-bridge' ) ); ?>
				<ol id="cr-steps" class="cr-steps">
					<?php foreach ( self::stages() as $stage ) : ?>
						<li data-stage="<?php echo esc_attr( $stage['id'] ); ?>" class="cr-step"><span class="cr-step-mark" aria-hidden="true"></span><span class="cr-step-label"><?php echo esc_html( $stage['label'] ); ?></span><span class="cr-step-state screen-reader-text"></span></li>
					<?php endforeach; ?>
				</ol>
				<div class="cr-bar">
					<progress id="cr-bar" max="100" value="0" aria-labelledby="cr-current"></progress>
					<span id="cr-percent" class="cr-percent" aria-hidden="true">0%</span>
				</div>
				<p id="cr-current" class="cr-current"></p>
				<div id="cr-status" class="screen-reader-text" role="status" aria-live="polite"></div>
				<dl class="cr-counts">
					<div><dt><?php esc_html_e( 'Content', 'contentrain-bridge' ); ?></dt><dd id="cr-count-posts">0</dd></div>
					<div><dt><?php esc_html_e( 'Media', 'contentrain-bridge' ); ?></dt><dd id="cr-count-media">0</dd></div>
					<div><dt><?php esc_html_e( 'Files', 'contentrain-bridge' ); ?></dt><dd id="cr-count-files">0</dd></div>
					<div><dt><?php esc_html_e( 'Texts to review', 'contentrain-bridge' ); ?></dt><dd id="cr-count-texts">0</dd></div>
					<div><dt><?php esc_html_e( 'Coverage notices', 'contentrain-bridge' ); ?></dt><dd id="cr-count-warnings">0</dd></div>
				</dl>
				<div class="cr-actions">
					<button type="button" id="cr-resume" class="button button-primary" hidden><?php esc_html_e( 'Continue', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-pause" class="button" hidden><?php esc_html_e( 'Pause', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-delete" class="button-link button-link-delete" hidden><?php esc_html_e( 'Delete temporary export', 'contentrain-bridge' ); ?></button>
				</div>
			</section>
			<section id="cr-scope" class="card cr-card">
				<?php self::heading( 'database-export', __( '1. Choose content', 'contentrain-bridge' ) ); ?>
				<p class="cr-empty"><?php esc_html_e( 'No export yet. Choose what to include and prepare the content; the export runs in this tab and saves its progress as it goes, so it can be paused and continued.', 'contentrain-bridge' ); ?></p>
				<div id="cr-types"></div>
				<label><input type="checkbox" id="cr-private" /> <?php esc_html_e( 'Include draft, scheduled, private and password-protected content (requires a private GitHub repository)', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-comments" /> <?php esc_html_e( 'Include comment archive: names, links and text; no email, IP address or comment metadata', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-media" checked /> <?php esc_html_e( 'Copy media files into the export (turn off when the receiving tool downloads media itself; content then keeps the WordPress upload URLs)', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-scan" checked /> <?php esc_html_e( 'Find interface text in the active theme and child theme', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-render" /> <?php esc_html_e( 'Also read the text your pages render (home, a post, a page, search and not-found pages, fetched from this site as a visitor)', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-plugins" /> <?php esc_html_e( 'Also scan active plugin source files (more text to review)', 'contentrain-bridge' ); ?></label>
				<label for="cr-meta"><?php esc_html_e( 'Additional post metadata keys to include, separated by commas. Secret-like keys are always excluded.', 'contentrain-bridge' ); ?></label>
				<input type="text" id="cr-meta" class="large-text" autocomplete="off" />
				<p><?php esc_html_e( 'ACF content, supported SEO metadata and core media fields are discovered automatically. Complex fields become editable related records. Unknown metadata and unsupported dynamic states are listed in the coverage report.', 'contentrain-bridge' ); ?></p>
				<p class="cr-actions"><button type="button" id="cr-create" class="button button-primary button-hero"><?php esc_html_e( 'Prepare content', 'contentrain-bridge' ); ?></button></p>
			</section>
			<section id="cr-review" class="card cr-card" hidden>
				<h2 id="cr-review-title" class="cr-card-title" tabindex="-1"><span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( '2. Review models and interface text', 'contentrain-bridge' ); ?></h2>
				<div id="cr-models"></div>
				<p><?php esc_html_e( 'Review each candidate in context. Change the key to group equivalent messages. Exclude code constants and unrelated text. Source code is never patched by this export.', 'contentrain-bridge' ); ?></p>
				<div id="cr-candidates"></div>
				<p class="cr-actions">
					<button type="button" id="cr-save-review" class="button"><?php esc_html_e( 'Save this page', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-prev" class="button"><?php esc_html_e( 'Previous', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-next" class="button"><?php esc_html_e( 'Next', 'contentrain-bridge' ); ?></button>
					<button type="button" id="cr-finish" class="button button-primary"><?php esc_html_e( 'Validate and finalize', 'contentrain-bridge' ); ?></button>
				</p>
			</section>
			<section id="cr-delivery" class="card cr-card" hidden>
				<h2 id="cr-done-title" class="cr-card-title" tabindex="-1"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( '3. Your content is ready', 'contentrain-bridge' ); ?></h2>
				<div class="notice notice-success inline cr-done">
					<p id="cr-done-summary"></p>
					<p><?php esc_html_e( 'Download it as a ZIP, deliver it to a GitHub repository, or both. The temporary export is kept for a day.', 'contentrain-bridge' ); ?></p>
				</div>
				<p><?php esc_html_e( 'Review the coverage report: copied media is included; missing or oversized files may still use WordPress URLs. Dynamic WordPress behavior needs a renderer.', 'contentrain-bridge' ); ?></p>
				<h3><?php esc_html_e( 'Services to reconnect', 'contentrain-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Outside services this site is connected to. No keys or tokens were exported: connect each one again on the new site with its own credentials.', 'contentrain-bridge' ); ?></p>
				<ul id="cr-integrations"></ul>
				<h3><?php esc_html_e( 'Source coverage', 'contentrain-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Every place WordPress keeps content, counted in the database and split into what was exported, what was left out and why, and what this export cannot read. The same report is in the export as bridge/coverage.json.', 'contentrain-bridge' ); ?></p>
				<details class="cr-coverage"><summary><?php esc_html_e( 'Show the full coverage table', 'contentrain-bridge' ); ?></summary><div id="cr-coverage"></div></details>
				<h3><?php esc_html_e( 'Download', 'contentrain-bridge' ); ?></h3>
				<p class="cr-actions">
					<button type="button" id="cr-zip" class="button button-primary"><?php esc_html_e( 'Prepare ZIP download', 'contentrain-bridge' ); ?></button>
					<a id="cr-download" class="button button-primary" hidden><?php esc_html_e( 'Download JSON / Markdown ZIP', 'contentrain-bridge' ); ?></a>
				</p>
				<h3><?php esc_html_e( 'Deliver to GitHub', 'contentrain-bridge' ); ?></h3>
				<label for="cr-repo"><?php esc_html_e( 'GitHub repository (owner/repository, initialized with a README)', 'contentrain-bridge' ); ?></label>
				<input id="cr-repo" type="text" class="regular-text" autocomplete="off" />
				<label for="cr-base"><?php esc_html_e( 'Base branch (optional; empty uses the repository\'s default branch). Delivery reads it and never writes it.', 'contentrain-bridge' ); ?></label>
				<input id="cr-base" type="text" class="regular-text" autocomplete="off" />
				<label for="cr-token"><?php esc_html_e( 'Fine-grained GitHub token: Contents read/write for this repository only', 'contentrain-bridge' ); ?></label>
				<input id="cr-token" type="password" class="regular-text" autocomplete="off" />
				<p><?php esc_html_e( 'The token is used only for these requests and is not saved. Delivery creates a separate branch. Existing edited content is never silently overwritten.', 'contentrain-bridge' ); ?></p>
				<label><input id="cr-consent" type="checkbox" /> <?php esc_html_e( 'Send this export to the selected GitHub repository. I have reviewed its content and repository visibility.', 'contentrain-bridge' ); ?></label>
				<label for="cr-conflict"><?php esc_html_e( 'If a file was edited in the repository since the last delivery', 'contentrain-bridge' ); ?></label>
				<select id="cr-conflict">
					<option value="refuse"><?php esc_html_e( 'Stop and show me what changed', 'contentrain-bridge' ); ?></option>
					<option value="keep-repository"><?php esc_html_e( 'Keep the repository version, deliver everything else', 'contentrain-bridge' ); ?></option>
					<option value="use-wordpress"><?php esc_html_e( 'Use the WordPress version on the delivery branch, for review', 'contentrain-bridge' ); ?></option>
				</select>
				<p class="cr-actions"><button id="cr-github" type="button" class="button"><?php esc_html_e( 'Deliver to GitHub', 'contentrain-bridge' ); ?></button></p>
				<p><a id="cr-receipt" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'View delivered commit', 'contentrain-bridge' ); ?></a></p>
			</section>
			<section id="cr-migrate" class="card cr-card" hidden>
				<?php self::heading( 'admin-site-alt3', __( 'Want an Astro website?', 'contentrain-bridge' ) ); ?>
				<p><?php esc_html_e( 'Your content export is already yours. Migrate can use the content models, source mappings and WordPress inventory to build an Astro website. No data is sent by opening this link; connect your export in Migrate when you choose to continue.', 'contentrain-bridge' ); ?></p>
				<a class="button" href="https://migrate.contentrain.io/?source=wordpress-bridge" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore Astro migration', 'contentrain-bridge' ); ?></a>
			</section>
		</div>
		<?php
	}

	/** The owner's switch for Contentrain Migrate in robots.txt (`Robots`): a plain form, so it works without the script. */
	private static function robots_card() {
		$chosen = Robots::chosen();
		$physical = Robots::physical();
		?>
		<section id="cr-robots" class="card cr-card">
			<?php self::heading( 'visibility', __( 'Let Contentrain Migrate read your pages', 'contentrain-bridge' ) ); ?>
			<p><?php esc_html_e( 'If your robots.txt closes this site to crawlers, Contentrain Migrate respects it and cannot read your pages. Turning this on adds one group to robots.txt for the ContentrainMigrate crawler only: the rules every crawler has, without the lines that close the whole site or the REST API. Other crawlers, and what stays closed (such as wp-admin), are unchanged. Turning it off removes the group.', 'contentrain-bridge' ); ?></p>
			<?php if ( $physical ) : ?>
				<p><?php esc_html_e( 'This site serves a robots.txt file from disk, which WordPress cannot change. To let Contentrain Migrate read your pages, add these lines to that file yourself, then check again in Migrate:', 'contentrain-bridge' ); ?></p>
				<textarea class="large-text code" rows="6" readonly><?php echo esc_textarea( Robots::group( Robots::groups( Robots::physical_text() ) ) ); ?></textarea>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="contentrain_bridge_robots" />
					<input type="hidden" name="allow" value="<?php echo $chosen ? '0' : '1'; ?>" />
					<?php wp_nonce_field( 'contentrain_bridge_robots' ); ?>
					<p role="status"><?php echo $chosen ? esc_html__( 'On: Contentrain Migrate may read your pages.', 'contentrain-bridge' ) : esc_html__( 'Off: robots.txt is as you or your plugins wrote it.', 'contentrain-bridge' ); ?></p>
					<p class="cr-actions"><button type="submit" class="button"><?php echo $chosen ? esc_html__( 'Turn off', 'contentrain-bridge' ) : esc_html__( 'Let Contentrain Migrate read my pages', 'contentrain-bridge' ); ?></button></p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function ajax() {
		if ( ! self::permitted() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'contentrain-bridge' ) ), 403 );
		}
		check_ajax_referer( 'contentrain_bridge', 'nonce' );
		try {
			$input = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed operation validation below; arbitrary content is not rendered.
			if ( ! is_array( $input ) ) {
				throw new \RuntimeException( 'Invalid request.' );
			}
			$op = $input['op'] ?? '';
			$id = $input['id'] ?? '';
			switch ( $op ) {
				case 'inventory':
					$result = Source::inventory();
					$result['active_job'] = get_user_meta( get_current_user_id(), 'contentrain_bridge_job_' . get_current_blog_id(), true );
					break;
				case 'create': $result = Jobs::create( $input ); break;
				case 'status': $result = Jobs::summary( Jobs::read( $id ) ); break;
				case 'step': $result = Jobs::advance( $id, $input['step'] ?? -1 ); break;
				case 'candidates':
					$job = Jobs::read( $id );
					$result = array_slice( array_values( $job['candidates'] ), max( 0, (int) ( $input['offset'] ?? 0 ) ), 30 );
					break;
				case 'review': $result = Jobs::review( $id, (array) ( $input['decisions'] ?? array() ), ! empty( $input['finish'] ) ); break;
				case 'github-start':
					if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
						throw new \RuntimeException( 'Use HTTPS in WordPress administration before sending a GitHub credential.' );
					}
					if ( empty( $input['consent'] ) ) {
						throw new \RuntimeException( 'Explicit GitHub transfer consent is required.' );
					}
					$result = GitHub::start( $id, $input['token'] ?? '', $input['repository'] ?? '', in_array( $input['on_conflict'] ?? 'refuse', GitHub::CONFLICT_CHOICES, true ) ? $input['on_conflict'] : 'refuse', is_string( $input['base_branch'] ?? null ) ? $input['base_branch'] : '' );
					break;
				case 'github-step':
					if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
						throw new \RuntimeException( 'Use HTTPS in WordPress administration before sending a GitHub credential.' );
					}
					$result = GitHub::step( $id, $input['token'] ?? '', $input['cursor'] ?? -1 ); break;
				case 'coverage':
					$job = Jobs::read( $id );
					if ( 'ready' !== $job['phase'] || ! isset( $job['files']['bridge/coverage.json'] ) ) {
						throw new \RuntimeException( 'Coverage is reported when the export is ready.' );
					}
					$result = json_decode( Files::read( Files::dir( $id ) . '/output', 'bridge/coverage.json' ), true );
					break;
				case 'zip': $result = self::zip( $id ); break;
				case 'key-status': $result = Key::status(); break;
				case 'key-create': $result = array( 'key' => Key::create(), 'status' => Key::status() ); break;
				case 'key-revoke':
					Key::revoke();
					$result = Key::status();
					break;
				case 'delete':
					// Ownership is checked even for an expired job; no caller-controlled directory is removed.
					$stored = get_user_meta( get_current_user_id(), 'contentrain_bridge_job_' . get_current_blog_id(), true );
					if ( $stored !== $id ) {
						throw new \RuntimeException( 'Export is not owned by the current user.' );
					}
					$result = Jobs::delete( $id );
					break;
				default: throw new \RuntimeException( 'Unknown operation.' );
			}
			wp_send_json_success( $result );
		} catch ( \Throwable $error ) {
			// 409 is a busy export, not a failure: the browser retries it, so it must reach the browser as one.
			$busy = 409 === $error->getCode();
			wp_send_json_error( array( 'message' => $error->getMessage(), 'busy' => $busy ), $busy ? 409 : 400 );
		}
	}

	public static function zip( $id ) {
		return Jobs::mutate( $id, static function ( &$job ) {
			if ( 'ready' !== $job['phase'] || ! class_exists( '\ZipArchive' ) ) {
				throw new \RuntimeException( 'Finalize your export and enable the PHP ZIP extension to download an archive.' );
			}
			$zip = new \ZipArchive();
			$dir = Files::dir( $job['id'] );
			if ( true !== $zip->open( $dir . '/export.zip', \ZipArchive::CREATE ) ) {
				throw new \RuntimeException( 'Cannot create ZIP archive.' );
			}
			$paths = array_keys( $job['files'] );
			$cursor = $job['zip_cursor'] ?? 0;
			foreach ( array_slice( $paths, $cursor, 20 ) as $path ) {
				if ( ! $zip->addFile( Files::path( $dir . '/output', $path ), $path ) ) {
					$zip->close();
					throw new \RuntimeException( 'Cannot add export file to archive.' );
				}
				++$cursor;
			}
			if ( ! $zip->close() ) {
				throw new \RuntimeException( 'Cannot finish ZIP archive.' );
			}
			Files::fs()->chmod( $dir . '/export.zip', 0600 );
			$job['zip_cursor'] = $cursor;
			return array( 'cursor' => $cursor, 'done' => $cursor >= count( $paths ) );
		} );
	}

	public static function download() {
		if ( ! self::permitted() ) {
			wp_die( esc_html__( 'Permission denied.', 'contentrain-bridge' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'contentrain_bridge_download' );
		try {
			$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
			$job = Jobs::read( $id );
			if ( 'ready' !== $job['phase'] || ( $job['zip_cursor'] ?? 0 ) < count( $job['files'] ) ) {
				throw new \RuntimeException( 'ZIP is not ready.' );
			}
			$file = Files::dir( $id ) . '/export.zip';
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="contentrain-' . $id . '.zip"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Length: ' . filesize( $file ) );
			readfile( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Authenticated binary stream; WP_Filesystem would load the entire archive into memory.
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 400 ) );
		}
	}

	public static function routes() {
		register_rest_route( 'contentrain-bridge/v1', '/exports/(?P<id>[a-f0-9]{32})', array( 'methods' => 'GET', 'permission_callback' => array( Remote::class, 'permitted' ), 'callback' => array( self::class, 'read_export' ) ) );
		// The same read for a caller whose key travels in the body (BR-27): a GET has none.
		register_rest_route( 'contentrain-bridge/v1', '/exports/(?P<id>[a-f0-9]{32})/read', array( 'methods' => 'POST', 'permission_callback' => array( Remote::class, 'permitted' ), 'callback' => array( self::class, 'read_export' ) ) );
		Remote::routes();
	}

	/**
	 * GET /exports/{id}: the file list. `?file=`: one file of up to 8 MiB, whole.
	 * `?file=&offset=N[&length=M]`: any file, in chunks of at most 8 MiB, always
	 * base64, with the whole file's `sha256` and `bytes` so the reader can
	 * assemble and verify it against the manifest.
	 */
	public static function read_export( $request ) {
		try {
			$job = Jobs::read( $request['id'] );
		} catch ( \Throwable $error ) {
			// Gone and expired are told apart (404 / 410); every other refusal below stays a 400.
			return Remote::missing( $error );
		}
		try {
			if ( 'ready' !== $job['phase'] ) {
				throw new \RuntimeException( 'Export is not ready.' );
			}
			// A reader is downloading this snapshot: a fresh start must not remove it under them (Remote::discard).
			Files::fs()->touch( Files::dir( $job['id'] ) . '/' . Remote::READ_MARK );
			$path = $request->get_param( 'file' );
			if ( null === $path ) {
				return new \WP_REST_Response( array( 'format' => 'contentrain-bridge@1', 'snapshot' => $job['id'], 'files' => $job['files'] ), 200, array( 'Cache-Control' => 'private, no-store' ) );
			}
			$offset = $request->get_param( 'offset' );
			if ( null !== $offset ) {
				return self::read_chunk( $job, $path, $offset, $request->get_param( 'length' ) );
			}
			if ( ! is_string( $path ) || ! isset( $job['files'][ $path ] ) || $job['files'][ $path ]['bytes'] > 8 * MB_IN_BYTES ) {
				throw new \RuntimeException( 'File unavailable through this endpoint.' );
			}
			$content = Files::read( Files::dir( $job['id'] ) . '/output', $path );
			$binary = 0 === strpos( $path, 'media/' );
			return new \WP_REST_Response( array( 'path' => $path, 'sha256' => $job['files'][ $path ]['sha256'], 'encoding' => $binary ? 'base64' : 'utf8', 'content' => $binary ? base64_encode( $content ) : $content ), 200, array( 'Cache-Control' => 'private, no-store' ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'bridge_export', $error->getMessage(), array( 'status' => 400 ) );
		}
	}

	private static function read_chunk( $job, $path, $offset, $length ) {
		$limit = 8 * MB_IN_BYTES;
		$length = null === $length ? $limit : $length;
		if ( ! is_string( $path ) || ! isset( $job['files'][ $path ] ) || ! preg_match( '/^[0-9]{1,12}$/D', (string) $offset ) || ! preg_match( '/^[0-9]{1,12}$/D', (string) $length ) ) {
			throw new \RuntimeException( 'File unavailable through this endpoint.' );
		}
		$bytes = (int) $job['files'][ $path ]['bytes'];
		$offset = (int) $offset;
		$length = (int) $length;
		if ( $offset > $bytes || $length > $limit ) {
			throw new \RuntimeException( 'Chunk out of range: offset 0-' . $bytes . ', length 0-' . $limit . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integers only; JSON encoded.
		}
		$length = min( $length, $bytes - $offset );
		$content = '';
		if ( $length > 0 ) {
			$stream = fopen( Files::path( Files::dir( $job['id'] ) . '/output', $path ), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Ranged read; WP_Filesystem reads whole files only.
			if ( ! $stream ) {
				throw new \RuntimeException( 'Cannot read export file.' );
			}
			try {
				if ( 0 !== fseek( $stream, $offset ) ) {
					throw new \RuntimeException( 'Cannot read export file.' );
				}
				while ( strlen( $content ) < $length && ! feof( $stream ) ) {
					$part = fread( $stream, $length - strlen( $content ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Ranged read.
					if ( false === $part ) {
						throw new \RuntimeException( 'Cannot read export file.' );
					}
					$content .= $part;
				}
			} finally {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handle.
			}
			if ( strlen( $content ) !== $length ) {
				throw new \RuntimeException( 'Export file changed while being read.' );
			}
		}
		return new \WP_REST_Response( array( 'path' => $path, 'sha256' => $job['files'][ $path ]['sha256'], 'bytes' => $bytes, 'offset' => $offset, 'length' => $length, 'encoding' => 'base64', 'content' => base64_encode( $content ) ), 200, array( 'Cache-Control' => 'private, no-store' ) );
	}

	public static function privacy() {
		wp_add_privacy_policy_content( __( 'Contentrain Bridge', 'contentrain-bridge' ), wp_kses_post( __( 'Contentrain Bridge prepares a private, temporary content export after an administrator requests it. Export files expire after 24 hours and may be deleted earlier. Content can contain personal information; review the scope before sharing. Comment email, IP and metadata are excluded. GitHub transfer is optional and sends the reviewed files to the selected repository only after explicit consent. GitHub credentials are not stored. The optional Migrate link sends no export data. A Contentrain Migrate connection key, when an administrator creates one, lets Contentrain Migrate start and read content exports as that administrator until it expires or is revoked; only a fingerprint of the key and the address and time of its last use are stored. No telemetry is collected.', 'contentrain-bridge' ) ) );
	}
}
