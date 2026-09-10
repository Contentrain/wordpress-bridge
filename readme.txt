=== Contentrain Bridge ===
Contributors: contentrain
Tags: export, migration, headless, astro, content
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model and export WordPress content and interface text as Contentrain JSON/Markdown, locally or to your own GitHub repository.

== Description ==

Contentrain Bridge turns your WordPress content into editable JSON and Markdown under Tools > Contentrain Bridge. You choose the content, review how it is modelled and which interface text travels, then download a ZIP or send the result to a branch in your own GitHub repository. Both are free.

* Free software under GPL-2.0-or-later. No account, subscription or paid service is required for either delivery.
* Your WordPress site is not modified. The plugin reads content and source files; it never patches a theme or plugin.
* Every export step is started by a logged-in administrator through a nonce-protected request.
* Exports run in bounded steps and can be resumed, so large sites do not depend on one long request.
* Comment archiving is off by default. When enabled, email addresses, IP addresses and comment metadata are excluded.
* Metadata that looks like a password, token, key, credential or personal identifier is never exported, including inside selected fields.
* Exported site data remains the site owner's data and is not placed under the plugin's GPL license merely by being exported.

The export reports what it did not cover. Media binaries are referenced by their original URL and are not transferred; dynamic WordPress and plugin output is not rendered during a source scan. These limits are written into the coverage report rather than implied to be complete.

= External services =

The plugin makes no network request unless you choose GitHub delivery.

**GitHub** — used only when you enter a repository and a token in step 3 and start the delivery. The plugin then calls the GitHub REST API at api.github.com to read the target repository's default branch and file list, upload the exported files, and create one new branch holding them. What is sent: the exported content files you reviewed, a commit message, and the token you supplied. Nothing is sent to Contentrain or to any other host, and no analytics or telemetry is collected. The token is used for those requests only and is never stored in the database or in the export. Delivery never writes to your default branch and never overwrites a file you edited in Git. GitHub's terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service — privacy policy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

== Installation ==

1. Upload the `contentrain-bridge` folder to `/wp-content/plugins/` or install the plugin ZIP.
2. Activate Contentrain Bridge in WordPress.
3. Open Tools > Contentrain Bridge.
4. Step 1: choose content types and options, then prepare the content.
5. Step 2: review the generated models and the interface-text candidates, then validate and finalize.
6. Step 3: download the ZIP, or deliver to a branch in your own GitHub repository.

== Frequently Asked Questions ==

= Is Contentrain Migrate or Studio required? =

No. Modelling, validation, the ZIP download and GitHub delivery are all local and free. Migrate is an optional later step if you want an Astro website.

= Does the plugin send telemetry? =

No. It collects no analytics and contacts no Contentrain server. The only external requests are to the GitHub API, only when you start a GitHub delivery yourself.

= Are media files exported? =

Media records carry their title, alt text, caption and original URL. Binary files are not transferred, so a delivered repository still points at this site for images. The coverage report states this.

= Which comment data is exported? =

Only when explicitly selected: author name, URL, content, status, dates and relationships. Email addresses, IP addresses, user-agent values and comment metadata are excluded.

= What does the source scan read? =

The active theme and child theme, and optionally the source files of active plugins. It reads PHP, HTML and JavaScript text to find interface strings, never executes them, skips vendor and dependency directories, and stops at 10,000 files. Every string it finds is a candidate you review before it becomes content.

== Privacy ==

The plugin stores no settings and sends nothing anywhere on its own. Export files are written to a private, per-administrator directory, are removed on uninstall, and expire after a day. An export can contain site content, author display names, selected post metadata and, if you chose it, privacy-minimized comments. Treat a downloaded export as you would a WordPress export file. If you deliver to GitHub, the exported content is sent to GitHub under the account whose token you provide; choose a private repository when the export includes draft or private content.

== Changelog ==

= 0.2.0 =

* Contentrain models: JSON collections, Markdown documents, a site singleton and an interface-text dictionary.
* Interface-text discovery in theme and plugin source, with a mandatory review step before anything is exported.
* Bounded, resumable export with validation before delivery.
* Free ZIP download and free delivery to a branch in your own GitHub repository.
* Metadata is opt-in, and secret-looking keys and values are excluded.

= 0.1.0 =

* Initial local RawIR v1 export.
