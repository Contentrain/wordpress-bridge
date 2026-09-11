<?php
/** Private, expiring job storage. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Files {
	/** Private temporary files are on this PHP host, never an FTP-backed WordPress install. */
	public static function fs() {
		static $fs;
		if ( ! $fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$fs = new \WP_Filesystem_Direct( null );
		}
		return $fs;
	}

	public static function mkdir( $dir ) {
		if ( is_link( $dir ) ) {
			return false;
		}
		if ( is_dir( $dir ) ) {
			return true;
		}
		return self::mkdir( dirname( $dir ) ) && self::fs()->mkdir( $dir, 0700 );
	}
	public static function root() {
		$base = realpath( sys_get_temp_dir() );
		$web  = realpath( ABSPATH );
		if ( ! $base || ! self::fs()->is_writable( $base ) || ( $web && ( $base === $web || 0 === strpos( $base, $web . DIRECTORY_SEPARATOR ) ) ) ) {
			throw new \RuntimeException( 'A writable temporary directory outside the WordPress web root is required.' );
		}
		$dir = $base . '/contentrain-bridge-' . substr( hash_hmac( 'sha256', ABSPATH . get_current_blog_id(), wp_salt( 'auth' ) ), 0, 20 );
		if ( ! self::mkdir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create private export storage.' );
		}
		return $dir;
	}

	public static function dir( $id ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new \RuntimeException( 'Invalid export identifier.' );
		}
		return self::root() . '/' . $id;
	}

	public static function path( $dir, $relative ) {
		if ( ! is_string( $relative ) || ! preg_match( '#^[a-zA-Z0-9_.\-/]+$#D', $relative ) || preg_match( '#(^|/)\.\.?(/|$)#', $relative ) || '/' === substr( $relative, 0, 1 ) ) {
			throw new \RuntimeException( 'Unsafe export path.' );
		}
		return $dir . '/' . $relative;
	}

	public static function put( $dir, $relative, $content ) {
		$path = self::path( $dir, $relative );
		if ( ! self::mkdir( dirname( $path ) ) ) {
			throw new \RuntimeException( 'Cannot create export directory.' );
		}
		$temp = $path . '.tmp';
		if ( ! self::fs()->put_contents( $temp, $content, 0600 ) || ! self::fs()->move( $temp, $path, true ) ) {
			throw new \RuntimeException( 'Cannot write export file.' );
		}
		self::fs()->chmod( $path, 0600 );
	}

	/** Stream a source file in; media must not pass through memory as a string. */
	public static function copy( $dir, $relative, $source ) {
		$path = self::path( $dir, $relative );
		if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
			throw new \RuntimeException( 'Source file is not readable.' );
		}
		if ( ! self::mkdir( dirname( $path ) ) ) {
			throw new \RuntimeException( 'Cannot create export directory.' );
		}
		$temp = $path . '.tmp';
		if ( ! self::fs()->copy( $source, $temp, true, 0600 ) || ! self::fs()->move( $temp, $path, true ) ) {
			throw new \RuntimeException( 'Cannot copy media into the export.' );
		}
		self::fs()->chmod( $path, 0600 );
		return (int) filesize( $path );
	}

	public static function read( $dir, $relative ) {
		$path = self::path( $dir, $relative );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( 'Export file does not exist.' );
		}
		$result = self::fs()->get_contents( $path );
		if ( false === $result ) {
			throw new \RuntimeException( 'Cannot read export file.' );
		}
		return $result;
	}

	public static function remove( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				self::fs()->delete( $file->getPathname(), false, 'd' );
			} else {
				self::fs()->delete( $file->getPathname(), false, 'f' );
			}
		}
		self::fs()->delete( $dir, false, 'd' );
	}

	public static function cleanup() {
		foreach ( glob( self::root() . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( is_link( $dir ) || ! preg_match( '/^[a-f0-9]{32}$/D', basename( $dir ) ) ) {
				continue;
			}
			$file = $dir . '/state.json';
			$state = is_file( $file ) ? json_decode( self::fs()->get_contents( $file ), true ) : array();
			$created = isset( $state['created_at'] ) ? strtotime( $state['created_at'] ) : filemtime( $dir );
			if ( $created < time() - DAY_IN_SECONDS ) {
				$lock = fopen( $dir . '/lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native advisory lock; filesystem API has no locking primitive.
				if ( $lock && flock( $lock, LOCK_EX | LOCK_NB ) ) {
					self::remove( $dir );
					flock( $lock, LOCK_UN );
				}
				if ( $lock ) {
					fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native advisory lock handle.
				}
			}
		}
	}
}
