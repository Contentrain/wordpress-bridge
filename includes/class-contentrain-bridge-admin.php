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
		wp_localize_script( 'contentrain-bridge', 'ContentrainBridge', array( 'secure' => is_ssl() || 'local' === wp_get_environment_type(), 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'contentrain_bridge' ), 'download' => admin_url( 'admin-post.php' ), 'downloadNonce' => wp_create_nonce( 'contentrain_bridge_download' ) ) );
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
			<div id="cr-status" role="status" aria-live="polite"></div>
			<div id="cr-error" class="notice notice-error" role="alert" hidden></div>
			<section id="cr-scope">
				<h2><?php esc_html_e( '1. Choose content', 'contentrain-bridge' ); ?></h2>
				<div id="cr-types"></div>
				<label><input type="checkbox" id="cr-private" /> <?php esc_html_e( 'Include draft, scheduled, private and password-protected content (requires a private GitHub repository)', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-comments" /> <?php esc_html_e( 'Include comment archive: names, links and text; no email, IP address or comment metadata', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-scan" checked /> <?php esc_html_e( 'Find interface text in the active theme and child theme', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-render" /> <?php esc_html_e( 'Also read the text your pages render (home, a post, a page, search and not-found pages, fetched from this site as a visitor)', 'contentrain-bridge' ); ?></label>
				<label><input type="checkbox" id="cr-plugins" /> <?php esc_html_e( 'Also scan active plugin source files (more text to review)', 'contentrain-bridge' ); ?></label>
				<label for="cr-meta"><?php esc_html_e( 'Additional post metadata keys to include, separated by commas. Secret-like keys are always excluded.', 'contentrain-bridge' ); ?></label>
				<input type="text" id="cr-meta" class="large-text" autocomplete="off" />
				<p><?php esc_html_e( 'ACF content, supported SEO metadata and core media fields are discovered automatically. Complex fields become editable related records. Unknown metadata and unsupported dynamic states are listed in the coverage report.', 'contentrain-bridge' ); ?></p>
				<button type="button" id="cr-create" class="button button-primary"><?php esc_html_e( 'Prepare content', 'contentrain-bridge' ); ?></button>
			</section>
			<div class="cr-actions">
				<button type="button" id="cr-resume" class="button" hidden><?php esc_html_e( 'Continue', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-pause" class="button" hidden><?php esc_html_e( 'Pause', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-delete" class="button" hidden><?php esc_html_e( 'Delete temporary export', 'contentrain-bridge' ); ?></button>
			</div>
			<section id="cr-review" hidden>
				<h2><?php esc_html_e( '2. Review models and interface text', 'contentrain-bridge' ); ?></h2>
				<div id="cr-models"></div>
				<p><?php esc_html_e( 'Review each candidate in context. Change the key to group equivalent messages. Exclude code constants and unrelated text. Source code is never patched by this export.', 'contentrain-bridge' ); ?></p>
				<div id="cr-candidates"></div>
				<button type="button" id="cr-save-review" class="button"><?php esc_html_e( 'Save this page', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-prev" class="button"><?php esc_html_e( 'Previous', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-next" class="button"><?php esc_html_e( 'Next', 'contentrain-bridge' ); ?></button>
				<button type="button" id="cr-finish" class="button button-primary"><?php esc_html_e( 'Validate and finalize', 'contentrain-bridge' ); ?></button>
			</section>
			<section id="cr-delivery" hidden>
				<h2><?php esc_html_e( '3. Get your content', 'contentrain-bridge' ); ?></h2>
				<p><?php esc_html_e( 'Review the coverage report: copied media is included; missing or oversized files may still use WordPress URLs. Dynamic WordPress behavior needs a renderer.', 'contentrain-bridge' ); ?></p>
				<h3><?php esc_html_e( 'Services to reconnect', 'contentrain-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Outside services this site is connected to. No keys or tokens were exported: connect each one again on the new site with its own credentials.', 'contentrain-bridge' ); ?></p>
				<ul id="cr-integrations"></ul>
				<h3><?php esc_html_e( 'Source coverage', 'contentrain-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Every place WordPress keeps content, counted in the database and split into what was exported, what was left out and why, and what this export cannot read. The same report is in the export as bridge/coverage.json.', 'contentrain-bridge' ); ?></p>
				<div id="cr-coverage"></div>
				<button type="button" id="cr-zip" class="button"><?php esc_html_e( 'Prepare ZIP download', 'contentrain-bridge' ); ?></button>
				<a id="cr-download" class="button" hidden><?php esc_html_e( 'Download JSON / Markdown ZIP', 'contentrain-bridge' ); ?></a>
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
				<button id="cr-github" type="button" class="button button-primary"><?php esc_html_e( 'Deliver to GitHub', 'contentrain-bridge' ); ?></button>
				<p><a id="cr-receipt" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'View delivered commit', 'contentrain-bridge' ); ?></a></p>
			</section>
			<section id="cr-migrate" hidden>
				<h2><?php esc_html_e( 'Want an Astro website?', 'contentrain-bridge' ); ?></h2>
				<p><?php esc_html_e( 'Your content export is already yours. Migrate can use the content models, source mappings and WordPress inventory to build an Astro website. No data is sent by opening this link; connect your export in Migrate when you choose to continue.', 'contentrain-bridge' ); ?></p>
				<a class="button" href="https://migrate.contentrain.io/?source=wordpress-bridge" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore Astro migration', 'contentrain-bridge' ); ?></a>
			</section>
		</div>
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
				case 'step': $result = Jobs::step( $id, $input['step'] ?? -1 ); break;
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
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
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
		register_rest_route( 'contentrain-bridge/v1', '/exports/(?P<id>[a-f0-9]{32})', array( 'methods' => 'GET', 'permission_callback' => array( self::class, 'permitted' ), 'callback' => array( self::class, 'read_export' ) ) );
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
			if ( 'ready' !== $job['phase'] ) {
				throw new \RuntimeException( 'Export is not ready.' );
			}
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
		if ( $offset > $bytes || $length < 1 || $length > $limit ) {
			throw new \RuntimeException( 'Chunk out of range: offset 0-' . $bytes . ', length 1-' . $limit . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integers only; JSON encoded.
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
		wp_add_privacy_policy_content( __( 'Contentrain Bridge', 'contentrain-bridge' ), wp_kses_post( __( 'Contentrain Bridge prepares a private, temporary content export after an administrator requests it. Export files expire after 24 hours and may be deleted earlier. Content can contain personal information; review the scope before sharing. Comment email, IP and metadata are excluded. GitHub transfer is optional and sends the reviewed files to the selected repository only after explicit consent. GitHub credentials are not stored. The optional Migrate link sends no export data. No telemetry is collected.', 'contentrain-bridge' ) ) );
	}
}
