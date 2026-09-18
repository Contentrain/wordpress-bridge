<?php
/** Bounded source-content candidate extraction; never executes source. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Text in theme and plugin source: gettext calls, literal HTML, echoed string
 * literals and JavaScript strings.
 *
 * Nothing found is dropped without a trace. Every occurrence comes back either
 * as a candidate or in `excluded` with the reason it is not interface text
 * (code, URL, number, placeholder-only, dynamic argument, secret…), and a file
 * that cannot be read comes back in `errors`. The counts have to add up, or a
 * string a person needed is missing and nobody can tell.
 */
final class Scanner {
	/** gettext functions and the positions of their translatable arguments. */
	const GETTEXT = array(
		'__' => array( 0 ), '_e' => array( 0 ), 'esc_html__' => array( 0 ), 'esc_html_e' => array( 0 ), 'esc_attr__' => array( 0 ), 'esc_attr_e' => array( 0 ),
		'_x' => array( 0 ), '_ex' => array( 0 ), 'esc_html_x' => array( 0 ), 'esc_attr_x' => array( 0 ), '_n' => array( 0, 1 ), '_nx' => array( 0, 1 ),
	);

	/** Excluded occurrences listed one by one per file; beyond this they are counted by reason. */
	const EXCLUDED_LISTED = 500;

	public static function files( $plugins = false ) {
		$roots = array( get_stylesheet_directory(), get_template_directory() );
		if ( $plugins ) {
			$active = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
			foreach ( $active as $plugin ) {
				if ( 0 === strpos( $plugin, 'contentrain-bridge/' ) ) {
					continue;
				}
				$roots[] = WP_PLUGIN_DIR . '/' . ( '.' === dirname( $plugin ) ? $plugin : dirname( $plugin ) );
			}
		}
		$files = array();
		foreach ( array_unique( $roots ) as $root ) {
			$real = realpath( $root );
			if ( ! $real || is_link( $root ) ) {
				continue;
			}
			$iterator = is_file( $real ) ? array( new \SplFileInfo( $real ) ) : new \RecursiveIteratorIterator( new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), static function ( $file ) {
				return ! $file->isLink() && ! in_array( $file->getFilename(), array( 'vendor', 'node_modules', '.git', 'tests', 'cache' ), true );
			} ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ! $file->isLink() && preg_match( '/\.(php|html|js|jsx|tsx)$/i', $file->getFilename() ) ) {
					$files[ $file->getPathname() ] = true;
					if ( count( $files ) > 10000 ) {
						throw new \RuntimeException( 'Source scan exceeds 10,000 files. Select a smaller source scope.' );
					}
				}
			}
		}
		$paths = array_keys( $files );
		sort( $paths, SORT_STRING );
		return $paths;
	}

	/**
	 * One file. Returns `candidates` (interface text), `excluded` (each with a
	 * reason), `excluded_counts` (reason => number, including those beyond the
	 * listing cap), `errors` and, for older callers, `warnings`.
	 */
	public static function scan( $file, $locale ) {
		$label = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $file );
		$result = array( 'candidates' => array(), 'excluded' => array(), 'excluded_counts' => array(), 'errors' => array(), 'warnings' => array() );
		if ( ! is_readable( $file ) || filesize( $file ) > 2 * MB_IN_BYTES ) {
			$result['errors'][] = array( 'source' => $label, 'reason' => is_readable( $file ) ? 'source-over-2MiB' : 'source-unreadable' );
			$result['warnings'][] = array( 'source' => $label, 'reason' => 'source-unreadable-or-over-2MiB' );
			return $result;
		}
		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme/plugin source file, read-only.
		if ( false === $source ) {
			$result['errors'][] = array( 'source' => $label, 'reason' => 'source-unreadable' );
			return $result;
		}
		$hash = hash( 'sha256', $source );
		$found = array();
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension ) {
			$found = self::php( $source, $label, $locale, $hash );
		} elseif ( 'html' === $extension ) {
			$found = self::html( $source, $label, $locale, $hash, 1, 'html' );
		} else {
			// Candidates, not automatic rewrites: JS literals may also be code constants.
			preg_match_all( '/([\x27\x22`])((?:\\\\.|(?!\1)[^\\\\\r\n])*)\1/', $source, $matches, PREG_OFFSET_CAPTURE );
			foreach ( $matches[2] as $match ) {
				$found[] = self::candidate( $match[0], $label, substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1, $locale, 'js', $hash, 'js' );
			}
		}
		foreach ( $found as $occurrence ) {
			if ( isset( $occurrence['reason'] ) ) {
				$result['excluded_counts'][ $occurrence['reason'] ] = ( $result['excluded_counts'][ $occurrence['reason'] ] ?? 0 ) + 1;
				if ( count( $result['excluded'] ) < self::EXCLUDED_LISTED ) {
					$result['excluded'][] = $occurrence;
				}
			} else {
				$result['candidates'][] = $occurrence;
			}
		}
		return $result;
	}

	/** PHP: gettext calls (literal or not), literal HTML between tags, and `echo`/`print` of a string literal. */
	private static function php( $source, $label, $locale, $hash ) {
		$out = array();
		$tokens = token_get_all( $source );
		$count = count( $tokens );
		// The template's HTML, whole. Parsed chunk by chunk, a link whose href is a
		// PHP block would read as the text `">Read more`. PHP blocks become the
		// newlines they spanned, so every line number still points at the source.
		$template = '';
		$has_html = false;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_INLINE_HTML === $token[0] ) {
				$template .= $token[1];
				$has_html = $has_html || '' !== trim( $token[1] );
			} else {
				$template .= str_repeat( "\n", substr_count( is_array( $token ) ? $token[1] : $token, "\n" ) );
			}
		}
		if ( $has_html ) {
			$out = self::html( $template, $label, $locale, $hash, 1, 'php-html' );
		}
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_ECHO, T_PRINT ), true ) ) {
				$next = self::next( $tokens, $i );
				$after = null === $next ? null : self::next( $tokens, $next );
				if ( null !== $after && is_array( $tokens[ $next ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $next ][0] && in_array( $tokens[ $after ], array( ';', ',' ), true ) ) {
					$out = array_merge( $out, self::html( self::literal( $tokens[ $next ][1] ), $label, $locale, $hash, $tokens[ $next ][2], 'php-echo' ) );
				}
				continue;
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( self::GETTEXT[ strtolower( $token[1] ) ] ) ) {
				continue;
			}
			$open = self::next( $tokens, $i );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}
			$call = strtolower( $token[1] );
			$arguments = self::arguments( $tokens, $open );
			$position_of_context = in_array( $call, array( '_x', '_ex', 'esc_html_x', 'esc_attr_x' ), true ) ? 1 : ( '_nx' === $call ? 3 : null );
			$gettext_context = null !== $position_of_context && isset( $arguments[ $position_of_context ]['literal'] ) ? $arguments[ $position_of_context ]['literal'] : null;
			foreach ( self::GETTEXT[ $call ] as $position ) {
				$argument = $arguments[ $position ] ?? null;
				if ( ! $argument ) {
					continue;
				}
				// gettext's own context separates meanings: _x( 'Post', 'noun' ) is not _x( 'Post', 'verb' ).
				$context = 'gettext' . ( null !== $gettext_context ? ':' . $gettext_context : '' ) . ( 1 === $position ? ':plural' : '' );
				if ( ! isset( $argument['literal'] ) ) {
					// A variable or an expression: its text exists only at run time.
					$out[] = self::excluded( $argument['code'], $label, $argument['line'], $locale, $context, 'php-gettext', 'dynamic' );
				} else {
					$out[] = self::candidate( $argument['literal'], $label, $argument['line'], $locale, $context, $hash, 'php-gettext' );
				}
			}
		}
		return $out;
	}

	/** The call's top-level arguments: `literal` when one string literal, and the code either way. */
	private static function arguments( $tokens, $open ) {
		$depth = 0;
		$arguments = array();
		$current = array( 'parts' => array(), 'line' => null );
		$count = count( $tokens );
		for ( $j = $open; $j < $count; ++$j ) {
			$t = $tokens[ $j ];
			$text = is_array( $t ) ? $t[1] : $t;
			if ( in_array( $text, array( '(', '[', '{' ), true ) ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( $current['parts'] ) {
						$arguments[] = $current;
					}
					break;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				$arguments[] = $current;
				$current = array( 'parts' => array(), 'line' => null );
				continue;
			}
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$current['parts'][] = $t;
			if ( null === $current['line'] && is_array( $t ) ) {
				$current['line'] = $t[2];
			}
		}
		return array_map(
			static function ( $argument ) {
				$parts = $argument['parts'];
				$out = array( 'line' => (int) $argument['line'], 'code' => implode( '', array_map( static function ( $t ) { return is_array( $t ) ? $t[1] : $t; }, $parts ) ) );
				if ( 1 === count( $parts ) && is_array( $parts[0] ) && T_CONSTANT_ENCAPSED_STRING === $parts[0][0] ) {
					$out['literal'] = self::literal( $parts[0][1] );
				}
				return $out;
			},
			$arguments
		);
	}

	private static function next( $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; ++$j ) {
			if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	private static function literal( $quoted ) {
		$text = substr( $quoted, 1, -1 );
		return "'" === $quoted[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $text ) : stripcslashes( $text );
	}

	/**
	 * Text nodes and text-bearing attributes of an HTML fragment. The context is
	 * the element (and attribute) the text sits in: `button`, `label`, `a`,
	 * `input@placeholder` — what a translator and a key need, not a DOM path.
	 */
	public static function html( $html, $source, $locale, $hash = '', $line = 1, $kind = 'html' ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			throw new \RuntimeException( 'PHP DOM extension is required for HTML text extraction.' );
		}
		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$xpath = new \DOMXPath( $doc );
		$out = array();
		foreach ( $xpath->query( '//text() | //@placeholder | //@aria-label | //@alt | //@title | //input[@type="submit" or @type="button"]/@value' ) as $node ) {
			$element = $node instanceof \DOMAttr ? $node->ownerElement : $node->parentNode;
			$tag = $element ? strtolower( $element->nodeName ) : 'text';
			$context = $node instanceof \DOMAttr ? $tag . '@' . $node->nodeName : $tag;
			$at = $line + max( 0, $node->getLineNo() - 1 );
			if ( ! $node instanceof \DOMAttr && '' === trim( $node->nodeValue ) ) {
				continue; // Whitespace between tags is layout, not an occurrence.
			}
			if ( ! $node instanceof \DOMAttr && in_array( $tag, array( 'script', 'style', 'code', 'pre' ), true ) ) {
				$out[] = self::excluded( $node->nodeValue, $source, $at, $locale, $context, $kind, 'code' );
				continue;
			}
			$out[] = self::candidate( $node->nodeValue, $source, $at, $locale, $context, $hash ?: hash( 'sha256', $html ), $kind );
		}
		return $out;
	}

	/** Why a string is not interface text, or null when it is. */
	public static function classify( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 'empty';
		}
		if ( strlen( $text ) > 2000 ) {
			return 'too-long';
		}
		$excluded = array();
		if ( null === Policy::clean( $text, $excluded, '' ) ) {
			return 'secret';
		}
		if ( false !== strpos( $text, '<?' ) ) {
			return 'code';
		}
		if ( preg_match( '#^(https?://|//|www\.|mailto:|tel:|/[\w./-]*$|\#[\w-]+$)#i', $text ) ) {
			return 'url';
		}
		if ( preg_match( '/^[\s\d.,:;%+\-\/()]+$/u', $text ) ) {
			return 'number';
		}
		// Only printf placeholders and punctuation left: nothing a person would translate.
		if ( ! preg_match( '/\p{L}/u', preg_replace( '/%(\d+\$)?[-+ 0#]*\d*(\.\d+)?[bcdeEfFgGosuxX%]/', '', $text ) ) ) {
			return preg_match( '/%(\d+\$)?[sdf]/', $text ) ? 'placeholder-only' : 'no-letters';
		}
		// An identifier, a hook or handle, a CSS class or a file name: one code-shaped token.
		if ( ! preg_match( '/\s/u', $text ) && ( preg_match( '/^[.#][a-z_][\w-]*$/i', $text ) || preg_match( '/^[a-z0-9]+([_\-.:\/][a-z0-9]+)+$/i', $text ) || preg_match( '/^[a-z]+[A-Z][A-Za-z0-9]*$/', $text ) || preg_match( '/[{}();=<>$\[\]]/', $text ) ) ) {
			return 'code';
		}
		if ( preg_match( '/[{};]\s*$|^\s*(function|return|var|const|let|if)\b|=>/', $text ) ) {
			return 'code';
		}
		return null;
	}

	private static function candidate( $text, $source, $line, $locale, $context, $hash, $kind ) {
		$reason = self::classify( $text );
		if ( null !== $reason ) {
			return self::excluded( $text, $source, $line, $locale, $context, $kind, $reason );
		}
		$text = trim( $text );
		$id = substr( hash( 'sha256', $source . ':' . $context . ':' . $line . ':' . $locale . ':' . $text ), 0, 20 );
		return array( 'id' => $id, 'key' => 'source.' . $id, 'value' => $text, 'locale' => $locale, 'source' => $source, 'line' => (int) $line, 'context' => $context, 'kind' => $kind, 'source_hash' => $hash, 'decision' => 'review' );
	}

	private static function excluded( $text, $source, $line, $locale, $context, $kind, $reason ) {
		// A secret is recorded by where it was, never by what it said.
		$value = 'secret' === $reason ? '[redacted]' : mb_substr( trim( (string) $text ), 0, 200 );
		return array( 'value' => $value, 'locale' => $locale, 'source' => $source, 'line' => (int) $line, 'context' => $context, 'kind' => $kind, 'reason' => $reason );
	}
}
