=== Contentrain Bridge ===
Contributors: contentrain
Tags: export, migration, headless, astro, content
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress content to a portable RawIR JSON file without requiring a paid service.

== Description ==

Contentrain Bridge provides a local-first export under Tools > Contentrain Bridge.

* The plugin is free software under GPL-2.0-or-later.
* Local JSON export works without Migrate, Studio, an account, or payment.
* It sends no telemetry and performs no external network requests.
* A download occurs only after an authorized administrator explicitly submits a nonce-protected form.
* Comment export is disabled by default. When enabled, comment email addresses and IP addresses are excluded.
* Exported site data remains the site owner's data and is not placed under the plugin's GPL license merely by being exported.

This initial release does not upload data to Contentrain. A future remote handoff, if added, must remain an explicit opt-in action and disclose the destination, transmitted fields, service terms, and privacy policy before transfer.

== Installation ==

1. Upload the `contentrain-bridge` folder to `/wp-content/plugins/` or install the plugin ZIP.
2. Activate Contentrain Bridge in WordPress.
3. Open Tools > Contentrain Bridge.
4. Choose whether to include privacy-minimized comment data, then download the RawIR JSON file.

== Frequently Asked Questions ==

= Is Contentrain Migrate or Studio required? =

No. The JSON export is local and portable.

= Does the plugin send telemetry? =

No. This release makes no external requests.

= Are media files stored in the JSON? =

The export contains attachment metadata and original URLs. Binary downloading and re-hosting happen only in a later migration workflow chosen by the site owner.

= Which comment data is exported? =

Only when explicitly selected: author name, URL, content, status, user ID, dates, relationships, and comment metadata. Email addresses, IP addresses, and user-agent values are excluded.

== Privacy ==

The plugin stores no settings and sends no data externally. It creates a local JSON download after an administrator action. The file can contain site content, user display names/logins, post metadata, and—if explicitly selected—privacy-minimized comments. Protect the downloaded file as you would protect a WordPress export.

== Changelog ==

= 0.1.0 =

* Initial local RawIR v1 export.
