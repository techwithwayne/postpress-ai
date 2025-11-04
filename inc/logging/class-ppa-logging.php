<?php
// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/logging/class-ppa-logging.php
/**
 * PostPress AI — Logging / History (CPT)
 *
 * ========= CHANGE LOG =========
 * 2025-11-03: New file. Introduces CPT `ppa_generation` + admin columns + logging helpers.   # CHANGED:
 *             - PPALogging::init() registers CPT + list table columns.                       # CHANGED:
 *             - PPALogging::log_event($args) for simple inserts from preview/store.          # CHANGED:
 *             - No UI styles or inline scripts; pure server logic.                           # CHANGED:
 * ==========================================================================================
 */

namespace PPA\Logging; // CHANGED:

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central logging utilities + CPT registration.
 *
 * Usage after wiring in bootstrap:
 *   \PPA\Logging\PPALogging::init();
 *   \PPA\Logging\PPALogging::log_event([ 'type' => 'preview', 'subject' => 'Hello', ... ]);
 */
class PPALogging { // CHANGED:

	/**
	 * Wire hooks (to be called from plugin bootstrap).                                        # CHANGED:
	 */
	public static function init() { // CHANGED:
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );                                   // CHANGED:
		add_filter( 'manage_edit-ppa_generation_columns', [ __CLASS__, 'admin_columns' ] );    // CHANGED:
		add_action( 'manage_ppa_generation_posts_custom_column', [ __CLASS__, 'admin_column_render' ], 10, 2 ); // CHANGED:
	} // CHANGED:

	/**
	 * Register the `ppa_generation` CPT to store history rows.                                # CHANGED:
	 */
	public static function register_cpt() { // CHANGED:
		$labels = [
			'name'               => __( 'PPA Generations', 'postpress-ai' ),
			'singular_name'      => __( 'PPA Generation', 'postpress-ai' ),
			'add_new_item'       => __( 'Add PPA Generation', 'postpress-ai' ),
			'edit_item'          => __( 'Edit PPA Generation', 'postpress-ai' ),
			'new_item'           => __( 'New PPA Generation', 'postpress-ai' ),
			'view_item'          => __( 'View PPA Generation', 'postpress-ai' ),
			'search_items'       => __( 'Search PPA Generations', 'postpress-ai' ),
			'not_found'          => __( 'No generations found', 'postpress-ai' ),
			'not_found_in_trash' => __( 'No generations found in Trash', 'postpress-ai' ),
			'menu_name'          => __( 'PPA History', 'postpress-ai' ),
		];

		register_post_type(
			'ppa_generation',
			[
				'labels'              => $labels,
				'public'              => false,   // not publicly queryable
				'show_ui'             => true,    // visible in admin
				'show_in_menu'        => 'postpress-ai', // groups under our menu if available
				'show_in_admin_bar'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => [ 'title', 'editor', 'author' ], // keep simple; details in meta
				'menu_icon'           => 'dashicons-media-text',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);
	} // CHANGED:

	/**
	 * Define admin list columns.                                                               # CHANGED:
	 * @param array $cols
	 * @return array
	 */
	public static function admin_columns( $cols ) { // CHANGED:
		// Keep checkbox/title/date but add quick info columns.
		$out = [];
		foreach ( $cols as $key => $label ) {
			if ( 'cb' === $key || 'title' === $key ) {
				$out[ $key ] = $label;
			}
		}
		$out['ppa_type']     = __( 'Type', 'postpress-ai' );           // preview|store|error
		$out['ppa_provider'] = __( 'Provider', 'postpress-ai' );       // django/local-fallback
		$out['ppa_status']   = __( 'Status', 'postpress-ai' );         // ok|fail
		$out['ppa_excerpt']  = __( 'Excerpt', 'postpress-ai' );        // short summary
		$out['date']         = __( 'Date', 'postpress-ai' );

		return $out;
	} // CHANGED:

	/**
	 * Render custom column values.                                                              # CHANGED:
	 */
	public static function admin_column_render( $column, $post_id ) { // CHANGED:
		switch ( $column ) {
			case 'ppa_type':
				echo esc_html( get_post_meta( $post_id, '_ppa_type', true ) ?: '-' );
				break;
			case 'ppa_provider':
				echo esc_html( get_post_meta( $post_id, '_ppa_provider', true ) ?: '-' );
				break;
			case 'ppa_status':
				echo esc_html( get_post_meta( $post_id, '_ppa_status', true ) ?: '-' );
				break;
			case 'ppa_excerpt':
				$ex = (string) get_post_meta( $post_id, '_ppa_excerpt', true );
				if ( '' === $ex ) {
					// Fall back to trimmed content if meta missing.
					$raw = get_post_field( 'post_content', $post_id );
					$ex  = wp_trim_words( wp_strip_all_tags( (string) $raw ), 18, '…' );
				}
				echo esc_html( $ex );
				break;
		}
	} // CHANGED:

	/**
	 * Insert a generation log row.                                                              # CHANGED:
	 *
	 * @param array $args {
	 *   @type string $type      'preview'|'store'|'error'
	 *   @type string $subject   Optional subject/topic
	 *   @type string $provider  e.g. 'django' or 'local-fallback'
	 *   @type string $status    'ok'|'fail'
	 *   @type string $message   Short status message (optional)
	 *   @type string $excerpt   Short preview of content (optional)
	 *   @type string $content   Long content/body to store in post_content (optional)
	 *   @type array  $meta      Additional key=>value pairs to persist as post meta (optional)
	 * }
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function log_event( array $args ) { // CHANGED:
		$defaults = [
			'type'     => 'preview',
			'subject'  => '',
			'provider' => '',
			'status'   => '',
			'message'  => '',
			'excerpt'  => '',
			'content'  => '',
			'meta'     => [],
		];
		$a = array_merge( $defaults, $args );

		$title = trim( sprintf(
			'%s — %s %s',
			$a['subject'] !== '' ? $a['subject'] : __( 'Untitled', 'postpress-ai' ),
			strtoupper( $a['type'] ),
			( $a['status'] ? '[' . $a['status'] . ']' : '' )
		) );

		$post_id = wp_insert_post( [
			'post_type'   => 'ppa_generation',
			'post_status' => 'publish', // history rows are immediately visible in admin
			'post_title'  => $title,
			'post_content'=> (string) $a['content'],
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Core metadata we expect to query in the list table.
		update_post_meta( $post_id, '_ppa_type',     sanitize_text_field( (string) $a['type'] ) );
		update_post_meta( $post_id, '_ppa_provider', sanitize_text_field( (string) $a['provider'] ) );
		update_post_meta( $post_id, '_ppa_status',   sanitize_text_field( (string) $a['status'] ) );
		update_post_meta( $post_id, '_ppa_message',  sanitize_text_field( (string) $a['message'] ) );
		update_post_meta( $post_id, '_ppa_excerpt',  sanitize_text_field( (string) $a['excerpt'] ) );

		// Any extra keys (e.g., tone/genre/word_count/slug).
		if ( is_array( $a['meta'] ) ) {
			foreach ( $a['meta'] as $k => $v ) {
				if ( is_string( $k ) ) {
					update_post_meta( $post_id, sanitize_key( $k ), is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
				}
			}
		}

		return (int) $post_id;
	} // CHANGED:
} // CHANGED:
