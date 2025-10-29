=== PostPress AI ===
Contributors: techwithwayne
Tags: ai, content, generator, post, wordpress, django, editorial
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Minimal PostPress AI — AI-assisted content composer and a WordPress → Django bridge for editorial workflows.

== Description ==

PostPress AI helps create draft content quickly using AI and provides a safe server-side bridge to a Django-based generation backend. It includes:

* Admin composer UI for quick article drafts
* AJAX preview and store flows
* Nonce-protected endpoints and safe fallbacks
* Centralized asset loading and localization
* Designed for local/offline fallback when external AI is unavailable

This repository is the plugin bootstrap and modular `inc/` includes so the plugin can be audited and extended feature-by-feature.

== Installation ==

1. Upload the `postpress-ai` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure your Django backend URL and `PPA_SHARED_KEY` on the server (environment) used by the plugin (do not store secrets in plugin files).
4. Visit the plugin admin page (Tools → PostPress AI or the top-level menu) to access the composer UI.

== Frequently Asked Questions ==

= Does PostPress AI send content to OpenAI or external APIs? =
PostPress AI is designed to call a trusted Django backend that runs AI providers server-side. The WordPress plugin communicates with the Django service using a shared key (`X-PPA-Key`) to authenticate requests. You must configure the Django service separately.

= Where do I put the shared key? =
Place the `PPA_SHARED_KEY` in your server environment (e.g., PythonAnywhere env, or as a secured SiteGround variable). The plugin expects the Django backend to validate the key. Do not paste keys into support threads.

== Screenshots ==

1. Composer UI — draft composer in admin.
2. Preview modal — generated HTML preview returned from backend.
3. Store flow — saved draft confirmation.

(Replace these placeholders with real screenshots before submitting to wordpress.org. Filenames: screenshot-1.png, screenshot-2.png, screenshot-3.png)

== Changelog ==

= 0.1.0 =
* Initial release — bootstrap, admin enqueue include, guarded AJAX stubs, and composer plumbing.

== Upgrade Notice ==

= 0.1.0 =
Initial public release.

== Arbitrary section: Development Notes ==

This plugin follows a modular pattern. Key directories:

- `inc/` — modular includes (admin, ajax, helpers)
- `assets/` — JS/CSS images
- `scripts/` — deployment tooling and helpers

Before submitting to the WordPress.org plugin repository you will need to:

1. Replace placeholder screenshots with real images (assets/screenshots).
2. Create a stable tag and follow SVN import or use the WordPress.org plugin release process.
3. Provide a translation-ready `.pot` file (use `make-pot.php` or WP i18n tools).
4. Ensure license headers and GPL compliance for any third-party libraries.

== Upgrade / Submission checklist ==

- [ ] Screenshots added to plugin root (screenshot-1.png, screenshot-2.png)
- [ ] Readme tested with `wordpress.org` format (Stable tag set to plugin version)
- [ ] POT file generated (i18n)
- [ ] All `inc/ajax/*.php` handlers implemented and smoke-tested
- [ ] Composer admin UI validated on WP admin screens
- [ ] All lint checks pass (`php -l`, `node --check`, WP-CLI smoke)
- [ ] Final security review (nonce names consistent, capability checks where appropriate)
