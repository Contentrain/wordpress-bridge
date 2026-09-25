<?php
/** Block-theme navigation: wp_navigation posts and inline navigation in template parts. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * A block theme has no nav_menu terms: its menus are `wp_navigation` posts,
 * shown where a template part's navigation block refers to them, and inline
 * navigation blocks that carry their own links (Twenty Twenty-Five's footer
 * columns). The same rules as @contentrain/wp-import's REST reader (ai #284,
 * #296), so a Bridge export and a REST import of one site agree:
 *
 *  - only template parts a template uses count (themes ship alternatives no
 *    page shows); without templates, the part whose slug is its area; a part
 *    used inside one is opened in place, once;
 *  - a navigation block's `ref` gives that menu the part's area as a
 *    location; one with neither `ref` nor links shows the most recent
 *    published navigation, as WordPress does;
 *  - an inline navigation is a menu of its part's area, named
 *    `<Area> navigation[ N]` or by its own `ariaLabel`, slugs unique;
 *  - items and inline menus have no WordPress record: negative ids, unique
 *    in the export; `#` links stay `#`;
 *  - a link to content that is not public (draft, private, protected) is
 *    left out and counted, never exported with its address.
 */
final class Menus {

	const AREA_ORDER = array( 'header', 'footer' );

	/** Menus of the active block theme, for Exporter::menus(). Empty on a classic theme. */
	public static function block( $taken ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'get_block_templates' ) ) {
			return array( 'menus' => array(), 'dropped' => 0 );
		}
		$markup = static function ( $template ) {
			return array( 'slug' => $template->slug, 'area' => $template->area ?? '', 'content' => (string) $template->content );
		};
		$templates = array_map( $markup, get_block_templates( array(), 'wp_template' ) );
		$parts = array_map( $markup, get_block_templates( array(), 'wp_template_part' ) );
		$navigations = array_map(
			static function ( $post ) {
				return array( 'id' => (int) $post->ID, 'slug' => $post->post_name, 'title' => $post->post_title, 'date' => $post->post_date_gmt, 'content' => $post->post_content );
			},
			get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) )
		);
		return self::from_blocks( $templates, $parts, $navigations, $taken, array( self::class, 'target' ) );
	}

	/**
	 * The menus of a block theme from its markup alone (testable without a
	 * theme): `$templates`/`$parts` are { slug, area, content }, `$navigations`
	 * published wp_navigation posts { id, slug, title, date, content }.
	 * `$target` maps a link block's attributes to [ target, public ].
	 */
	public static function from_blocks( $templates, $parts, $navigations, $taken, $target ) {
		$used = self::used_parts( $templates, $parts );
		$state = (object) array( 'next' => 0, 'dropped' => 0, 'taken' => array_fill_keys( (array) $taken, true ) );
		$next = static function () use ( $state ) {
			return --$state->next;
		};
		$locations = self::locations( $used, $navigations, $parts );
		$menus = array();
		foreach ( $navigations as $nav ) {
			$slug = self::unique( $nav['slug'] ?: sanitize_title( $nav['title'] ) ?: 'navigation', $state );
			$menu = array(
				'id' => (int) $nav['id'],
				'slug' => $slug,
				'name' => wp_strip_all_tags( $nav['title'] ) ?: $slug,
				'items' => self::items( parse_blocks( $nav['content'] ), $target, $next, $state ),
			);
			if ( ! empty( $locations[ $nav['id'] ] ) ) {
				$menu['locations'] = $locations[ $nav['id'] ];
			}
			$menus[] = $menu;
		}
		foreach ( self::ordered( $used ) as $part ) {
			$inline = array();
			self::inline_navs( self::expand( $part, $parts ), $inline );
			foreach ( $inline as $i => $nav ) {
				$area = $part['area'];
				$label = trim( wp_strip_all_tags( (string) ( $nav['attrs']['ariaLabel'] ?? '' ) ) );
				$name = '' !== $label ? $label : ucfirst( $area ) . ' navigation' . ( count( $inline ) > 1 ? ' ' . ( $i + 1 ) : '' );
				$slug = self::unique( sanitize_title( $name ) ?: $area . '-navigation', $state );
				$items = self::items( $nav['innerBlocks'], $target, $next, $state );
				if ( $items ) {
					$menus[] = array( 'id' => $next(), 'slug' => $slug, 'name' => $name, 'items' => $items, 'locations' => array( $area ) );
				}
			}
		}
		return array( 'menus' => $menus, 'dropped' => $state->dropped );
	}

	/** Parts a template uses; without templates, the part named after its area. A part inside a part is opened in place (`expand()`). */
	public static function used_parts( $templates, $parts ) {
		$slugs = array();
		foreach ( $templates as $template ) {
			self::part_slugs( parse_blocks( $template['content'] ), $slugs );
		}
		if ( ! $slugs ) {
			return array_values( array_filter( $parts, static function ( $p ) { return '' !== $p['slug'] && $p['slug'] === $p['area']; } ) );
		}
		return array_values( array_filter( $parts, static function ( $p ) use ( $slugs ) { return isset( $slugs[ $p['slug'] ] ); } ) );
	}

	/**
	 * A part's blocks with the parts it uses opened where they stand, each once
	 * (a part naming itself, or a cycle, stops): its navigation is shown in the
	 * outer part's area, in document order.
	 */
	public static function expand( $part, $parts, $seen = array() ) {
		$seen[ $part['slug'] ] = true;
		$by_slug = array_column( $parts, null, 'slug' );
		$open = static function ( $blocks ) use ( &$open, $by_slug, &$seen ) {
			$out = array();
			foreach ( $blocks as $block ) {
				$slug = 'core/template-part' === $block['blockName'] ? (string) ( $block['attrs']['slug'] ?? '' ) : '';
				if ( '' !== $slug ) {
					if ( isset( $by_slug[ $slug ] ) && ! isset( $seen[ $slug ] ) ) {
						$seen[ $slug ] = true;
						array_push( $out, ...$open( parse_blocks( $by_slug[ $slug ]['content'] ) ) );
					}
					continue;
				}
				$block['innerBlocks'] = $open( $block['innerBlocks'] ?? array() );
				$out[] = $block;
			}
			return $out;
		};
		return $open( parse_blocks( $part['content'] ) );
	}

	private static function part_slugs( $blocks, &$slugs ) {
		foreach ( $blocks as $block ) {
			if ( 'core/template-part' === $block['blockName'] && ! empty( $block['attrs']['slug'] ) ) {
				$slugs[ (string) $block['attrs']['slug'] ] = true;
			}
			self::part_slugs( $block['innerBlocks'] ?? array(), $slugs );
		}
	}

	/** navigation id → areas of the used parts that show it (`ref`, or the fallback for an empty block). */
	public static function locations( $parts, $navigations, $all = null ) {
		$fallback = null;
		foreach ( $navigations as $nav ) {
			if ( ! $fallback || $nav['date'] > $fallback['date'] || ( $nav['date'] === $fallback['date'] && $nav['id'] > $fallback['id'] ) ) {
				$fallback = $nav;
			}
		}
		$published = array_column( $navigations, 'id' );
		$out = array();
		foreach ( $parts as $part ) {
			$area = self::area( $part );
			if ( ! $area ) {
				continue;
			}
			$navs = array();
			self::nav_blocks( self::expand( $part, $all ?? $parts ), $navs );
			foreach ( $navs as $nav ) {
				$ref = $nav['attrs']['ref'] ?? null;
				$id = is_numeric( $ref ) ? (int) $ref : ( ! $nav['innerBlocks'] && $fallback ? $fallback['id'] : null );
				if ( $id && in_array( $id, $published, true ) ) {
					$out[ $id ][ $area ] = true;
				}
			}
		}
		return array_map( static function ( $areas ) { $list = array_keys( $areas ); sort( $list ); return $list; }, $out );
	}

	private static function nav_blocks( $blocks, &$out ) {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === $block['blockName'] ) {
				$out[] = $block;
				continue;
			}
			self::nav_blocks( $block['innerBlocks'] ?? array(), $out );
		}
	}

	/** Navigation blocks with links of their own and no `ref`, in document order. */
	private static function inline_navs( $blocks, &$out ) {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === $block['blockName'] ) {
				if ( ! is_numeric( $block['attrs']['ref'] ?? null ) && $block['innerBlocks'] ) {
					$out[] = $block;
				}
				continue;
			}
			self::inline_navs( $block['innerBlocks'] ?? array(), $out );
		}
	}

	private static function area( $part ) {
		$area = (string) ( $part['area'] ?? '' );
		return '' === $area || 'uncategorized' === $area ? null : $area;
	}

	/** Used parts with an area, header first, then footer, then the rest. */
	private static function ordered( $parts ) {
		$parts = array_values( array_filter( $parts, static function ( $p ) { return null !== self::area( $p ); } ) );
		$rank = static function ( $p ) {
			$i = array_search( $p['area'], self::AREA_ORDER, true );
			return false === $i ? 99 : $i;
		};
		usort( $parts, static function ( $a, $b ) use ( $rank ) { return $rank( $a ) <=> $rank( $b ); } );
		return $parts;
	}

	private static function unique( $slug, $state ) {
		$candidate = $slug;
		for ( $n = 2; isset( $state->taken[ $candidate ] ); $n++ ) {
			$candidate = $slug . '-' . $n;
		}
		$state->taken[ $candidate ] = true;
		return $candidate;
	}

	/** Menu items of navigation blocks, in the shape of `Exporter::map_menu_item()`. */
	private static function items( $blocks, $target, $next, $state, $parent = null, &$items = array() ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'];
			$attrs = $block['attrs'] ?? array();
			if ( 'core/navigation-link' === $name || 'core/navigation-submenu' === $name ) {
				list( $to, $public ) = call_user_func( $target, $attrs );
				if ( ! $public ) {
					++$state->dropped;
					continue;
				}
				$id = $next();
				$items[] = self::item( $id, $attrs['label'] ?? '', $to, $attrs, $parent, count( $items ) );
				self::items( $block['innerBlocks'] ?? array(), $target, $next, $state, $id, $items );
			} elseif ( 'core/home-link' === $name ) {
				$items[] = self::item( $next(), $attrs['label'] ?? 'Home', array( 'kind' => 'url', 'url' => home_url( '/' ), 'resolved' => true ), $attrs, $parent, count( $items ) );
			} elseif ( 'core/page-list' === $name ) {
				foreach ( get_pages( array( 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish' ) ) as $page ) {
					if ( $page->post_password ) {
						continue;
					}
					$items[] = self::item( $next(), $page->post_title, array( 'kind' => 'post', 'post_type' => 'page', 'id' => (int) $page->ID, 'slug' => $page->post_name, 'resolved' => true, 'url' => (string) get_permalink( $page ) ), array(), $parent, count( $items ) );
				}
			}
		}
		return $items;
	}

	private static function item( $id, $label, $to, $attrs, $parent, $order ) {
		$url = $to['url'] ?? ( $attrs['url'] ?? '' );
		if ( 'url' !== ( $to['kind'] ?? '' ) ) {
			unset( $to['url'] ); // A post or term target is known by its id and slug, as in a classic menu.
		}
		return array(
			'id' => $id,
			'title' => wp_strip_all_tags( (string) $label ),
			'order' => $order,
			'parent' => $parent,
			'parent_unresolved' => false,
			'url' => $url,
			'target' => $to,
			'target_attr' => ! empty( $attrs['opensInNewTab'] ) ? '_blank' : null,
			'classes' => array_values( array_filter( explode( ' ', (string) ( $attrs['className'] ?? '' ) ) ) ),
			'description' => (string) ( $attrs['description'] ?? '' ),
			'status' => 'publish',
		);
	}

	/** A host without case or a leading `www.`: example.com and www.example.com are one site. */
	private static function bare_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/** The post id a same-site `?page_id=N` / `?p=N` address names, or 0. */
	public static function query_post_id( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['query'] ) ) {
			return 0;
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( isset( $parts['host'] ) && self::bare_host( $parts['host'] ) !== self::bare_host( (string) ( $home['host'] ?? '' ) ) ) {
			return 0;
		}
		parse_str( $parts['query'], $query );
		foreach ( array( 'page_id', 'p' ) as $key ) {
			if ( isset( $query[ $key ] ) && ctype_digit( (string) $query[ $key ] ) ) {
				return (int) $query[ $key ];
			}
		}
		return 0;
	}

	/**
	 * A link block's target, and whether it may be exported: a post or term by
	 * its id when the block names one; otherwise its URL. `#` stays `#`.
	 */
	public static function target( $attrs ) {
		$url = (string) ( $attrs['url'] ?? '' );
		$kind = $attrs['kind'] ?? '';
		$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		// A hand-typed `?page_id=` / `?p=` link on this site is a post link like any other (wp-import #296).
		if ( ! $id && 'post-type' !== $kind && 'taxonomy' !== $kind ) {
			$query_id = self::query_post_id( $url );
			if ( $query_id ) {
				$kind = 'post-type';
				$id = $query_id;
			}
		}
		if ( 'post-type' === $kind && $id ) {
			$post = get_post( $id );
			$public = $post && 'publish' === $post->post_status && ! $post->post_password;
			return array( array( 'kind' => 'post', 'post_type' => $post ? $post->post_type : (string) ( $attrs['type'] ?? '' ), 'id' => $id, 'slug' => $post ? $post->post_name : null, 'resolved' => (bool) $post, 'url' => $public ? ( get_permalink( $post ) ?: $url ) : $url ), $public );
		}
		if ( 'taxonomy' === $kind && $id ) {
			$taxonomy = 'tag' === ( $attrs['type'] ?? '' ) ? 'post_tag' : (string) ( $attrs['type'] ?? 'category' );
			$term = get_term( $id, $taxonomy );
			$found = $term && ! is_wp_error( $term );
			$link = $found ? get_term_link( $term ) : null;
			return array( array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'id' => $id, 'slug' => $found ? $term->slug : null, 'resolved' => $found, 'url' => is_string( $link ) ? $link : $url ), true );
		}
		return array( array( 'kind' => 'url', 'url' => $url, 'resolved' => true ), true );
	}
}
