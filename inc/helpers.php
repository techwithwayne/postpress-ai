<?php
defined( 'ABSPATH' ) || exit;

/**
 * Simple global helpers that the plugin can use.
 */

/**
 * Check capability wrapper.
 *
 * @return bool
 */
function ppa_current_user_can_edit_posts() {
    return current_user_can( 'edit_posts' );
}

/**
 * Simple wrapper health-check (also used by WP-CLI tests).
 *
 * @return array
 */
function ppa_health_check_global() {
    return array( 'ok' => true, 'plugin' => 'postpress-ai', 'message' => 'helpers loaded' );
}
