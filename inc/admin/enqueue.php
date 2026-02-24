<?php
/*
 * PostPress AI — Admin Enqueue (CLEAN)
 *
 * RULES (LOCKED):
 * - ONE CSS FILE PER SCREEN (no cross-screen bleed)
 * - Settings: CSS only (no JS stack)
 * - Account: account CSS + account JS only
 * - Videos: videos CSS only (no JS)
 * - Composer/Testbed: per-screen CSS + shared JS stack
 * - Testbed assets only if PPA_ENABLE_TESTBED === true
 */

defined('ABSPATH') || exit;

/**
 * Dev-only switch:
 * - Default OFF (no Testbed UI, no Testbed assets)
 * - Enable by adding to wp-config.php:
 *     define('PPA_ENABLE_TESTBED', true);
 */
if (!defined('PPA_ENABLE_TESTBED')) {
	define('PPA_ENABLE_TESTBED', false);
}

if (!function_exists('ppa_admin_enqueue')) {

	function ppa_admin_enqueue($hook = '') {

		// -----------------------------
		// Identify current page (safe)
		// -----------------------------
		$page_param = '';
		if (isset($_GET['page'])) {
			$page_param = sanitize_key(wp_unslash($_GET['page']));
		}

		$is_cli    = defined('WP_CLI') && WP_CLI;
		$screen_id = '';
		if (!$is_cli && function_exists('get_current_screen')) {
			try {
				$screen = get_current_screen();
				$screen_id = ($screen && isset($screen->id)) ? (string) $screen->id : '';
			} catch (Throwable $e) {
				$screen_id = '';
			}
		}

		$hook_str = is_string($hook) ? $hook : '';

		// -----------------------------
		// Known PPA screens
		// -----------------------------
		$pages = array(
			'composer' => array(
				'page' => 'postpress-ai',
				'hook' => 'toplevel_page_postpress-ai',
			),
			'settings' => array(
				'page' => 'postpress-ai-settings',
				'hook' => 'postpress-ai_page_postpress-ai-settings',
			),
			'testbed' => array(
				'page' => 'postpress-ai-testbed',
				'hook' => 'postpress-ai_page_postpress-ai-testbed',
			),
			'account' => array(
				'page' => 'postpress-ai-account',
				'hook' => 'postpress-ai_page_postpress-ai-account',
			),
			'videos' => array(
				'page' => 'postpress-ai-videos',
				'hook' => 'postpress-ai_page_postpress-ai-videos',
			),
		);

		$current = '';
		foreach ($pages as $key => $meta) {
			$is_match =
				($page_param === $meta['page']) ||
				($hook_str === $meta['hook']) ||
				($screen_id === $meta['hook']); // screen_id usually matches hook ids for admin pages

			if ($is_match) {
				$current = $key;
				break;
			}
		}

		// Not our admin page → do nothing
		if ($current === '') {
			return;
		}

		// Respect testbed toggle
		if ($current === 'testbed' && !(defined('PPA_ENABLE_TESTBED') && PPA_ENABLE_TESTBED)) {
			return;
		}

		// -----------------------------
		// Resolve paths/URLs once
		// -----------------------------
		$plugin_root_dir  = dirname(__DIR__, 2); // .../wp-content/plugins/postpress-ai
		$plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

		$base_url = '';
		if (defined('PPA_PLUGIN_URL') && is_string(PPA_PLUGIN_URL) && PPA_PLUGIN_URL !== '') {
			$base_url = rtrim(PPA_PLUGIN_URL, '/');
		} else {
			$base_url = rtrim(plugin_dir_url($plugin_main_file), '/');
		}

		$asset_url = function($rel) use ($base_url) {
			$rel = ltrim((string) $rel, '/');
			$url = $base_url . '/' . $rel;
			return function_exists('set_url_scheme') ? set_url_scheme($url, 'https') : $url;
		};

		$asset_path = function($rel) use ($plugin_root_dir) {
			$rel = ltrim((string) $rel, '/');
			return rtrim($plugin_root_dir, '/') . '/' . $rel;
		};

		$asset_ver = function($path) {
			if ($path && file_exists($path)) {
				return (string) filemtime($path);
			}
			// Prefer a stable fallback (avoid time() nuking caches)
			if (defined('PPA_VERSION')) {
				return (string) PPA_VERSION;
			}
			return '1.0.0';
		};

		// -----------------------------
		// Handles we own (optional: hard reset)
		// -----------------------------
		$style_handles = array(
			'ppa-admin-settings-css',
			'ppa-admin-composer-css',
			'ppa-admin-testbed-css',
			'ppa-admin-account-css',
			'ppa-admin-videos-css',
		);

		$script_handles = array(
			'ppa-admin-config',
			'ppa-admin-core',
			'ppa-admin-api',
			'ppa-admin-payloads',
			'ppa-admin-notices',
			'ppa-admin-editor',
			'ppa-admin-generate-view',
			'ppa-admin-composer-preview',
			'ppa-admin-composer-generate',
			'ppa-admin-composer-store',
			'ppa-admin',
			'ppa-admin-preview-spinner',
			'ppa-testbed',
			'ppa-admin-account',
		);

		foreach ($style_handles as $h) {
			if (wp_style_is($h, 'enqueued')) { wp_dequeue_style($h); }
			if (wp_style_is($h, 'registered')) { wp_deregister_style($h); }
		}
		foreach ($script_handles as $h) {
			if (wp_script_is($h, 'enqueued')) { wp_dequeue_script($h); }
			if (wp_script_is($h, 'registered')) { wp_deregister_script($h); }
		}

		// -----------------------------
		// Per-screen CSS (ONE FILE ONLY)
		// -----------------------------
		$css = array(
			'settings' => 'assets/css/admin-settings.css',
			'composer' => 'assets/css/admin-composer.css',
			'testbed'  => 'assets/css/admin-testbed.css',
			'account'  => 'assets/css/admin-account.css',
			'videos'   => 'assets/css/admin-videos.css',
		);

		if (isset($css[$current])) {
			$css_rel  = $css[$current];
			$css_file = $asset_path($css_rel);

			// If a screen’s CSS doesn’t exist yet, don’t fatal — just skip.
			if (file_exists($css_file)) {
				$css_handle = 'ppa-admin-' . $current . '-css';
				wp_register_style($css_handle, $asset_url($css_rel), array(), $asset_ver($css_file), 'all');
				wp_enqueue_style($css_handle);
			}
		}

		// Settings: CSS only
		if ($current === 'settings') {
			return;
		}

		// Videos: CSS only
		if ($current === 'videos') {
			return;
		}

		// Account: CSS + account JS only
		if ($current === 'account') {

			$account_js_rel  = 'assets/js/admin-account.js';
			$account_js_file = $asset_path($account_js_rel);

			if (file_exists($account_js_file)) {
				wp_register_script(
					'ppa-admin-account',
					$asset_url($account_js_rel),
					array('jquery'),
					$asset_ver($account_js_file),
					true
				);
				wp_enqueue_script('ppa-admin-account');

				$site = function_exists('home_url') ? home_url('/') : '';
				if (function_exists('set_url_scheme')) {
					$site = set_url_scheme($site, 'https');
				}

				wp_localize_script('ppa-admin-account', 'PPAAccount', array(
					'ajaxUrl' => admin_url('admin-ajax.php'),
					'nonce'   => wp_create_nonce('ppa-admin'),
					'action'  => 'ppa_account_status',
					'site'    => esc_url_raw((string) $site),
					'jsVer'   => $asset_ver($account_js_file),
				));
			}

			return;
		}

		// -----------------------------
		// Composer/Testbed JS stack only
		// -----------------------------
		$is_stack = ($current === 'composer' || $current === 'testbed');
		if (!$is_stack) {
			return;
		}

		// Config (inline-only; no data: URL nonsense)
		$cfg = array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'restUrl' => esc_url_raw(rest_url()),
			'page'    => $page_param,
			'jsVer'   => defined('PPA_VERSION') ? (string) PPA_VERSION : '1.0.0',
			'wpNonce' => wp_create_nonce('wp_rest'),
			'nonce'   => wp_create_nonce('ppa-admin'),
		);

		wp_register_script('ppa-admin-config', '', array(), null, true);
		wp_enqueue_script('ppa-admin-config');
		wp_add_inline_script(
			'ppa-admin-config',
			'window.PPA = window.PPA || {}; window.PPA = Object.assign(window.PPA, ' . wp_json_encode($cfg) . ');',
			'before'
		);

		// Script manifest (only enqueue if file exists)
		$scripts = array(
			array('ppa-admin-core',            'assets/js/ppa-admin-core.js',            array('jquery','ppa-admin-config')),
			array('ppa-admin-api',             'assets/js/ppa-admin-api.js',             array('jquery','ppa-admin-config','ppa-admin-core')),
			array('ppa-admin-payloads',        'assets/js/ppa-admin-payloads.js',        array('jquery','ppa-admin-config','ppa-admin-core')),
			array('ppa-admin-notices',         'assets/js/ppa-admin-notices.js',         array('jquery','ppa-admin-config','ppa-admin-core')),
			array('ppa-admin-editor',          'assets/js/ppa-admin-editor.js',          array('jquery','ppa-admin-config','ppa-admin-core')),
			array('ppa-admin-generate-view',   'assets/js/ppa-admin-generate-view.js',   array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor')),
			array('ppa-admin-composer-preview','assets/js/ppa-admin-composer-preview.js',array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor')),
			array('ppa-admin-composer-generate','assets/js/ppa-admin-composer-generate.js',array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor')),
			array('ppa-admin-composer-store',  'assets/js/ppa-admin-composer-store.js',  array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor')),
			array('ppa-admin',                 'assets/js/admin.js',                     array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor')),
		);

		foreach ($scripts as $row) {
			$h   = $row[0];
			$rel = $row[1];
			$deps= $row[2];

			$file = $asset_path($rel);
			if (!file_exists($file)) {
				continue;
			}

			wp_register_script($h, $asset_url($rel), $deps, $asset_ver($file), true);
			wp_enqueue_script($h);
		}

		// Localize (kept for legacy consumers)
		if (wp_script_is('ppa-admin', 'enqueued')) {
			wp_localize_script('ppa-admin', 'ppaAdmin', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('ppa-admin'),
				'jsVer'   => defined('PPA_VERSION') ? (string) PPA_VERSION : '1.0.0',
				'wpNonce' => wp_create_nonce('wp_rest'),
			));
		}

		// Composer-only spinner (optional)
		if ($current === 'composer') {
			$spinner_rel  = 'assets/js/admin-preview-spinner.js';
			$spinner_file = $asset_path($spinner_rel);

			if (file_exists($spinner_file) && wp_script_is('ppa-admin', 'enqueued')) {
				wp_register_script(
					'ppa-admin-preview-spinner',
					$asset_url($spinner_rel),
					array('ppa-admin'),
					$asset_ver($spinner_file),
					true
				);
				wp_enqueue_script('ppa-admin-preview-spinner');
			}
		}

		// Testbed-only script (optional)
		if ($current === 'testbed') {
			$testbed_rel  = 'inc/admin/ppa-testbed.js';
			$testbed_file = $asset_path($testbed_rel);

			if (file_exists($testbed_file)) {
				wp_register_script(
					'ppa-testbed',
					$asset_url($testbed_rel),
					array('ppa-admin-config'),
					$asset_ver($testbed_file),
					true
				);
				wp_enqueue_script('ppa-testbed');
			}
		}
	}
}

// Ensure hook is registered
add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 9);