<?php
/** The snapshot's RawIR v1 document, `bridge/rawir.json`. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * `bridge/rawir.json` is the snapshot's RawIR (`@contentrain/types` RawIR v1), so
 * a reader of the snapshot needs no assembler of its own. It is the document
 * `tools/prepare-migrate.mjs` builds from the same files, field for field:
 * the raw tables become arrays in the order a JSON reader lists their keys
 * (numeric ids ascending, then the rest), and the proposed fields (`seo`,
 * `routing`, `integrations`, `hardcoded_text`) ride beside it as there.
 *
 * Streamed like a content table, one row in memory at a time: a large site's
 * RawIR is well past the 8 MiB limit of a single written file.
 *
 * Written for remote (REST) exports only, and never delivered to GitHub: the
 * site's repository holds the store, not a raw copy of every record.
 */
final class Rawir {
	const PATH = 'bridge/rawir.json';

	public static function write( &$job ) {
		$output = Files::dir( $job['id'] ) . '/output';
		// A snapshot file as it is, already JSON; `null` when the export has no such file.
		$file = static function ( $path ) use ( $job, $output ) {
			return isset( $job['files'][ $path ] ) ? trim( Files::read( $output, $path ) ) : null;
		};
		$target = Files::path( $output, self::PATH );
		if ( ! Files::mkdir( dirname( $target ) ) ) {
			throw new \RuntimeException( 'Cannot create content directory.' );
		}
		$stream = fopen( $target . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded streaming output; WP_Filesystem has no append stream primitive.
		if ( ! $stream ) {
			throw new \RuntimeException( 'Cannot write RawIR.' );
		}
		try {
			$first = true;
			$field = static function ( $name, $json = '' ) use ( $stream, &$first ) {
				self::put( $stream, ( $first ? '{' : ',' ) . "\n" . wp_json_encode( $name ) . ': ' . $json );
				$first = false;
			};
			$field( 'version', '1' );
			$field( 'provenance', self::encode( array( 'kind' => 'bridge', 'fetched_at' => $job['created_at'], 'tool' => 'contentrain-bridge/' . CONTENTRAIN_BRIDGE_VERSION ) ) );
			$field( 'site', $file( 'bridge/site.json' ) ?? 'null' );
			foreach ( array( 'posts' => 'bridge/raw-posts.json', 'authors' => 'bridge/raw-authors.json', 'terms' => 'bridge/raw-terms.json', 'attachments' => 'bridge/raw-attachments.json', 'comments' => 'bridge/raw-comments.json' ) as $name => $path ) {
				$field( $name );
				self::rows( $stream, $job, $path, false );
			}
			$field( 'menus', $file( 'bridge/raw-menus.json' ) ?? '[]' );
			$field( 'language_pairs' );
			self::rows( $stream, $job, 'bridge/language-pairs.json', false );
			$field( 'options', $file( 'bridge/options.json' ) ?? '{}' );
			// RawIR.redirects is RawRedirect[]: what the site serves. Excluded rules stay in bridge/redirects.json.
			$redirects = json_decode( $file( 'bridge/redirects.json' ) ?? '{}' );
			$field( 'redirects', self::encode( $redirects->redirects ?? array() ) );
			// Not yet RawIR fields (proposed): carried beside it rather than dropped.
			$seo = json_decode( $file( 'bridge/seo.json' ) ?? 'null' );
			if ( is_object( $seo ) ) {
				unset( $seo->entries );
				$field( 'seo' );
				$open = '{';
				foreach ( get_object_vars( $seo ) as $key => $value ) {
					self::put( $stream, $open . "\n" . wp_json_encode( (string) $key ) . ': ' . self::encode( $value ) );
					$open = ',';
				}
				self::put( $stream, $open . "\n\"entries\": " );
				self::rows( $stream, $job, 'bridge/seo-entries.json', true );
				self::put( $stream, "\n}" );
			}
			$routing = $file( 'bridge/routing.json' );
			if ( null !== $routing ) {
				$field( 'routing', $routing );
			}
			$integrations = $file( 'bridge/integrations.json' );
			if ( null !== $integrations ) {
				$field( 'integrations', self::encode( json_decode( $integrations )->services ?? null ) );
			}
			$text = $file( 'bridge/hardcoded-text.json' );
			if ( null !== $text ) {
				$field( 'hardcoded_text', $text );
			}
			self::put( $stream, "\n}\n" );
		} finally {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handle.
		}
		if ( ! Files::fs()->move( $target . '.tmp', $target, true ) ) {
			throw new \RuntimeException( 'Cannot finalize RawIR.' );
		}
		Files::fs()->chmod( $target, 0600 );
		$job['files'][ self::PATH ] = array( 'sha256' => hash_file( 'sha256', $target ), 'bytes' => filesize( $target ) );
	}

	/**
	 * One raw table as a JSON array of its rows, or as an object keyed like the
	 * table, in the order a JSON reader lists an object's keys: array-index keys
	 * ascending, then the others as the table file holds them.
	 */
	private static function rows( $stream, $job, $path, $keyed ) {
		$rows = $job['tables'][ $path ] ?? array();
		ksort( $rows, SORT_STRING );
		$index = array();
		$named = array();
		foreach ( $rows as $key => $row ) {
			$key = (string) $key;
			if ( preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $key ) && (float) $key < 4294967295 ) {
				$index[ $key ] = $row;
			} else {
				$named[ $key ] = $row;
			}
		}
		uksort( $index, static function ( $a, $b ) { return (float) $a <=> (float) $b; } );
		$dir = Files::dir( $job['id'] );
		self::put( $stream, $keyed ? '{' : '[' );
		$first = true;
		foreach ( array_keys( $index + $named ) as $key ) {
			$json = trim( Files::read( $dir, Models::row_file( $path, $key ) ) );
			self::put( $stream, ( $first ? "\n" : ",\n" ) . ( $keyed ? wp_json_encode( (string) $key ) . ': ' : '' ) . $json );
			$first = false;
		}
		self::put( $stream, ( $first ? '' : "\n" ) . ( $keyed ? '}' : ']' ) );
	}

	/** JSON as the snapshot writes it; a decoded object stays an object, even an empty one. */
	private static function encode( $value ) {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'RawIR cannot be encoded as UTF-8 JSON.' );
		}
		return $json;
	}

	private static function put( $stream, $bytes ) {
		while ( '' !== $bytes ) {
			$written = fwrite( $stream, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded streaming output.
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'Cannot finish writing RawIR.' );
			}
			$bytes = substr( $bytes, $written );
		}
	}
}
