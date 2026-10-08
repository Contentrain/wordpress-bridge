<?php
/** SourceDeltaPlan from two inventories. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * What changed at the origin since the inventory the repository was built from.
 *
 * Output is `SourceDeltaPlan` (`@contentrain/types`, version 1). `wp_type` is
 * always set: post 5 and category 5 are different records. The fields only the
 * store can know — `model`, `entry_id`, `conflict`, `redirects` — are left to
 * the planner, which reads `bridge/entry-source-map.json` and the repository.
 */
final class Delta {
	/** Compare the inventory the repository holds (T0) with one taken now (T1). */
	public static function compare( $before, $after, $trusted_hash = null ) {
		$plan = array(
			'version'              => 1,
			'generated_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'cursor'               => self::cursor( $before ),
			'next_cursor'          => self::cursor( $after ),
			'entries'              => array(),
			'deletions_detectable' => false,
			'warnings'             => array(),
		);
		// A cursor that cannot be trusted measures nothing: refusing is the only
		// answer that cannot be misread as "nothing changed".
		if ( ! Inventory::verify( $before ) || ( null !== $trusted_hash && ! hash_equals( (string) $trusted_hash, (string) ( $before['inventory_hash'] ?? '' ) ) ) ) {
			$plan['cursor'] = array( 'kind' => 'bridge_inventory', 'inventory_hash' => is_array( $before ) && is_string( $before['inventory_hash'] ?? null ) ? $before['inventory_hash'] : '', 'taken_at' => is_array( $before ) && is_string( $before['taken_at'] ?? null ) ? $before['taken_at'] : $plan['generated_at'] );
			$plan['refused'] = true;
			$plan['warnings'][] = is_array( $before ) && Inventory::FORMAT !== ( $before['format'] ?? '' )
				? 'inventory-legacy: the previous delivery has no per-record inventory; no delta was computed. This export establishes the cursor.'
				: 'inventory-unverified: the previous inventory does not match its hash or was edited after delivery; no delta was computed. This export establishes a new cursor.';
			return $plan;
		}

		$lost = array();
		foreach ( array( 'post_types', 'taxonomies' ) as $axis ) {
			foreach ( array_diff( (array) ( $before['scope'][ $axis ] ?? array() ), (array) ( $after['scope'][ $axis ] ?? array() ) ) as $type ) {
				$lost[] = $type;
				$plan['warnings'][] = 'scope-lost:' . $type . ': the type is no longer enumerated (its plugin may be inactive or it was deselected); its records are not reported as deleted.';
			}
		}
		sort( $lost, SORT_STRING );
		if ( ( $before['permalink_structure'] ?? null ) !== ( $after['permalink_structure'] ?? null ) ) {
			$plan['warnings'][] = 'permalink-structure-changed: addresses moved in bulk; each record is listed as moved.';
		}
		if ( empty( $before['complete'] ) || empty( $after['complete'] ) ) {
			$plan['warnings'][] = 'inventory-truncated: an inventory did not enumerate its whole scope; deletions cannot be proven.';
		}

		$old = self::index( $before['records'] );
		$new = self::index( $after['records'] );
		foreach ( $new as $key => $record ) {
			$prior = $old[ $key ] ?? null;
			if ( null === $prior ) {
				// Created and trashed since T0 never reached the repository.
				if ( 'trash' !== ( $record['status'] ?? null ) ) {
					$plan['entries'][] = self::entry( 'created', $record, null, $record );
				}
				continue;
			}
			$entry = self::changed( $prior, $record );
			if ( $entry ) {
				$plan['entries'][] = $entry;
			}
		}
		// Absence proves a deletion only when both walks saw their whole scope.
		$complete = ! empty( $before['complete'] ) && ! empty( $after['complete'] );
		foreach ( $old as $key => $prior ) {
			if ( ! $complete || isset( $new[ $key ] ) || in_array( $prior['wp_type'], $lost, true ) ) {
				continue;
			}
			$entry = self::entry( 'deleted', $prior, $prior, null );
			$entry['deleted_kind'] = 'purged';
			$entry['detail'] = 'purged';
			$plan['entries'][] = $entry;
		}
		usort( $plan['entries'], array( Inventory::class, 'compare' ) );

		$plan['deletions_detectable'] = ! $lost && $complete;
		if ( $lost ) {
			$plan['deletions_undetectable_types'] = $lost;
		}
		if ( ! $plan['warnings'] ) {
			unset( $plan['warnings'] );
		}
		return $plan;
	}

	/** Live plan: T1 is taken now over T0's scope, narrowed to what is still registered. */
	public static function plan( $before, $trusted_hash = null, $max_records = 0 ) {
		$scope = is_array( $before['scope'] ?? null ) ? $before['scope'] : Inventory::scope();
		$scope['post_types'] = array_values( array_filter( (array) ( $scope['post_types'] ?? array() ), 'post_type_exists' ) );
		$scope['taxonomies'] = array_values( array_filter( (array) ( $scope['taxonomies'] ?? array() ), 'taxonomy_exists' ) );
		$after = Inventory::build( $scope, $max_records );
		return array( 'plan' => self::compare( $before, $after, $trusted_hash ), 'inventory' => $after );
	}

	/**
	 * What a `modified_after` cursor alone reports — the REST API's
	 * `modified_after` view, taken over every type the inventory counts
	 * (`Source::content_types()`), a type hidden from REST included. It sees
	 * neither deletions nor a meta-only edit, and says so.
	 *
	 * Read in-process rather than through the REST routes: a type registered
	 * without `show_in_rest` has no route, so a REST request for it answers
	 * "no route" and its edits would go unreported — the inventory would hold
	 * records this cursor never names.
	 */
	public static function rest_modified_after( $since ) {
		$since_ts = strtotime( $since );
		// `>=` with a second of overlap: post_modified has one-second resolution.
		$after = gmdate( 'Y-m-d H:i:s', $since_ts - 1 );
		$entries = array();
		foreach ( Source::content_types() as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ), true ) ) {
				continue;
			}
			for ( $page = 1; $page < 1000; ++$page ) {
				// `lang => ''`: Polylang would otherwise narrow the query to the admin's language filter (as `Source::term_by()`).
				$query = new \WP_Query( array(
					'post_type'              => $type->name,
					'post_status'            => 'any',
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'date_query'             => array( array( 'column' => 'post_modified_gmt', 'after' => $after, 'inclusive' => true ) ),
					'lang'                   => '',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
				foreach ( $query->posts as $post ) {
					$entries[] = array(
						// A draft has no GMT date of its own; `get_post_time()` derives one from the local date, as REST's `date_gmt` does.
						'op'      => get_post_time( 'U', true, $post ) >= $since_ts - 1 ? 'created' : 'updated',
						'wp_id'   => (int) $post->ID,
						'wp_type' => $type->name,
						'detail'  => 'modified_gmt ' . get_post_modified_time( 'Y-m-d\TH:i:s', true, $post ) . 'Z',
					);
				}
				if ( count( $query->posts ) < 100 ) {
					break;
				}
			}
		}
		usort( $entries, array( Inventory::class, 'compare' ) );
		return array(
			'version'              => 1,
			'generated_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'cursor'               => array( 'kind' => 'rest_modified_after', 'modified_after' => gmdate( 'Y-m-d\TH:i:s\Z', $since_ts ), 'taken_at' => gmdate( 'Y-m-d\TH:i:s\Z', $since_ts ) ),
			'entries'              => $entries,
			'deletions_detectable' => false,
			'warnings'             => array( 'modified-after-only: deletions, trashing, meta-only edits (post_modified unchanged), terms and menus are not visible to this cursor.' ),
		);
	}

	private static function changed( $prior, $record ) {
		$was_trash = 'trash' === ( $prior['status'] ?? null );
		$is_trash = 'trash' === ( $record['status'] ?? null );
		if ( $is_trash ) {
			if ( $was_trash ) {
				return null;
			}
			$entry = self::entry( 'deleted', $record, $prior, $record );
			$entry['deleted_kind'] = 'trashed';
			$entry['detail'] = 'trashed';
			return $entry;
		}
		$same = $prior['fingerprint'] === $record['fingerprint'] && ( $prior['status'] ?? null ) === ( $record['status'] ?? null ) && ( $prior['path'] ?? null ) === ( $record['path'] ?? null );
		if ( $same ) {
			return null;
		}
		// A move needs an address before and after; unpublishing is an update
		// the planner decides on, and a draft's slug was never an address.
		if ( ! $was_trash && Inventory::is_public( $prior ) && Inventory::is_public( $record ) && isset( $prior['path'], $record['path'] ) && $prior['path'] !== $record['path'] ) {
			$entry = self::entry( 'moved', $record, $prior, $record );
			$entry['path_before'] = $prior['path'];
			$entry['path_after'] = $record['path'];
			$entry['slug_before'] = $prior['slug'] ?? null;
			$entry['slug_after'] = $record['slug'] ?? null;
			$entry = array_filter( $entry, static function ( $value ) { return null !== $value; } );
			$evidence = in_array( $prior['slug'] ?? '', $record['old_slugs'] ?? array(), true ) ? 'old slug recorded by WordPress' : 'address changed without an old-slug record';
			$entry['detail'] = ( ( $prior['slug'] ?? null ) === ( $record['slug'] ?? null ) ? 'parent or permalink change' : 'slug change' ) . '; ' . $evidence;
			return $entry;
		}
		$entry = self::entry( 'updated', $record, $prior, $record );
		if ( $was_trash ) {
			$entry['detail'] = 'restored from trash';
		} elseif ( ( $prior['status'] ?? null ) !== ( $record['status'] ?? null ) ) {
			$entry['detail'] = 'status ' . $prior['status'] . ' -> ' . $record['status'];
		} elseif ( isset( $prior['modified_at'], $record['modified_at'] ) && $prior['modified_at'] === $record['modified_at'] ) {
			$entry['detail'] = 'content changed with modified_at unchanged (meta-only edit)';
		} else {
			$entry['detail'] = 'content changed';
		}
		return $entry;
	}

	private static function entry( $op, $record, $prior, $current ) {
		$entry = array( 'op' => $op, 'wp_id' => (int) $record['wp_id'], 'wp_type' => $record['wp_type'] );
		if ( isset( $record['locale'] ) ) {
			$entry['locale'] = $record['locale'];
		}
		if ( $prior ) {
			$entry['fingerprint_before'] = $prior['fingerprint'];
		}
		if ( $current ) {
			$entry['fingerprint_after'] = $current['fingerprint'];
		}
		return $entry;
	}

	private static function cursor( $inventory ) {
		return array( 'kind' => 'bridge_inventory', 'inventory_hash' => (string) ( $inventory['inventory_hash'] ?? '' ), 'taken_at' => (string) ( $inventory['taken_at'] ?? '' ) );
	}

	private static function index( $records ) {
		$out = array();
		foreach ( $records as $record ) {
			$out[ Inventory::key( $record ) ] = $record;
		}
		return $out;
	}
}
