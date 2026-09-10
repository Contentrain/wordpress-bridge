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
		if ( ! mkdir( $dir, 0700 ) ) {
			throw new \RuntimeException( 'Cannot create export job.' );
		}
		$locale = Source::locale( get_locale() );
		// One language means a store without locale-named files; the decision is
		// taken here because it selects the paths every later step writes to.
		$languages = array_values( array_unique( array_map( array( Source::class, 'locale' ), (array) $inventory['languages'] ) ) );
		$job = array(
			'id' => $id, 'owner' => get_current_user_id(), 'blog' => get_current_blog_id(), 'created_at' => gmdate( 'c' ),
			'revision' => Source::revision(), 'phase' => 'posts', 'cursor' => 0, 'step' => 0,
			'inventory' => $inventory, 'default_locale' => $locale, 'locales' => array( $locale => true ), 'i18n' => count( $languages ) > 1,
			'options' => array( 'types' => $types, 'private' => ! empty( $input['private'] ), 'comments' => ! empty( $input['comments'] ), 'scan_plugins' => ! empty( $input['scan_plugins'] ), 'scan_sources' => ! empty( $input['scan_sources'] ), 'selected_meta' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $input['selected_meta'] ?? array() ) ) ) ), 'labels' => array_map( 'sanitize_text_field', (array) ( $input['labels'] ?? array() ) ) ),
			'models' => array(), 'tables' => array(), 'files' => array(), 'candidates' => array(), 'counts' => array( 'posts' => 0, 'media' => 0, 'comments' => 0, 'warnings' => 0 ),
		);
		$site = array( 'url' => home_url( '/' ), 'title' => get_bloginfo( 'name' ), 'description' => get_bloginfo( 'description' ), 'language' => $locale, 'base_site_url' => site_url( '/' ), 'base_blog_url' => home_url( '/' ), 'generator' => 'WordPress/' . get_bloginfo( 'version' ), 'export_date' => $job['created_at'] );
		Models::file( $job, 'bridge/site.json', Policy::json( $site ) );
		Models::file( $job, 'bridge/inventory.json', Policy::json( $inventory ) );
		Models::file( $job, 'bridge/options.json', Policy::json( Exporter::options() ) );
		Models::model( $job, 'site', 'singleton', 'site', 'Site', array( 'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'text' ), 'source_url' => array( 'type' => 'url' ) ) );
		Models::entry( $job, 'site', $locale, '', array( 'title' => $site['title'], 'description' => $site['description'], 'source_url' => $site['url'] ) );
		$menus = Exporter::menus();
		Models::file( $job, 'bridge/raw-menus.json', Policy::json( $menus ) );
		Models::model( $job, 'wp-menu-items', 'collection', 'site', 'Navigation links', array( 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'url' ), 'position' => array( 'type' => 'integer' ), 'menu' => array( 'type' => 'string' ), 'wp_parent' => array( 'type' => 'integer' ) ) );
		foreach ( $menus as $menu ) {
			foreach ( $menu['items'] as $item ) {
				Models::entry( $job, 'wp-menu-items', $locale, substr( hash( 'sha256', 'menu:' . $item['id'] ), 0, 12 ), array( 'title' => $item['title'], 'url' => $item['url'], 'position' => $item['order'], 'menu' => $menu['name'], 'wp_parent' => (int) $item['parent'] ) );
			}
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

	/** Serialize all mutations, including repeated or concurrent browser requests. */
	public static function mutate( $id, $callback ) {
		self::read( $id );
		$lock = fopen( Files::dir( $id ) . '/lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock );
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
			fclose( $lock );
		}
	}

	public static function step( $id, $expected ) {
		return self::mutate( $id, static function ( &$job ) use ( $expected ) {
			if ( (int) $expected !== $job['step'] ) {
				return self::summary( $job );
			}
			if ( $job['revision'] !== Source::revision() ) {
				throw new \RuntimeException( 'WordPress content changed during export. Restart to obtain a consistent snapshot.' );
			}
			if ( 'posts' === $job['phase'] ) {
				self::posts( $job );
			} elseif ( 'terms' === $job['phase'] ) {
				self::terms( $job );
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
			++$job['step'];
		} );
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
			if ( in_array( $p->post_status, array( 'auto-draft', 'trash' ), true ) || ( ! $job['options']['private'] && ( $p->post_password || ! in_array( $p->post_status, array( 'publish', 'inherit' ), true ) ) ) ) {
				self::warning( $job, array( 'source' => 'post/' . $id, 'reason' => 'excluded-by-status-scope' ) );
				continue;
			}
			if ( 'attachment' === $p->post_type ) {
				$a = Exporter::map_attachment( $p );
				$a['image_meta'] = array_intersect_key( (array) $a['image_meta'], array_flip( array( 'width', 'height', 'sizes', 'file' ) ) );
				Models::row( $job, 'bridge/raw-attachments.json', $id, $a );
				Models::model( $job, 'wp-media', 'collection', 'assets', 'Media', array( 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'url' ), 'alt' => array( 'type' => 'text' ), 'caption' => array( 'type' => 'richtext' ), 'description' => array( 'type' => 'richtext' ) ) );
				$data = array_intersect_key( $a, array_flip( array( 'title', 'alt', 'caption', 'description' ) ) );
				if ( $a['url'] ) {
					$data['url'] = $a['url'];
				}
				Models::entry( $job, 'wp-media', $job['default_locale'], substr( hash( 'sha256', 'media:' . $id ), 0, 12 ), $data );
				++$job['counts']['media'];
				continue;
			}
			$excluded = array();
			$record = Source::post( $p, $job['options']['selected_meta'], $excluded );
			foreach ( $excluded as $warning ) {
				self::warning( $job, $warning );
			}
			Models::post( $job, $record );
			++$job['counts']['posts'];
		}
		if ( count( $ids ) < 25 ) {
			$job['phase'] = 'terms';
			$job['cursor'] = 0;
		}
	}

	private static function terms( &$job ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d ORDER BY term_taxonomy_id LIMIT 50", $job['cursor'] ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Cannot enumerate taxonomies.' );
		}
		foreach ( $ids as $id ) {
			$t = get_term_by( 'term_taxonomy_id', $id );
			if ( ! $t || is_wp_error( $t ) ) {
				throw new \RuntimeException( 'Cannot read taxonomy term.' );
			}
			Models::term( $job, $t, $job['default_locale'] );
			$job['cursor'] = (int) $id;
		}
		if ( count( $ids ) < 50 ) {
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
				self::warning( $job, array( 'source' => 'comment/' . $id, 'reason' => 'post-not-in-scope' ) );
				continue;
			}
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

	private static function sources( &$job ) {
		$file = $job['source_files'][ $job['cursor'] ] ?? null;
		if ( $file ) {
			$result = Scanner::scan( $file, $job['default_locale'] );
			foreach ( $result['candidates'] as $candidate ) {
				$job['candidates'][ $candidate['id'] ] = $candidate;
			}
			foreach ( $result['warnings'] as $warning ) {
				self::warning( $job, $warning );
			}
			if ( count( $job['candidates'] ) > 20000 ) {
				throw new \RuntimeException( 'More than 20,000 text candidates. Select a smaller source scope.' );
			}
			++$job['cursor'];
		} else {
			$job['phase'] = 'review';
			$job['cursor'] = 0;
		}
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
				foreach ( $job['candidates'] as $candidate ) {
					if ( 'review' === $candidate['decision'] ) {
						throw new \RuntimeException( 'Review every text candidate before finalizing.' );
					}
					if ( 'include' === $candidate['decision'] ) {
						$key = $candidate['locale'] . ':' . $candidate['key'];
						if ( isset( $seen[ $key ] ) && $seen[ $key ] !== $candidate['value'] ) {
							throw new \RuntimeException( 'Different strings cannot use the same dictionary key.' );
						}
						$seen[ $key ] = $candidate['value'];
						Models::model( $job, 'ui-strings', 'dictionary', 'system', 'Interface text', array(), 'key' );
						Models::entry( $job, 'ui-strings', $candidate['locale'], $candidate['key'], $candidate['value'] );
					}
					Models::row( $job, 'bridge/string-sources.json', $candidate['id'], $candidate );
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
		$manifest = array( 'format' => 'contentrain-bridge@1', 'version' => CONTENTRAIN_BRIDGE_VERSION, 'snapshot' => $job['id'], 'site' => $job['inventory']['site'], 'created_at' => $job['created_at'], 'scope' => $job['options'], 'counts' => $job['counts'], 'files' => $job['files'], 'media' => 'source-urls; binaries have not been transferred', 'source_reuse' => 'not-applied', 'complete_source_coverage' => false );
		Models::file( $job, 'bridge/manifest.json', Policy::json( $manifest ) );
		Models::file( $job, 'CONTENTRAIN-EXPORT.md', "# Contentrain WordPress export\n\nFree, editable JSON and Markdown content. Models live in `.contentrain/models`.\n\nMarkdown bodies preserve the original WordPress HTML; shortcodes and dynamic blocks require a renderer. Source files and the live WordPress theme have not been rewritten. Media still uses source URLs. Review `bridge/warnings.json` and `bridge/string-sources.json` for scope and text decisions.\n\nUse Contentrain Studio or the query SDK to edit/read this store. For an Astro website, choose the optional Migrate handoff from WordPress after delivery.\n" );
		$job['phase'] = 'ready';
		$job['cursor'] = 0;
	}

	public static function warning( &$job, $warning ) {
		Models::row( $job, 'bridge/warnings.json', substr( hash( 'sha256', Policy::json( $warning ) ), 0, 24 ), $warning );
		++$job['counts']['warnings'];
	}

	public static function summary( $job ) {
		$result = array_intersect_key( $job, array_flip( array( 'id', 'phase', 'cursor', 'step', 'counts', 'created_at' ) ) ) + array( 'files' => count( $job['files'] ), 'models' => array_values( $job['models'] ), 'candidates' => count( $job['candidates'] ), 'unreviewed' => count( array_filter( $job['candidates'], static function ( $candidate ) { return 'review' === $candidate['decision']; } ) ) );
		if ( isset( $job['github'] ) ) {
			$result['github'] = array_intersect_key( $job['github'], array_flip( array( 'repository', 'branch', 'phase', 'cursor', 'commit', 'url' ) ) );
		}
		return $result;
	}
}
