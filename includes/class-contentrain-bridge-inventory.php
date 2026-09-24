<?php
/** Per-record source inventory: the cursor a delta is measured from. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * One row per record in scope, with a fingerprint of the record as mapped.
 *
 * Deletions and moves are proven by comparing two of these, never by reading
 * a "modified since" feed: a deleted post simply stops appearing in such a
 * feed, and a meta-only edit (an ACF field) does not touch `post_modified`.
 * The inventory is delivered with the content (`bridge/inventory.json`), so
 * WordPress keeps no delta state and the cursor survives a plugin reinstall.
 */
final class Inventory {
	const FORMAT = 'contentrain-bridge-inventory@2';

	/** Records per bounded page; a page is one resumable step. */
	const PAGE = 25;

	/** Statuses whose record has a public address; everything else is redacted in a public-scope inventory. */
	const PUBLIC_STATUSES = array( 'publish', 'inherit' );

	/**
	 * What a standalone inventory enumerates: the post types and taxonomies an
	 * export writes, attachments and menu items included.
	 */
	public static function scope( $post_types = null, $selected_meta = array(), $private = true ) {
		$types = null === $post_types ? array_values( get_post_types( array( 'public' => true ), 'names' ) ) : array_values( (array) $post_types );
		// Menus are always exported, so their items are always in scope.
		$types[] = 'nav_menu_item';
		$types = array_values( array_unique( $types ) );
		sort( $types, SORT_STRING );
		$taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
		sort( $taxonomies, SORT_STRING );
		$meta = array_values( array_unique( array_map( 'strval', (array) $selected_meta ) ) );
		sort( $meta, SORT_STRING );
		return array( 'post_types' => $types, 'taxonomies' => $taxonomies, 'selected_meta' => $meta, 'private' => (bool) $private );
	}

	/** A fresh bounded-walk cursor. */
	public static function start() {
		return array( 'stage' => 'posts', 'after' => 0 );
	}

	/**
	 * One bounded page of records. Returns [ records, next cursor ]; the cursor's
	 * stage is `done` once every post and term in scope has been read.
	 */
	public static function page( $scope, $cursor ) {
		global $wpdb;
		$records = array();
		if ( 'posts' === $cursor['stage'] ) {
			$types = $scope['post_types'];
			$ids = array();
			if ( $types ) {
				$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				// Revisions are another post type and never in scope; an auto-draft is not content yet.
				$sql = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($placeholders) AND post_status <> 'auto-draft' ORDER BY ID ASC LIMIT %d";
				$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $cursor['after'] ), $types, array( self::PAGE ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholder list contains no user data; an inventory must read the table, not a cache.
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'Cannot enumerate WordPress content for the inventory.' );
				}
			}
			foreach ( $ids as $id ) {
				clean_post_cache( (int) $id );
				$post = get_post( (int) $id );
				if ( $post ) {
					$records[] = self::post_record( $post, $scope );
				}
				$cursor['after'] = (int) $id;
			}
			if ( count( $ids ) < self::PAGE ) {
				$cursor = array( 'stage' => 'terms', 'after' => 0 );
			}
			return array( $records, $cursor );
		}
		if ( 'terms' === $cursor['stage'] ) {
			$taxonomies = $scope['taxonomies'];
			$ids = array();
			if ( $taxonomies ) {
				$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
				$sql = "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND taxonomy IN ($placeholders) ORDER BY term_taxonomy_id ASC LIMIT %d";
				$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $cursor['after'] ), $taxonomies, array( self::PAGE ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Placeholder list contains no user data; an inventory must read the table, not a cache.
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'Cannot enumerate taxonomies for the inventory.' );
				}
			}
			foreach ( $ids as $id ) {
				$term = Source::term_by( 'term_taxonomy_id', (int) $id );
				if ( $term ) {
					$records[] = self::term_record( $term );
				}
				$cursor['after'] = (int) $id;
			}
			if ( count( $ids ) < self::PAGE ) {
				$cursor = array( 'stage' => 'done', 'after' => 0 );
			}
			return array( $records, $cursor );
		}
		return array( array(), $cursor );
	}

	/**
	 * Walk the whole scope in one call. `$max_records` bounds the walk; a walk
	 * cut short says so, because a partial inventory cannot prove a deletion.
	 */
	public static function build( $scope, $max_records = 0 ) {
		$records = array();
		$cursor = self::start();
		$complete = true;
		while ( 'done' !== $cursor['stage'] ) {
			list( $page, $cursor ) = self::page( $scope, $cursor );
			foreach ( $page as $record ) {
				$records[ self::key( $record ) ] = $record;
			}
			if ( $max_records && count( $records ) >= $max_records && 'done' !== $cursor['stage'] ) {
				$complete = false;
				break;
			}
		}
		return self::document( $scope, $records, gmdate( 'c' ), $complete );
	}

	/** The delivered file: records sorted by (wp_type, wp_id) and the hash over them. */
	public static function document( $scope, $records, $taken_at, $complete = true, $environment = null ) {
		$records = array_values( $records );
		usort( $records, array( self::class, 'compare' ) );
		$document = array(
			'format'              => self::FORMAT,
			'taken_at'            => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $taken_at ) ),
			'scope'               => $scope,
			'complete'            => (bool) $complete,
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'records'             => $records,
			'inventory_hash'      => self::hash( $records ),
		);
		if ( null !== $environment ) {
			$document['environment'] = $environment;
		}
		return $document;
	}

	/** Add a page of records to the job's inventory file, one compact JSON line each. */
	public static function append( $file, $records ) {
		if ( ! $records ) {
			return;
		}
		$lines = '';
		foreach ( $records as $record ) {
			$lines .= wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		}
		if ( false === file_put_contents( $file, $lines, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append-only journal; WP_Filesystem cannot append.
			throw new \RuntimeException( 'Cannot write the inventory.' );
		}
	}

	/** The inventory file's length, 0 when there is none yet. */
	public static function bytes( $file ) {
		clearstatcache( true, $file );
		return file_exists( $file ) ? (int) filesize( $file ) : 0;
	}

	/** Cut the inventory file back to `$bytes`: what the saved state accounts for. */
	public static function truncate( $file, $bytes ) {
		if ( self::bytes( $file ) <= $bytes ) {
			return;
		}
		$handle = fopen( $file, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Truncating a journal; WP_Filesystem cannot.
		if ( ! $handle || ! ftruncate( $handle, $bytes ) ) {
			throw new \RuntimeException( 'Cannot write the inventory.' );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream opened above.
	}

	/**
	 * `bridge/inventory.json` from the job's inventory file, the same document `document()`
	 * builds, without holding the records in memory: an index of short sort keys, one pass
	 * for the hash (which sorts before `records` in the file), and the records streamed in.
	 */
	public static function write( &$job, $journal, $path ) {
		$index = array();
		$in = file_exists( $journal ) ? fopen( $journal, 'rb' ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed read of a large journal.
		if ( $in ) {
			for ( $offset = ftell( $in ); false !== ( $line = fgets( $in ) ); $offset = ftell( $in ) ) {
				if ( preg_match( '/^\{"wp_type":"((?:[^"\\\\]|\\\\.)*)","wp_id":(\d+)/', $line, $m ) ) {
					$index[] = stripcslashes( $m[1] ) . "\0" . str_pad( $m[2], 20, '0', STR_PAD_LEFT ) . "\0" . $offset;
				} else {
					$record = json_decode( $line, true );
					$index[] = $record['wp_type'] . "\0" . str_pad( (string) (int) $record['wp_id'], 20, '0', STR_PAD_LEFT ) . "\0" . $offset;
				}
			}
		}
		// (wp_type, wp_id): the type by strcmp, then the zero-padded id; exactly `compare()`.
		sort( $index, SORT_STRING );
		$read = static function ( $entry ) use ( $in ) {
			fseek( $in, (int) substr( $entry, strrpos( $entry, "\0" ) + 1 ) );
			return json_decode( fgets( $in ), true );
		};
		$hash = hash_init( 'sha256' );
		foreach ( $index as $n => $entry ) {
			$r = $read( $entry );
			hash_update( $hash, ( $n ? "\n" : '' ) . wp_json_encode( array( (string) $r['wp_type'], (int) $r['wp_id'], (string) $r['fingerprint'], $r['path'] ?? null, $r['status'] ?? null ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		$marker = '@@contentrain-bridge-records@@';
		$document = self::document( $job['record_scope'], array(), $job['created_at'], true, $job['inventory'] );
		$document['records'] = $marker;
		$document['inventory_hash'] = hash_final( $hash );
		list( $head, $tail ) = explode( '"' . $marker . '"', Policy::json( $document ), 2 );
		$target = Files::path( Files::dir( $job['id'] ) . '/output', $path );
		if ( ! Files::mkdir( dirname( $target ) ) ) {
			throw new \RuntimeException( 'Cannot create content directory.' );
		}
		$out = fopen( $target . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed write of a large document.
		if ( ! $out ) {
			throw new \RuntimeException( 'Cannot write the inventory.' );
		}
		try {
			fwrite( $out, $head . ( $index ? "[\n" : '[]' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streamed write.
			foreach ( $index as $n => $entry ) {
				// The record exactly as Policy::json would place it, two levels in.
				$json = rtrim( Policy::json( $read( $entry ) ), "\n" );
				fwrite( $out, ( $n ? ",\n" : '' ) . '    ' . str_replace( "\n", "\n    ", $json ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streamed write.
			}
			fwrite( $out, ( $index ? "\n  ]" : '' ) . $tail ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streamed write.
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed write.
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed read.
			}
		}
		if ( ! Files::fs()->move( $target . '.tmp', $target, true ) ) {
			throw new \RuntimeException( 'Cannot finalize the inventory.' );
		}
		Files::fs()->chmod( $target, 0600 );
		$job['files'][ $path ] = array( 'bytes' => filesize( $target ), 'sha256' => hash_file( 'sha256', $target ) );
	}

	/**
	 * sha256 over one JSON line per record, `[wp_type, wp_id, fingerprint, path, status]`,
	 * in (wp_type, wp_id) order. JSON lines, not a PHP serialization, so a
	 * TypeScript reader recomputes it with `JSON.stringify`.
	 */
	public static function hash( $records ) {
		usort( $records, array( self::class, 'compare' ) );
		$lines = array();
		foreach ( $records as $record ) {
			$lines[] = wp_json_encode( array( (string) $record['wp_type'], (int) $record['wp_id'], (string) $record['fingerprint'], $record['path'] ?? null, $record['status'] ?? null ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/** Is this a well-formed inventory whose declared hash matches its records? */
	public static function verify( $document ) {
		return is_array( $document )
			&& self::FORMAT === ( $document['format'] ?? '' )
			&& is_array( $document['records'] ?? null )
			&& is_array( $document['scope']['post_types'] ?? null )
			&& is_string( $document['inventory_hash'] ?? null )
			&& hash_equals( self::hash( $document['records'] ), $document['inventory_hash'] );
	}

	public static function key( $record ) {
		return $record['wp_type'] . ':' . $record['wp_id'];
	}

	public static function compare( $a, $b ) {
		return strcmp( $a['wp_type'], $b['wp_type'] ) ?: ( (int) $a['wp_id'] <=> (int) $b['wp_id'] );
	}

	/** A record with a public address; a draft, a private post or a trashed one has none. */
	public static function is_public( $record ) {
		return ! isset( $record['status'] ) || ( in_array( $record['status'], self::PUBLIC_STATUSES, true ) && empty( $record['protected'] ) );
	}

	private static function post_record( $post, $scope ) {
		if ( 'attachment' === $post->post_type ) {
			$mapped = Exporter::map_attachment( $post );
			$url = wp_get_attachment_url( $post->ID );
			$path = $url ? wp_make_link_relative( $url ) : null;
		} elseif ( 'nav_menu_item' === $post->post_type ) {
			$mapped = Exporter::map_menu_item( wp_setup_nav_menu_item( $post ) );
			$path = null;
		} else {
			$excluded = array();
			$mapped = Source::post( $post, $scope['selected_meta'], $excluded )['raw'];
			$path = wp_make_link_relative( get_permalink( $post ) );
		}
		$record = array(
			'wp_type' => $post->post_type,
			'wp_id'   => (int) $post->ID,
			'status'  => $post->post_status,
		);
		if ( $post->post_password ) {
			$record['protected'] = true;
		}
		$public = self::is_public( $record );
		$record['fingerprint'] = self::fingerprint( $mapped + array( 'protected' => (bool) $post->post_password ) );
		if ( $post->post_parent ) {
			$record['parent'] = (int) $post->post_parent;
		}
		// A public-scope export can land in a public repository; an unpublished
		// record keeps its identity and fingerprint there, not its slug or address.
		if ( $public || ! empty( $scope['private'] ) ) {
			$record['slug'] = $post->post_name;
			$record['path'] = $path;
			$modified = Exporter::utc_date( $post->post_modified_gmt );
			if ( $modified && 'nav_menu_item' !== $post->post_type ) {
				$record['modified_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $modified ) );
			}
			$old = array_values( array_unique( array_map( 'strval', (array) get_post_meta( $post->ID, '_wp_old_slug' ) ) ) );
			if ( $old ) {
				sort( $old, SORT_STRING );
				$record['old_slugs'] = $old;
			}
		}
		if ( function_exists( 'pll_get_post_language' ) || has_filter( 'wpml_post_language_details' ) ) {
			$record['locale'] = Source::language( $post );
		}
		return $record;
	}

	private static function term_record( $term ) {
		$parent = $term->parent ? get_term( $term->parent, $term->taxonomy ) : null;
		$link = get_term_link( $term );
		$record = array(
			'wp_type'     => $term->taxonomy,
			'wp_id'       => (int) $term->term_id,
			'slug'        => $term->slug,
			'path'        => is_wp_error( $link ) ? null : wp_make_link_relative( $link ),
			'fingerprint' => self::fingerprint(
				array(
					'id'          => (int) $term->term_id,
					'taxonomy'    => $term->taxonomy,
					'slug'        => $term->slug,
					'name'        => $term->name,
					'description' => $term->description,
					'parent'      => $parent && ! is_wp_error( $parent ) ? (int) $parent->term_id : null,
				)
			),
		);
		if ( $term->parent ) {
			$record['parent'] = (int) $term->parent;
		}
		return $record;
	}

	/**
	 * sha256 of the mapped record's canonical JSON, volatile fields removed.
	 * Term references are by id: renaming a category is that term's change, not
	 * an edit of every post filed under it.
	 */
	public static function fingerprint( $mapped ) {
		unset( $mapped['modified'], $mapped['fetched_at'], $mapped['guid'] );
		if ( isset( $mapped['terms'] ) && is_array( $mapped['terms'] ) ) {
			$refs = array();
			foreach ( $mapped['terms'] as $term ) {
				$found = Source::term_by( 'slug', $term['slug'], $term['taxonomy'] );
				$refs[] = $term['taxonomy'] . ':' . ( $found ? (int) $found->term_id : $term['slug'] );
			}
			sort( $refs, SORT_STRING );
			$mapped['terms'] = $refs;
		}
		return hash( 'sha256', Policy::json( $mapped ) );
	}
}
