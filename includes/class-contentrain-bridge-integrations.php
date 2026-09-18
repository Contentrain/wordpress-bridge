<?php
/** Third-party services the site is connected to, and what must be reconnected. @package ContentrainBridge */
namespace Contentrain\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Which outside services this site talks to — analytics, CRM, newsletter,
 * ads, comments, captcha, CDN — and the evidence for each: an active plugin,
 * a settings option by name, a script loaded from the service's domain, a form
 * wired to it, or content embedded from it.
 *
 * Credentials never leave. An option's value is read only to answer "is a key
 * set?" as a boolean, in memory; what is written is the option's *name*, the
 * setting's *name*, a domain, a plugin slug. A migrated site has to connect
 * again with its own credentials, and this is the list of what to connect.
 */
final class Integrations {
	const FORMAT = 'contentrain-bridge-integrations@1';

	/** Keys that hold credentials; their presence is reported, never their value. */
	const SECRET_KEY = '/(api[_-]?key|apikey|secret|token|password|private[_-]?key|access[_-]?key|client[_-]?secret|license[_-]?key|site[_-]?key|app[_-]?id)/i';

	/**
	 * Service catalog: id => [name, category, plugin slugs, option-name pattern, script domains, reconnect].
	 * `reconnect` is false for what needs no account (an embed): it is reported, not a to-do.
	 */
	public static function catalog() {
		return array(
			'google-analytics'  => array( 'Google Analytics', 'analytics', array( 'google-site-kit', 'google-analytics-for-wordpress', 'ga-google-analytics', 'google-analytics-dashboard-for-wp' ), '/^(googlesitekit_analytics.*|monsterinsights_.*|ga_google_analytics.*|exactmetrics_.*)$/', array( 'google-analytics.com', 'www.googletagmanager.com/gtag' ), true ),
			'google-tag-manager' => array( 'Google Tag Manager', 'analytics', array( 'duracelltomi-google-tag-manager', 'gtm-kit' ), '/^(gtm4wp-options|gtmkit.*)$/', array( 'www.googletagmanager.com/gtm.js' ), true ),
			'hotjar'            => array( 'Hotjar', 'analytics', array( 'hotjar' ), '/^hotjar.*$/', array( 'static.hotjar.com' ), true ),
			'microsoft-clarity' => array( 'Microsoft Clarity', 'analytics', array( 'microsoft-clarity' ), '/^clarity_.*$/', array( 'www.clarity.ms' ), true ),
			'plausible'         => array( 'Plausible Analytics', 'analytics', array( 'plausible-analytics' ), '/^plausible_analytics_settings$/', array( 'plausible.io' ), true ),
			'matomo'            => array( 'Matomo', 'analytics', array( 'matomo', 'wp-piwik' ), '/^(matomo-.*|wp-piwik.*)$/', array( 'matomo.cloud', 'cdn.matomo.cloud' ), true ),
			'facebook-pixel'    => array( 'Meta (Facebook) Pixel', 'ads', array( 'official-facebook-pixel', 'pixelyoursite', 'facebook-for-woocommerce' ), '/^(facebook_config|pys_.*|facebook_for_woocommerce.*)$/', array( 'connect.facebook.net' ), true ),
			'google-adsense'    => array( 'Google AdSense', 'ads', array( 'ad-inserter', 'advanced-ads', 'quick-adsense-reloaded' ), '/^(googlesitekit_adsense.*|advanced-ads.*|ad_inserter)$/', array( 'pagead2.googlesyndication.com' ), true ),
			'mailchimp'         => array( 'Mailchimp', 'newsletter', array( 'mailchimp-for-wp', 'mailchimp-for-woocommerce' ), '/^(mc4wp.*|mailchimp_.*)$/', array( 'chimpstatic.com', 'list-manage.com' ), true ),
			'mailerlite'        => array( 'MailerLite', 'newsletter', array( 'official-mailerlite-sign-up-forms' ), '/^(mailerlite_.*|mlsf_.*)$/', array( 'static.mailerlite.com', 'assets.mailerlite.com' ), true ),
			'convertkit'        => array( 'Kit (ConvertKit)', 'newsletter', array( 'convertkit' ), '/^_wp_convertkit_settings$/', array( 'f.convertkit.com', 'ck.page' ), true ),
			'brevo'             => array( 'Brevo (Sendinblue)', 'newsletter', array( 'mailin' ), '/^(sib_.*|ws_main_option)$/', array( 'sibforms.com', 'sibautomation.com' ), true ),
			'klaviyo'           => array( 'Klaviyo', 'newsletter', array( 'klaviyo' ), '/^klaviyo_settings$/', array( 'static.klaviyo.com' ), true ),
			'hubspot'           => array( 'HubSpot', 'crm', array( 'leadin' ), '/^leadin_.*$/', array( 'js.hs-scripts.com', 'js.hsforms.net' ), true ),
			'zapier'            => array( 'Zapier', 'crm', array( 'zapier' ), '/^zapier_.*$/', array(), true ),
			'salesforce'        => array( 'Salesforce', 'crm', array( 'salesforce-wordpress-to-lead', 'gravityformssalesforce' ), '/^salesforce_.*$/', array(), true ),
			'zoho-crm'          => array( 'Zoho CRM', 'crm', array( 'zoho-crm-forms' ), '/^zcf_.*$/', array(), true ),
			'disqus'            => array( 'Disqus', 'comments', array( 'disqus-comment-system' ), '/^disqus_.*$/', array( 'disqus.com' ), true ),
			'recaptcha'         => array( 'Google reCAPTCHA', 'captcha', array( 'advanced-nocaptcha-recaptcha', 'google-captcha', 'invisible-recaptcha' ), '/^(anr_admin_options|gglcptch_options|recaptcha_.*)$/', array( 'www.google.com/recaptcha', 'www.gstatic.com/recaptcha' ), true ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A host name to recognise in the site's own markup; nothing is loaded from it.
			'hcaptcha'          => array( 'hCaptcha', 'captcha', array( 'hcaptcha-for-forms-and-more' ), '/^hcaptcha_settings$/', array( 'js.hcaptcha.com' ), true ),
			'turnstile'         => array( 'Cloudflare Turnstile', 'captcha', array( 'simple-cloudflare-turnstile' ), '/^cfturnstile_.*$/', array( 'challenges.cloudflare.com' ), true ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A host name to recognise in the site's own markup; nothing is loaded from it.
			'cloudflare'        => array( 'Cloudflare', 'cdn', array( 'cloudflare' ), '/^cloudflare_.*$/', array(), true ),
			'akismet'           => array( 'Akismet', 'other', array( 'akismet' ), '/^wordpress_api_key$/', array(), true ),
			'jetpack'           => array( 'Jetpack (WordPress.com)', 'other', array( 'jetpack' ), '/^jetpack_options$/', array( 'stats.wp.com', 'i0.wp.com' ), true ),
		);
	}

	/** Jetpack modules are services of their own. */
	const JETPACK_MODULES = array( 'stats' => array( 'Jetpack Stats', 'analytics' ), 'comments' => array( 'Jetpack Comments', 'comments' ), 'photon' => array( 'Jetpack Site Accelerator', 'cdn' ), 'subscriptions' => array( 'Jetpack Newsletter', 'newsletter' ), 'wordads' => array( 'WordAds', 'ads' ) );

	/** Content embedded from these hosts: reported, no account to reconnect. */
	const EMBEDS = array( 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'vimeo.com' => 'Vimeo', 'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'instagram.com' => 'Instagram', 'soundcloud.com' => 'SoundCloud', 'open.spotify.com' => 'Spotify', 'tiktok.com' => 'TikTok', 'google.com/maps' => 'Google Maps' );

	/**
	 * Detect. `$html` is page HTML already fetched (render scan), or empty;
	 * `$post_ids` the exported posts whose bodies are searched for embeds.
	 */
	public static function detect( $post_ids = array(), $html = '' ) {
		$found = array();
		$add = static function ( $id, $name, $category, $kind, $detail, $reconnect = true, $secret = false ) use ( &$found ) {
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = array( 'service' => $id, 'name' => $name, 'category' => $category, 'evidence' => array(), 'reconnect_required' => $reconnect, 'secret_present' => false );
			}
			$found[ $id ]['evidence'][ $kind . ':' . $detail ] = array( 'kind' => $kind, 'detail' => $detail );
			$found[ $id ]['secret_present'] = $found[ $id ]['secret_present'] || $secret;
		};
		$catalog = self::catalog();

		// 1. Active plugins.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array();
		foreach ( get_plugins() as $path => $plugin ) {
			if ( is_plugin_active( $path ) ) {
				$active[ dirname( $path ) ] = $plugin['Version'];
			}
		}
		foreach ( $catalog as $id => list( $name, $category, $plugins ) ) {
			foreach ( $plugins as $slug ) {
				if ( isset( $active[ $slug ] ) ) {
					$add( $id, $name, $category, 'plugin', $slug . ' ' . $active[ $slug ] );
				}
			}
		}

		// 2. Settings, by option name. The value is inspected for "is a credential set", never kept.
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_%transient\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerates option names only.
		foreach ( $catalog as $id => list( $name, $category, , $pattern ) ) {
			foreach ( preg_grep( $pattern, $names ) as $option ) {
				$set = self::secret_keys( get_option( $option ), preg_match( self::SECRET_KEY, $option ) ? $option : '' );
				$add( $id, $name, $category, 'option-key', $option . ( $set ? ' (' . implode( ', ', $set ) . ')' : '' ), true, (bool) $set );
			}
		}
		foreach ( (array) get_option( 'jetpack_active_modules', array() ) as $module ) {
			if ( isset( self::JETPACK_MODULES[ $module ] ) ) {
				$add( 'jetpack-' . $module, self::JETPACK_MODULES[ $module ][0], self::JETPACK_MODULES[ $module ][1], 'option-key', 'jetpack_active_modules[' . $module . ']' );
			}
		}

		// 3. Forms wired to a service.
		foreach ( self::form_links() as list( $id, $detail, $secret ) ) {
			if ( isset( $catalog[ $id ] ) ) {
				$add( $id, $catalog[ $id ][0], $catalog[ $id ][1], 'form-config', $detail, true, $secret );
			}
		}

		// 4. Scripts: the theme's own files, and the rendered page when it was read.
		$sources = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $root ) {
			foreach ( self::files( $root ) as $file ) {
				$sources[ str_replace( trailingslashit( WP_CONTENT_DIR ), '', $file ) ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme source, read-only.
			}
		}
		if ( '' !== $html ) {
			$sources['render:home'] = $html;
		}
		foreach ( get_option( 'ihaf_insert_header' ) ? array( 'ihaf_insert_header', 'ihaf_insert_footer' ) : array() as $option ) {
			$sources[ 'option:' . $option ] = (string) get_option( $option );
		}
		foreach ( $sources as $where => $text ) {
			foreach ( $catalog as $id => list( $name, $category, , , $domains ) ) {
				foreach ( $domains as $domain ) {
					if ( false !== stripos( $text, $domain ) ) {
						$add( $id, $name, $category, 'script-domain', $domain . ' @ ' . $where );
					}
				}
			}
		}

		// 5. Embeds in exported content: listed, nothing to reconnect.
		foreach ( $post_ids as $post_id ) {
			$content = (string) get_post_field( 'post_content', $post_id );
			foreach ( self::EMBEDS as $host => $label ) {
				if ( preg_match( '#https?://(www\.)?' . preg_quote( $host, '#' ) . '#i', $content ) ) {
					$add( 'embed-' . sanitize_title( $label ), $label, 'other', 'embed-domain', $host, false );
				}
			}
		}

		$services = array_values( $found );
		foreach ( $services as &$service ) {
			$service['evidence'] = array_values( $service['evidence'] );
			$service['notes'] = $service['reconnect_required']
				? 'Connect again on the new site with its own credentials; none were exported.' . ( $service['secret_present'] ? ' A credential is configured on WordPress.' : '' )
				: 'Embedded content; kept as a URL in the body, no account to reconnect.';
		}
		unset( $service );
		usort( $services, static function ( $a, $b ) { return strcmp( $a['category'] . $a['service'], $b['category'] . $b['service'] ); } );
		return array(
			'format'   => self::FORMAT,
			'services' => $services,
			'totals'   => array( 'services' => count( $services ), 'reconnect_required' => count( array_filter( $services, static function ( $s ) { return $s['reconnect_required']; } ) ) ),
			'scanned'  => array( 'plugins' => count( $active ), 'options' => count( $names ), 'script_sources' => count( $sources ), 'posts' => count( $post_ids ), 'rendered_home' => '' !== $html ),
		);
	}

	/**
	 * Names of credential settings that hold a value. The value is looked at to
	 * tell "set" from "empty" and discarded; only the key name comes back.
	 */
	private static function secret_keys( $value, $option_name = '' ) {
		$set = array();
		if ( '' !== $option_name && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$set[] = $option_name;
		}
		$walk = static function ( $node, $path ) use ( &$walk, &$set ) {
			if ( is_object( $node ) ) {
				$node = get_object_vars( $node );
			}
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => $child ) {
				$name = '' === $path ? (string) $key : $path . '.' . $key;
				if ( is_scalar( $child ) && preg_match( self::SECRET_KEY, (string) $key ) && '' !== trim( (string) $child ) ) {
					$set[] = $name;
				} else {
					$walk( $child, $name );
				}
			}
		};
		$walk( is_string( $value ) && is_serialized( $value ) ? unserialize( $value, array( 'allowed_classes' => false, 'max_depth' => 16 ) ) : $value, '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Class instantiation is explicitly disabled; nothing is kept but key names.
		return array_values( array_unique( $set ) );
	}

	/** Forms connected to a service: Contact Form 7 integrations, WPForms providers, Gravity Forms feeds. */
	private static function form_links() {
		global $wpdb;
		$out = array();
		$cf7 = get_option( 'wpcf7' );
		if ( is_array( $cf7 ) ) {
			foreach ( array( 'recaptcha' => 'recaptcha', 'sendinblue' => 'brevo', 'constant_contact' => 'constant-contact', 'stripe' => 'stripe' ) as $key => $service ) {
				if ( ! empty( $cf7[ $key ] ) ) {
					$out[] = array( $service, 'contact-form-7 integration: ' . $key, (bool) self::secret_keys( $cf7[ $key ] ) || ! empty( $cf7[ $key ] ) );
				}
			}
		}
		$providers = get_option( 'wpforms_providers' );
		foreach ( is_array( $providers ) ? array_keys( $providers ) : array() as $provider ) {
			$service = array( 'mailchimpv3' => 'mailchimp', 'hubspot' => 'hubspot', 'convertkit' => 'convertkit', 'sendinblue' => 'brevo', 'zapier' => 'zapier', 'salesforce' => 'salesforce' )[ $provider ] ?? null;
			if ( $service ) {
				$out[] = array( $service, 'wpforms provider: ' . $provider, (bool) self::secret_keys( $providers[ $provider ] ) );
			}
		}
		$feeds = $wpdb->prefix . 'gf_addon_feed';
		if ( $feeds === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feeds ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table probe.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT form_id, addon_slug FROM %i WHERE is_active = 1', $feeds ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Gravity Forms feeds; only the add-on slug is read.
				$service = array( 'gravityformsmailchimp' => 'mailchimp', 'gravityformshubspot' => 'hubspot', 'gravityformssalesforce' => 'salesforce', 'gravityformszapier' => 'zapier', 'gravityformsconvertkit' => 'convertkit' )[ $row['addon_slug'] ] ?? null;
				if ( $service ) {
					$out[] = array( $service, 'gravity forms feed: form ' . (int) $row['form_id'] . ' → ' . $row['addon_slug'], false );
				}
			}
		}
		return $out;
	}

	private static function files( $root ) {
		$real = realpath( $root );
		if ( ! $real ) {
			return array();
		}
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && ! $file->isLink() && preg_match( '/\.(php|html|js)$/i', $file->getFilename() ) && $file->getSize() < 2 * MB_IN_BYTES ) {
				$files[] = $file->getPathname();
				if ( count( $files ) >= 2000 ) {
					break;
				}
			}
		}
		sort( $files, SORT_STRING );
		return $files;
	}
}
