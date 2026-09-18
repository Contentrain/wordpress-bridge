<?php
/** How WordPress turns content into addresses. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * The address rules, not just the addresses: a migrated site has to put
 * tomorrow's post at the URL WordPress would have given it, and a list of
 * today's permalinks cannot say what that is.
 */
final class Routing {
	const FORMAT = 'contentrain-bridge-routing@1';

	public static function document() {
		global $wp_rewrite;
		$structure = (string) get_option( 'permalink_structure' );
		$front = static function ( $option ) {
			$id = (int) get_option( $option );
			$post = $id ? get_post( $id ) : null;
			return $post ? array( 'id' => $id, 'slug' => $post->post_name, 'path' => wp_make_link_relative( get_permalink( $post ) ) ) : null;
		};
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$archive = $type->has_archive ? get_post_type_archive_link( $type->name ) : false;
			$post_types[] = array(
				'name'         => $type->name,
				'hierarchical' => (bool) $type->hierarchical,
				'rewrite'      => is_array( $type->rewrite ) ? array_intersect_key( $type->rewrite, array_flip( array( 'slug', 'with_front', 'feeds', 'pages' ) ) ) : false,
				'permastruct'  => in_array( $type->name, array( 'post', 'page', 'attachment' ), true ) ? null : self::path( $wp_rewrite->get_extra_permastruct( $type->name ) ),
				'has_archive'  => (bool) $type->has_archive,
				'archive_path' => $archive ? wp_make_link_relative( $archive ) : null,
				'query_var'    => $type->query_var,
			);
		}
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$taxonomies[] = array(
				'name'         => $taxonomy->name,
				'object_types' => array_values( (array) $taxonomy->object_type ),
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'rewrite'      => is_array( $taxonomy->rewrite ) ? array_intersect_key( $taxonomy->rewrite, array_flip( array( 'slug', 'with_front', 'hierarchical' ) ) ) : false,
				'permastruct'  => self::path( $wp_rewrite->get_extra_permastruct( $taxonomy->name ) ),
			);
		}
		return array(
			'format'              => self::FORMAT,
			'home'                => home_url( '/' ),
			'permalink_structure' => $structure,
			// Empty structure = "plain" permalinks: every address is a query string.
			'plain'               => '' === $structure,
			'trailing_slash'      => '' !== $structure && '/' === substr( $structure, -1 ),
			'front'               => (string) $wp_rewrite->front,
			'category_base'       => (string) get_option( 'category_base' ),
			'tag_base'            => (string) get_option( 'tag_base' ),
			'pagination_base'     => (string) $wp_rewrite->pagination_base,
			'author_base'         => (string) $wp_rewrite->author_base,
			'search_base'         => (string) $wp_rewrite->search_base,
			'comments_pagination_base' => (string) $wp_rewrite->comments_pagination_base,
			'feed_base'           => (string) $wp_rewrite->feed_base,
			'author_structure'    => self::path( $wp_rewrite->get_author_permastruct() ),
			'date_structure'      => self::path( $wp_rewrite->get_date_permastruct() ),
			'page_structure'      => self::path( $wp_rewrite->get_page_permastruct() ),
			'show_on_front'       => (string) get_option( 'show_on_front' ),
			'page_on_front'       => 'page' === get_option( 'show_on_front' ) ? $front( 'page_on_front' ) : null,
			'page_for_posts'      => 'page' === get_option( 'show_on_front' ) ? $front( 'page_for_posts' ) : null,
			'posts_per_page'      => (int) get_option( 'posts_per_page' ),
			'post_types'          => $post_types,
			'taxonomies'          => $taxonomies,
		);
	}

	/** WordPress stores some structures without the leading slash (`topics/%category%`); addresses are root-relative. */
	private static function path( $structure ) {
		return $structure ? '/' . ltrim( (string) $structure, '/' ) : null;
	}
}
