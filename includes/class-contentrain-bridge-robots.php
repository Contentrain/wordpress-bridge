<?php
/** The owner's switch that lets Contentrain Migrate read this site's pages through robots.txt. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. When the site owner turns it on (Tools > Contentrain Bridge), WordPress's own robots.txt gets one more
 * group, for the `ContentrainMigrate` crawler alone: the rules every crawler (`*`) has without the Disallows that close the
 * whole site (the ones matching `/`), then `Allow` for the REST API, the uploaded media and `/`. Every other rule stays, so what the owner keeps closed (wp-admin, a
 * private path) stays closed to it too; other crawlers see robots.txt exactly as before. Turning it off removes the group.
 *
 * A crawler follows only the group that names it (RFC 9309, 2.2.1), so the new group repeats the `*` rules rather than
 * relaxing them for everyone. A robots.txt file on disk is served by the web server, not by WordPress: the switch cannot
 * change it, stays off, and the screen shows the lines to add by hand.
 */
final class Robots {
	const OPTION = 'contentrain_bridge_robots_allow';
	const AGENT = 'ContentrainMigrate';

	public static function register() {
		// Last, so the group repeats the `*` rules every other plugin (an SEO plugin, core's sitemap line) has written.
		add_filter( 'robots_txt', array( self::class, 'filter' ), PHP_INT_MAX, 2 );
		add_action( 'admin_post_contentrain_bridge_robots', array( self::class, 'toggle' ) );
	}

	/** Whether the owner turned it on (the stored choice, whatever robots.txt is served from). */
	public static function chosen() {
		return (bool) get_option( self::OPTION, false );
	}

	/** Whether a robots.txt file on disk answers before WordPress does (in WordPress's own directory or the site's root). */
	public static function physical() {
		return '' !== self::physical_path();
	}

	/** The robots.txt on disk, or '' when WordPress serves it. */
	private static function physical_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		foreach ( array_unique( array( ABSPATH, get_home_path() ) ) as $dir ) {
			if ( file_exists( trailingslashit( $dir ) . 'robots.txt' ) ) {
				return trailingslashit( $dir ) . 'robots.txt';
			}
		}
		return '';
	}

	/** The robots.txt WordPress serves without this plugin's group (core's lines and every other plugin's filter). */
	public static function served_without() {
		$output = 'User-agent: *' . "\n" . 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n" . 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";
		remove_filter( 'robots_txt', array( self::class, 'filter' ), PHP_INT_MAX );
		$served = (string) apply_filters( 'robots_txt', $output, (bool) get_option( 'blog_public' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own robots.txt filter, read as do_robots() builds it.
		add_filter( 'robots_txt', array( self::class, 'filter' ), PHP_INT_MAX, 2 );
		return $served;
	}

	/** Whether the robots.txt WordPress serves already has a group of the owner's naming ContentrainMigrate. */
	public static function owner_group() {
		foreach ( self::groups( self::served_without() ) as $group ) {
			foreach ( $group['agents'] as $agent ) {
				if ( 0 === strcasecmp( $agent, self::AGENT ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** What `/about` tells a reader: the switch, and where robots.txt comes from (a file is the owner's to edit). */
	public static function state() {
		return array(
			'allow' => self::chosen() && ! self::physical(),
			'file' => self::physical() ? 'physical' : 'virtual',
			'owner_group' => self::owner_group(),
		);
	}

	public static function set( $allow ) {
		if ( $allow ) {
			update_option( self::OPTION, 1, true );
		} else {
			delete_option( self::OPTION );
		}
	}

	/** The admin form's handler: the owner's click, behind the export permission and its own nonce. */
	public static function toggle() {
		if ( ! Admin::permitted() ) {
			wp_die( esc_html__( 'Permission denied.', 'contentrain-bridge' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'contentrain_bridge_robots' );
		self::set( isset( $_POST['allow'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['allow'] ) ) );
		wp_safe_redirect( admin_url( 'tools.php?page=contentrain-bridge#cr-robots' ) );
		exit;
	}

	/** `robots_txt`: WordPress's robots.txt with the Migrate group added when the owner turned it on. */
	public static function filter( $output, $public = true ) {
		$output = (string) $output;
		if ( ! self::chosen() || self::physical() ) {
			return $output;
		}
		return self::with_group( $output );
	}

	/** A robots.txt with the Migrate group added; unchanged when it already has a group naming Migrate (the owner's own). */
	public static function with_group( $robots ) {
		$robots = (string) $robots;
		$groups = self::groups( $robots );
		foreach ( $groups as $group ) {
			foreach ( $group['agents'] as $agent ) {
				if ( 0 === strcasecmp( $agent, self::AGENT ) ) {
					return $robots;
				}
			}
		}
		return rtrim( $robots, "\n" ) . "\n\n" . self::group( $groups ) . "\n";
	}

	/** The lines of the Migrate group for this robots.txt (also what the screen shows for a file on disk). */
	public static function group( $groups ) {
		$lines = array( '# Contentrain Bridge: the site owner lets Contentrain Migrate read this site.', 'User-agent: ' . self::AGENT );
		foreach ( $groups as $group ) {
			if ( ! in_array( '*', $group['agents'], true ) ) {
				continue;
			}
			foreach ( $group['rules'] as $rule ) {
				// Only a closure of the whole site goes (it matches `/`); every other rule is the owner's, kept as written.
				if ( 'disallow' === $rule[0] && self::matches( $rule[1], '/' ) ) {
					continue;
				}
				$lines[] = ( 'allow' === $rule[0] ? 'Allow: ' : 'Disallow: ' ) . $rule[1];
			}
		}
		foreach ( self::opened() as $path ) {
			$lines[] = 'Allow: ' . $path;
		}
		return implode( "\n", array_unique( $lines ) );
	}

	/**
	 * The groups of a robots.txt (RFC 9309, 2.1): consecutive `user-agent` lines and the rules after them. Comments,
	 * blank lines and other records (`sitemap`) are not rules.
	 *
	 * @return array<int, array{agents: string[], rules: array<int, array{0: string, 1: string}>}>
	 */
	public static function groups( $robots ) {
		$groups = array();
		$current = null;
		$in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $robots ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$key = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( 'user-agent' === $key ) {
				if ( null === $current || $in_rules ) {
					if ( null !== $current ) {
						$groups[] = $current;
					}
					$current = array( 'agents' => array(), 'rules' => array() );
					$in_rules = false;
				}
				$current['agents'][] = $value;
			} elseif ( ( 'allow' === $key || 'disallow' === $key ) && null !== $current ) {
				$in_rules = true;
				if ( '' !== $value ) {
					$current['rules'][] = array( $key, $value );
				}
			}
		}
		if ( null !== $current ) {
			$groups[] = $current;
		}
		return $groups;
	}

	/**
	 * The paths a migration reads, opened by an Allow each: by longest match (RFC 9309, 2.2.2; an Allow wins a tie) they
	 * open the REST API and the uploaded media under any shorter Disallow (`/wp-`, `/wp-content/`), while every other path
	 * such a Disallow closes, wp-admin among them, stays closed.
	 */
	public static function opened() {
		$uploads = wp_parse_url( content_url( '/uploads/' ), PHP_URL_PATH );
		// `/wp-json/` always: without pretty permalinks `rest_url()` is `/index.php?rest_route=`, and readers try both.
		$rest = trailingslashit( (string) wp_parse_url( home_url( '/' . rest_get_url_prefix() ), PHP_URL_PATH ) );
		return array_values( array_unique( array( '/wp-json/', $rest, is_string( $uploads ) ? $uploads : '/wp-content/uploads/', '/' ) ) );
	}

	/** Whether a rule's path pattern matches a path (RFC 9309, 2.2.2/2.2.3: `*` any run, a trailing `$` the end). */
	public static function matches( $pattern, $path ) {
		$anchored = '$' === substr( $pattern, -1 );
		$body = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $body, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}

	/** The robots.txt on disk, when there is one: what the screen builds the lines to add from. */
	public static function physical_text() {
		$path = self::physical_path();
		if ( '' === $path ) {
			return '';
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the site root, read to show the owner lines to add.
		return false === $text ? '' : $text;
	}
}
