# Security policy

Report vulnerabilities privately to `security@contentrain.io`. Do not attach production credentials or a complete WordPress export.

Admin operations require both `export` and `manage_options`, plus a WordPress nonce. The read-only REST endpoint uses WordPress authentication and the same capabilities, and checks snapshot ownership. Unauthenticated access is denied. REST responses are private/no-store; binary files are base64 encoded.

Exports live in private temporary directories outside the WordPress web root. They expire after 24 hours; scheduled cleanup and uninstall remove files and ownership bookkeeping. Cancellation acquires the same lock as export/delivery. If the temporary volume is unavailable during uninstall, its private files cannot be removed by WordPress.

GitHub delivery is opt-in and uses a repository-scoped credential held only for the request. Production administration must use HTTPS. The destination is fixed to api.github.com, redirects are disabled, and delivery creates a separate branch without force-pushing or writing the default branch. Exports including private content require a private repository. Existing edited files cause a conflict.

Post metadata is selected and redacted; ACF sensitive field types are also checked recursively. These filters do not claim to detect every possible secret embedded in arbitrary prose. Review the content before sharing. Comment email, IP and comment metadata are excluded. The source scan never executes code and source-file reuse is not applied.
