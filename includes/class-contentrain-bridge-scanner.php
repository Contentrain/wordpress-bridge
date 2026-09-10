<?php
/** Bounded source-content candidate extraction; never executes source. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Scanner {
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

	public static function scan( $file, $locale ) {
		$label = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $file );
		if ( ! is_readable( $file ) || filesize( $file ) > 2 * MB_IN_BYTES ) {
			return array( 'candidates' => array(), 'warnings' => array( array( 'source' => $label, 'reason' => 'source-unreadable-or-over-2MiB' ) ) );
		}
		$source = file_get_contents( $file );
		if ( false === $source ) {
			throw new \RuntimeException( 'Cannot read selected source file.' );
		}
		$hash = hash( 'sha256', $source );
		$out = array();
		if ( 'php' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			$tokens = token_get_all( $source );
			$call = null;
			$arguments = array();
			$depth = 0;
			foreach ( $tokens as $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && in_array( strtolower( $token[1] ), array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'esc_html_x', 'esc_attr_x' ), true ) ) {
					$call = strtolower( $token[1] );
					$arguments = array();
					$depth = 0;
				} elseif ( $call && '(' === $token ) {
					++$depth;
				} elseif ( $call && ')' === $token ) {
					--$depth;
					if ( 0 === $depth ) {
						$count = in_array( $call, array( '_n', '_nx' ), true ) ? 2 : 1;
						foreach ( array_slice( $arguments, 0, $count ) as $index => $argument ) {
							$out[] = self::candidate( $argument['text'], $label, $argument['line'], $locale, $call . ':' . $index, $hash );
						}
						$call = null;
					}
				} elseif ( $call && 1 === $depth && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$quoted = $token[1];
					$text = substr( $quoted, 1, -1 );
					$text = "'" === $quoted[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $text ) : stripcslashes( $text );
					$arguments[] = array( 'text' => $text, 'line' => $token[2] );
				} elseif ( is_array( $token ) && T_INLINE_HTML === $token[0] ) {
					$out = array_merge( $out, self::html( $token[1], $label, $locale, $hash, $token[2] ) );
				}
			}
		} elseif ( 'html' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			$out = self::html( $source, $label, $locale, $hash );
		} else {
			// Candidates, not automatic rewrites: JS literals may also be code constants.
			preg_match_all( '/([\x27\x22])((?:\\\\.|(?!\1)[^\\\\\r\n]){2,500})\1/', $source, $matches, PREG_OFFSET_CAPTURE );
			foreach ( $matches[2] as $match ) {
				if ( preg_match( '/[\p{L}]/u', $match[0] ) && preg_match( '/\s/u', $match[0] ) && ! preg_match( '#^(https?://|[./]|[a-z-]+:)#i', $match[0] ) ) {
					$out[] = self::candidate( $match[0], $label, substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1, $locale, 'js-literal-review', $hash );
				}
			}
		}
		$out = array_values( array_filter( $out ) );
		return array( 'candidates' => $out, 'warnings' => array() );
	}

	public static function html( $html, $source, $locale, $hash = '', $line = 1 ) {
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
		foreach ( $xpath->query( '//text()[not(ancestor::script or ancestor::style or ancestor::code or ancestor::pre)] | //@placeholder | //@aria-label | //@alt | //@title' ) as $node ) {
			$out[] = self::candidate( $node->nodeValue, $source, $line + max( 0, $node->getLineNo() - 1 ), $locale, $node->getNodePath(), $hash ?: hash( 'sha256', $html ) );
		}
		return array_values( array_filter( $out ) );
	}

	private static function candidate( $text, $source, $line, $locale, $context, $hash ) {
		$text = trim( $text );
		if ( strlen( $text ) < 2 || strlen( $text ) > 2000 || ! preg_match( '/\p{L}/u', $text ) || false !== strpos( $text, '<?' ) ) {
			return null;
		}
		$excluded = array();
		if ( null === Policy::clean( $text, $excluded, $source ) ) {
			return null;
		}
		$id = substr( hash( 'sha256', $source . ':' . $context . ':' . $line . ':' . $locale ), 0, 20 );
		return array( 'id' => $id, 'key' => 'source.' . $id, 'value' => $text, 'locale' => $locale, 'source' => $source, 'line' => $line, 'context' => $context, 'source_hash' => $hash, 'decision' => 'review' );
	}
}
