<?php
/** Hardcoded interface text: every source, one inventory, one outcome each. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * The interface text a WordPress site shows that is not content: theme source,
 * site options, widgets, menu labels, Customizer settings, and what the rendered
 * pages actually say (buttons, form labels, footers, the 404 page).
 *
 * Occurrences merge into one candidate only when text, locale and context are
 * all the same: "Read more" on a button and "Read more" in a link are two
 * records, because a translation can differ between them. Every candidate
 * carries exactly one outcome — transfer (with its target), exclude (with its
 * reason) or error — and the totals are checked against the occurrences found.
 */
final class Text {
	const FORMAT = 'contentrain-bridge-hardcoded-text@1';

	/** Render states, relative to home: the pages a theme's own text shows up on. */
	public static function render_states() {
		$states = array( 'home' => home_url( '/' ), 'search' => add_query_arg( 's', 'contentrain-bridge-no-results', home_url( '/' ) ), 'not-found' => home_url( '/contentrain-bridge-not-found-' . substr( hash( 'sha256', home_url() ), 0, 8 ) . '/' ) );
		$post = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$page = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		if ( $post ) {
			$states['single'] = get_permalink( $post[0] );
		}
		if ( $page ) {
			$states['page'] = get_permalink( $page[0] );
		}
		/** Filters the pages whose rendered text is scanned (state => URL). */
		return apply_filters( 'contentrain_bridge_render_states', $states );
	}

	/**
	 * Site options, widgets, menu labels and Customizer values. Returns the same
	 * shape as Scanner::scan: candidates, excluded (with reasons) and errors.
	 */
	public static function settings( $locale ) {
		$found = array();
		foreach ( array( 'blogname' => 'site.title', 'blogdescription' => 'site.description' ) as $option => $target ) {
			$found[] = self::occurrence( (string) get_option( $option ), 'option:' . $option, 'option', $option, $locale, array( 'target' => $target ) );
		}
		// Classic widgets keep a title and sometimes HTML text; block widgets keep block markup.
		foreach ( self::widget_options() as $option ) {
			foreach ( (array) get_option( $option, array() ) as $number => $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}
				$source = $option . '#' . $number;
				if ( isset( $instance['title'] ) ) {
					$found[] = self::occurrence( (string) $instance['title'], 'widget:title', 'widget', $source, $locale );
				}
				foreach ( array( 'text', 'content' ) as $field ) {
					if ( isset( $instance[ $field ] ) && is_string( $instance[ $field ] ) ) {
						foreach ( Scanner::html( $instance[ $field ], $source, $locale, '', 1, 'widget' ) as $item ) {
							$item['context'] = 'widget:' . $item['context'];
							$found[] = $item;
						}
					}
				}
			}
		}
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				// Menus are exported as content (wp-menu-items); listed here so the inventory is whole.
				$found[] = self::occurrence( (string) $item->title, 'menu', 'menu', 'nav_menu_item#' . $item->ID, $locale, array( 'target' => 'content:wp-menu-items' ) );
			}
		}
		$mods = get_theme_mods();
		foreach ( is_array( $mods ) ? $mods : array() as $key => $value ) {
			$found[] = is_string( $value )
				? self::occurrence( $value, 'customizer:' . $key, 'customizer', 'theme_mods_' . get_option( 'stylesheet' ) . '#' . $key, $locale, array( 'target' => 'theme-settings.' . sanitize_key( $key ) ) )
				: self::occurrence( '', 'customizer:' . $key, 'customizer', 'theme_mods_' . get_option( 'stylesheet' ) . '#' . $key, $locale, array( 'reason' => 'not-text' ) );
		}
		return self::split( $found );
	}

	/**
	 * What one rendered page says, as an anonymous visitor sees it. Text inside
	 * the main content region is that page's content, exported elsewhere, and
	 * is excluded as `content` rather than skipped; form controls are interface
	 * text wherever they sit.
	 */
	public static function render( $state, $url, $locale ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 2, 'limit_response_size' => 2 * MB_IN_BYTES, 'headers' => array( 'Accept' => 'text/html' ) ) );
		if ( is_wp_error( $response ) ) {
			return array( 'candidates' => array(), 'excluded' => array(), 'excluded_counts' => array(), 'errors' => array( array( 'source' => 'render:' . $state, 'reason' => 'render-fetch-failed: ' . $response->get_error_code() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$html = (string) wp_remote_retrieve_body( $response );
		if ( ( $code >= 500 || '' === $html ) || ( $code >= 400 && 'not-found' !== $state ) ) {
			return array( 'candidates' => array(), 'excluded' => array(), 'excluded_counts' => array(), 'errors' => array( array( 'source' => 'render:' . $state, 'reason' => 'render-http-' . $code ) ) );
		}
		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$xpath = new \DOMXPath( $doc );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$found = array();
		if ( ! $body ) {
			return array( 'candidates' => array(), 'excluded' => array(), 'excluded_counts' => array(), 'errors' => array( array( 'source' => 'render:' . $state, 'reason' => 'render-no-body' ) ) );
		}
		foreach ( $xpath->query( './/text() | .//@placeholder | .//@aria-label | .//@alt | .//@title | .//input[@type="submit" or @type="button"]/@value', $body ) as $node ) {
			$element = $node instanceof \DOMAttr ? $node->ownerElement : $node->parentNode;
			$tag = strtolower( $element->nodeName );
			if ( ! $node instanceof \DOMAttr && '' === trim( $node->nodeValue ) ) {
				continue;
			}
			$landmark = self::landmark( $element );
			$context = ( $landmark ? $landmark . '>' : '' ) . $tag . ( $node instanceof \DOMAttr ? '@' . $node->nodeName : '' );
			$source = 'render:' . $state;
			if ( ! $node instanceof \DOMAttr && in_array( $tag, array( 'script', 'style', 'noscript', 'template' ), true ) ) {
				$found[] = self::occurrence( $node->nodeValue, $context, 'render', $source, $locale, array( 'reason' => 'code' ) );
				continue;
			}
			$control = in_array( $tag, array( 'button', 'label', 'input', 'select', 'option', 'textarea', 'legend' ), true ) || self::inside( $element, 'form' );
			$in_content = self::inside_content( $element );
			$found[] = self::occurrence( $node->nodeValue, $context, 'render', $source, $locale, $in_content && ! $control ? array( 'reason' => 'content' ) : array() );
		}
		return self::split( $found );
	}

	/**
	 * Merge occurrences into candidates: same text, locale and context only.
	 * Returns candidates keyed by id, each with occurrences, one outcome and a key.
	 */
	public static function merge( $occurrences, $content = array() ) {
		$candidates = array();
		$content = array_flip( array_map( static function ( $t ) { return trim( wp_strip_all_tags( (string) $t ) ); }, $content ) );
		foreach ( $occurrences as $o ) {
			$text = trim( (string) $o['value'] );
			// Rendered text that is exported content elsewhere (a post title in a widget list).
			if ( 'render' === $o['kind'] && ! isset( $o['reason'] ) && isset( $content[ $text ] ) ) {
				$o['reason'] = 'content';
			}
			$id = substr( hash( 'sha256', $text . "\0" . $o['locale'] . "\0" . $o['context'] ), 0, 20 );
			if ( ! isset( $candidates[ $id ] ) ) {
				$candidates[ $id ] = array( 'id' => $id, 'value' => $text, 'locale' => $o['locale'], 'context' => $o['context'], 'occurrences' => array(), 'reasons' => array(), 'targets' => array() );
			}
			$candidates[ $id ]['occurrences'][] = array_intersect_key( $o, array_flip( array( 'kind', 'source', 'line' ) ) );
			if ( isset( $o['reason'] ) ) {
				$candidates[ $id ]['reasons'][ $o['reason'] ] = true;
			}
			if ( isset( $o['target'] ) ) {
				$candidates[ $id ]['targets'][ $o['target'] ] = true;
			}
		}
		foreach ( $candidates as $id => &$c ) {
			// One outcome per candidate. Text and context are the same across its
			// occurrences, so a reason found on one applies to all; every reason is kept.
			$reasons = array_keys( $c['reasons'] );
			$targets = array_keys( $c['targets'] );
			sort( $reasons );
			sort( $targets );
			if ( $reasons ) {
				$c['outcome'] = 'exclude';
				$c['reason'] = implode( ',', $reasons );
			} else {
				$c['outcome'] = 'transfer';
				$c['target'] = $targets ? implode( ',', $targets ) : 'dictionary:ui-strings';
			}
			$c['key'] = self::key( $c );
			$c['source'] = $c['occurrences'][0]['source'];
			$c['line'] = $c['occurrences'][0]['line'] ?? 0;
			$c['kind'] = $c['occurrences'][0]['kind'];
			$c['decision'] = 'transfer' === $c['outcome'] && 0 === strpos( $c['target'], 'dictionary:' ) ? 'review' : ( 'transfer' === $c['outcome'] ? 'include' : 'exclude' );
			unset( $c['reasons'], $c['targets'] );
		}
		unset( $c );
		// What a page renders is often a source string rendered: link it to that
		// candidate instead of transferring the same words twice. Contexts are not
		// merged; the render record stays, excluded, pointing at its source.
		$sourced = array();
		foreach ( $candidates as $c ) {
			if ( 'transfer' === $c['outcome'] && 'render' !== $c['kind'] ) {
				$sourced[ $c['locale'] . "\0" . $c['value'] ][] = $c['id'];
			}
		}
		foreach ( $candidates as $id => $c ) {
			if ( 'render' !== $c['kind'] || 'transfer' !== $c['outcome'] ) {
				continue;
			}
			$related = $sourced[ $c['locale'] . "\0" . $c['value'] ] ?? array();
			if ( ! $related ) {
				// A source string with a run-time number beside it ("All rights reserved. 2026").
				foreach ( $sourced as $pair => $ids ) {
					list( $locale, $text ) = explode( "\0", $pair, 2 );
					if ( $locale === $c['locale'] && mb_strlen( $text ) >= 4 && false !== strpos( $c['value'], $text ) && preg_match( '/^[\s\d.,:;|\-–—·©()]*$/u', str_replace( $text, '', $c['value'] ) ) ) {
						$related = array_merge( $related, $ids );
					}
				}
			}
			if ( $related ) {
				$candidates[ $id ]['outcome'] = 'exclude';
				$candidates[ $id ]['reason'] = 'rendered-from-source';
				$candidates[ $id ]['related'] = array_values( array_unique( $related ) );
				$candidates[ $id ]['decision'] = 'exclude';
				unset( $candidates[ $id ]['target'] );
			}
		}
		ksort( $candidates );
		$keys = array();
		foreach ( $candidates as $c ) {
			if ( isset( $keys[ $c['key'] ] ) ) {
				throw new \RuntimeException( 'Interface text key collision: ' . $c['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic data is escaped at the admin output boundary or JSON encoded.
			}
			$keys[ $c['key'] ] = true;
		}
		return $candidates;
	}

	/**
	 * A key that is a function of the text and its context only: moving the
	 * string to another file or line does not change it, and two different
	 * (text, context) pairs cannot share one. Readable part first, then a short
	 * digest of exactly what makes the pair unique.
	 */
	public static function key( $candidate ) {
		$context = (string) $candidate['context'];
		if ( 0 === strpos( $context, 'customizer:' ) ) {
			return 'theme-settings.' . sanitize_key( substr( $context, 11 ) );
		}
		$group = preg_match( '/^(gettext|widget|option|menu)/', $context, $m ) ? $m[1] : 'ui';
		$area = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( preg_replace( '/^(gettext|widget|option|menu):?/', '', $context ) ) ), '-' );
		$words = array_slice( array_filter( explode( '-', sanitize_title( remove_accents( wp_strip_all_tags( $candidate['value'] ) ) ) ) ), 0, 5 );
		$slug = implode( '-', $words ) ?: 'text';
		$key = $group . '.' . ( '' !== $area ? substr( $area, 0, 40 ) . '.' : '' ) . substr( $slug, 0, 50 );
		return $key . '-' . substr( hash( 'sha256', $candidate['value'] . "\0" . $context ), 0, 6 );
	}

	/** The delivered inventory: every candidate, its occurrences and outcome, and the totals. */
	public static function document( $candidates, $errors, $counts ) {
		$by_outcome = array_fill_keys( array( 'transfer', 'exclude' ), 0 ) + array( 'error' => count( $errors ) );
		$occurrences = 0;
		foreach ( $candidates as $c ) {
			++$by_outcome[ $c['outcome'] ];
			$occurrences += count( $c['occurrences'] );
		}
		return array(
			'format'     => self::FORMAT,
			'candidates' => array_values( $candidates ),
			'errors'     => array_values( $errors ),
			'totals'     => array(
				'occurrences'        => $occurrences + (int) ( $counts['unlisted_excluded'] ?? 0 ),
				'occurrences_listed' => $occurrences,
				'unlisted_excluded'  => (int) ( $counts['unlisted_excluded'] ?? 0 ),
				'unlisted_by_reason' => (object) ( $counts['unlisted_by_reason'] ?? array() ),
				'candidates'         => count( $candidates ),
				'by_outcome'         => $by_outcome,
				'sources'            => (object) ( $counts['sources'] ?? array() ),
			),
		);
	}

	/** Text that is exported as content, so a page rendering it is not showing interface text. */
	public static function content_texts() {
		$texts = array( get_option( 'blogname' ), get_option( 'blogdescription' ) );
		foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids' ) ) as $id ) {
			$texts[] = get_the_title( $id );
		}
		foreach ( get_terms( array( 'hide_empty' => false, 'number' => 2000 ) ) as $term ) {
			$texts[] = $term->name;
		}
		foreach ( get_users( array( 'fields' => array( 'display_name' ), 'number' => 500 ) ) as $user ) {
			$texts[] = $user->display_name;
		}
		return array_values( array_filter( array_map( 'html_entity_decode', array_map( 'strval', $texts ) ) ) );
	}

	private static function occurrence( $text, $context, $kind, $source, $locale, $extra = array() ) {
		$reason = $extra['reason'] ?? Scanner::classify( $text );
		$o = array( 'value' => 'secret' === $reason ? '[redacted]' : trim( (string) $text ), 'locale' => $locale, 'context' => $context, 'kind' => $kind, 'source' => $source, 'line' => 0 );
		if ( null !== $reason ) {
			$o['reason'] = $reason;
		} elseif ( isset( $extra['target'] ) ) {
			$o['target'] = $extra['target'];
		}
		return $o;
	}

	private static function split( $found ) {
		$out = array( 'candidates' => array(), 'excluded' => array(), 'excluded_counts' => array(), 'errors' => array() );
		foreach ( $found as $o ) {
			if ( isset( $o['reason'] ) ) {
				$out['excluded'][] = $o;
				$out['excluded_counts'][ $o['reason'] ] = ( $out['excluded_counts'][ $o['reason'] ] ?? 0 ) + 1;
			} else {
				$out['candidates'][] = $o;
			}
		}
		return $out;
	}

	private static function widget_options() {
		global $wpdb;
		return (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' AND option_name <> 'widget_recent-comments' ORDER BY option_name" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerates widget option names; no API lists them.
	}

	private static function landmark( $element ) {
		for ( $e = $element; $e && $e instanceof \DOMElement; $e = $e->parentNode ) {
			$role = $e->getAttribute( 'role' );
			if ( in_array( $e->nodeName, array( 'header', 'footer', 'nav', 'aside', 'main', 'form' ), true ) ) {
				return $e->nodeName;
			}
			if ( in_array( $role, array( 'banner', 'contentinfo', 'navigation', 'complementary', 'search', 'main' ), true ) ) {
				return $role;
			}
		}
		return null;
	}

	private static function inside( $element, $name ) {
		for ( $e = $element; $e && $e instanceof \DOMElement; $e = $e->parentNode ) {
			if ( $name === $e->nodeName ) {
				return true;
			}
		}
		return false;
	}

	/** The page's own content: an article, or an element WordPress marks as post content or title. */
	private static function inside_content( $element ) {
		for ( $e = $element; $e && $e instanceof \DOMElement; $e = $e->parentNode ) {
			$class = ' ' . $e->getAttribute( 'class' ) . ' ';
			if ( 'article' === $e->nodeName || preg_match( '/ (entry-content|entry-title|wp-block-post-content|wp-block-post-title|wp-block-post-excerpt|comment-content|site-title|site-description|menu-item) /', $class ) ) {
				return true;
			}
		}
		return false;
	}
}
