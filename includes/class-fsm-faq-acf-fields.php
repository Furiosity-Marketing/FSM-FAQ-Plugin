<?php
/**
 * Furiosity FAQ: Register ACF field groups (FAQ post type + Page FAQs relationship).
 *
 * Provides display_on_pages and faq_answer on FAQ, and optional page_faqs on parent post types
 * (default: Page; extend via fsm_faq_parent_post_types filter)
 * for bidirectional editing. No manual ACF setup required.
 *
 * One-time: deactivates any database copies of these field groups (same keys) so
 * the plugin's local groups are used. Groups are not deleted and can be re-enabled
 * under Custom Fields → Field Groups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keys for FAQ and Page FAQs field groups. */
define( 'FSM_FAQ_ACF_GROUP_FAQS', 'group_68dd4428d3136' );
define( 'FSM_FAQ_ACF_GROUP_PAGE_FAQS', 'group_68f0076749dc4' );

/**
 * Post types that may appear in FAQ "Display On" (post_object) and that receive the
 * bidirectional "Page FAQs" field group. Defaults to page only; sites may append CPTs
 * (e.g. Divi project) via the fsm_faq_parent_post_types filter.
 *
 * @return string[] Sanitized post type slugs.
 */
function fsm_faq_get_parent_post_types() {
	$types = apply_filters( 'fsm_faq_parent_post_types', array( 'page' ) );
	if ( ! is_array( $types ) ) {
		$types = array( 'page' );
	}
	$types = array_map( 'sanitize_key', $types );
	$types = array_filter( array_unique( $types ) );
	return ! empty( $types ) ? array_values( $types ) : array( 'page' );
}

/**
 * Allowed HTML for FAQ answers (front-end, schema, and on save).
 *
 * Permits formatting, http(s)/mailto links, images, and tables. No scripts,
 * iframes, event handlers, or arbitrary shortcodes.
 *
 * @return array<string,array<string,bool>>
 * @since 1.1.3
 */
function fsm_faq_answer_allowed_html() {
	$cell_atts = array(
		'class'   => true,
		'colspan' => true,
		'rowspan' => true,
		'scope'   => true,
		'width'   => true,
		'height'  => true,
	);

	return array(
		'a'          => array(
			'href'   => true,
			'title'  => true,
			'rel'    => true,
			'target' => true,
			'class'  => true,
		),
		'p'          => array( 'class' => true ),
		'br'         => array(),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'ul'         => array( 'class' => true ),
		'ol'         => array( 'class' => true ),
		'li'         => array( 'class' => true ),
		'blockquote' => array( 'class' => true ),
		'span'       => array( 'class' => true ),
		'div'        => array(
			'id'    => true,
			'class' => true,
			'style' => true, // Core sets width on div.wp-caption when HTML5 captions are off.
		),
		'img'        => array(
			'src'      => true,
			'alt'      => true,
			'width'    => true,
			'height'   => true,
			'class'    => true,
			'srcset'   => true,
			'sizes'    => true,
			'loading'  => true,
			'decoding' => true,
		),
		'figure'     => array(
			'id'    => true,
			'class' => true,
			'style' => true,
		),
		'figcaption' => array( 'class' => true ),
		'table'      => array(
			'class' => true,
			'width' => true,
		),
		'caption'    => array( 'class' => true ),
		'thead'      => array(),
		'tbody'      => array(),
		'tfoot'      => array(),
		'tr'         => array( 'class' => true ),
		'th'         => $cell_atts,
		'td'         => $cell_atts,
		'colgroup'   => array(),
		'col'        => array(
			'span'  => true,
			'width' => true,
		),
	);
}

/**
 * Expand only the core [caption] / [wp_caption] shortcodes (Add Media with a caption).
 *
 * Other shortcodes are left unexpanded so they cannot run, then stripped by kses.
 *
 * @param string $content Raw answer HTML.
 * @return string
 * @since 1.1.3
 */
function fsm_faq_expand_caption_shortcodes( $content ) {
	if ( ! is_string( $content ) || '' === $content || false === strpos( $content, '[' ) ) {
		return $content;
	}

	global $shortcode_tags;
	if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
		return $content;
	}

	$allowed        = array_flip( array( 'caption', 'wp_caption' ) );
	$original       = $shortcode_tags;
	$shortcode_tags = array_intersect_key( $original, $allowed );
	$content        = do_shortcode( $content );
	$shortcode_tags = $original;

	return $content;
}

/**
 * Harden <a> tags: only _blank as target; add noopener noreferrer when it is used.
 *
 * @param string $html HTML after wp_kses.
 * @return string
 * @since 1.1.3
 */
function fsm_faq_harden_answer_links( $html ) {
	if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( 'A' ) ) {
		$target = strtolower( (string) $processor->get_attribute( 'target' ) );
		if ( $target && '_blank' !== $target ) {
			$processor->remove_attribute( 'target' );
			$target = '';
		}
		if ( '_blank' === $target ) {
			$rel       = (string) $processor->get_attribute( 'rel' );
			$rel_parts = $rel ? preg_split( '/\s+/', strtolower( $rel ) ) : array();
			$rel_parts = is_array( $rel_parts ) ? $rel_parts : array();
			foreach ( array( 'noopener', 'noreferrer' ) as $token ) {
				if ( ! in_array( $token, $rel_parts, true ) ) {
					$rel_parts[] = $token;
				}
			}
			$processor->set_attribute( 'rel', implode( ' ', array_filter( $rel_parts ) ) );
		}
	}

	return $processor->get_updated_html();
}

/**
 * Prepare FAQ answer HTML for storage and display.
 *
 * Does not run the_content (no arbitrary shortcodes or oEmbed). Applies wpautop
 * only when the content has no block-level HTML yet (plain-text imports), then a
 * tight kses allowlist so images, tables, captions, and links remain. Skips
 * wpautop when markup already has paragraphs/blocks so save + front-end sanitize
 * do not double-wrap.
 *
 * @param string $content Raw answer from ACF.
 * @return string Safe HTML.
 * @since 1.1.3
 */
function fsm_faq_sanitize_answer_html( $content ) {
	if ( ! is_string( $content ) || '' === $content ) {
		return '';
	}

	$content = fsm_faq_normalize_typographic_apostrophes( $content );
	$content = fsm_faq_expand_caption_shortcodes( $content );
	if ( ! preg_match( '/<(p|ul|ol|li|table|figure|blockquote|div|h[1-6])\b/i', $content ) ) {
		$content = wpautop( $content );
	}
	$content = wp_kses(
		$content,
		fsm_faq_answer_allowed_html(),
		array( 'http', 'https', 'mailto' )
	);
	$content = fsm_faq_harden_answer_links( $content );

	return $content;
}

/**
 * Restrict the FAQ answer WYSIWYG to formatting, links, lists, and tables.
 * Add Media remains available on the field for images.
 *
 * @param array $toolbars ACF toolbar definitions.
 * @return array
 * @since 1.1.3
 */
function fsm_faq_wysiwyg_toolbars( $toolbars ) {
	$toolbars['FAQ']    = array();
	$toolbars['FAQ'][1] = array(
		'bold',
		'italic',
		'bullist',
		'numlist',
		'link',
		'unlink',
		'blockquote',
		'table',
		'undo',
		'redo',
		'removeformat',
	);
	return $toolbars;
}
add_filter( 'acf/fields/wysiwyg/toolbars', 'fsm_faq_wysiwyg_toolbars' );

/**
 * Sanitize faq_answer when saved so disallowed markup is not stored.
 *
 * @param mixed $value Field value.
 * @return mixed
 * @since 1.1.3
 */
function fsm_faq_sanitize_answer_on_save( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}
	return fsm_faq_sanitize_answer_html( $value );
}
add_filter( 'acf/update_value/name=faq_answer', 'fsm_faq_sanitize_answer_on_save', 10, 1 );

/**
 * One-time: deactivate native (DB) FAQ field groups so plugin local groups are used.
 *
 * Does not delete field-group posts. FAQ post meta is unchanged. Re-run is skipped
 * after fsm_faq_acf_db_groups_deactivated is set; groups can be re-activated in ACF.
 *
 * @since 1.1.3
 */
add_action( 'acf/init', 'fsm_faq_maybe_deactivate_native_faq_groups', 5 );
function fsm_faq_maybe_deactivate_native_faq_groups() {
	if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_update_field_group' ) ) {
		return;
	}
	if ( get_option( 'fsm_faq_acf_db_groups_deactivated', false ) ) {
		return;
	}

	$keys = array( FSM_FAQ_ACF_GROUP_FAQS, FSM_FAQ_ACF_GROUP_PAGE_FAQS );
	foreach ( $keys as $key ) {
		$group = acf_get_field_group( $key );
		if ( ! is_array( $group ) || empty( $group['ID'] ) ) {
			continue;
		}
		if ( isset( $group['active'] ) && ! $group['active'] ) {
			continue;
		}
		$group['active'] = 0;
		acf_update_field_group( $group );
	}

	update_option( 'fsm_faq_acf_db_groups_deactivated', true, true );
}

add_action( 'acf/init', 'fsm_faq_register_field_groups', 10 );
function fsm_faq_register_field_groups() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$faq_parent_post_types = fsm_faq_get_parent_post_types();

	$page_faqs_location = array();
	foreach ( $faq_parent_post_types as $post_type_slug ) {
		$page_faqs_location[] = array(
			array(
				'param'    => 'post_type',
				'operator' => '==',
				'value'    => $post_type_slug,
			),
		);
	}

	acf_add_local_field_group( array(
		'key'                   => FSM_FAQ_ACF_GROUP_FAQS,
		'title'                 => 'FAQs',
		'fields'                => array(
			array(
				'key'               => 'field_68dd4429b1ee9',
				'label'             => 'Answer',
				'name'              => 'faq_answer',
				'aria-label'        => '',
				'type'              => 'wysiwyg',
				'instructions'      => 'Formatting, lists, links (http/https/mailto), images via Add Media, and HTML tables. Shortcodes and embeds are not rendered.',
				'required'          => 1,
				'conditional_logic' => 0,
				'wrapper'           => array(
					'width' => '',
					'class' => '',
					'id'    => '',
				),
				'default_value'     => '',
				'tabs'              => 'all',
				'toolbar'           => 'FAQ',
				'media_upload'      => 1,
				'delay'             => 0,
			),
			array(
				'key'                   => 'field_68dd445ab1eea',
				'label'                 => 'Display On',
				'name'                  => 'display_on_pages',
				'aria-label'            => '',
				'type'                  => 'post_object',
				'instructions'          => '',
				'required'              => 1,
				'conditional_logic'     => 0,
				'wrapper'               => array(
					'width' => '',
					'class' => '',
					'id'    => '',
				),
				'post_type'             => $faq_parent_post_types,
				'post_status'           => '',
				'taxonomy'              => '',
				'return_format'          => 'id',
				'multiple'              => 1,
				'allow_null'             => 0,
				'bidirectional'          => 1,
				'bidirectional_target'   => array( 'field_68f00767a983d' ),
				'ui'                    => 1,
			),
		),
		'location'              => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'faq',
				),
			),
		),
		'menu_order'            => 0,
		'position'               => 'acf_after_title',
		'style'                  => 'seamless',
		'label_placement'        => 'top',
		'instruction_placement'  => 'label',
		'hide_on_screen'         => '',
		'active'                 => true,
		'description'             => '',
		'show_in_rest'           => 0,
	) );

	acf_add_local_field_group( array(
		'key'                   => FSM_FAQ_ACF_GROUP_PAGE_FAQS,
		'title'                 => 'Page FAQs',
		'fields'                => array(
			array(
				'key'                   => 'field_68f00767a983d',
				'label'                 => 'Page FAQs',
				'name'                  => 'page_faqs',
				'aria-label'            => '',
				'type'                  => 'relationship',
				'instructions'          => '',
				'required'              => 0,
				'conditional_logic'     => 0,
				'wrapper'               => array(
					'width' => '',
					'class' => '',
					'id'    => '',
				),
				'post_type'             => array( 'faq' ),
				'post_status'           => array( 'publish' ),
				'taxonomy'              => '',
				'filters'               => array( 'search' ),
				'return_format'          => 'id',
				'min'                   => '',
				'max'                   => '',
				'elements'              => '',
				'bidirectional'          => 1,
				'bidirectional_target'  => array( 'field_68dd445ab1eea' ),
				'ui'                    => 1,
			),
		),
		'location'              => $page_faqs_location,
		'menu_order'            => 0,
		'position'               => 'normal',
		'style'                  => 'default',
		'label_placement'        => 'top',
		'instruction_placement'  => 'label',
		'hide_on_screen'         => '',
		'active'                 => true,
		'description'             => '',
		'show_in_rest'           => 0,
	) );
}
