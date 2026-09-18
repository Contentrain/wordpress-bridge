<?php
/** Resumable, owner-scoped export state machine. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Jobs {
	public static function create( $input ) {
		Files::cleanup();
		$inventory = Source::inventory();
		$types = array_values( array_intersect( array_keys( $inventory['post_types'] ), (array) ( $input['types'] ?? array_keys( $inventory['post_types'] ) ) ) );
		if ( ! $types ) {
			throw new \RuntimeException( 'Select at least one content type.' );
		}
		$old = get_user_meta( get_current_user_id(), 'contentrain_bridge_job_' . get_current_blog_id(), true );
		if ( $old && is_dir( Files::dir( $old ) ) ) {
			throw new \RuntimeException( 'Resume or delete your previous export before starting another.' );
		}
		$id = bin2hex( random_bytes( 16 ) );
		$dir = Files::dir( $id );
		if ( ! Files::mkdir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create export job.' );
		}
		$locale = Source::default_locale();
		// One language means a store without locale-named files; the decision is
		// taken here because it selects the paths every later step writes to.
		$languages = array_values( array_unique( array_map( array( Source::class, 'locale' ), (array) $inventory['languages'] ) ) );
		$job = array(
			'id' => $id, 'owner' => get_current_user_id(), 'blog' => get_current_blog_id(), 'created_at' => gmdate( 'c' ),
			'revision' => Source::revision(), 'phase' => 'media', 'cursor' => 0, 'step' => 0,
			'inventory' => $inventory, 'default_locale' => $locale, 'locales' => array( $locale => true ), 'i18n' => count( $languages ) > 1,
			'options' => array( 'types' => $types, 'private' => ! empty( $input['private'] ), 'comments' => ! empty( $input['comments'] ), 'scan_plugins' => ! empty( $input['scan_plugins'] ), 'scan_sources' => ! empty( $input['scan_sources'] ), 'scan_render' => ! empty( $input['scan_render'] ), 'selected_meta' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $input['selected_meta'] ?? array() ) ) ) ), 'labels' => array_map( 'sanitize_text_field', (array) ( $input['labels'] ?? array() ) ) ),
			'uploads' => array_intersect_key( (array) wp_get_upload_dir(), array_flip( array( 'basedir', 'baseurl' ) ) ),
			'record_scope' => Inventory::scope( $types, array_filter( array_map( 'sanitize_text_field', (array) ( $input['selected_meta'] ?? array() ) ) ), ! empty( $input['private'] ) ), 'records' => array(),
			'models' => array(), 'tables' => array(), 'files' => array(), 'candidates' => array(), 'counts' => array( 'posts' => 0, 'media' => 0, 'media_files' => 0, 'media_bytes' => 0, 'media_kept_remote' => 0, 'comments' => 0, 'warnings' => 0 ),
		);
		$site = array( 'url' => home_url( '/' ), 'title' => get_bloginfo( 'name' ), 'description' => get_bloginfo( 'description' ), 'language' => $locale, 'base_site_url' => site_url( '/' ), 'base_blog_url' => home_url( '/' ), 'generator' => 'WordPress/' . get_bloginfo( 'version' ), 'export_date' => $job['created_at'] );
		Models::file( $job, 'bridge/site.json', Policy::json( $site ) );
		Models::file( $job, 'bridge/options.json', Policy::json( Exporter::options() ) );
		// Site-wide SEO, redirect and address rules: small, and read once, inside the snapshot.
		Models::file( $job, 'bridge/seo.json', Policy::json( Seo::document() ) );
		Models::file( $job, 'bridge/redirects.json', Policy::json( Redirects::document() ) );
		Models::file( $job, 'bridge/routing.json', Policy::json( Routing::document() ) );
		Models::model( $job, 'site', 'singleton', 'site', 'Site', array( 'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'text' ), 'source_url' => array( 'type' => 'url' ) ), 'title', false );
		Models::entry( $job, 'site', $locale, '', array( 'title' => $site['title'], 'description' => $site['description'], 'source_url' => $site['url'] ) );
		$menus = Exporter::menus();
		Models::file( $job, 'bridge/raw-menus.json', Policy::json( $menus ) );
		Models::menus( $job, $menus, $inventory['menu_locations'] );
		foreach ( $menus as $menu ) {
			Coverage::tally( $job, array( 'menu_items' ), 'exported', count( $menu['items'] ) );
		}
		self::warning( $job, array( 'source' => 'rendered-states', 'reason' => 'Source scan does not execute dynamic WordPress/plugin states. Rendered coverage requires the Migrate capture adapter.' ) );
		self::save( $job );
		update_user_meta( get_current_user_id(), 'contentrain_bridge_job_' . get_current_blog_id(), $id );
		return self::summary( $job );
	}

	public static function read( $id ) {
		$job = json_decode( Files::read( Files::dir( $id ), 'state.json' ), true );
		if ( ! is_array( $job ) || (int) $job['owner'] !== get_current_user_id() || (int) $job['blog'] !== get_current_blog_id() ) {
			throw new \RuntimeException( 'Export is not owned by the current user.' );
		}
		if ( strtotime( $job['created_at'] ) < time() - DAY_IN_SECONDS ) {
			throw new \RuntimeException( 'Export expired. Delete it and start again.' );
		}
		return $job;
	}

	public static function save( $job ) {
		if ( count( $job['files'] ) > 50000 ) {
			throw new \RuntimeException( 'Export exceeds 50,000 files; reduce its scope.' );
		}
		Files::put( Files::dir( $job['id'] ), 'state.json', Policy::json( $job ) );
	}

	/** Also permits deleting an expired job, but never races an active writer. */
	public static function delete( $id ) {
		$key = 'contentrain_bridge_job_' . get_current_blog_id();
		if ( get_user_meta( get_current_user_id(), $key, true ) !== $id ) {
			throw new \RuntimeException( 'Export is not owned by the current user.' );
		}
		$dir = Files::dir( $id );
		if ( is_dir( $dir ) ) {
			$lock = fopen( $dir . '/lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native advisory locking has no WP_Filesystem equivalent.
			if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				if ( $lock ) {
					fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
				}
				throw new \RuntimeException( 'Export is busy. Stop the running operation and retry deletion.' );
			}
			try {
				Files::remove( $dir );
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native advisory lock handle.
			}
		}
		delete_user_meta( get_current_user_id(), $key );
		return array( 'deleted' => true );
	}

	/** Serialize all mutations, including repeated or concurrent browser requests. */
	public static function mutate( $id, $callback ) {
		self::read( $id );
		$lock = fopen( Files::dir( $id ) . '/lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream required for bounded output or advisory locking; WP_Filesystem has no equivalent.
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream required for bounded output or advisory locking; WP_Filesystem has no equivalent.
			}
			throw new \RuntimeException( 'Export is busy. Retry this step.' );
		}
		try {
			$job = self::read( $id );
			$result = $callback( $job );
			self::save( $job );
			return $result ?? self::summary( $job );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream required for bounded output or advisory locking; WP_Filesystem has no equivalent.
		}
	}

	public static function step( $id, $expected ) {
		return self::mutate( $id, static function ( &$job ) use ( $expected ) {
			if ( (int) $expected !== $job['step'] ) {
				return self::summary( $job );
			}
			if ( $job['revision'] !== Source::revision() ) {
				throw new \RuntimeException( 'WordPress content changed during export' . ( Source::changed_by() ? ' (' . Source::changed_by() . ')' : '' ) . '. Restart to obtain a consistent snapshot.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
			}
			if ( 'media' === $job['phase'] ) {
				self::media( $job );
			} elseif ( 'posts' === $job['phase'] ) {
				self::posts( $job );
			} elseif ( 'terms' === $job['phase'] ) {
				self::terms( $job );
			} elseif ( 'inventory' === $job['phase'] ) {
				self::inventory( $job );
			} elseif ( 'comments' === $job['phase'] ) {
				self::comments( $job );
			} elseif ( 'sources' === $job['phase'] ) {
				self::sources( $job );
			} elseif ( 'tables' === $job['phase'] ) {
				$paths = array_keys( $job['tables'] );
				if ( isset( $paths[ $job['cursor'] ] ) ) {
					Models::table( $job, $paths[ $job['cursor']++ ] );
				} else {
					self::finish( $job );
				}
			}
			if ( $job['revision'] !== Source::revision() ) {
				throw new \RuntimeException( 'WordPress content changed during export' . ( Source::changed_by() ? ' (' . Source::changed_by() . ')' : '' ) . '. Restart to obtain a consistent snapshot.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
			}
			++$job['step'];
		} );
	}

	/**
	 * Media first: a post body can only be relinked to `media/...` once the file
	 * it points at is actually in the export. Original and generated sizes are
	 * both copied, because a theme references the sizes, not the original.
	 */
	private static function media( &$job ) {
		global $wpdb;
		if ( ! in_array( 'attachment', $job['options']['types'], true ) ) {
			self::warning( $job, array( 'source' => 'media', 'reason' => 'attachments-not-selected: content keeps WordPress URLs' ) );
			$job['phase'] = 'posts';
			$job['cursor'] = 0;
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' ORDER BY ID ASC LIMIT 25", $job['cursor'] ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot enumerate media.' );
		}
		$basedir = $job['uploads']['basedir'] ?? '';
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			$job['cursor'] = (int) $id;
			if ( ! $p ) {
				throw new \RuntimeException( 'Source attachment disappeared during export.' );
			}
			$parent = $p->post_parent ? get_post( $p->post_parent ) : null;
			$reason = 'trash' === $p->post_status ? 'trash' : null;
			if ( ! $reason && ! $job['options']['private'] ) {
				$reason = ! in_array( $p->post_status, array( 'publish', 'inherit' ), true ) ? 'status-scope' : ( $p->post_password ? 'password-protected' : ( $parent && ( 'publish' !== $parent->post_status || $parent->post_password ) ? 'parent-not-public' : null ) );
			}
			if ( $reason ) {
				Coverage::tally( $job, array( 'posts', 'attachment', $p->post_status ), 'excluded:' . $reason );
				self::warning( $job, array( 'source' => 'attachment/' . $id, 'reason' => 'excluded-by-status-scope' ) );
				continue;
			}
			Coverage::tally( $job, array( 'posts', 'attachment', $p->post_status ), 'exported' );
			foreach ( array_keys( get_post_meta( $id ) ) as $key ) {
				Coverage::tally( $job, array( 'attachment_meta' ), in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ), true ) ? 'exported' : ( preg_match( Coverage::INTERNAL_META, $key ) ? 'excluded:wordpress-internal' : 'excluded:attachment-meta-not-read' ) );
			}
			$a = Exporter::map_attachment( $p );
			$image_meta = (array) $a['image_meta'];
			$a['image_meta'] = array_intersect_key( $image_meta, array_flip( array( 'width', 'height', 'sizes', 'file' ) ) );
			Models::row( $job, 'bridge/raw-attachments.json', $id, $a );
			Models::model( $job, 'wp-media', 'collection', 'assets', 'Media', array( 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'url' ), 'file' => array( 'type' => 'file' ), 'alt' => array( 'type' => 'text' ), 'caption' => array( 'type' => 'richtext' ), 'description' => array( 'type' => 'richtext' ) ), 'title', false );
			$data = array_intersect_key( $a, array_flip( array( 'title', 'alt', 'caption', 'description' ) ) );
			if ( $a['url'] ) {
				$data['url'] = $a['url'];
			}
			$relative = ltrim( (string) $a['file'], '/' );
			$stored = null;
			if ( $relative && $basedir ) {
				$stored = self::transfer( $job, $basedir, $relative );
				// Generated sizes live beside the original and are what a theme
				// actually renders; without them a relinked page loads nothing.
				foreach ( (array) ( $image_meta['sizes'] ?? array() ) as $size ) {
					if ( ! empty( $size['file'] ) ) {
						if ( ! self::transfer( $job, $basedir, dirname( $relative ) . '/' . $size['file'] ) ) {
							++$job['counts']['media_kept_remote'];
						}
					}
				}
			}
			if ( $stored ) {
				$data['file'] = $stored;
			} else {
				++$job['counts']['media_kept_remote'];
			}
			Models::entry( $job, 'wp-media', $job['default_locale'], substr( hash( 'sha256', 'media:' . $id ), 0, 12 ), $data );
			++$job['counts']['media'];
		}
		if ( count( $ids ) < 25 ) {
			$job['phase'] = 'posts';
			$job['cursor'] = 0;
		}
	}

	/** Copy one upload into the export; returns its stored path, or null with a reason. */
	private static function transfer( &$job, $basedir, $relative ) {
		$relative = preg_replace( '#^\./#', '', $relative );
		$path = self::media_path( $relative );
		if ( isset( $job['files'][ $path ] ) ) {
			return $path;
		}
		$source = $basedir . '/' . $relative;
		$real = realpath( $source );
		if ( ! $real || 0 !== strpos( $real, realpath( $basedir ) . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || is_link( $source ) ) {
			self::warning( $job, array( 'source' => $path, 'reason' => 'media-file-missing-or-outside-uploads' ) );
			return null;
		}
		$size = (int) filesize( $real );
		if ( $size > Policy::MAX_FILE ) {
			self::warning( $job, array( 'source' => $path, 'reason' => 'media-over-8MiB: kept as a WordPress URL' ) );
			return null;
		}
		if ( $job['counts']['media_bytes'] + $size > Policy::MAX_MEDIA_TOTAL ) {
			self::warning( $job, array( 'source' => $path, 'reason' => 'media-budget-exhausted: kept as a WordPress URL' ) );
			return null;
		}
		$job['counts']['media_bytes'] += Models::binary( $job, $path, $real );
		++$job['counts']['media_files'];
		$job['media_paths'][ $relative ] = $path;
		return $path;
	}

	/** Where an upload is stored in the export; a name outside the safe set is hashed. */
	public static function media_path( $relative ) {
		$relative = preg_replace( '#^\./#', '', (string) $relative );
		if ( preg_match( '#^[a-zA-Z0-9_.\-/]+$#D', $relative ) ) {
			return 'media/' . $relative;
		}
		$extension = preg_replace( '/[^a-z0-9]/', '', strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) );
		return 'media/file-' . substr( hash( 'sha256', $relative ), 0, 24 ) . ( $extension ? '.' . $extension : '' );
	}

	private static function posts( &$job ) {
		global $wpdb;
		$types = $job['options']['types'];
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = array_merge( array( $job['cursor'] ), $types );
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($placeholders) ORDER BY ID ASC LIMIT 25";
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list contains no user data.
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot enumerate WordPress content.' );
		}
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			$job['cursor'] = (int) $id;
			if ( ! $p ) {
				throw new \RuntimeException( 'Source post disappeared during export.' );
			}
			if ( 'attachment' === $p->post_type ) {
				continue; // Handled, and counted, in the media phase, before any content can link to it.
			}
			$reason = in_array( $p->post_status, array( 'auto-draft', 'trash' ), true ) ? $p->post_status : null;
			if ( ! $reason && ! $job['options']['private'] ) {
				$reason = $p->post_password ? 'password-protected' : ( in_array( $p->post_status, array( 'publish', 'inherit' ), true ) ? null : 'status-scope' );
			}
			if ( $reason ) {
				Coverage::tally( $job, array( 'posts', $p->post_type, $p->post_status ), 'excluded:' . $reason );
				self::warning( $job, array( 'source' => 'post/' . $id, 'reason' => 'excluded-by-status-scope' ) );
				continue;
			}
			$excluded = array();
			$record = Source::post( $p, $job['options']['selected_meta'], $excluded );
			Coverage::tally( $job, array( 'posts', $p->post_type, $p->post_status ), 'exported' );
			Coverage::post_meta( $job, $id, $record, $excluded );
			foreach ( $excluded as $warning ) {
				self::warning( $job, $warning );
			}
			Models::post( $job, $record );
			$seo = Seo::post( $p );
			if ( $seo ) {
				Models::row( $job, 'bridge/seo-entries.json', 'post:' . $id, $seo );
			}
			++$job['counts']['posts'];
		}
		if ( count( $ids ) < 25 ) {
			$job['phase'] = 'terms';
			$job['cursor'] = 0;
		}
	}

	private static function terms( &$job ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d ORDER BY term_taxonomy_id LIMIT 50", $job['cursor'] ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot enumerate taxonomies.' );
		}
		foreach ( $rows as $row ) {
			$id = (int) $row->term_taxonomy_id;
			// A plugin that registers a taxonomy only while active (Polylang's
			// `language`, `term_language`, `term_translations`) can leave its rows
			// behind after deactivation; `get_term_by()` cannot resolve a term
			// against a taxonomy nothing currently registers, so check first
			// rather than let a stale row fail the safety check below.
			if ( ! get_taxonomy( $row->taxonomy ) ) {
				$job['cursor'] = $id;
				continue;
			}
			$t = get_term_by( 'term_taxonomy_id', $id );
			if ( ! $t || is_wp_error( $t ) ) {
				throw new \RuntimeException( 'Cannot read taxonomy term.' );
			}
			// A registered but non-public taxonomy (e.g. `nav_menu`) is not content.
			if ( get_taxonomy( $t->taxonomy )->public ) {
				Models::term( $job, $t, $job['default_locale'] );
				Coverage::tally( $job, array( 'terms', $t->taxonomy ), 'exported' );
				foreach ( get_term_meta( $t->term_id ) as $key => $values ) {
					Coverage::tally( $job, array( 'term_meta' ), Policy::sensitive( $key ) ? 'excluded:sensitive-key' : 'exported', count( (array) $values ) );
				}
				$seo = Seo::term( $t );
				if ( $seo ) {
					Models::row( $job, 'bridge/seo-entries.json', 'term:' . $t->taxonomy . ':' . $t->term_id, $seo );
				}
			}
			$job['cursor'] = $id;
		}
		if ( count( $rows ) < 50 ) {
			$job['phase'] = 'inventory';
			$job['cursor'] = 0;
			$job['inventory_cursor'] = Inventory::start();
		}
	}

	/**
	 * Every record in scope, every status: the cursor the next export's delta is
	 * measured from. Taken inside the same revision-checked snapshot as the content.
	 */
	private static function inventory( &$job ) {
		list( $records, $job['inventory_cursor'] ) = Inventory::page( $job['record_scope'], $job['inventory_cursor'] );
		foreach ( $records as $record ) {
			$job['records'][ Inventory::key( $record ) ] = $record;
		}
		if ( 'done' === $job['inventory_cursor']['stage'] ) {
			unset( $job['inventory_cursor'] );
			$job['phase'] = 'comments';
			$job['cursor'] = 0;
		}
	}

	private static function comments( &$job ) {
		global $wpdb;
		$ids = $job['options']['comments'] ? $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d ORDER BY comment_ID LIMIT 50", $job['cursor'] ) ) : array();
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot enumerate comments.' );
		}
		foreach ( $ids as $id ) {
			$c = get_comment( $id );
			$job['cursor'] = (int) $id;
			if ( ! isset( $job['tables']['bridge/entry-source-map.json'][ $c->comment_post_ID ] ) ) {
				Coverage::tally( $job, array( 'comments', (string) $c->comment_approved ), 'excluded:post-not-in-scope' );
				self::warning( $job, array( 'source' => 'comment/' . $id, 'reason' => 'post-not-in-scope' ) );
				continue;
			}
			Coverage::tally( $job, array( 'comments', (string) $c->comment_approved ), 'exported' );
			$data = array( 'id' => (int) $id, 'post' => (int) $c->comment_post_ID, 'post_type' => get_post_type( $c->comment_post_ID ), 'parent' => (int) $c->comment_parent ?: null, 'parent_resolved' => ! $c->comment_parent || null !== get_comment( $c->comment_parent ), 'author' => $c->comment_author, 'url' => $c->comment_author_url ?: null, 'date' => Exporter::utc_date( $c->comment_date_gmt ), 'content' => $c->comment_content, 'approved' => $c->comment_approved, 'type' => $c->comment_type, 'user_id' => null, 'meta' => (object) array() );
			Models::row( $job, 'bridge/raw-comments.json', $id, $data );
			++$job['counts']['comments'];
		}
		if ( count( $ids ) < 50 ) {
			$job['phase'] = 'sources';
			$job['cursor'] = 0;
			$job['source_files'] = $job['options']['scan_sources'] ? Scanner::files( $job['options']['scan_plugins'] ) : array();
		}
	}

	/**
	 * Interface text, one bounded step at a time: each source file, then site
	 * settings (options, widgets, menus, Customizer), then each render state.
	 * Occurrences are merged into candidates only when every source is read, so
	 * a candidate lists all its occurrences and carries one outcome.
	 */
	private static function sources( &$job ) {
		if ( ! $job['options']['scan_sources'] ) {
			$job['phase'] = 'review';
			$job['cursor'] = 0;
			return;
		}
		$files = $job['source_files'];
		$states = $job['options']['scan_render'] ? ( $job['render_states'] = $job['render_states'] ?? Text::render_states() ) : array();
		$step = $job['cursor'];
		$job['text'] = $job['text'] ?? array( 'occurrences' => array(), 'errors' => array(), 'counts' => array( 'unlisted_excluded' => 0, 'unlisted_by_reason' => array(), 'sources' => array( 'files' => count( $files ), 'settings' => 1, 'render_states' => count( $states ) ) ) );
		if ( $step < count( $files ) ) {
			$result = Scanner::scan( $files[ $step ], $job['default_locale'] );
		} elseif ( $step === count( $files ) ) {
			$result = Text::settings( $job['default_locale'] );
		} elseif ( $step <= count( $files ) + count( $states ) ) {
			$state = array_keys( $states )[ $step - count( $files ) - 1 ];
			$result = Text::render( $state, $states[ $state ], $job['default_locale'] );
		} else {
			$job['candidates'] = Text::merge( $job['text']['occurrences'], Text::content_texts() );
			if ( count( $job['candidates'] ) > 20000 ) {
				throw new \RuntimeException( 'More than 20,000 text candidates. Select a smaller source scope.' );
			}
			$job['phase'] = 'review';
			$job['cursor'] = 0;
			return;
		}
		foreach ( array_merge( $result['candidates'], $result['excluded'] ) as $occurrence ) {
			$job['text']['occurrences'][] = $occurrence;
		}
		// Beyond the per-file listing cap, excluded occurrences are counted by reason, never dropped.
		foreach ( $result['excluded_counts'] as $reason => $count ) {
			$listed = count( array_filter( $result['excluded'], static function ( $o ) use ( $reason ) { return $reason === $o['reason']; } ) );
			if ( $count > $listed ) {
				$job['text']['counts']['unlisted_excluded'] += $count - $listed;
				$job['text']['counts']['unlisted_by_reason'][ $reason ] = ( $job['text']['counts']['unlisted_by_reason'][ $reason ] ?? 0 ) + $count - $listed;
			}
		}
		foreach ( $result['errors'] as $error ) {
			$job['text']['errors'][] = $error;
			self::warning( $job, array( 'source' => $error['source'], 'reason' => 'text-scan-error: ' . $error['reason'] ) );
		}
		if ( count( $job['text']['occurrences'] ) > 100000 ) {
			throw new \RuntimeException( 'More than 100,000 text occurrences. Select a smaller source scope.' );
		}
		++$job['cursor'];
	}

	public static function review( $id, $decisions, $finish = false ) {
		return self::mutate( $id, static function ( &$job ) use ( $decisions, $finish ) {
			if ( 'review' !== $job['phase'] ) {
				throw new \RuntimeException( 'Export is not awaiting model/text review.' );
			}
			foreach ( $decisions as $id => $decision ) {
				if ( ! isset( $job['candidates'][ $id ] ) || ! in_array( $decision['decision'] ?? '', array( 'include', 'exclude' ), true ) ) {
					throw new \RuntimeException( 'Invalid text review decision.' );
				}
				$candidate = &$job['candidates'][ $id ];
				// A secret is recorded redacted and a dynamic argument is code: neither is text to keep.
				if ( 'include' === $decision['decision'] && preg_match( '/(^|,)(secret|dynamic)(,|$)/', (string) ( $candidate['reason'] ?? '' ) ) ) {
					throw new \RuntimeException( 'This candidate cannot be included: ' . $candidate['reason'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
				}
				$key = $decision['key'] ?? $candidate['key'];
				if ( ! preg_match( '/^[a-z][a-z0-9_.-]{1,120}$/D', $key ) ) {
					throw new \RuntimeException( 'Invalid dictionary key.' );
				}
				$candidate['key'] = $key;
				$candidate['decision'] = $decision['decision'];
				unset( $candidate );
			}
			if ( $finish ) {
				$seen = array();
				$settings = array();
				foreach ( $job['candidates'] as $candidate ) {
					if ( 'review' === $candidate['decision'] ) {
						throw new \RuntimeException( 'Review every text candidate before finalizing.' );
					}
					$target = $candidate['target'] ?? 'dictionary:ui-strings';
					if ( 'include' === $candidate['decision'] && 0 === strpos( $target, 'theme-settings.' ) ) {
						$settings[ $candidate['locale'] ][ substr( $target, 15 ) ] = $candidate['value'];
					} elseif ( 'include' === $candidate['decision'] && 0 === strpos( $target, 'dictionary:' ) ) {
						$key = $candidate['locale'] . ':' . $candidate['key'];
						if ( isset( $seen[ $key ] ) && $seen[ $key ] !== $candidate['value'] ) {
							throw new \RuntimeException( 'Different strings cannot use the same dictionary key.' );
						}
						$seen[ $key ] = $candidate['value'];
						Models::model( $job, 'ui-strings', 'dictionary', 'system', 'Interface text', array(), 'key' );
						Models::entry( $job, 'ui-strings', $candidate['locale'], $candidate['key'], $candidate['value'] );
					}
					// Site title/description and menu labels are already exported as content; nothing more to write.
					Models::row( $job, 'bridge/string-sources.json', $candidate['id'], $candidate );
				}
				// Customizer text becomes one editable singleton, a field per setting.
				foreach ( $settings as $locale => $values ) {
					ksort( $values );
					Models::model( $job, 'theme-settings', 'singleton', 'system', 'Theme settings', array_map( static function () { return array( 'type' => 'string' ); }, $values ), array_keys( $values )[0] );
					Models::entry( $job, 'theme-settings', $locale, '', $values );
				}
				if ( isset( $job['text'] ) ) {
					Models::file( $job, 'bridge/hardcoded-text.json', Policy::json( Text::document( $job['candidates'], $job['text']['errors'], $job['text']['counts'] ) ) );
				}
				foreach ( $job['models'] as $model ) {
					Models::file( $job, '.contentrain/models/' . $model['id'] . '.json', Policy::json( $model, Policy::MODEL_ORDER ) );
					if ( in_array( $model['kind'], array( 'collection', 'dictionary' ), true ) ) {
						foreach ( array_keys( $job['locales'] ) as $locale ) {
							$path = Models::content_path( $job, $model, $locale );
							if ( ! isset( $job['tables'][ $path ] ) ) {
								Models::file( $job, $path, "{}\n" );
								Models::file( $job, Models::meta_path( $job, $model, $locale ), Policy::json( 'dictionary' === $model['kind'] ? Models::meta() : (object) array() ) );
							}
						}
					}
				}
				$domains = array_values( array_unique( array_column( $job['models'], 'domain' ) ) );
				sort( $domains );
				Models::file( $job, '.contentrain/config.json', Policy::json( array( 'version' => 1, 'stack' => 'other', 'platform' => 'web', 'workflow' => 'review', 'locales' => array( 'default' => $job['default_locale'], 'supported' => array_keys( $job['locales'] ) ), 'domains' => $domains ) ) );
				Models::file( $job, '.contentrain/vocabulary.json', Policy::json( array( 'version' => 1, 'terms' => (object) array() ) ) );
				$job['phase'] = 'tables';
				$job['cursor'] = 0;
			}
		} );
	}

	private static function finish( &$job ) {
		Models::file( $job, 'bridge/validation.json', Policy::json( Validator::run( $job ) ) );
		Models::file( $job, 'CONTENTRAIN-EXPORT.md', "# Contentrain WordPress export\n\nFree, editable JSON and Markdown content. Models live in `.contentrain/models`.\n\nMarkdown bodies preserve the original WordPress HTML; shortcodes and dynamic blocks require a renderer. Source files and the live WordPress theme have not been rewritten. Transferred media lives under `media/` and content links to it; anything too large or unreadable kept its WordPress URL and is listed in the warnings. Review `bridge/warnings.json` and `bridge/string-sources.json` for scope and text decisions.\n\nUse Contentrain Studio or the query SDK to edit/read this store. For an Astro website, choose the optional Migrate handoff from WordPress after delivery.\n" );
		Models::file( $job, 'bridge/inventory.json', Policy::json( Inventory::document( $job['record_scope'], $job['records'], $job['created_at'], true, $job['inventory'] ) ) );
		$coverage = Coverage::report( $job );
		Models::file( $job, 'bridge/coverage.json', Policy::json( $coverage ) );
		$job['coverage_summary'] = $coverage['totals'] + array( 'complete' => $coverage['complete'] );
		self::manifest( $job );
		$job['phase'] = 'ready';
		$job['cursor'] = 0;
	}

	/** The manifest lists every other file; rewritten when delivery adds one. */
	public static function manifest( &$job ) {
		unset( $job['files']['bridge/manifest.json'] );
		$manifest = array( 'format' => 'contentrain-bridge@1', 'version' => CONTENTRAIN_BRIDGE_VERSION, 'snapshot' => $job['id'], 'site' => $job['inventory']['site'], 'created_at' => $job['created_at'], 'scope' => $job['options'], 'counts' => $job['counts'], 'files' => $job['files'], 'media' => array( 'transferred_files' => $job['counts']['media_files'], 'transferred_bytes' => $job['counts']['media_bytes'], 'kept_as_source_url' => $job['counts']['media_kept_remote'], 'stored_under' => 'media/', 'note' => $job['counts']['media_kept_remote'] ? 'Some media still points at WordPress; see bridge/warnings.json' : 'All exported media travels with the content' ), 'source_reuse' => 'not-applied', 'complete_source_coverage' => false, 'coverage' => $job['coverage_summary'] ?? null );
		Models::file( $job, 'bridge/manifest.json', Policy::json( $manifest ) );
	}

	public static function warning( &$job, $warning ) {
		Models::row( $job, 'bridge/warnings.json', substr( hash( 'sha256', Policy::json( $warning ) ), 0, 24 ), $warning );
		++$job['counts']['warnings'];
	}

	public static function summary( $job ) {
		$result = array_intersect_key( $job, array_flip( array( 'id', 'phase', 'cursor', 'step', 'counts', 'created_at' ) ) ) + array( 'files' => count( $job['files'] ), 'models' => array_values( $job['models'] ), 'candidates' => count( $job['candidates'] ), 'unreviewed' => count( array_filter( $job['candidates'], static function ( $candidate ) { return 'review' === $candidate['decision']; } ) ) );
		if ( isset( $job['coverage_summary'] ) ) {
			$result['coverage'] = $job['coverage_summary'];
		}
		if ( isset( $job['github'] ) ) {
			$result['github'] = array_intersect_key( $job['github'], array_flip( array( 'repository', 'branch', 'phase', 'cursor', 'commit', 'url', 'removed', 'conflicts', 'on_conflict' ) ) );
		}
		return $result;
	}
}
