<?php
/** Redirect rules from every place WordPress keeps them. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * One normalized list, `RawRedirect` from `@contentrain/types`:
 * `{ from, to, status, source }`, plus `id`, `match` and `regex`.
 *
 * Accounting is the point. Every rule a source holds lands in exactly one of
 * `redirects` (the site serves it) or `excluded` (with the reason it is not a
 * plain redirect: disabled, conditional, "gone"). A migration that quietly
 * drops a redirect loses the ranking of the page it pointed at, so a count
 * that does not add up is a failed export, not a smaller one.
 */
final class Redirects {
	const FORMAT = 'contentrain-bridge-redirects@1';

	/** Whether the source being read is running: an inactive plugin's rules are not served. */
	private static $serving = true;

	public static function document() {
		$doc = array( 'format' => self::FORMAT, 'sources' => array(), 'redirects' => array(), 'excluded' => array() );
		self::redirection( $doc );
		self::yoast_premium( $doc );
		self::rank_math( $doc );
		self::safe_redirect_manager( $doc );
		self::old_slugs( $doc );
		usort( $doc['redirects'], array( self::class, 'order' ) );
		usort( $doc['excluded'], array( self::class, 'order' ) );
		$excluded = array();
		$doc = Policy::clean( $doc, $excluded, 'redirects' );
		if ( $excluded ) {
			$doc['redacted'] = $excluded;
		}
		return $doc;
	}

	/** Redirection (John Godley): the most common redirect plugin, in its own tables. */
	private static function redirection( &$doc ) {
		global $wpdb;
		$items = $wpdb->prefix . 'redirection_items';
		if ( ! self::table( $items ) ) {
			$doc['sources']['redirection'] = self::source( 'absent' );
			return;
		}
		$groups = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, module_id FROM %i', $wpdb->prefix . 'redirection_groups' ), ARRAY_A ) as $group ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
			$groups[ (int) $group['id'] ] = $group;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url, regex, group_id, status, action_type, action_code, action_data, match_type FROM %i ORDER BY id', $items ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
		$modules = array( 1 => 'wordpress', 2 => 'apache', 3 => 'nginx' );
		self::$serving = defined( 'REDIRECTION_VERSION' );
		foreach ( $rows as $row ) {
			$group = $groups[ (int) $row['group_id'] ] ?? null;
			$rule = array( 'id' => 'redirection:' . $row['id'], 'from' => (string) $row['url'], 'source' => 'redirection', 'match' => (string) $row['match_type'], 'regex' => (bool) $row['regex'] );
			if ( $group && isset( $modules[ (int) $group['module_id'] ] ) && 1 !== (int) $group['module_id'] ) {
				$rule['served_by'] = $modules[ (int) $group['module_id'] ];
			}
			if ( 'enabled' !== $row['status'] || ( $group && 'enabled' !== $group['status'] ) ) {
				self::exclude( $doc, $rule, 'disabled' );
			} elseif ( 'url' !== $row['action_type'] ) {
				// error (404/410), pass-through, "do nothing", random: not a from→to rule.
				$rule['status'] = (int) $row['action_code'];
				self::exclude( $doc, $rule, 'not-a-redirect:' . $row['action_type'] );
			} elseif ( 'url' !== $row['match_type'] ) {
				// Login, referrer, agent, cookie, header, IP, server…: the target depends on the request.
				$rule['condition'] = self::decode( $row['action_data'] );
				$rule['status'] = (int) $row['action_code'];
				self::exclude( $doc, $rule, 'conditional-match:' . $row['match_type'] );
			} else {
				self::add( $doc, $rule, (string) $row['action_data'], (int) $row['action_code'] );
			}
		}
		$doc['sources']['redirection'] = self::source( defined( 'REDIRECTION_VERSION' ) ? 'active' : 'inactive-with-data', count( $rows ) );
	}

	/** Yoast SEO Premium keeps its redirects in one option. */
	private static function yoast_premium( &$doc ) {
		$rules = get_option( 'wpseo-premium-redirects-base', null );
		if ( ! is_array( $rules ) ) {
			$doc['sources']['yoast_premium'] = self::source( 'absent' );
			return;
		}
		self::$serving = defined( 'WPSEO_PREMIUM_VERSION' );
		foreach ( array_values( $rules ) as $i => $item ) {
			$regex = 'regex' === ( $item['format'] ?? 'plain' );
			$origin = (string) ( $item['origin'] ?? '' );
			$rule = array( 'id' => 'yoast_premium:' . $i, 'from' => $regex || preg_match( '#^(/|https?://)#', $origin ) ? $origin : '/' . $origin, 'source' => 'yoast_premium', 'match' => $regex ? 'regex' : 'url', 'regex' => $regex );
			$status = (int) ( $item['type'] ?? 301 );
			if ( in_array( $status, array( 410, 451 ), true ) ) {
				$rule['status'] = $status;
				self::exclude( $doc, $rule, 'not-a-redirect:gone' );
				continue;
			}
			$target = (string) ( $item['url'] ?? '' );
			self::add( $doc, $rule, preg_match( '#^(/|https?://)#', $target ) ? $target : '/' . $target, $status );
		}
		$doc['sources']['yoast_premium'] = self::source( defined( 'WPSEO_PREMIUM_VERSION' ) ? 'active' : 'inactive-with-data', count( $rules ) );
	}

	/** Rank Math's redirection module: one row, several source patterns. */
	private static function rank_math( &$doc ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table( $table ) ) {
			$doc['sources']['rank_math'] = self::source( 'absent' );
			return;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sources, url_to, header_code, status FROM %i ORDER BY id', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
		$count = 0;
		self::$serving = defined( 'RANK_MATH_VERSION' );
		foreach ( $rows as $row ) {
			$sources = self::decode( $row['sources'] );
			foreach ( is_array( $sources ) ? array_values( $sources ) : array( array() ) as $i => $source ) {
				++$count;
				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				$pattern = (string) ( $source['pattern'] ?? '' );
				$rule = array( 'id' => 'rank_math:' . $row['id'] . ':' . $i, 'from' => 'regex' === $comparison || preg_match( '#^(/|https?://)#', $pattern ) ? $pattern : '/' . $pattern, 'source' => 'rank_math', 'match' => 'exact' === $comparison ? 'url' : $comparison, 'regex' => 'regex' === $comparison );
				$status = (int) $row['header_code'];
				if ( 'active' !== $row['status'] ) {
					self::exclude( $doc, $rule, 'disabled' );
				} elseif ( in_array( $status, array( 410, 451 ), true ) ) {
					$rule['status'] = $status;
					self::exclude( $doc, $rule, 'not-a-redirect:gone' );
				} else {
					self::add( $doc, $rule, (string) $row['url_to'], $status );
				}
			}
		}
		$doc['sources']['rank_math'] = self::source( defined( 'RANK_MATH_VERSION' ) ? 'active' : 'inactive-with-data', $count );
	}

	/** Safe Redirect Manager: a `redirect_rule` post per rule, fields in post meta. */
	private static function safe_redirect_manager( &$doc ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'redirect_rule' AND post_status NOT IN ('auto-draft') ORDER BY ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The post type is unregistered when the plugin is inactive.
		if ( ! $ids ) {
			$doc['sources']['safe_redirect_manager'] = self::source( 'absent' );
			return;
		}
		self::$serving = defined( 'SRM_VERSION' );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			$regex = (bool) get_post_meta( $id, '_redirect_rule_from_regex', true );
			$rule = array( 'id' => 'safe_redirect_manager:' . $id, 'from' => (string) get_post_meta( $id, '_redirect_rule_from', true ), 'source' => 'safe_redirect_manager', 'match' => $regex ? 'regex' : 'url', 'regex' => $regex );
			$status = (int) get_post_meta( $id, '_redirect_rule_status_code', true ) ?: 302;
			if ( ! $post || 'publish' !== $post->post_status ) {
				self::exclude( $doc, $rule, 'disabled' );
			} elseif ( in_array( $status, array( 403, 404, 410 ), true ) ) {
				$rule['status'] = $status;
				self::exclude( $doc, $rule, 'not-a-redirect:status-' . $status );
			} else {
				self::add( $doc, $rule, (string) get_post_meta( $id, '_redirect_rule_to', true ), $status );
			}
		}
		$doc['sources']['safe_redirect_manager'] = self::source( defined( 'SRM_VERSION' ) ? 'active' : 'inactive-with-data', count( $ids ) );
	}

	/**
	 * WordPress itself answers an old slug with a 301 to the current address
	 * (`wp_old_slug_redirect`). No plugin holds these, so a plugin-table export
	 * misses every one of them.
	 */
	private static function old_slugs( &$doc ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_old_slug' AND p.post_status = 'publish' ORDER BY pm.post_id, pm.meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One pass over a core meta key.
		self::$serving = true;
		foreach ( $rows as $row ) {
			$post = get_post( (int) $row['post_id'] );
			$rule = array( 'id' => 'wordpress:old-slug:' . $row['post_id'] . ':' . $row['meta_value'], 'source' => 'wordpress', 'match' => 'url', 'regex' => false );
			$current = $post ? get_permalink( $post ) : false;
			if ( ! $current || ! is_post_type_viewable( $post->post_type ) || ! get_option( 'permalink_structure' ) ) {
				// With plain permalinks the address is `?p=ID`: the slug was never in it.
				$rule['from'] = (string) $row['meta_value'];
				self::exclude( $doc, $rule, 'no-slug-address' );
				continue;
			}
			$old = clone $post;
			$old->post_name = (string) $row['meta_value'];
			$rule['from'] = wp_make_link_relative( get_permalink( $old ) );
			if ( $post->post_name === $old->post_name ) {
				self::exclude( $doc, $rule, 'slug-reused' );
				continue;
			}
			self::add( $doc, $rule, $current, 301 );
		}
		$doc['sources']['wordpress_old_slug'] = self::source( 'active', count( $rows ) );
	}

	private static function add( &$doc, $rule, $to, $status ) {
		$rule['to'] = self::relative( $to );
		$rule['status'] = $status >= 300 && $status < 400 ? $status : 301;
		if ( $status < 300 || $status >= 400 ) {
			$rule['status_note'] = 'source status ' . $status . ' is not a redirect code; 301 assumed';
		}
		if ( ! self::$serving ) {
			// Kept whole, but not listed as served: the live site does not answer it.
			self::exclude( $doc, $rule, 'source-inactive' );
			return;
		}
		$doc['redirects'][] = $rule;
	}

	private static function exclude( &$doc, $rule, $reason ) {
		$rule['reason'] = $reason;
		$doc['excluded'][] = $rule;
	}

	/** Same-site targets become root-relative, like every `from`; other hosts stay absolute. */
	private static function relative( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$target = wp_parse_url( $url );
		if ( is_array( $target ) && isset( $target['host'], $home['host'] ) && strtolower( $target['host'] ) === strtolower( $home['host'] ) && ( $target['port'] ?? null ) === ( $home['port'] ?? null ) ) {
			return wp_make_link_relative( $url );
		}
		return $url;
	}

	private static function source( $status, $rules = 0 ) {
		return 'absent' === $status ? array( 'status' => 'absent' ) : array( 'status' => $status, 'rules' => $rules );
	}

	private static function table( $name ) {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table probe.
	}

	private static function decode( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 16 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Plugin data; class instantiation is explicitly disabled.
		}
		$json = is_string( $value ) ? json_decode( $value, true ) : null;
		return null === $json ? $value : $json;
	}

	private static function order( $a, $b ) {
		return strcmp( $a['id'], $b['id'] );
	}
}
