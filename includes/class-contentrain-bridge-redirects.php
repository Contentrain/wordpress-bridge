<?php
/** Redirect rules from every place WordPress keeps them. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * One normalized list, `RawRedirect` from `@contentrain/types`:
 * `{ from, to, status, source }`, plus `id`, `match` and `regex`.
 *
 * Accounting is the point. Every rule a source holds lands in exactly one of
 * `redirects` (the site serves it) or `excluded` (with the reason it is not a
 * plain redirect: disabled, conditional, "gone"). A migration that quietly
 * drops a redirect loses the ranking of the page it pointed at, so a count
 * that does not add up is a failed export, not a smaller one.
 *
 * A "gone" rule (410, 451) is served as such: `status` 410/451 and an empty
 * `to`. The migrated site answers the same, rather than a 404.
 */
final class Redirects {
	const FORMAT = 'contentrain-bridge-redirects@1';

	/** Whether the source being read is running: an inactive plugin's rules are not served. */
	private static $serving = true;

	public static function document() {
		$doc = array( 'format' => self::FORMAT, 'sources' => array(), 'redirects' => array(), 'excluded' => array() );
		self::redirection( $doc );
		self::yoast_premium( $doc );
		self::rank_math( $doc );
		self::safe_redirect_manager( $doc );
		self::simple_301( $doc );
		self::htaccess( $doc );
		self::old_slugs( $doc );
		usort( $doc['redirects'], array( self::class, 'order' ) );
		usort( $doc['excluded'], array( self::class, 'order' ) );
		$excluded = array();
		$doc = Policy::clean( $doc, $excluded, 'redirects' );
		if ( $excluded ) {
			$doc['redacted'] = $excluded;
		}
		return $doc;
	}

	/** Redirection (John Godley): the most common redirect plugin, in its own tables. */
	private static function redirection( &$doc ) {
		global $wpdb;
		$items = $wpdb->prefix . 'redirection_items';
		if ( ! self::table( $items ) ) {
			$doc['sources']['redirection'] = self::source( 'absent' );
			return;
		}
		$groups = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, module_id FROM %i', $wpdb->prefix . 'redirection_groups' ), ARRAY_A ) as $group ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
			$groups[ (int) $group['id'] ] = $group;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url, regex, group_id, status, action_type, action_code, action_data, match_type, match_data FROM %i ORDER BY id', $items ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
		self::redirection_rules( $doc, $groups, $rows, get_option( 'redirection_options' ), defined( 'REDIRECTION_VERSION' ) );
		$doc['sources']['redirection'] = self::source( defined( 'REDIRECTION_VERSION' ) ? 'active' : 'inactive-with-data', count( $rows ) );
	}

	/**
	 * Redirection's rows as rules: `$groups` by id { status, module_id },
	 * `$rows` its items' columns, `$site` its saved options (false when never
	 * saved). Public for the parity fixture, which feeds it the same rows the
	 * REST reader sees.
	 */
	public static function redirection_rules( &$doc, $groups, $rows, $site, $serving ) {
		$modules = array( 1 => 'wordpress', 2 => 'apache', 3 => 'nginx' );
		self::$serving = $serving;
		foreach ( $rows as $row ) {
			$group = $groups[ (int) $row['group_id'] ] ?? null;
			$rule = array( 'id' => 'redirection:' . $row['id'], 'from' => (string) $row['url'], 'source' => 'redirection', 'match' => (string) $row['match_type'], 'regex' => (bool) $row['regex'] ) + self::redirection_flags( $row['match_data'] ?? null, $site, (bool) $row['regex'] );
			if ( $group && isset( $modules[ (int) $group['module_id'] ] ) && 1 !== (int) $group['module_id'] ) {
				$rule['served_by'] = $modules[ (int) $group['module_id'] ];
			}
			if ( 'enabled' !== $row['status'] || ( $group && 'enabled' !== $group['status'] ) ) {
				self::exclude( $doc, $rule, 'disabled' );
			} elseif ( 'error' === $row['action_type'] && self::gone( (int) $row['action_code'] ) ) {
				self::add( $doc, $rule, '', (int) $row['action_code'] );
			} elseif ( 'url' !== $row['action_type'] ) {
				// error (404), pass-through, "do nothing", random: not a from→to rule.
				$rule['status'] = (int) $row['action_code'];
				self::exclude( $doc, $rule, 'not-a-redirect:' . $row['action_type'] );
			} elseif ( 'url' !== $row['match_type'] ) {
				// Login, referrer, agent, cookie, header, IP, server…: the target depends on the request.
				$rule['condition'] = self::decode( $row['action_data'] );
				$rule['status'] = (int) $row['action_code'];
				self::exclude( $doc, $rule, 'conditional-match:' . $row['match_type'] );
			} else {
				self::add( $doc, $rule, (string) $row['action_data'], (int) $row['action_code'] );
			}
		}
	}

	/** Yoast SEO Premium keeps its redirects in one option. */
	private static function yoast_premium( &$doc ) {
		$rules = get_option( 'wpseo-premium-redirects-base', null );
		if ( ! is_array( $rules ) ) {
			$doc['sources']['yoast_premium'] = self::source( 'absent' );
			return;
		}
		self::$serving = defined( 'WPSEO_PREMIUM_VERSION' );
		foreach ( array_values( $rules ) as $i => $item ) {
			$regex = 'regex' === ( $item['format'] ?? 'plain' );
			$origin = (string) ( $item['origin'] ?? '' );
			$rule = array( 'id' => 'yoast_premium:' . $i, 'from' => $regex || preg_match( '#^(/|https?://)#', $origin ) ? $origin : '/' . $origin, 'source' => 'yoast_premium', 'match' => $regex ? 'regex' : 'url', 'regex' => $regex );
			$status = (int) ( $item['type'] ?? 301 );
			if ( self::gone( $status ) ) {
				self::add( $doc, $rule, '', $status );
				continue;
			}
			$target = (string) ( $item['url'] ?? '' );
			self::add( $doc, $rule, preg_match( '#^(/|https?://)#', $target ) ? $target : '/' . $target, $status );
		}
		$doc['sources']['yoast_premium'] = self::source( defined( 'WPSEO_PREMIUM_VERSION' ) ? 'active' : 'inactive-with-data', count( $rules ) );
	}

	/** Rank Math's redirection module: one row, several source patterns. */
	private static function rank_math( &$doc ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table( $table ) ) {
			$doc['sources']['rank_math'] = self::source( 'absent' );
			return;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sources, url_to, header_code, status FROM %i ORDER BY id', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; no read API when inactive.
		$count = 0;
		self::$serving = defined( 'RANK_MATH_VERSION' );
		foreach ( $rows as $row ) {
			$sources = self::decode( $row['sources'] );
			foreach ( is_array( $sources ) ? array_values( $sources ) : array( array() ) as $i => $source ) {
				++$count;
				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				$pattern = (string) ( $source['pattern'] ?? '' );
				$rule = array( 'id' => 'rank_math:' . $row['id'] . ':' . $i, 'from' => 'regex' === $comparison || preg_match( '#^(/|https?://)#', $pattern ) ? $pattern : '/' . $pattern, 'source' => 'rank_math', 'match' => 'exact' === $comparison ? 'url' : $comparison, 'regex' => 'regex' === $comparison );
				if ( 'case' === ( $source['ignore'] ?? '' ) ) {
					$rule['case_insensitive'] = true;
				}
				$status = (int) $row['header_code'];
				if ( 'active' !== $row['status'] ) {
					self::exclude( $doc, $rule, 'disabled' );
				} elseif ( self::gone( $status ) ) {
					self::add( $doc, $rule, '', $status );
				} else {
					self::add( $doc, $rule, (string) $row['url_to'], $status );
				}
			}
		}
		$doc['sources']['rank_math'] = self::source( defined( 'RANK_MATH_VERSION' ) ? 'active' : 'inactive-with-data', $count );
	}

	/** Safe Redirect Manager: a `redirect_rule` post per rule, fields in post meta. */
	private static function safe_redirect_manager( &$doc ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'redirect_rule' AND post_status NOT IN ('auto-draft') ORDER BY ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The post type is unregistered when the plugin is inactive.
		if ( ! $ids ) {
			$doc['sources']['safe_redirect_manager'] = self::source( 'absent' );
			return;
		}
		self::$serving = defined( 'SRM_VERSION' );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			$regex = (bool) get_post_meta( $id, '_redirect_rule_from_regex', true );
			$rule = array( 'id' => 'safe_redirect_manager:' . $id, 'from' => (string) get_post_meta( $id, '_redirect_rule_from', true ), 'source' => 'safe_redirect_manager', 'match' => $regex ? 'regex' : 'url', 'regex' => $regex );
			$status = (int) get_post_meta( $id, '_redirect_rule_status_code', true ) ?: 302;
			if ( ! $post || 'publish' !== $post->post_status ) {
				self::exclude( $doc, $rule, 'disabled' );
			} elseif ( self::gone( $status ) ) {
				self::add( $doc, $rule, '', $status );
			} elseif ( in_array( $status, array( 403, 404 ), true ) ) {
				$rule['status'] = $status;
				self::exclude( $doc, $rule, 'not-a-redirect:status-' . $status );
			} else {
				self::add( $doc, $rule, (string) get_post_meta( $id, '_redirect_rule_to', true ), $status );
			}
		}
		$doc['sources']['safe_redirect_manager'] = self::source( defined( 'SRM_VERSION' ) ? 'active' : 'inactive-with-data', count( $ids ) );
	}

	/**
	 * Simple 301 Redirects: one option, request → destination. With wildcards
	 * on, a `*` in the request matches anything and the destination's `*`
	 * takes what it matched: a regex rule.
	 */
	private static function simple_301( &$doc ) {
		$rules = get_option( '301_redirects', null );
		if ( ! is_array( $rules ) ) {
			$doc['sources']['simple_301_redirects'] = self::source( 'absent' );
			return;
		}
		self::$serving = defined( 'SIMPLE301REDIRECTS_VERSION' );
		$wildcard = 'true' === get_option( '301_redirects_wildcard' );
		$i = 0;
		foreach ( $rules as $from => $to ) {
			$from = (string) $from;
			$rule = array( 'id' => 'simple_301_redirects:' . $i++, 'from' => $from, 'source' => 'simple_301_redirects', 'match' => 'url', 'regex' => false );
			if ( $wildcard && false !== strpos( $from, '*' ) ) {
				// preg_quote() escapes `-` and `#` too, which a JavaScript `u` regex rejects outside a class.
				$rule['from'] = '^' . str_replace( array( '\*', '\-', '\#' ), array( '(.*)', '-', '#' ), preg_quote( $from ) ) . '$';
				$rule['match'] = 'regex';
				$rule['regex'] = true;
				$to = str_replace( '*', '$1', (string) $to );
			}
			self::add( $doc, $rule, (string) $to, 301 );
		}
		$doc['sources']['simple_301_redirects'] = self::source( defined( 'SIMPLE301REDIRECTS_VERSION' ) ? 'active' : 'inactive-with-data', count( $rules ) );
	}

	/**
	 * The site's .htaccess (read, never written), outside WordPress's own
	 * `# BEGIN WordPress` block: `Redirect` (a prefix: /a/x follows /a to
	 * /b/x), `RedirectPermanent`/`RedirectTemp`, `RedirectMatch`, and
	 * `RewriteRule` with `[R]` or `[G]`. A rule that depends on a
	 * `RewriteCond` or sits in an `<If>`/`<Files>` block is conditional; a
	 * rewrite without `R`/`G` is not a redirect. Every one is accounted for.
	 * A server other than Apache/LiteSpeed does not read the file.
	 */
	private static function htaccess( &$doc ) {
		$path = ABSPATH . '.htaccess';
		if ( ! file_exists( $path ) ) {
			$doc['sources']['htaccess'] = self::source( 'absent' );
			return;
		}
		$lines = is_readable( $path ) ? file( $path, FILE_IGNORE_NEW_LINES ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Read only.
		if ( false === $lines ) {
			$doc['sources']['htaccess'] = array( 'status' => 'unreadable' );
			return;
		}
		$software = (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared, never output.
		// Unknown (a CLI run) is read as Apache: the file exists because the site's server uses it.
		$apache = '' === $software || false !== stripos( $software, 'apache' ) || false !== stripos( $software, 'litespeed' );
		self::$serving = $apache;
		$count = self::htaccess_rules( $doc, $lines );
		$doc['sources']['htaccess'] = self::source( $apache ? 'active' : 'inactive-with-data', $count );
		if ( ! $apache ) {
			$doc['sources']['htaccess']['note'] = 'server ' . $software . ' does not read .htaccess';
		}
		if ( untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) !== untrailingslashit( (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ) ) ) {
			$doc['sources']['htaccess']['note'] = 'WordPress is in a subdirectory: the .htaccess that serves the home address is not the one read';
		}
	}

	/** The rules of .htaccess lines, into `$doc`; returns how many there were. Public for tests. */
	public static function htaccess_rules( &$doc, $lines ) {
		$count = 0;
		$in_wp = false;
		$base = '/';
		$conds = array();
		$blocks = array();
		$joined = array();
		$carry = '';
		foreach ( $lines as $n => $line ) {
			if ( '' !== $carry ) {
				$line = $carry . ltrim( $line );
			}
			if ( '\\' === substr( rtrim( $line ), -1 ) ) {
				$carry = substr( rtrim( $line ), 0, -1 ) . ' ';
				continue;
			}
			$carry = '';
			$joined[ $n + 1 ] = trim( $line );
		}
		foreach ( $joined as $number => $line ) {
			if ( preg_match( '/^#\s*BEGIN WordPress\b/i', $line ) ) {
				$in_wp = true;
				continue;
			}
			if ( preg_match( '/^#\s*END WordPress\b/i', $line ) ) {
				$in_wp = false;
				continue;
			}
			if ( $in_wp || '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '#^<(If|ElseIf|Else|Files|FilesMatch)\b[^>]*>#i', $line, $open ) ) {
				$blocks[] = $line;
				continue;
			}
			if ( preg_match( '#^</(If|ElseIf|Else|Files|FilesMatch)>#i', $line ) ) {
				array_pop( $blocks );
				continue;
			}
			$words = self::htaccess_words( $line );
			$directive = strtolower( $words[0] ?? '' );
			if ( 'rewritebase' === $directive ) {
				$base = '/' . trim( (string) ( $words[1] ?? '/' ), '/' ) . '/';
				$base = '//' === $base ? '/' : $base;
				continue;
			}
			if ( 'rewritecond' === $directive ) {
				$conds[] = $line;
				continue;
			}
			if ( ! in_array( $directive, array( 'redirect', 'redirectpermanent', 'redirecttemp', 'redirectmatch', 'rewriterule' ), true ) ) {
				continue;
			}
			++$count;
			$rule = array( 'id' => 'htaccess:' . $number, 'source' => 'htaccess', 'served_by' => 'apache' );
			$pending = $conds;
			$conds = array();
			if ( 'rewriterule' === $directive ) {
				$rule += self::rewrite_rule( $words, $base );
			} else {
				$rule += self::alias_rule( $directive, array_slice( $words, 1 ) );
			}
			if ( $pending || $blocks ) {
				$rule['condition'] = array_merge( $blocks, $pending );
				if ( isset( $rule['status_code'] ) ) {
					$rule['status'] = $rule['status_code'];
					$rule['to'] = $rule['target'];
				}
				unset( $rule['status_code'], $rule['target'], $rule['excluded'] );
				self::exclude( $doc, $rule, 'conditional-match:htaccess' );
				continue;
			}
			if ( isset( $rule['excluded'] ) ) {
				$reason = $rule['excluded'];
				unset( $rule['excluded'], $rule['target'], $rule['status_code'] );
				self::exclude( $doc, $rule, $reason );
				continue;
			}
			$status = $rule['status_code'];
			$to = $rule['target'];
			unset( $rule['status_code'], $rule['target'] );
			self::add( $doc, $rule, $to, $status );
		}
		return $count;
	}

	/** A mod_alias rule: `Redirect [status] /path [url]`, `RedirectMatch [status] regex [url]`. */
	private static function alias_rule( $directive, $args ) {
		$codes = array( 'permanent' => 301, 'temp' => 302, 'seeother' => 303, 'gone' => 410 );
		$status = 'redirectpermanent' === $directive ? 301 : 302;
		if ( 'redirect' === $directive || 'redirectmatch' === $directive ) {
			$first = strtolower( (string) ( $args[0] ?? '' ) );
			if ( isset( $codes[ $first ] ) ) {
				$status = $codes[ $first ];
				array_shift( $args );
			} elseif ( ctype_digit( $first ) ) {
				$status = (int) $first;
				array_shift( $args );
			}
		}
		$regex = 'redirectmatch' === $directive;
		// mod_alias carries the query string over to the target.
		$rule = array( 'from' => (string) ( $args[0] ?? '' ), 'match' => $regex ? 'regex' : 'start', 'regex' => $regex, 'query' => 'pass', 'status_code' => $status, 'target' => (string) ( $args[1] ?? '' ) );
		if ( ! self::gone( $status ) && ( $status < 300 || $status > 399 ) ) {
			$rule['excluded'] = 'not-a-redirect:status-' . $status;
		}
		return $rule;
	}

	/** `RewriteRule pattern substitution [flags]`: a redirect only with `R` (or `G`, gone). */
	private static function rewrite_rule( $words, $base ) {
		$pattern = (string) ( $words[1] ?? '' );
		$target = (string) ( $words[2] ?? '-' );
		$flags = array();
		foreach ( explode( ',', trim( (string) ( $words[3] ?? '' ), '[]' ) ) as $flag ) {
			$parts = explode( '=', trim( $flag ), 2 );
			if ( '' !== $parts[0] ) {
				$flags[ strtoupper( $parts[0] ) ] = $parts[1] ?? '';
			}
		}
		// In .htaccess the pattern sees the path without its leading `/`.
		$negated = '!' === substr( $pattern, 0, 1 );
		$from = '^' === substr( $pattern, 0, 1 ) ? '^' . $base . substr( $pattern, 1 ) : $pattern;
		$rule = array( 'from' => $from, 'match' => 'regex', 'regex' => true );
		if ( isset( $flags['NC'] ) || isset( $flags['NOCASE'] ) ) {
			$rule['case_insensitive'] = true;
		}
		if ( isset( $flags['G'] ) || isset( $flags['GONE'] ) ) {
			return $rule + array( 'status_code' => 410, 'target' => '' );
		}
		if ( isset( $flags['F'] ) || isset( $flags['FORBIDDEN'] ) ) {
			return $rule + array( 'excluded' => 'not-a-redirect:status-403' );
		}
		$redirect = isset( $flags['R'] ) ? $flags['R'] : ( $flags['REDIRECT'] ?? null );
		if ( null === $redirect || '-' === $target || $negated ) {
			return $rule + array( 'excluded' => $negated ? 'not-a-redirect:negated' : 'not-a-redirect:rewrite' );
		}
		$codes = array( '' => 302, 'permanent' => 301, 'temp' => 302, 'seeother' => 303 );
		$status = isset( $codes[ strtolower( $redirect ) ] ) ? $codes[ strtolower( $redirect ) ] : (int) $redirect;
		if ( ! preg_match( '#^(/|[a-z][a-z0-9+.-]*://)#i', $target ) ) {
			$target = $base . $target;
		}
		$discard = isset( $flags['QSD'] ) || ( false !== strpos( $target, '?' ) && ! isset( $flags['QSA'] ) );
		$rule['query'] = $discard ? 'ignore' : 'pass';
		if ( ! self::gone( $status ) && ( $status < 300 || $status > 399 ) ) {
			return $rule + array( 'excluded' => 'not-a-redirect:status-' . $status );
		}
		return $rule + array( 'status_code' => $status, 'target' => $target );
	}

	/** A directive's words; double quotes group, as Apache reads them. */
	private static function htaccess_words( $line ) {
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $line, $m, PREG_SET_ORDER );
		return array_map( static function ( $w ) { return isset( $w[2] ) && '' !== $w[2] ? $w[2] : stripcslashes( $w[1] ); }, $m );
	}

	/**
	 * WordPress itself answers an old slug with a 301 to the current address
	 * (`wp_old_slug_redirect`). No plugin holds these, so a plugin-table export
	 * misses every one of them.
	 */
	private static function old_slugs( &$doc ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_old_slug' AND p.post_status = 'publish' ORDER BY pm.post_id, pm.meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One pass over a core meta key.
		self::$serving = true;
		foreach ( $rows as $row ) {
			$post = get_post( (int) $row['post_id'] );
			$rule = array( 'id' => 'wordpress:old-slug:' . $row['post_id'] . ':' . $row['meta_value'], 'source' => 'wordpress', 'match' => 'url', 'regex' => false );
			$current = $post ? get_permalink( $post ) : false;
			if ( ! $current || ! is_post_type_viewable( $post->post_type ) || ! get_option( 'permalink_structure' ) ) {
				// With plain permalinks the address is `?p=ID`: the slug was never in it.
				$rule['from'] = (string) $row['meta_value'];
				self::exclude( $doc, $rule, 'no-slug-address' );
				continue;
			}
			$old = clone $post;
			$old->post_name = (string) $row['meta_value'];
			$rule['from'] = wp_make_link_relative( get_permalink( $old ) );
			if ( $post->post_name === $old->post_name ) {
				self::exclude( $doc, $rule, 'slug-reused' );
				continue;
			}
			self::add( $doc, $rule, $current, 301 );
		}
		$doc['sources']['wordpress_old_slug'] = self::source( 'active', count( $rows ) );
	}

	private static function add( &$doc, $rule, $to, $status ) {
		if ( self::gone( $status ) ) {
			$rule['to'] = '';
			$rule['status'] = $status;
		} else {
			$rule['to'] = self::relative( $to );
			$rule['status'] = $status >= 300 && $status < 400 ? $status : 301;
			if ( $status < 300 || $status >= 400 ) {
				$rule['status_note'] = 'source status ' . $status . ' is not a redirect code; 301 assumed';
			}
		}
		if ( ! self::$serving ) {
			// Kept whole, but not listed as served: the live site does not answer it.
			self::exclude( $doc, $rule, 'source-inactive' );
			return;
		}
		$doc['redirects'][] = $rule;
	}

	/** 410 Gone and 451 Unavailable For Legal Reasons: an answer the new site gives too. */
	private static function gone( $status ) {
		return 410 === $status || 451 === $status;
	}

	/**
	 * The matching flags Redirection applies to a rule (`Red_Source_Flags`):
	 * query `exact`, case- and slash-sensitive; on a fresh install (its
	 * options never saved) case and trailing slash are ignored; the site's
	 * saved flags, then the rule's own `match_data.source`, override; a regex
	 * rule matches its query exactly. `exactorder` is read as `exact`.
	 */
	private static function redirection_flags( $match_data, $site, $regex ) {
		$flags = array( 'flag_query' => 'exact', 'flag_case' => false === $site, 'flag_trailing' => false === $site );
		$data = self::decode( $match_data );
		$own = is_array( $data ) && isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();
		foreach ( array( is_array( $site ) ? $site : array(), $own ) as $layer ) {
			if ( isset( $layer['flag_query'] ) && in_array( $layer['flag_query'], array( 'exact', 'ignore', 'pass', 'exactorder' ), true ) ) {
				$flags['flag_query'] = $layer['flag_query'];
			}
			foreach ( array( 'flag_case', 'flag_trailing' ) as $key ) {
				if ( isset( $layer[ $key ] ) && is_bool( $layer[ $key ] ) ) {
					$flags[ $key ] = $layer[ $key ];
				}
			}
			if ( ! empty( $layer['flag_regex'] ) && true === $layer['flag_regex'] ) {
				$regex = true;
			}
		}
		return array(
			'query' => $regex || 'exactorder' === $flags['flag_query'] ? 'exact' : $flags['flag_query'],
			'case_insensitive' => $flags['flag_case'],
			'trailing_slash' => $flags['flag_trailing'] ? 'ignore' : 'exact',
		);
	}

	private static function exclude( &$doc, $rule, $reason ) {
		$rule['reason'] = $reason;
		$doc['excluded'][] = $rule;
	}

	/** Same-site targets become root-relative, like every `from`; other hosts stay absolute. */
	private static function relative( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$target = wp_parse_url( $url );
		if ( is_array( $target ) && isset( $target['host'], $home['host'] ) && strtolower( $target['host'] ) === strtolower( $home['host'] ) && ( $target['port'] ?? null ) === ( $home['port'] ?? null ) ) {
			return wp_make_link_relative( $url );
		}
		return $url;
	}

	private static function source( $status, $rules = 0 ) {
		return 'absent' === $status ? array( 'status' => 'absent' ) : array( 'status' => $status, 'rules' => $rules );
	}

	private static function table( $name ) {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table probe.
	}

	private static function decode( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 16 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Plugin data; class instantiation is explicitly disabled.
		}
		$json = is_string( $value ) ? json_decode( $value, true ) : null;
		return null === $json ? $value : $json;
	}

	private static function order( $a, $b ) {
		return strcmp( $a['id'], $b['id'] );
	}
}
