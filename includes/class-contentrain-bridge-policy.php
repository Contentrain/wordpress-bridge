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

	/**
	 * Page-builder layout meta, understood without selection: Elementor's element tree and page settings,
	 * Divi's builder switches. A migration rebuilds the page from these; `@contentrain/wp-import` never
	 * turns them into content fields (its CORE_META drops `_elementor_*`), so status and entry parity are
	 * untouched. Divi's `_et_pb_old_content` (a backup of the pre-builder body) is not layout and stays out.
	 */
	const BUILDER = '/^(_elementor_(data|page_settings|template_type|edit_mode|version)|_et_pb_(use_builder|page_layout|side_nav|post_hide_nav|show_title))$/';

	public static function sensitive( $key ) {
		// `webhook`/`hook_url`: form actions (Elementor Pro, Divi) keep secret-bearing URLs under these names.
		return (bool) preg_match( '/pass(word|wd)?|secret|token|credential|api[_-]?key|private[_-]?key|authorization|cookie|session|email|webhook|hook_url|(^|_)ip($|_)|user_agent/i', $key );
	}

	/** Never export secrets nested inside selected fields either. */
	/**
	 * Nesting a builder tree may reach: Elementor spends about two levels per container step
	 * (`elements` → index) plus repeaters inside widget settings, so twelve cut a widget five
	 * containers deep. Only builder meta gets this depth; everything else keeps twelve.
	 */
	const BUILDER_DEPTH = 64;

	public static function clean( $value, &$excluded, $path = '', $depth = 0, $max_depth = 12 ) {
		if ( $depth > $max_depth || is_object( $value ) || is_resource( $value ) ) {
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
				$out[ $key ] = self::clean( $item, $excluded, $child, $depth + 1, $max_depth );
			}
			return $out;
		}
		// Webhook URLs are credentials in URL form: whoever holds one can post into the channel.
		if ( is_string( $value ) && preg_match( '/-----BEGIN .*PRIVATE KEY-----|\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9]{20,})|hooks\.slack\.com\/services\/|discord(?:app)?\.com\/api\/webhooks\/|hooks\.zapier\.com\/hooks\/|hook\.[a-z0-9]+\.make\.com\//i', $value ) ) {
			$excluded[] = array( 'source' => $path, 'reason' => 'credential-pattern' );
			return null;
		}
		return $value;
	}

	public static function meta( $meta, $selected, &$excluded, $prefix, $protected = false ) {
		$out = array();
		foreach ( $meta as $key => $values ) {
			$builder = (bool) preg_match( self::BUILDER, $key );
			// A protected post's builder tree is its body in another shape: it follows the password rule, not the meta rule.
			if ( $builder && $protected ) {
				$excluded[] = array( 'source' => $prefix . '/' . $key, 'reason' => 'password-protected' );
				continue;
			}
			$known = in_array( $key, self::CORE, true ) || $builder || preg_match( '/^(_yoast_wpseo_|rank_math_|_aioseo_)/', $key );
			if ( self::sensitive( $key ) || ( ! $known && ! in_array( $key, $selected, true ) ) ) {
				$excluded[] = array( 'source' => $prefix . '/' . $key, 'reason' => self::sensitive( $key ) ? 'sensitive-key' : 'not-selected' );
				continue;
			}
			$value = is_array( $values ) && 1 === count( $values ) ? reset( $values ) : $values;
			// Bulk get_post_meta returns stored strings. Decode only selected values, never PHP classes.
			if ( is_string( $value ) && is_serialized( $value ) ) {
				$value = unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Selected WP metadata; class instantiation is explicitly disabled.
			}
			// Elementor keeps its tree as a JSON string; decoded, the secret filter reaches every widget
			// setting (a form widget's `email_to`, an integration's API key) instead of passing one opaque string.
			if ( '_elementor_data' === $key && is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : null;
				if ( null === $value ) {
					$excluded[] = array( 'source' => $prefix . '/' . $key, 'reason' => 'unparseable-builder-data' );
					continue;
				}
			}
			$out[ $key ] = self::clean( $value, $excluded, $prefix . '/' . $key, 0, $builder ? self::BUILDER_DEPTH : 12 );
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
	 * Scalars are JSON-encoded, so a quote, a backslash, a newline or a tab
	 * survives as an escape rather than breaking the document. That used to be
	 * refused outright: the published `@contentrain/types` reader stripped a
	 * scalar's quotes without decoding its escapes, so a title with a quote in
	 * it came back changed, and publishing a store the reader alters is worse
	 * than refusing to publish one.
	 *
	 * Fixed in `@contentrain/types@1.14.0`, and proven rather than assumed:
	 * `tests/reader-compat.mjs` reads this writer's own output back with the
	 * pinned reader and compares it to the values that went in. That gate runs
	 * in CI, so the compatibility cannot regress silently.
	 */
	public static function frontmatter( $data ) {
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
				// Defensive: a value JSON cannot represent at all stops the export
				// rather than writing a document whose metadata is unreadable.
				// Not reachable through the obvious route — `wp_json_encode` runs
				// `_wp_json_sanity_check`, which repairs invalid UTF-8 instead of
				// failing — so this is left untested rather than paired with a
				// test that asserts a case WordPress does not produce.
				if ( false === $encoded ) {
					throw new \RuntimeException( 'Document metadata could not be encoded.' );
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

	/** Keys in canonical order, recursively: what `json()` writes, as a value. */
	public static function canonical( $value ) {
		return self::sort( $value );
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
