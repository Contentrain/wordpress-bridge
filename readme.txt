=== Contentrain Bridge ===
Contributors: abb65
Tags: export, migration, headless, astro, content
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model and export WordPress content and interface text as Contentrain JSON/Markdown, locally or to your own GitHub repository.

== Description ==

Contentrain Bridge turns your WordPress content into editable JSON and Markdown under Tools > Contentrain Bridge. You choose the content, review how it is modelled and which interface text travels, then download a ZIP or send the result to a branch in your own GitHub repository. Both are free.

* Advanced Custom Fields groups and repeaters become their own models with their own named fields, not one anonymous bag of values.
* Free software under GPL-2.0-or-later. No account, subscription or paid service is required for either delivery.
* Your WordPress site is not modified. The plugin reads content and source files; it never patches a theme or plugin.
* Every export step is started by a logged-in administrator through a nonce-protected request.
* Exports run in bounded steps and can be resumed, so large sites do not depend on one long request.
* Comment archiving is off by default. When enabled, email addresses, IP addresses and comment metadata are excluded.
* Metadata that looks like a password, token, key, credential or personal identifier is never exported, including inside selected fields.
* Exported site data remains the site owner's data and is not placed under the plugin's GPL license merely by being exported.

Media travels with the content: uploads are copied into `media/` and the content is relinked to them, so the delivered repository does not depend on this site for images. A file over 8 MB, or one missing from the uploads directory, keeps its WordPress URL and is named in the coverage report. Dynamic WordPress and plugin output is not rendered during a source scan. These limits are written into the coverage report rather than implied to be complete.

= External services =

The plugin contacts no host other than this site unless you choose GitHub delivery. Two options read this site's own pages over HTTP, the way a visitor would, and send nothing elsewhere: "Also read the text your pages render" (the home page, a post, a page, search and not-found) and, with it, the connected-services check (the home page's script hosts).

**GitHub** — used only when you enter a repository and a token in step 3 and start the delivery. The plugin then calls the GitHub REST API at api.github.com to read the target repository's default branch (or the base branch you name) and file list, upload the exported files, and create one new branch holding them. What is sent: the exported content files you reviewed, a commit message, and the token you supplied. Nothing is sent to Contentrain or to any other host, and no analytics or telemetry is collected. The token is used for those requests only and is never stored in the database or in the export. Delivery never writes to your default branch and never overwrites a file you edited in Git. GitHub's terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service — privacy policy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

**REST export API (inbound)** — the plugin adds routes under `/wp-json/contentrain-bridge/v1/` that let a program you authorize start an export and read its files. The plugin sends nothing by itself: the program asks, with an application password of an administrator who has the export and manage_options capabilities. Contentrain Migrate uses these routes when you give it such a password to migrate this site; what Migrate then does with the content is governed by Contentrain's terms (https://contentrain.io/terms-of-service) and privacy policy (https://contentrain.io/privacy-policy). Revoke the application password in your profile to end that access; exports expire after a day.

== Installation ==

1. Install Contentrain Bridge from Plugins > Add New, or install `contentrain-bridge.zip` from the latest GitHub release (https://github.com/Contentrain/wordpress-bridge/releases/latest) with Plugins > Add New > Upload Plugin.
2. Activate Contentrain Bridge in WordPress.
3. Open Tools > Contentrain Bridge.
4. Step 1: choose content types and options, then prepare the content.
5. Step 2: review the generated models and the interface-text candidates, then validate and finalize.
6. Step 3: download the ZIP, or deliver to a branch in your own GitHub repository.

Installed from WordPress.org, the plugin updates like any other. A ZIP installed from a GitHub release is updated by uploading the newer release ZIP the same way; WordPress replaces the installed version. Export snapshots are temporary and are not affected.

== Frequently Asked Questions ==

= Is Contentrain Migrate or Studio required? =

No. Modelling, validation, the ZIP download and GitHub delivery are all local and free. Migrate is an optional later step if you want an Astro website.

= Does the plugin send telemetry? =

No. It collects no analytics and contacts no Contentrain server. The only request to another host is to the GitHub API, when you start a GitHub delivery yourself.

= Are media files exported? =

Yes, when you include the Media type. Each upload and its generated sizes are copied into `media/` in the export, and content that pointed at a WordPress upload URL is rewritten to that path, so images keep working after this site is gone. Media records also keep the original URL. Files larger than 8 MB, files missing from disk, and anything past the export's total media budget keep their WordPress URL instead of being half-copied; the coverage report lists them. The 8 MB ceiling exists because GitHub delivery has to hold a file in memory to upload it.

= What is the Contentrain Migrate connection key? =

A way for Contentrain Migrate to read this site's content when your host blocks application passwords (some hosts strip the Authorization header they travel in). Create it under Tools > Contentrain Bridge and paste it into Migrate. It lets Migrate start and read content exports as you, nothing else: it does not open the rest of the WordPress REST API. It is shown once and only a fingerprint of it is stored. Paste it into Migrate within an hour; once Migrate has used it, it works for that one move until 14 days after it was created, until Migrate closes it when the move is done, or until you revoke it or create a new one. It can only be created and used over HTTPS. Nothing is sent from your site to Contentrain when you create it.

= Which comment data is exported? =

Only when explicitly selected: author name, URL, content, status, dates and relationships. Email addresses, IP addresses, user-agent values and comment metadata are excluded.

= What does the source scan read? =

The active theme and child theme, and optionally the source files of active plugins. It reads PHP, HTML and JavaScript text to find interface strings, never executes them, skips vendor and dependency directories, and stops at 10,000 files. Every string it finds is a candidate you review before it becomes content.

== Privacy ==

The plugin stores export ownership and a source revision marker. It sends nothing anywhere on its own; an export leaves the site only when you download it, deliver it to GitHub, or authorize a program to read it through the REST export API with an administrator's application password or a Contentrain Migrate connection key. For a connection key the plugin stores a fingerprint of the key (never the key), the Migrate order it is paired with, and the time and IP address of its last use; creating a new key or revoking it, and uninstalling the plugin, removes them. Export files are written to a private, per-administrator directory, are removed on uninstall, and expire after a day. An export can contain site content, author display names, selected post metadata and, if you chose it, privacy-minimized comments. Treat a downloaded export as you would a WordPress export file. If you deliver to GitHub, the exported content is sent to GitHub under the account whose token you provide; choose a private repository when the export includes draft or private content.

== Changelog ==

= 0.4.0 =

* Contentrain Migrate connection key: Migrate can start and read exports on hosts that strip the Authorization header, without an application password. Shown once, HTTPS only, bound to one Migrate order: an hour to paste it, then valid for that order up to 14 days; revocable by you or closed by Migrate.
* REST: `GET /about` says which sign-in a reader can use; `POST /exports/{id}/read` reads a snapshot for a caller whose key travels in the request body; `DELETE /key` lets Migrate close its own key.

= 0.3.1 =

* Readme: contributors.

= 0.3.0 =

* REST export API for Migrate: start (`POST /exports`, reused per scope, `max_age`/`fresh`), list, and advance an export with an application password; read any snapshot file in 8 MiB chunks. Administrator permissions only.
* The snapshot of a REST export carries its RawIR v1 as `bridge/rawir.json`. GitHub delivery never includes it.
* `media_files: false` (REST, and a checkbox on the export screen): attachment records without their files; content keeps the WordPress upload URLs.
* SEO: Rank Math, AIOSEO, SEOPress and Yoast title and description templates are rendered to the text a page carries, with robots and canonical; SEOPress support; AIOSEO Pro term values. Checked against each plugin running live.
* Large sites: the export's memory no longer grows with the site. 5,000 posts, 20,000 media records and 20,000 comments export in about two minutes at a 64 MB memory limit.
* Shared hosts: an advance stops before PHP's max_execution_time and saves as it goes, and a request killed midway writes nothing twice.
* A snapshot being downloaded is not replaced by a fresh export for ten minutes.
* Updates are manual outside WordPress.org: install the new release ZIP over the old one.
* Readme: what the plugin requests from this site and from GitHub, and the REST export API, described for WordPress.org.

= 0.2.1 =

* Prevent partial ACF modelling, disambiguate same-name fields and normalize stored dates.
* Protect nested sensitive ACF values and private attachment scope.
* Fix nested-page media URLs, binary export reads and repeated GitHub delivery manifests.
* Clean up expired and uninstalled snapshots; serialize cancellation with active export work.
* Add a checksum-verified Bridge-to-Migrate intake adapter and reproducible package build.

= 0.2.0 =

* ACF field groups become Contentrain models: a repeater is a collection of records, a group is a related record, and sub-field types map onto Contentrain field types.
* Media transfer: uploads and their generated sizes are copied into `media/` and content is relinked to them.
* Contentrain models: JSON collections, Markdown documents, a site singleton and an interface-text dictionary.
* Interface-text discovery in theme and plugin source, with a mandatory review step before anything is exported.
* Bounded, resumable export with validation before delivery.
* Free ZIP download and free delivery to a branch in your own GitHub repository.
* Metadata is opt-in, and secret-looking keys and values are excluded.

= 0.1.0 =

* Initial local RawIR v1 export.
