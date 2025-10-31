<?php
namespace PPA\Ajax; // MUST be first — no BOM/whitespace above.

/*
CHANGE LOG
----------
2025-10-29 • Save-to-Draft: allow auth via either logged-in+cap OR X-PPA-Key;                       # CHANGED:
             fixed debug tail path; removed leading backslashes; retained single-backslash callbacks. # CHANGED:
*/

defined('ABSPATH') || exit;

// --- Debug breadcrumbs (non-sensitive) -------------------------------------------------------------- # CHANGED:
error_log('PPA: store.php loaded');                                                                     # CHANGED:

/**
 * Normalize mixed JSON/form input into a canonical array.
 *
 * @return array{data:array, meta:array, err:?string}
 */
function ppa_normalize_request(): array {                                                                # CHANGED:
    $raw     = file_get_contents('php://input');
    $ct      = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
    $is_json = stripos($ct, 'application/json') !== false;
    $method  = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
    $payload = [];
    $err     = null;

    if ($is_json && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payload = $decoded;
        } else {
            $err = 'invalid json: ' . json_last_error_msg();
        }
    }

    // If JSON missing/invalid, use POST (typical admin-ajax form).
    if (empty($payload) && !empty($_POST)) {
        // phpcs:ignore WordPress.Security.NonceVerification
        $payload = $_POST;
    }

    // Canonical fields
    $title   = isset($payload['title'])   ? $payload['title']   : '';
    $content = isset($payload['content']) ? $payload['content'] : '';
    $excerpt = isset($payload['excerpt']) ? $payload['excerpt'] : '';
    $status  = isset($payload['status'])  ? $payload['status']  : '';
    $slug    = isset($payload['slug'])    ? $payload['slug']    : '';
    $mode    = isset($payload['mode'])    ? $payload['mode']    : '';

    // Optional collections: allow array or CSV string
    $tags       = isset($payload['tags']) ? $payload['tags'] : [];
    $categories = isset($payload['categories']) ? $payload['categories'] : [];
    $author     = isset($payload['author']) ? $payload['author'] : '';

    if (is_string($tags)) {
        $tags = array_filter(array_map('trim', explode(',', $tags)), 'strlen');
    } elseif (!is_array($tags)) {
        $tags = [];
    }

    if (is_string($categories)) {
        $categories = array_filter(array_map('trim', explode(',', $categories)), 'strlen');
    } elseif (!is_array($categories)) {
        $categories = [];
    }

    // Sanitize (content/excerpt use wp_kses_post; others are text)
    $title_s   = sanitize_text_field((string) $title);
    $status_s  = sanitize_text_field((string) $status);
    $slug_s    = sanitize_title((string) $slug);
    $mode_s    = sanitize_text_field((string) $mode);
    $author_s  = sanitize_text_field((string) $author);
    $content_s = wp_kses_post((string) $content);
    $excerpt_s = wp_kses_post((string) $excerpt);

    $tags_s = [];
    foreach ($tags as $t) { $tags_s[] = sanitize_text_field((string) $t); }

    $cats_s = [];
    foreach ($categories as $c) { $cats_s[] = sanitize_text_field((string) $c); }

    $data = [
        'title'      => $title_s,
        'content'    => $content_s,
        'excerpt'    => $excerpt_s,
        'status'     => $status_s,
        'slug'       => $slug_s,
        'tags'       => $tags_s,
        'categories' => $cats_s,
        'author'     => $author_s,
        'mode'       => $mode_s,
    ];

    $meta = [
        'ct'      => $ct,
        'raw_len' => strlen((string) $raw),
        'json'    => $is_json ? 'yes' : 'no',
        'method'  => $method,
    ];

    return ['data' => $data, 'meta' => $meta, 'err' => $err];
}

/**
 * Return the configured PPA shared key without logging it.
 * Prefers WP option 'ppa_shared_key'; falls back to env PPA_SHARED_KEY.
 */
function ppa_get_shared_key(): string {                                                                 # CHANGED:
    $opt = get_option('ppa_shared_key', '');
    if (is_string($opt) && $opt !== '') {
        return trim($opt);
    }
    // Env fallback (never logged)
    $env = getenv('PPA_SHARED_KEY');
    return is_string($env) ? trim($env) : '';
}

/**
 * Check whether the incoming request is authorized to create drafts.
 * True if (A) logged-in and can 'edit_posts' OR (B) header X-PPA-Key matches shared key.
 */
function ppa_is_authorized(): bool {                                                                    # CHANGED:
    if (is_user_logged_in() && current_user_can('edit_posts')) {
        return true;
    }
    $hdr = isset($_SERVER['HTTP_X_PPA_KEY']) ? trim((string) $_SERVER['HTTP_X_PPA_KEY']) : '';
    if ($hdr === '') {
        return false;
    }
    $key = ppa_get_shared_key();
    return ($key !== '' && hash_equals($key, $hdr));
}

/**
 * Main handler for ppa_store.
 * - mode=draft → creates a Draft post
 * - otherwise → echo-only normalize response (no writes)
 */
function handle_store(): void {
    $pack = ppa_normalize_request();                                                                     # CHANGED:

    $mode = strtolower($pack['data']['mode'] ?? '');

    if ($mode === 'draft') {
        // Auth: logged-in+cap OR X-PPA-Key
        if (!ppa_is_authorized()) {                                                                      # CHANGED:
            wp_send_json(
                ['ok' => false, 'error' => 'auth required', 'ver' => 'postpress-ai.v2.1-2025-10-29'],
                401
            );
        }

        error_log('PPA: store mode=draft start');                                                        # CHANGED:

        // Build post array (force draft)
        $postarr = [
            'post_title'   => $pack['data']['title'] ?: '(untitled)',
            'post_content' => $pack['data']['content'],
            'post_excerpt' => $pack['data']['excerpt'],
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ];

        if ($pack['data']['slug'] !== '') {
            $postarr['post_name'] = $pack['data']['slug'];
        }

        // Assign author if provided; else current user
        $author_id = get_current_user_id();
        if ($pack['data']['author'] !== '') {
            $maybe = (int) $pack['data']['author'];
            if ($maybe > 0) {
                $author_id = $maybe;
            }
        }
        $postarr['post_author'] = $author_id;

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            error_log('PPA: store mode=draft wp_error: ' . $post_id->get_error_message());               # CHANGED:
            wp_send_json(
                [
                    'ok'      => false,
                    'error'   => 'insert failed',
                    'message' => $post_id->get_error_message(),
                    'ver'     => 'postpress-ai.v2.1-2025-10-29',
                ],
                500
            );
        }

        // Terms (best-effort; tolerate slugs/ids; ignore failures quietly)
        if (!empty($pack['data']['categories'])) {
            wp_set_post_terms((int) $post_id, $pack['data']['categories'], 'category', false);
        }
        if (!empty($pack['data']['tags'])) {
            wp_set_post_terms((int) $post_id, $pack['data']['tags'], 'post_tag', false);
        }

        $edit_link = get_edit_post_link((int) $post_id, '');

        $resp = [
            'ok'        => true,
            'post_id'   => (int) $post_id,
            'status'    => 'draft',
            'provider'  => 'local-fallback',
            'edit_link' => $edit_link,
            'ver'       => 'postpress-ai.v2.1-2025-10-29',
        ];
        error_log('PPA: store mode=draft ok id=' . (int) $post_id);                                      # CHANGED:
        wp_send_json($resp, 200);
    }

    // Default path: echo normalized payload (no writes)
    error_log('PPA: store echo-only path');                                                              # CHANGED:
    $resp = [
        'ok'      => true,
        'result'  => $pack['data'],
        'meta'    => $pack['meta'],
        'warning' => $pack['err'], // null if none
        'ver'     => '1',
    ];
    wp_send_json($resp, 200);
}

// Hooks (both logged-in and public) ------------------------------------------------------------------ # CHANGED:
add_action('wp_ajax_ppa_store',        __NAMESPACE__ . '\handle_store');                                 # CHANGED:
add_action('wp_ajax_nopriv_ppa_store', __NAMESPACE__ . '\handle_store');                                 # CHANGED:
