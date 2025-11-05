<?php
/**
 * PostPress AI — AJAX Preview Proxy
 *
 * CHANGE LOG
 * 2025-11-04 • Add logging via \PPA\Logging\PPALogging::log_event() with safe guards.                    # CHANGED:
 *             - Log upstream Django success (ok) and failures (error)                                    # CHANGED:
 *             - Log local-fallback preview (ok)                                                           # CHANGED:
 *             - Added tiny helper ppa_log_safe() to avoid hard dependency fatals                          # CHANGED:
 * 2025-10-28 • FIX: robust multi-source input merge; tolerate slashes/BOM; guard helper; always JSON-forward; safe breadcrumbs.
 * 2025-10-27 • Harden JSON forwarding; accept JSON or form; normalize payload keys; add safe breadcrumbs.
 */

namespace PPA\Ajax;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal logging wrapper so this file never fatals if logging class is missing.                # CHANGED:
 * @param array<string,mixed> $args                                                              # CHANGED:
 * @return int|false                                                                             # CHANGED:
 */
if (!function_exists(__NAMESPACE__ . '\\ppa_log_safe')) {                                        // CHANGED:
    function ppa_log_safe(array $args) {                                                         // CHANGED:
        if (class_exists('\\PPA\\Logging\\PPALogging') && method_exists('\\PPA\\Logging\\PPALogging', 'log_event')) { // CHANGED:
            try {                                                                                // CHANGED:
                return \PPA\Logging\PPALogging::log_event($args);                                 // CHANGED:
            } catch (\Throwable $e) {                                                            // CHANGED:
                error_log('PPA: preview log_event error: ' . $e->getMessage());                  // CHANGED:
            }                                                                                    // CHANGED:
        }                                                                                        // CHANGED:
        return false;                                                                            // CHANGED:
    }                                                                                            // CHANGED:
}

/**
 * Merge incoming inputs from multiple sources without secrets.
 * Guarded so it can coexist with other files.
 *
 * @return array<string,mixed>
 */
if (!function_exists(__NAMESPACE__ . '\\ppa_collect_input')) {
    function ppa_collect_input(): array {
        // Detect content type hints (best-effort)
        $ct = '';
        foreach (['CONTENT_TYPE', 'HTTP_CONTENT_TYPE'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ct = strtolower((string) $_SERVER[$h]);
                break;
            }
        }

        // Read raw body once; tolerate BOM and magic slashes
        $raw = file_get_contents('php://input');
        $raw_len = is_string($raw) ? strlen($raw) : 0;

        // Strip UTF-8 BOM if present
        if ($raw_len >= 3 && substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
            $raw_len = strlen($raw);
        }

        $json = null;
        if ($raw_len > 0) {
            // Try as-is
            $json = json_decode($raw, true);
            // Retry with stripslashes if the stack added slashes
            if (!is_array($json)) {
                $json = json_decode(stripslashes($raw), true);
            }
        }

        // Start with JSON (only if decoded), then layer POST, then REQUEST
        $in = is_array($json) ? $json : [];
        foreach (['_POST', '_REQUEST'] as $super) {
            $src = $GLOBALS[$super] ?? [];
            if (is_array($src)) {
                foreach ($src as $k => $v) {
                    if (!array_key_exists($k, $in)) {
                        $in[$k] = $v;
                    }
                }
            }
        }

        // Safe breadcrumb only (no secrets)
        error_log(sprintf(
            'PPA: preview input ct=%s raw_len=%d post_keys=%d req_keys=%d json=%s',
            ($ct ?: 'n/a'),
            $raw_len,
            is_array($_POST ?? null) ? count($_POST) : 0,
            is_array($_REQUEST ?? null) ? count($_REQUEST) : 0,
            is_array($json) ? 'yes' : 'no'
        ));

        return $in;
    }
}

/**
 * Handle preview requests from admin-ajax.php?action=ppa_preview
 */
function preview() {
    $in = ppa_collect_input();

    // Normalize (accept multiple aliases)
    $title   = isset($in['title'])   ? sanitize_text_field($in['title'])   : '';
    $content = isset($in['content']) ? wp_kses_post($in['content'])        : '';
    $status  = isset($in['status'])  ? sanitize_text_field($in['status'])  : 'draft';
    $excerpt = isset($in['excerpt']) ? sanitize_text_field($in['excerpt']) : '';

    if (!$title && !empty($in['subject'])) { $title = sanitize_text_field($in['subject']); }
    if (!$content && !empty($in['body']))  { $content = wp_kses_post($in['body']); }

    $payload = [
        'title'      => $title,
        'content'    => $content,
        'excerpt'    => $excerpt,
        'status'     => $status ?: 'draft',
        'slug'       => isset($in['slug']) ? sanitize_title($in['slug']) : '',
        'tags'       => isset($in['tags']) && is_array($in['tags']) ? array_map('sanitize_text_field', $in['tags']) : [],
        'categories' => isset($in['categories']) && is_array($in['categories']) ? array_map('sanitize_text_field', $in['categories']) : [],
        'author'     => get_current_user_id() ? (string) get_current_user_id() : '',
        // Diagnostics (non-secret)
        'site'       => get_site_url(),
        'plugin'     => 'postpress-ai',
        'timestamp'  => time(),
    ];

    // Endpoint
    $django = defined('PPA_DJANGO_URL') ? constant('PPA_DJANGO_URL') : (getenv('PPA_DJANGO_URL') ?: get_option('ppa_django_url'));
    if (!$django) { $django = 'https://apps.techwithwayne.com/postpress-ai'; }
    $endpoint = rtrim($django, '/') . '/preview/';

    // Auth
    $shared_key = defined('PPA_SHARED_KEY') ? constant('PPA_SHARED_KEY') : (getenv('PPA_SHARED_KEY') ?: '');
    $headers = [
        'Content-Type'   => 'application/json',
        'X-PPA-Install'  => parse_url(get_site_url(), PHP_URL_HOST),
        'X-PPA-Version'  => 'wp-plugin-1',
    ];
    if ($shared_key) { $headers['X-PPA-Key'] = $shared_key; }

    // Forward (always JSON)
    $body_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
    $resp = wp_remote_post($endpoint, [
        'timeout'     => 20,
        'httpversion' => '1.1',
        'headers'     => $headers,
        'body'        => $body_json,
    ]);

    $subject_for_log = $title ?: __('Preview', 'postpress-ai');                                                // CHANGED:

    if (is_wp_error($resp)) {
        // Upstream transport failure -> log error, then fall back.                                            // CHANGED:
        $msg = $resp->get_error_message();                                                                     // CHANGED:
        error_log('PPA: preview upstream error: ' . $msg);
        ppa_log_safe([
            'type'     => 'error',
            'subject'  => $subject_for_log,
            'provider' => 'django',
            'status'   => 'fail',
            'message'  => $msg,
            'excerpt'  => wp_trim_words(wp_strip_all_tags((string) $content), 24, '…'),
            'meta'     => ['endpoint' => $endpoint, 'phase' => 'transport'],                                   // CHANGED:
        ]);                                                                                                     // CHANGED:
    } else {
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        error_log('PPA: preview upstream code=' . intval($code) . ' len=' . strlen((string) $body));
        if ($code >= 200 && $code < 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['ok'])) {
                $result  = isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;    // CHANGED:
                $ex      = (string) ($result['excerpt'] ?? $result['summary'] ?? '');                                     // CHANGED:
                $long    = (string) ($result['content'] ?? $result['html'] ?? '');                                        // CHANGED:
                // Log django success                                                                                      // CHANGED:
                ppa_log_safe([
                    'type'     => 'preview',
                    'subject'  => $subject_for_log,
                    'provider' => 'django',
                    'status'   => 'ok',
                    'message'  => 'preview ok',
                    'excerpt'  => $ex !== '' ? $ex : wp_trim_words(wp_strip_all_tags($long ?: (string) $content), 24, '…'),
                    'content'  => $long,
                    'meta'     => [
                        'slug'   => (string) ($payload['slug'] ?? ''),
                        'tags'   => $payload['tags'],
                        'cats'   => $payload['categories'],
                    ],                                                                                                   // CHANGED:
                ]);                                                                                                       // CHANGED:

                wp_send_json([
                    'ok'       => true,
                    'ver'      => isset($decoded['ver']) ? $decoded['ver'] : '1',
                    'result'   => isset($decoded['result']) ? $decoded['result'] : $decoded,
                    'provider' => 'django',
                ], 200);
            } else {
                // Non-OK payload from django -> log error (then fall back)                                              // CHANGED:
                ppa_log_safe([
                    'type'     => 'error',
                    'subject'  => $subject_for_log,
                    'provider' => 'django',
                    'status'   => 'fail',
                    'message'  => 'invalid django payload',
                    'excerpt'  => wp_trim_words(wp_strip_all_tags((string) $content), 24, '…'),
                    'meta'     => ['endpoint' => $endpoint, 'phase' => 'payload'],                                       // CHANGED:
                ]);                                                                                                       // CHANGED:
            }
        } else {
            // Non-2xx from django -> log error (then fall back)                                                          // CHANGED:
            ppa_log_safe([
                'type'     => 'error',
                'subject'  => $subject_for_log,
                'provider' => 'django',
                'status'   => 'fail',
                'message'  => 'http ' . intval($code),
                'excerpt'  => wp_trim_words(wp_strip_all_tags((string) $content), 24, '…'),
                'meta'     => ['endpoint' => $endpoint, 'phase' => 'http'],                                              // CHANGED:
            ]);                                                                                                           // CHANGED:
        }
    }

    // Fallback (also log success for the local-fallback shown to the user)                                    // CHANGED:
    $fallback_html = '<h1>' . esc_html($title ?: 'Preview') . '</h1>' . "\n<p>Preview is using a local fallback.</p>\n<!-- provider: local-fallback -->"; // CHANGED:
    ppa_log_safe([
        'type'     => 'preview',
        'subject'  => $subject_for_log,
        'provider' => 'local-fallback',
        'status'   => 'ok',
        'message'  => 'fallback ok',
        'excerpt'  => ($excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags((string) $content), 24, '…')),
        'content'  => $fallback_html,
        'meta'     => [
            'slug'   => (string) ($payload['slug'] ?? ''),
            'tags'   => $payload['tags'],
            'cats'   => $payload['categories'],
        ],                                                                                                               // CHANGED:
    ]);                                                                                                                   // CHANGED:

    wp_send_json([
        'ok'       => true,
        'ver'      => '1',
        'result'   => [
            'title'   => $title,
            'html'    => $fallback_html,                                                                                  // CHANGED:
            'summary' => "Preview generated for '" . ($title ?: 'Preview') . "' using local-fallback.",
        ],
        'provider' => 'local-fallback',
    ], 200);
}

add_action('wp_ajax_ppa_preview', __NAMESPACE__ . '\\preview');
add_action('wp_ajax_nopriv_ppa_preview', __NAMESPACE__ . '\\preview');
