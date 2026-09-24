<?php
/** SEO title and description templates rendered to the text a page carries. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * The four SEO plugins store templates, not text: `%title% %sep% %sitename%`
 * (Rank Math), `#post_title #separator_sa #site_title` (AIOSEO),
 * `%%post_title%% %%sep%% %%sitetitle%%` (SEOPress), `%%title%% %%sep%%
 * %%sitename%%` (Yoast). A migrated site needs the text. This renders each
 * syntax from the record itself, the way the plugin would on the page, without
 * the plugin running.
 *
 * Nothing is invented: a variable this cannot answer (a plugin-specific one, an
 * unknown custom field) is dropped from the text and named in `unresolved`, and
 * the separators it leaves dangling are tidied as the plugins tidy them.
 */
final class SeoRender {
	/** Each provider's variable names, mapped to one vocabulary. */
	const VARIABLES = array(
		'rank_math' => array(
			'title' => 'title', 'sitename' => 'sitename', 'sitedesc' => 'sitedesc', 'sep' => 'sep', 'excerpt' => 'excerpt', 'excerpt_only' => 'excerpt_only',
			'category' => 'category', 'primary_category' => 'category', 'categories' => 'categories', 'tag' => 'tag', 'tags' => 'tags', 'term' => 'term', 'term_description' => 'term_description',
			'name' => 'author', 'post_author' => 'author', 'date' => 'date', 'modified' => 'modified', 'currentyear' => 'currentyear', 'currentmonth' => 'currentmonth',
			'currentday' => 'currentday', 'currentdate' => 'currentdate', 'page' => 'page', 'pagenumber' => 'page', 'focuskw' => 'focuskw', 'id' => 'id',
			'parent_title' => 'parent_title', 'pt_single' => 'pt_single', 'pt_plural' => 'pt_plural', 'seo_title' => 'seo_title', 'seo_description' => 'seo_description', 'url' => 'url',
		),
		'aioseo' => array(
			'post_title' => 'title', 'site_title' => 'sitename', 'tagline' => 'sitedesc', 'separator_sa' => 'sep', 'post_excerpt' => 'excerpt_words', 'post_excerpt_only' => 'excerpt_only',
			'post_content' => 'content', 'categories' => 'categories', 'taxonomy_title' => 'term', 'taxonomy_description' => 'term_description', 'author_name' => 'author',
			'author_first_name' => 'author_first', 'author_last_name' => 'author_last', 'post_date' => 'date', 'post_day' => 'post_day', 'post_month' => 'post_month', 'post_year' => 'post_year',
			'current_date' => 'currentdate', 'current_day' => 'currentday', 'current_month' => 'currentmonth', 'current_year' => 'currentyear', 'permalink' => 'url', 'page_number' => 'page',
			'parent_title' => 'parent_title',
		),
		'seopress' => array(
			'post_title' => 'title', 'sitetitle' => 'sitename', 'tagline' => 'sitedesc', 'sep' => 'sep', 'post_excerpt' => 'excerpt', 'post_content' => 'content', 'post_date' => 'date',
			'post_modified_date' => 'modified', 'post_author' => 'author', 'post_category' => 'category', 'post_tag' => 'tag', '_category_title' => 'term', '_category_description' => 'term_description',
			'tag_title' => 'term', 'tag_description' => 'term_description', 'term_title' => 'term', 'term_description' => 'term_description', 'currentday' => 'currentday', 'currentmonth' => 'currentmonth',
			'currentyear' => 'currentyear', 'currentdate' => 'currentdate', 'current_pagination' => 'page', 'page' => 'page', 'cpt_plural' => 'pt_plural', 'author_first_name' => 'author_first',
			'author_last_name' => 'author_last', 'target_keyword' => 'focuskw', 'post_url' => 'url',
		),
		'yoast' => array(
			'title' => 'title', 'sitename' => 'sitename', 'sitedesc' => 'sitedesc', 'sep' => 'sep', 'excerpt' => 'excerpt', 'excerpt_only' => 'excerpt_only', 'category' => 'category',
			'primary_category' => 'category', 'tag' => 'tags', 'term_title' => 'term', 'term_description' => 'term_description', 'category_description' => 'term_description',
			'tag_description' => 'term_description', 'name' => 'author', 'date' => 'date', 'modified' => 'modified', 'currentyear' => 'currentyear', 'currentmonth' => 'currentmonth',
			'currentday' => 'currentday', 'currentdate' => 'currentdate', 'page' => 'page', 'pagenumber' => 'page', 'focuskw' => 'focuskw', 'id' => 'id', 'parent_title' => 'parent_title',
			'pt_single' => 'pt_single', 'pt_plural' => 'pt_plural', 'category_title' => 'term',
		),
	);

	/** What each plugin writes when nothing is set, per kind of page. */
	const DEFAULTS = array(
		'rank_math' => array( 'post' => array( '%title% %sep% %sitename%', '%excerpt%' ), 'term' => array( '%term% %sep% %sitename%', '%term_description%' ), 'home' => array( '%sitename% %page% %sep% %sitedesc%', '' ) ),
		'aioseo'    => array( 'post' => array( '#post_title #separator_sa #site_title', '#post_excerpt' ), 'term' => array( '#taxonomy_title #separator_sa #site_title', '#taxonomy_description' ), 'home' => array( '#site_title #separator_sa #tagline', '' ) ),
		// SEOPress writes nothing without a template: the page keeps WordPress's own title (null) and no description.
		'seopress'  => array( 'post' => array( null, '' ), 'term' => array( null, '' ), 'home' => array( null, '' ) ),
		'yoast'     => array( 'post' => array( '%%title%% %%page%% %%sep%% %%sitename%%', '' ), 'term' => array( '%%term_title%% Archives %%page%% %%sep%% %%sitename%%', '' ), 'home' => array( '%%sitename%% %%page%% %%sep%% %%sitedesc%%', '' ) ),
	);

	/**
	 * Render one template. `$values` answers the shared vocabulary; `$meta` reads
	 * a custom field for the plugins' custom-field variables. Returns the text
	 * and the variables left out.
	 */
	public static function render( $provider, $template, $values, $meta = null ) {
		$unresolved = array();
		$names = self::VARIABLES[ $provider ];
		$answer = static function ( $token, $name, $arg ) use ( $names, $values, $meta, &$unresolved ) {
			if ( null !== $meta && '' !== (string) $arg && in_array( $name, array( 'customfield', 'cf', 'custom_field', '_cf' ), true ) ) {
				$value = $meta( $arg );
				if ( null !== $value ) {
					return $value;
				}
			}
			if ( isset( $names[ $name ] ) && array_key_exists( $names[ $name ], $values ) ) {
				return (string) $values[ $names[ $name ] ];
			}
			$unresolved[] = $token;
			return '';
		};
		$text = (string) $template;
		if ( 'rank_math' === $provider ) {
			$text = preg_replace_callback( '/%([a-z_]+)(?:\(([^)%]*)\))?%/', static function ( $m ) use ( $answer ) { return $answer( $m[0], $m[1], $m[2] ?? '' ); }, $text );
		} elseif ( 'aioseo' === $provider ) {
			// A `#word` that is not one of AIOSEO's tags is text (a hashtag), not a variable.
			$text = preg_replace_callback( '/#(custom_field|tax_name)-([a-zA-Z0-9_-]+)|#([a-z_]+)/', static function ( $m ) use ( $answer, $names ) {
				if ( '' !== $m[1] ) {
					return $answer( $m[0], 'custom_field' === $m[1] ? 'custom_field' : 'tax_name', $m[2] );
				}
				return isset( $names[ $m[3] ] ) ? $answer( $m[0], $m[3], '' ) : $m[0];
			}, $text );
		} else {
			// Yoast and SEOPress: `%%name%%`; custom fields as `%%cf_key%%` / `%%_cf_key%%`.
			$text = preg_replace_callback( '/%%([a-zA-Z0-9_-]+)%%/', static function ( $m ) use ( $answer ) {
				if ( preg_match( '/^_?cf_(.+)$/D', $m[1], $cf ) ) {
					return $answer( $m[0], 'cf', $cf[1] );
				}
				return $answer( $m[0], $m[1], '' );
			}, $text );
		}
		return array( 'text' => self::tidy( $text, (string) ( $values['sep'] ?? '' ) ), 'unresolved' => array_values( array_unique( $unresolved ) ) );
	}

	/** Collapse the space and separators an empty variable leaves behind, as the plugins do. */
	public static function tidy( $text, $sep ) {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' !== $sep ) {
			$q = preg_quote( $sep, '/' );
			$text = preg_replace( '/(?:\s*' . $q . '\s*){2,}/u', ' ' . $sep . ' ', $text );
			$text = trim( preg_replace( '/^\s*' . $q . '\s*|\s*' . $q . '\s*$/u', '', $text ) );
		}
		return $text;
	}

	/** The shared vocabulary for one post, page or CPT record. */
	public static function post_values( $post, $sep, $focus = '', $primary = 0 ) {
		$categories = get_the_terms( $post, 'category' );
		$categories = is_array( $categories ) ? $categories : array();
		if ( $primary ) {
			usort( $categories, static function ( $a, $b ) use ( $primary ) { return ( (int) $b->term_id === (int) $primary ) <=> ( (int) $a->term_id === (int) $primary ); } );
		}
		$tags = get_the_terms( $post, 'post_tag' );
		$tags = is_array( $tags ) ? $tags : array();
		$author = get_userdata( (int) $post->post_author );
		$type = get_post_type_object( $post->post_type );
		$content = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( (string) $post->post_content ) ) ) ) );
		$own = '' !== trim( (string) $post->post_excerpt );
		$excerpt = $own ? (string) $post->post_excerpt : wp_html_excerpt( $content, 156, '' );
		return self::site_values( $sep ) + array(
			'title'          => html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'excerpt'        => $excerpt,
			// WordPress's own excerpt length, without its "more" suffix: what AIOSEO prints.
			'excerpt_words'  => $own ? (string) $post->post_excerpt : wp_trim_words( $content, 55, '' ),
			'excerpt_only'   => (string) $post->post_excerpt,
			'content'        => $content,
			'category'       => $categories ? $categories[0]->name : '',
			'categories'     => implode( ', ', wp_list_pluck( $categories, 'name' ) ),
			'tag'            => $tags ? $tags[0]->name : '',
			'tags'           => implode( ', ', wp_list_pluck( $tags, 'name' ) ),
			'author'         => $author ? $author->display_name : '',
			'author_first'   => $author ? (string) $author->first_name : '',
			'author_last'    => $author ? (string) $author->last_name : '',
			'date'           => mysql2date( (string) get_option( 'date_format' ), $post->post_date ),
			'modified'       => mysql2date( (string) get_option( 'date_format' ), $post->post_modified ),
			'post_day'       => mysql2date( 'd', $post->post_date ),
			'post_month'     => mysql2date( 'F', $post->post_date ),
			'post_year'      => mysql2date( 'Y', $post->post_date ),
			'focuskw'        => (string) $focus,
			'id'             => (string) $post->ID,
			'parent_title'   => $post->post_parent ? get_the_title( $post->post_parent ) : '',
			'pt_single'      => $type ? $type->labels->singular_name : '',
			'pt_plural'      => $type ? $type->labels->name : '',
			'url'            => (string) get_permalink( $post ),
		);
	}

	/** The shared vocabulary for one term archive. */
	public static function term_values( $term, $sep ) {
		return self::site_values( $sep ) + array(
			'term'             => html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'term_description' => wp_strip_all_tags( (string) $term->description ),
			'url'              => (string) get_term_link( $term ),
		);
	}

	/** Site-wide variables; page 1 of anything has no page number. */
	public static function site_values( $sep ) {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local time, as the plugins render the current date.
		return array(
			'sitename'     => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'sitedesc'     => html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'sep'          => (string) $sep,
			'page'         => '',
			'currentyear'  => gmdate( 'Y', $now ),
			'currentmonth' => date_i18n( 'F', $now ),
			'currentday'   => date_i18n( 'j', $now ),
			'currentdate'  => date_i18n( (string) get_option( 'date_format' ), $now ),
		);
	}

	/**
	 * An image as an absolute URL at the source origin (never rewritten), with
	 * its size when it is one of the site's attachments. `$value` is a URL, a
	 * root-relative path or an attachment id.
	 */
	public static function image( $value ) {
		$id = 0;
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$id = (int) $value;
			$url = (string) wp_get_attachment_url( $id );
		} else {
			$url = trim( (string) $value );
			if ( '' !== $url && '/' === $url[0] && '/' !== ( $url[1] ?? '' ) ) {
				$url = untrailingslashit( home_url() ) === '' ? $url : preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url( '/' ) ) . $url;
			}
			$id = '' !== $url ? (int) attachment_url_to_postid( $url ) : 0;
		}
		if ( '' === $url ) {
			return array();
		}
		$out = array( 'image' => $url );
		$meta = $id ? wp_get_attachment_metadata( $id ) : null;
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$out['image_width'] = (int) $meta['width'];
			$out['image_height'] = (int) $meta['height'];
		}
		return $out;
	}

	/**
	 * The `rendered` block for one provider, from what the record says for
	 * itself (`own`) or else the site's template for its kind (`template`) or
	 * else the plugin's default. `$spec` keys: title, description (each
	 * `[own, template, default]`), robots (`[index, follow, source]`),
	 * canonical, og and twitter (`[title, description, image]` own values).
	 */
	public static function block( $provider, $spec, $values, $meta = null ) {
		$source = array();
		$unresolved = array();
		$text = array();
		foreach ( array( 'title', 'description' ) as $field ) {
			list( $own, $template, $default ) = $spec[ $field ] + array( '', '', '' );
			if ( '' !== trim( (string) $own ) ) {
				list( $chosen, $source[ $field ] ) = array( $own, 'post' );
			} elseif ( '' !== trim( (string) $template ) ) {
				list( $chosen, $source[ $field ] ) = array( $template, 'post_type' );
			} else {
				list( $chosen, $source[ $field ] ) = array( $default, 'default' );
			}
			$result = null === $chosen
				? array( 'text' => 'title' === $field ? self::core_title( $values ) : '', 'unresolved' => array() )
				: self::render( $provider, $chosen, $values + array( 'seo_title' => $text['title'] ?? '' ), $meta );
			$text[ $field ] = $result['text'];
			$unresolved = array_merge( $unresolved, $result['unresolved'] );
		}
		$values += array( 'seo_title' => $text['title'], 'seo_description' => $text['description'] );
		list( $index, $follow, $source['robots'] ) = $spec['robots'];
		$rendered = array(
			'title'       => $text['title'],
			'description' => $text['description'],
			'canonical'   => (string) ( $spec['canonical'] ?? '' ),
			'robots'      => array( 'index' => $index ? 'index' : 'noindex', 'follow' => $follow ? 'follow' : 'nofollow' ),
		);
		foreach ( array( 'open_graph' => 'og', 'twitter' => 'twitter' ) as $key => $name ) {
			list( $title, $description, $image ) = ( $spec[ $name ] ?? array() ) + array( '', '', '' );
			$social = array( 'title' => $text['title'], 'description' => $text['description'] );
			foreach ( array( 'title' => $title, 'description' => $description ) as $part => $own ) {
				if ( '' !== trim( (string) $own ) ) {
					$result = self::render( $provider, $own, $values, $meta );
					$social[ $part ] = $result['text'];
					$unresolved = array_merge( $unresolved, $result['unresolved'] );
				}
			}
			$social += self::image( $image );
			$rendered[ $key ] = array_filter( $social, static function ( $v ) { return '' !== $v; } );
		}
		if ( ! empty( $spec['schema'] ) ) {
			$rendered['schema'] = array( 'graph' => self::render_tree( $provider, $spec['schema'], $values, $meta, $unresolved ) );
		}
		return array(
			'rendered'        => $rendered,
			'rendered_by'     => 'bridge',
			'template_source' => $source,
			'unresolved'      => array_values( array_unique( $unresolved ) ),
		);
	}

	/**
	 * The title WordPress itself prints when no plugin sets one (`wp_get_document_title`): the record's
	 * name and the site's, joined by its separator and texturized (` - ` reads ` – `). The front page is
	 * the site and its tagline.
	 */
	public static function core_title( $values ) {
		$name = $values['title'] ?? ( $values['term'] ?? null );
		$parts = null !== $name ? array( $name, $values['sitename'] ) : array_filter( array( $values['sitename'], $values['sitedesc'] ?? '' ) );
		$sep = apply_filters( 'document_title_separator', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter, read.
		return html_entity_decode( wptexturize( implode( " $sep ", $parts ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Every string in a stored JSON-LD node, rendered; the plugin's own bookkeeping key dropped. */
	private static function render_tree( $provider, $node, $values, $meta, &$unresolved ) {
		if ( is_string( $node ) ) {
			$result = self::render( $provider, $node, $values, $meta );
			$unresolved = array_merge( $unresolved, $result['unresolved'] );
			return false === strpos( $node, '%' ) ? $node : $result['text'];
		}
		if ( ! is_array( $node ) ) {
			return $node;
		}
		unset( $node['metadata'] );
		foreach ( $node as $key => $child ) {
			$node[ $key ] = self::render_tree( $provider, $child, $values, $meta, $unresolved );
		}
		return $node;
	}
}
