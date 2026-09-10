<?php
/** Private, expiring job storage. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

final class Files {
	public static function root() {
		$base = realpath( sys_get_temp_dir() );
		$web  = realpath( ABSPATH );
		if ( ! $base || ! is_writable( $base ) || ( $web && ( $base === $web || 0 === strpos( $base, $web . DIRECTORY_SEPARATOR ) ) ) ) {
			throw new \RuntimeException( 'A writable temporary directory outside the WordPress web root is required.' );
		}
		$dir = $base . '/contentrain-bridge-' . substr( hash_hmac( 'sha256', ABSPATH . get_current_blog_id(), wp_salt( 'auth' ) ), 0, 20 );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
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
		if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0700, true ) ) {
			throw new \RuntimeException( 'Cannot create export directory.' );
		}
		$temp = $path . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) || ! rename( $temp, $path ) ) {
			throw new \RuntimeException( 'Cannot write export file.' );
		}
		chmod( $path, 0600 );
	}

	public static function read( $dir, $relative ) {
		$path = self::path( $dir, $relative );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( 'Export file does not exist.' );
		}
		$result = file_get_contents( $path );
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
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $dir );
	}

	public static function cleanup() {
		foreach ( glob( self::root() . '/*/state.json' ) ?: array() as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				$lock = fopen( dirname( $file ) . '/lock', 'c' );
				if ( $lock && flock( $lock, LOCK_EX | LOCK_NB ) ) {
					self::remove( dirname( $file ) );
					flock( $lock, LOCK_UN );
				}
				if ( $lock ) {
					fclose( $lock );
				}
			}
		}
	}
}
