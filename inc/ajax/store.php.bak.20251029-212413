<?php
namespace PPA\Ajax; // MUST be first — no BOM/whitespace above.                           // CHANGED:

/*
CHANGE LOG
----------
2025-10-29 • Add nopriv hook; robust JSON+form normalization; echo fields; breadcrumbs; guard helper.  // CHANGED:
2025-10-28 • Prior namespace/BOM + normalization attempt (superseded).                                  // CHANGED:
*/

\defined('ABSPATH') || exit;

\error_log('PPA: ajax loaded store.php'); // breadcrumb (load)                                         // CHANGED:

/**
 * Normalize input (JSON + form) into canonical array.
 * Guard name to avoid redeclare if preview.php defines a similar helper.
 */
if (!\function_exists(__NAMESPACE__ . '\ppa_collect_input_store')) {                                   // CHANGED:
    /**
     * @return array{ok:bool, meta:array<string,mixed>, data:array<string,mixed>, err:?string}
     */
    function ppa_collect_input_store(): array                                                          // CHANGED:
    {
        $ct  = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $raw = \file_get_contents('php://input') ?: '';
        $is_json = \is_string($ct) && \stripos($ct, 'application/json') !== false;

        $meta = [
            'ct'      => $ct,
            'raw_len' => \strlen($raw),
            'json'    => $is_json ? 'yes' : 'no',
            'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
        ];

        $payload = [];
        $err = null;

        if ($is_json && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\json_last_error() === JSON_ERROR_NONE && \is_array($decoded)) {
                $payload = $decoded;
            } else {
                $err = 'invalid json: ' . \json_last_error_msg();
            }
        }

        // If JSON missing/invalid, use POST (typical admin-ajax form)
        if (empty($payload) && !empty($_POST)) {
            // phpcs:ignore WordPress.Security.NonceVerification
            $payload = \wp_unslash($_POST);
        }

        // Canonical fields
        $norm = [
            'title'      => isset($payload['title']) ? (string)$payload['title'] : '',
            'content'    => isset($payload['content']) ? (string)$payload['content'] : '',
            'excerpt'    => isset($payload['excerpt']) ? (string)$payload['excerpt'] : '',
            'status'     => isset($payload['status']) ? (string)$payload['status'] : 'draft',
            'slug'       => isset($payload['slug']) ? (string)$payload['slug'] : '',
            'tags'       => isset($payload['tags']) && \is_array($payload['tags']) ? $payload['tags'] : [],
            'categories' => isset($payload['categories']) && \is_array($payload['categories']) ? $payload['categories'] : [],
            'author'     => isset($payload['author']) ? (string)$payload['author'] : '',
        ];

        // Breadcrumb (no secrets)
        \error_log(
            'PPA: store input ct=' . $meta['ct'] .
            ' raw_len=' . $meta['raw_len'] .
            ' json=' . $meta['json'] .
            ' post_keys=' . (isset($_POST) ? \count($_POST) : 0) .
            ' req_keys=' . (isset($_REQUEST) ? \count($_REQUEST) : 0)
        );                                                                                             // CHANGED:

        return ['ok' => true, 'meta' => $meta, 'data' => $norm, 'err' => $err];
    }
}

/**
 * AJAX handler — normalize + echo (proxy to Django can be layered later).
 */
function handle_store(): void
{
    // Allow both priv and nopriv smoke calls; do capability check only if logged-in context needed later.
    $pack = ppa_collect_input_store();                                                                 // CHANGED:

    $resp = [
        'ok'      => true,
        'result'  => $pack['data'],
        'meta'    => $pack['meta'],
        'warning' => $pack['err'], // null if none
        'ver'     => '1',
    ];

    \wp_send_json($resp, 200);
}

// Register both hooks (priv + nopriv)                                                                // CHANGED:
\add_action('wp_ajax_ppa_store',        __NAMESPACE__ . '\handle_store');                              // CHANGED:
\add_action('wp_ajax_nopriv_ppa_store', __NAMESPACE__ . '\handle_store');                              // CHANGED:
