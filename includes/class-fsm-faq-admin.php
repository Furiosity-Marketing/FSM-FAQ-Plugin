<?php
/**
 * Furiosity FAQ: Admin columns, save_post handler (_has_faqs + cache invalidation), and helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache token appended to FAQ output cache keys. Combines the plugin version, a
 * bumpable cache buster (content/order changes), and a hash of display settings
 * (styling changes) so cached HTML is invalidated whenever any of them change.
 *
 * @return string Cache token.
 * @since 1.1.0
 */
function fsm_faq_cache_token() {
	$buster        = (int) get_option( 'fsm_faq_cache_buster', 1 );
	$settings_hash = function_exists( 'fsm_faq_settings_hash' ) ? fsm_faq_settings_hash() : '0';
	return FSM_FAQ_VERSION . '_' . $buster . '_' . $settings_hash;
}

/**
 * Bump the cache buster so all cached FAQ output is invalidated on the next request.
 *
 * @since 1.1.0
 */
function fsm_faq_bump_cache() {
	update_option( 'fsm_faq_cache_buster', ( (int) get_option( 'fsm_faq_cache_buster', 1 ) ) + 1 );
}

/**
 * Checks if any FAQs are assigned to a specific post ID.
 *
 * @param int|null $post_id Post ID to check. Defaults to current post ID.
 * @return bool True if FAQs are assigned, false otherwise.
 */
function fsm_has_faqs_for_page( $post_id = null ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	if ( ! $post_id ) {
		return false;
	}

	$args = array(
		'post_type'                  => 'faq',
		'posts_per_page'             => 1,
		'meta_query'                 => array(
			array(
				'key'     => 'display_on_pages',
				'value'   => '"' . absint( $post_id ) . '"',
				'compare' => 'LIKE',
			),
		),
		'fields'                     => 'ids',
		'no_found_rows'              => true,
		'update_post_meta_cache'     => false,
		'update_post_term_cache'     => false,
	);

	$faq_query = new WP_Query( $args );

	return $faq_query->have_posts();
}

/**
 * When a page or FAQ is saved, updates _has_faqs on affected pages (for Divi conditional logic)
 * and invalidates [fsm_display_faqs] cache.
 */
function fsm_update_faq_status_on_save( $post_id, $post ) {
	$parent_types = function_exists( 'fsm_faq_get_parent_post_types' )
		? fsm_faq_get_parent_post_types()
		: array( 'page' );
	$tracked_types = array_merge( array( 'faq' ), $parent_types );

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! in_array( $post->post_type, $tracked_types, true ) ) {
		return;
	}

	$pages_to_check = array();

	if ( 'faq' === $post->post_type ) {
		if ( isset( $_POST['acf']['display_on_pages'] ) && is_array( $_POST['acf']['display_on_pages'] ) ) {
			$new_pages = array_map( 'absint', wp_unslash( $_POST['acf']['display_on_pages'] ) );
			$pages_to_check = array_merge( $pages_to_check, $new_pages );
		}
		if ( function_exists( 'get_field' ) ) {
			$old_pages = (array) get_field( 'display_on_pages', $post_id, false );
			$pages_to_check = array_merge( $pages_to_check, $old_pages );
		}
	} elseif ( in_array( $post->post_type, $parent_types, true ) ) {
		$pages_to_check[] = $post_id;
	}

	foreach ( array_unique( $pages_to_check ) as $page_id ) {
		$page_id = absint( $page_id );
		if ( empty( $page_id ) ) {
			continue;
		}
		if ( fsm_has_faqs_for_page( $page_id ) ) {
			update_post_meta( $page_id, '_has_faqs', 1 );
		} else {
			delete_post_meta( $page_id, '_has_faqs' );
		}
	}

	// Cache keys are versioned + settings-hashed, so bump the global buster to
	// invalidate any affected FAQ output (replaces the previous un-versioned delete).
	fsm_faq_bump_cache();
}
add_action( 'save_post', 'fsm_update_faq_status_on_save', 10, 2 );

add_filter( 'manage_faq_posts_columns', 'fsm_faq_add_columns' );
function fsm_faq_add_columns( $columns ) {
	$new_columns = array(
		'assigned_pages' => __( 'Assigned to Pages', 'fsm-faq' ),
	);
	return array_slice( $columns, 0, 2, true ) + $new_columns + array_slice( $columns, 2, null, true );
}

add_action( 'manage_faq_posts_custom_column', 'fsm_faq_display_assigned_pages_column', 10, 2 );
function fsm_faq_display_assigned_pages_column( $column, $post_id ) {
	if ( 'assigned_pages' !== $column ) {
		return;
	}
	if ( ! function_exists( 'get_field' ) ) {
		echo '—';
		return;
	}
	$assigned_pages = get_field( 'display_on_pages', $post_id );
	if ( empty( $assigned_pages ) || ! is_array( $assigned_pages ) ) {
		echo '—';
		return;
	}
	$links = array();
	foreach ( $assigned_pages as $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			continue;
		}
		$page_title = get_the_title( $page_id );
		$edit_link  = get_edit_post_link( $page_id );
		if ( $edit_link && $page_title ) {
			$links[] = '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $page_title ) . '</a>';
		}
	}
	echo ! empty( $links ) ? implode( ', ', $links ) : '—';
}
