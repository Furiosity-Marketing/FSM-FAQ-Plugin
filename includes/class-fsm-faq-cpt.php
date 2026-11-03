<?php
/**
 * Furiosity FAQ: Register FAQ post type.
 *
 * Uses dedicated faq/faqs capabilities (not post) so the Author role cannot
 * publish FAQs or assign them onto pages. Editors and Administrators keep full
 * access, including assigning FAQs to pages they did not publish.
 *
 * @see get_post_type_labels() for label keys.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primitive capabilities for the FAQ post type (map_meta_cap).
 *
 * @return string[]
 * @since 1.1.3
 */
function fsm_faq_get_capability_names() {
	return array(
		'edit_faqs',
		'edit_others_faqs',
		'edit_published_faqs',
		'edit_private_faqs',
		'publish_faqs',
		'read_private_faqs',
		'delete_faqs',
		'delete_others_faqs',
		'delete_published_faqs',
		'delete_private_faqs',
	);
}

/**
 * Whether a role should manage FAQs (Editor/Admin-level, not Author).
 *
 * Defaults to roles that can edit others' pages or manage options. Filter
 * fsm_faq_role_can_manage_faqs to include a custom role.
 *
 * @param WP_Role $role Role object.
 * @return bool
 * @since 1.1.3
 */
function fsm_faq_role_should_manage_faqs( $role ) {
	if ( ! $role instanceof WP_Role ) {
		return false;
	}

	$allowed = $role->has_cap( 'edit_others_pages' ) || $role->has_cap( 'manage_options' );

	/**
	 * Filter whether a role is granted FAQ management capabilities.
	 *
	 * @param bool   $allowed Whether the role should manage FAQs.
	 * @param string $role_name Role slug.
	 */
	return (bool) apply_filters( 'fsm_faq_role_can_manage_faqs', $allowed, $role->name );
}

/**
 * Grant FAQ capabilities to Editor/Admin-level roles; remove them from others.
 *
 * Runs on activation and admin_init so existing installs pick this up without
 * a re-activation. Authors (publish_posts only) are not granted these caps.
 *
 * @since 1.1.3
 */
function fsm_faq_grant_capabilities() {
	$caps  = fsm_faq_get_capability_names();
	$roles = wp_roles();
	if ( ! $roles ) {
		return;
	}

	foreach ( $roles->role_objects as $role ) {
		if ( fsm_faq_role_should_manage_faqs( $role ) ) {
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
			continue;
		}
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

add_action( 'admin_init', 'fsm_faq_grant_capabilities' );

add_action( 'init', 'fsm_faq_cpt' );
function fsm_faq_cpt() {
	$labels = array(
		'name'               => _x( 'FAQs', 'Post type general name', 'fsm-faq' ),
		'singular_name'      => _x( 'FAQ', 'Post type singular name', 'fsm-faq' ),
		'menu_name'          => _x( 'FAQs', 'Admin Menu text', 'fsm-faq' ),
		'add_new'            => __( 'Add New', 'fsm-faq' ),
		'add_new_item'       => __( 'Add New FAQ', 'fsm-faq' ),
		'edit_item'          => __( 'Edit FAQ', 'fsm-faq' ),
		'view_item'          => __( 'View FAQ', 'fsm-faq' ),
		'all_items'          => __( 'All FAQs', 'fsm-faq' ),
		'search_items'       => __( 'Search FAQs', 'fsm-faq' ),
		'not_found'          => __( 'No FAQs found.', 'fsm-faq' ),
		'not_found_in_trash' => __( 'No FAQs found in Trash.', 'fsm-faq' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => false,
		'rewrite'            => false,
		'capability_type'    => array( 'faq', 'faqs' ),
		'map_meta_cap'       => true,
		'has_archive'        => false,
		'hierarchical'       => false,
		'menu_position'      => 20,
		'supports'           => array( 'title' ),
		'menu_icon'          => 'dashicons-editor-help',
	);

	register_post_type( 'faq', $args );
}
