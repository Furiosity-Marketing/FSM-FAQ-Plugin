<?php
/**
 * Furiosity FAQ: Native drag-and-drop ordering for the FAQ list table.
 *
 * Sets menu_order on the FAQ post type by dragging rows on the All FAQs screen
 * (no Order meta box). This provides the global display order used by the shortcode;
 * the ACF "Page FAQs" relationship controls membership (which FAQs appear on a page).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen Options per-page value for the All FAQs list table.
 *
 * Uses the current user's edit_faq_per_page setting so pagination offsets stay
 * correct on incomplete last pages (visible row count must not be used).
 *
 * @return int At least 1; defaults to 20 when unset.
 * @since 1.1.5
 */
function fsm_faq_get_edit_per_page() {
	$per_page = (int) get_user_option( 'edit_faq_per_page' );
	if ( $per_page < 1 ) {
		$per_page = 20;
	}
	return $per_page;
}

/**
 * Default the admin FAQ list to menu_order so drag ordering is reflected immediately.
 *
 * @param WP_Query $query The current query.
 * @since 1.1.0
 */
add_action( 'pre_get_posts', 'fsm_faq_order_admin_query' );
function fsm_faq_order_admin_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'faq' !== $query->get( 'post_type' ) ) {
		return;
	}
	if ( $query->get( 'orderby' ) ) {
		return;
	}
	$query->set( 'orderby', 'menu_order title' );
	$query->set( 'order', 'ASC' );
}

/**
 * Whether drag ordering may run on the current list-table request. Disabled while
 * searching, filtering by date, or using a custom sort, where a partial/re-sorted
 * list would make reordering ambiguous.
 *
 * @return bool
 * @since 1.1.0
 */
function fsm_faq_order_is_sortable_view() {
	if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view check.
		return false;
	}
	if ( ! empty( $_GET['m'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}
	if ( isset( $_GET['orderby'] ) && 'menu_order' !== $_GET['orderby'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}
	return true;
}

/**
 * Enqueue the sortable script on the All FAQs screen.
 *
 * @param string $hook Current admin page hook.
 * @since 1.1.0
 */
add_action( 'admin_enqueue_scripts', 'fsm_faq_order_enqueue' );
function fsm_faq_order_enqueue( $hook ) {
	if ( 'edit.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'faq' !== $screen->post_type ) {
		return;
	}
	if ( ! current_user_can( 'edit_others_faqs' ) || ! fsm_faq_order_is_sortable_view() ) {
		return;
	}

	wp_enqueue_script(
		'fsm-faq-admin-order',
		plugin_dir_url( dirname( __FILE__ ) ) . 'assets/fsm-faq-admin-order.js',
		array( 'jquery', 'jquery-ui-sortable' ),
		FSM_FAQ_VERSION,
		true
	);
	wp_localize_script(
		'fsm-faq-admin-order',
		'fsmFaqOrder',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fsm_faq_update_order' ),
			'perPage' => fsm_faq_get_edit_per_page(),
		)
	);

	$handle = 'fsm-faq-admin-order-inline';
	wp_register_style( $handle, false, array(), FSM_FAQ_VERSION );
	wp_enqueue_style( $handle );
	wp_add_inline_style(
		$handle,
		'.post-type-faq #the-list tr{cursor:move;}'
		. '.post-type-faq #the-list tr.fsm-faq-no-drag{cursor:default;}'
		. '.post-type-faq .fsm-faq-sort-placeholder{outline:2px dashed #b4b9be;background:#f0f0f1;}'
		. '.post-type-faq #the-list tr.fsm-faq-saving{opacity:.5;}'
	);
}

/**
 * AJAX handler: persist a new menu_order for the dragged FAQ rows.
 *
 * Assigns menu_order sequentially within the visible page, offset by the current
 * pagination so ordering is stable across paged views. Per-page comes from the
 * user's Screen Options (edit_faq_per_page), not the visible row count.
 *
 * @since 1.1.0
 */
add_action( 'wp_ajax_fsm_faq_update_order', 'fsm_faq_ajax_update_order' );
function fsm_faq_ajax_update_order() {
	check_ajax_referer( 'fsm_faq_update_order', 'nonce' );

	if ( ! current_user_can( 'edit_others_faqs' ) ) {
		wp_send_json_error( array( 'message' => 'insufficient_permissions' ), 403 );
	}

	$ids = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : array();
	$ids = array_values( array_filter( $ids ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'empty_order' ), 400 );
	}

	$paged    = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
	$per_page = fsm_faq_get_edit_per_page();
	$base     = ( $paged - 1 ) * $per_page;

	foreach ( $ids as $index => $post_id ) {
		if ( get_post_type( $post_id ) !== 'faq' || ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'         => $post_id,
				'menu_order' => $base + $index,
			)
		);
	}

	if ( function_exists( 'fsm_faq_bump_cache' ) ) {
		fsm_faq_bump_cache();
	}

	wp_send_json_success( array( 'message' => 'ok' ) );
}
