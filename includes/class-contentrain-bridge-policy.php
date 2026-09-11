<?php
/** Content selection and secret redaction. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Policy {

	/**
	 * Largest single exported file. GitHub delivery base64-encodes a file into
	 * memory, so the export cannot contain what it cannot deliver; larger media
	 * keeps its source URL and is reported.
	 */
	const MAX_FILE = 8388608;

	/** Total media budget for one export; beyond it, files keep their source URL. */
	const MAX_MEDIA_TOTAL = 536870912;

	/** Unknown metadata requires selection; these content keys are understood. */
	const CORE = array( '_thumbnail_id', '_wp_page_template', '_wp_attachment_image_alt', '_wp_attached_file', '_wp_attachment_metadata' );

	public static function sensitive( $key ) {
		return (bool) preg_match( '/pass(word|wd)?|secret|token|credential|api[_-]?key|private[_-]?key|authorization|cookie|session|email|(^|_)ip($|_)|user_agent/i', $key );
	}

	/** Never export secrets nested inside selected fields either. */
	public static function clean( $value, &$excluded, $path = '', $depth = 0 ) {
		if ( $depth > 12 || is_object( $value ) || is_resource( $value ) ) {
			$excluded[] = array( 'source' => $path, 'reason' => 'unsupported-value' );
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$child = $path . '/' . $key;
				if ( self::sensitive( (string) $key ) ) {
					$excluded[] = array( 'source' => $child, 'reason' => 'sensitive-key' );
					continue;
				}
				$out[ $key ] = self::clean( $item, $excluded, $child, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) && preg_match( '/-----BEGIN .*PRIVATE KEY-----|\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9]{20,})/', $value ) ) {
			$excluded[] = array( 'source' => $path, 'reason' => 'credential-pattern' );
			return null;
		}
		return $value;
	}

	public static function meta( $meta, $selected, &$excluded, $prefix ) {
		$out = array();
		foreach ( $meta as $key => $values ) {
			$known = in_array( $key, self::CORE, true ) || preg_match( '/^(_yoast_wpseo_|rank_math_|_aioseo_)/', $key );
			if ( self::sensitive( $key ) || ( ! $known && ! in_array( $key, $selected, true ) ) ) {
				$excluded[] = array( 'source' => $prefix . '/' . $key, 'reason' => self::sensitive( $key ) ? 'sensitive-key' : 'not-selected' );
				continue;
			}
			$value = is_array( $values ) && 1 === count( $values ) ? reset( $values ) : $values;
			// Bulk get_post_meta returns stored strings. Decode only selected values, never PHP classes.
			if ( is_string( $value ) && is_serialized( $value ) ) {
				$value = unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Selected WP metadata; class instantiation is explicitly disabled.
			}
			$out[ $key ] = self::clean( $value, $excluded, $prefix . '/' . $key );
		}
		return $out;
	}

	/**
	 * Canonical Contentrain JSON. `$order` mirrors `MODEL_FIELD_ORDER` in
	 * `@contentrain/types`: named keys first, the rest alphabetically, and — as
	 * `sortKeys` does — **only at the top level**, so a field that happens to be
	 * called `name` is not hoisted inside `fields`. Without this the first write
	 * from Contentrain reorders the file and shows a whole-file diff.
	 */
	public static function json( $value, $order = array() ) {
		$value = self::sort( $value, $order );
		$json  = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'Content cannot be encoded as UTF-8 JSON.' );
		}
		return preg_replace_callback( '/^( +)/m', static function ( $match ) { return str_repeat( ' ', (int) ( strlen( $match[1] ) / 2 ) ); }, $json ) . "\n";
	}

	/**
	 * Flat Contentrain frontmatter: scalar values and ordered relation IDs.
	 *
	 * `$strict` is the reader-compatibility guard below. It is always on when
	 * writing a store; the acceptance suite turns it off to emit a fixture of
	 * what this writer *would* produce, so a reader can be tested against real
	 * output instead of a reimplementation of it.
	 */
	public static function frontmatter( $data, $strict = true ) {
		ksort( $data, SORT_STRING );
		$lines = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$lines[] = $key . ':';
				foreach ( $value as $id ) {
					if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9-]+$/D', $id ) ) {
						throw new \RuntimeException( 'Document arrays must contain stable relation identifiers.' );
					}
					$lines[] = '  - ' . $id;
				}
			} else {
				$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				// The published @contentrain/types reader strips quotes without
				// unescaping JSON/YAML sequences. Never publish a store it reads lossy.
				//
				// Fixed upstream in Contentrain/ai PR #179: the reader now decodes
				// escapes and both readers share one grammar. Verified against this
				// writer's exact output — every value this guard rejects today round
				// trips losslessly, quotes, backslashes, newlines, tabs and all.
				// Lift this guard, and its two acceptance tests, once the package
				// carrying that fix is on npm and pinned in package.json.
				if ( false === $encoded || ( $strict && is_string( $value ) && false !== strpos( $encoded, chr( 92 ) ) ) ) {
					throw new \RuntimeException( 'Document metadata needs escaped characters unsupported by the current Contentrain reader. Export cannot be finalized until reader compatibility is resolved.' );
				}
				$lines[] = $key . ': ' . $encoded;
			}
		}
		return implode( "\n", $lines );
	}

	public static function parse_frontmatter( $yaml ) {
		$data = array();
		$key = null;
		foreach ( explode( "\n", $yaml ) as $line ) {
			if ( preg_match( '/^([a-z][a-z0-9_]*):(?: (.*))?$/D', $line, $match ) ) {
				$key = $match[1];
				$data[ $key ] = isset( $match[2] ) ? json_decode( $match[2], true ) : array();
			} elseif ( $key && preg_match( '/^  - ([a-z0-9-]+)$/D', $line, $match ) ) {
				$data[ $key ][] = $match[1];
			}
		}
		return $data;
	}

	private static function sort( $value, $order = array() ) {
		if ( is_object( $value ) ) {
			$vars = self::sort( get_object_vars( $value ), $order );
			return array() === $vars ? (object) array() : (object) $vars;
		}
		if ( is_array( $value ) ) {
			$list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $list ) {
				$keys = array_keys( $value );
				sort( $keys, SORT_STRING );
				$out = array();
				foreach ( array_merge( $order, $keys ) as $key ) {
					if ( array_key_exists( $key, $value ) && ! array_key_exists( $key, $out ) ) {
						$out[ $key ] = $value[ $key ];
					}
				}
				$value = $out;
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::sort( $item );
			}
			return $value;
		}
		return $value;
	}

	/** Key order of a model file; mirrors MODEL_FIELD_ORDER in @contentrain/types. */
	const MODEL_ORDER = array( 'id', 'name', 'kind', 'domain', 'i18n', 'title_field', 'description', 'content_path', 'locale_strategy', 'fields', 'form', 'comments' );
}
