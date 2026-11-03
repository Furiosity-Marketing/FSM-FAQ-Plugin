<?php
/**
 * Furiosity FAQ: [fsm_display_faqs] and [fsm_display_generic_faqs] shortcodes.
 *
 * - [fsm_display_faqs]: Uses Divi markup when Divi is active; otherwise generic accordion.
 * - [fsm_display_generic_faqs]: Always uses generic accordion (theme-agnostic).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Divi (or a Divi child theme) is the active theme.
 *
 * @return bool True if Divi or Divi child is active.
 * @since 1.1.0
 */
function fsm_faq_is_divi_active() {
	$theme    = wp_get_theme();
	$name     = $theme->get( 'Name' );
	$template = $theme->get_template();
	return ( 'Divi' === $name || 'Divi' === $template );
}

/**
 * Normalize typographic apostrophes (and their HTML entities) to ASCII so they survive
 * the_content/wp_kses and any filters that strip or replace U+2019 (e.g. property's → property s).
 * Covers: Unicode chars U+2018/U+2019 and entities &#8216;/&#8217;, &#x2018;/&#x2019;, &lsquo;/&rsquo;.
 *
 * @param string $text FAQ question title, answer body, or other content.
 * @return string Same content with typographic apostrophes replaced by ASCII apostrophe.
 * @since 1.1.0
 */
function fsm_faq_normalize_typographic_apostrophes( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return $text;
	}
	$replace = array(
		"\u{2019}", "\u{2018}",           // RIGHT/LEFT SINGLE QUOTATION MARK
		"&#8217;", "&#8216;",             // decimal entities
		"&#x2019;", "&#x2018;",           // hex entities (lowercase)
		"&#X2019;", "&#X2018;",           // hex entities (uppercase)
		'&rsquo;', '&lsquo;',             // named entities
	);
	return str_replace( $replace, "'", $text );
}

/**
 * Maximum number of FAQ posts loaded for a page (shortcode / schema).
 *
 * Never returns a value below 1, so WP_Query is never passed posts_per_page -1.
 * Raise via the fsm_faq_query_limit filter when a page legitimately needs more.
 *
 * @return int
 * @since 1.1.3
 */
function fsm_faq_get_query_limit() {
	$limit = (int) apply_filters( 'fsm_faq_query_limit', 50 );
	return ( $limit < 1 ) ? 50 : $limit;
}

/**
 * Get FAQ items and schema data for a post. Shared by both shortcodes.
 *
 * Answer HTML is sanitized with a tight allowlist (not the_content). wpautop runs
 * only when the stored answer has no block-level HTML yet, so schema and the toggle
 * share the same safe markup without double-wrapping paragraphs.
 *
 * @param int $post_id Current page/post ID.
 * @return array{ items: array, schema_questions: array } Empty items/schema_questions on failure.
 * @since 1.1.0
 */
function fsm_faq_get_faq_data( $post_id ) {
	$result = array(
		'items'            => array(),
		'schema_questions' => array(),
	);

	if ( ! $post_id || ! function_exists( 'get_field' ) ) {
		return $result;
	}

	$query_limit = fsm_faq_get_query_limit();

	// Membership: when the page's ACF "Page FAQs" relationship is populated, use those
	// IDs (bidirectional sync keeps this set for normal Display On assignments). Always
	// order by global menu_order from All FAQs drag-and-drop — do not use post__in order,
	// which would ignore menu_order whenever page_faqs is non-empty.
	$member_ids = get_field( 'page_faqs', $post_id );
	$member_ids = ( is_array( $member_ids ) ) ? array_values( array_filter( array_map( 'absint', $member_ids ) ) ) : array();

	if ( ! empty( $member_ids ) ) {
		// Do not slice $member_ids here: relationship order is not menu_order. WP_Query
		// applies posts_per_page after sorting the full membership set by menu_order.
		$args = array(
			'post_type'              => 'faq',
			'posts_per_page'         => $query_limit,
			'post__in'               => $member_ids,
			'orderby'                => 'menu_order',
			'order'                  => 'ASC',
			'post_status'            => 'publish',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
	} else {
		$args = array(
			'post_type'              => 'faq',
			'posts_per_page'         => $query_limit,
			'meta_query'             => array(
				array(
					'key'     => 'display_on_pages',
					'value'   => '"' . absint( $post_id ) . '"',
					'compare' => 'LIKE',
				),
			),
			'orderby'                => 'menu_order',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
	}

	$faq_query = new WP_Query( $args );

	if ( ! $faq_query->have_posts() ) {
		wp_reset_postdata();
		return $result;
	}

	while ( $faq_query->have_posts() ) {
		$faq_query->the_post();
		$question = fsm_faq_normalize_typographic_apostrophes( get_the_title() );
		$answer   = get_field( 'faq_answer' );

		if ( empty( $question ) || empty( $answer ) ) {
			continue;
		}

		if ( function_exists( 'fsm_faq_sanitize_answer_html' ) ) {
			$answer = fsm_faq_sanitize_answer_html( $answer );
		} else {
			$answer = fsm_faq_normalize_typographic_apostrophes( $answer );
			$answer = wp_kses_post( $answer );
		}

		if ( '' === trim( wp_strip_all_tags( $answer ) ) && false === strpos( $answer, '<img' ) && false === strpos( $answer, '<table' ) ) {
			continue;
		}

		$result['items'][] = array(
			'question' => $question,
			'answer'   => $answer,
		);

		$result['schema_questions'][] = array(
			'@type'          => 'Question',
			'name'           => esc_html( $question ),
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $answer,
			),
		);
	}
	wp_reset_postdata();

	return $result;
}

/**
 * Enqueue generic accordion CSS and JS (for fallback output).
 *
 * @since 1.1.0
 */
function fsm_faq_enqueue_generic_assets() {
	$url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';
	wp_enqueue_style(
		'fsm-faq-accordion',
		$url . 'fsm-faq-accordion.css',
		array(),
		FSM_FAQ_VERSION
	);
	wp_enqueue_script(
		'fsm-faq-accordion',
		$url . 'fsm-faq-accordion.js',
		array(),
		FSM_FAQ_VERSION,
		true
	);
}

/**
 * Build Divi accordion markup (original behavior). No schema; caller adds it.
 *
 * Answer HTML is already sanitized in fsm_faq_get_faq_data(); apostrophes are
 * entity-encoded so they survive post-shortcode processing (e.g. Divi).
 *
 * @param array $items Array of { question, answer }.
 * @return string HTML.
 * @since 1.1.0
 */
function fsm_faq_render_divi_markup( $items ) {
	if ( empty( $items ) ) {
		return '';
	}

	$settings    = function_exists( 'fsm_faq_get_settings' ) ? fsm_faq_get_settings() : array( 'first_open' => '1', 'allow_close' => '1' );
	$first_open  = isset( $settings['first_open'] ) && '1' === (string) $settings['first_open'];
	// Checkbox OR Foundation WCAG kit — kit closes accordions globally, so the icon must match.
	$allow_close = function_exists( 'fsm_faq_is_divi_faq_close_afforded' )
		? ( fsm_faq_is_divi_faq_close_afforded( $settings ) ? '1' : '0' )
		: ( ( function_exists( 'fsm_faq_is_allow_close_enabled' ) && fsm_faq_is_allow_close_enabled( $settings ) ) ? '1' : '0' );

	$html = '<div class="fsm-faq-divi et_pb_module et_pb_accordion et_pb_accordion_0_tb_body et_pb_text_align_left" data-allow-close="' . esc_attr( $allow_close ) . '">';
	$i   = 0;
	foreach ( $items as $item ) {
		$answer_content = fsm_faq_normalize_typographic_apostrophes( $item['answer'] );
		// Output apostrophe as entity so it survives any post-shortcode processing (e.g. Divi) that strips the raw character.
		$answer_content = str_replace( "'", '&#39;', $answer_content );
		$toggle_state_class = ( 0 === $i && $first_open ) ? 'et_pb_toggle_open' : 'et_pb_toggle_close';
		$html .= '<div class="et_pb_toggle et_pb_module et_pb_accordion_item ' . esc_attr( $toggle_state_class ) . '">';
		$html .= '<h3 class="et_pb_toggle_title">' . esc_html( $item['question'] ) . '</h3>';
		$html .= '<div class="et_pb_toggle_content clearfix">' . $answer_content . '</div>';
		$html .= '</div>';
		$i++;
	}
	$html .= '</div>';
	return $html;
}

/**
 * Build generic accordion markup (W3Schools-style). No schema; caller adds it.
 * Each question wraps in .fsm-faq-accordion__item (button + panel) for card chrome.
 *
 * Answer HTML is already sanitized in fsm_faq_get_faq_data(); apostrophes are
 * entity-encoded so they survive post-shortcode processing.
 *
 * @param array $items Array of { question, answer }.
 * @return string HTML.
 * @since 1.1.0
 */
function fsm_faq_render_generic_markup( $items ) {
	if ( empty( $items ) ) {
		return '';
	}

	$settings    = function_exists( 'fsm_faq_get_settings' ) ? fsm_faq_get_settings() : array( 'first_open' => '1', 'allow_close' => '1' );
	$first_open  = ( isset( $settings['first_open'] ) && '1' === (string) $settings['first_open'] ) ? '1' : '0';
	$allow_close = function_exists( 'fsm_faq_is_allow_close_enabled' ) && fsm_faq_is_allow_close_enabled( $settings ) ? '1' : '0';

	$block_id = 'fsm-faq-' . uniqid();
	$html     = '<div class="fsm-faq-accordion" id="' . esc_attr( $block_id ) . '" data-first-open="' . esc_attr( $first_open ) . '" data-allow-close="' . esc_attr( $allow_close ) . '">';
	$index   = 0;
	foreach ( $items as $item ) {
		$answer_content = fsm_faq_normalize_typographic_apostrophes( $item['answer'] );
		// Output apostrophe as entity so it survives any post-shortcode processing that strips the raw character.
		$answer_content = str_replace( "'", '&#39;', $answer_content );
		$btn_id   = $block_id . '-btn-' . $index;
		$panel_id = $block_id . '-panel-' . $index;
		$html    .= '<div class="fsm-faq-accordion__item">';
		$html    .= '<button type="button" id="' . esc_attr( $btn_id ) . '" class="fsm-faq-accordion__btn" aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '">';
		$html    .= '<h3 class="fsm-faq-accordion__title">' . esc_html( $item['question'] ) . '</h3>';
		$html    .= '</button>';
		$html    .= '<div id="' . esc_attr( $panel_id ) . '" class="fsm-faq-accordion__panel" role="region" aria-labelledby="' . esc_attr( $btn_id ) . '">';
		$html    .= '<div class="fsm-faq-accordion__panel-inner">' . $answer_content . '</div>';
		$html    .= '</div>';
		$html    .= '</div>';
		$index++;
	}
	$html .= '</div>';
	return $html;
}

/**
 * Sync data-allow-close on Divi FAQ markup to the live close affordance.
 *
 * The Foundation kit enqueue state is request-time and is not part of
 * fsm_faq_cache_token() (version + buster + settings only). Cached HTML can
 * therefore keep data-allow-close="0" after the kit is present, which hides
 * the close icon and lets the capture-phase handler block the kit.
 *
 * Cached HTML is left unchanged; only the returned markup is patched.
 *
 * @param string $html Cached or freshly rendered FAQ output.
 * @return string HTML with data-allow-close synced on .fsm-faq-divi.
 * @since 1.1.9
 */
function fsm_faq_sync_divi_allow_close_attr( $html ) {
	if ( ! is_string( $html ) || '' === $html || false === strpos( $html, 'fsm-faq-divi' ) ) {
		return $html;
	}

	$allow = ( function_exists( 'fsm_faq_is_divi_faq_close_afforded' ) && fsm_faq_is_divi_faq_close_afforded() ) ? '1' : '0';

	$updated = preg_replace(
		'/(<div class="fsm-faq-divi\b[^"]*" data-allow-close=")[01](")/',
		'${1}' . $allow . '${2}',
		$html,
		1
	);

	return is_string( $updated ) ? $updated : $html;
}

/**
 * Shortcode: [fsm_display_faqs]
 *
 * Description: Displays FAQ posts assigned to the page. Uses Divi markup when Divi is active; otherwise generic accordion. Includes FAQ schema.
 *
 * Attributes: None. Uses current post ID in the loop.
 *
 * Example Usage:
 * [fsm_display_faqs]
 *
 * @return string HTML output
 */
add_shortcode( 'fsm_display_faqs', 'fsm_display_faqs_shortcode' );
function fsm_display_faqs_shortcode() {
	$current_post_id = get_the_ID();

	if ( ! $current_post_id ) {
		return '';
	}

	$use_divi = fsm_faq_is_divi_active();

	// Enqueue assets on every call (including cache hits) so styling always loads.
	if ( function_exists( 'fsm_faq_enqueue_frontend_assets' ) ) {
		fsm_faq_enqueue_frontend_assets( $use_divi ? 'divi' : 'generic' );
	}

	$cache_key     = 'fsm_faqs_' . absint( $current_post_id ) . '_v' . fsm_faq_cache_token();
	$cached_output = wp_cache_get( $cache_key );

	if ( false !== $cached_output ) {
		return fsm_faq_sync_divi_allow_close_attr( $cached_output );
	}

	$data = fsm_faq_get_faq_data( $current_post_id );

	if ( empty( $data['items'] ) ) {
		wp_cache_set( $cache_key, '', '', HOUR_IN_SECONDS );
		return '';
	}

	$html = $use_divi
		? fsm_faq_render_divi_markup( $data['items'] )
		: fsm_faq_render_generic_markup( $data['items'] );

	$final_output  = fsm_faq_get_inline_schema_script( $data['schema_questions'] );
	$final_output .= $html;

	// Cache the render-time HTML; live kit enqueue may still differ on later hits.
	wp_cache_set( $cache_key, $final_output, '', HOUR_IN_SECONDS );

	return fsm_faq_sync_divi_allow_close_attr( $final_output );
}

/**
 * Shortcode: [fsm_display_generic_faqs]
 *
 * Description: Displays FAQ posts assigned to the page using the generic accordion (theme-agnostic). Use on non-Divi sites or when you want accordion behavior without Divi. Includes FAQ schema.
 *
 * Attributes: None. Uses current post ID in the loop.
 *
 * Example Usage:
 * [fsm_display_generic_faqs]
 *
 * @return string HTML output
 */
add_shortcode( 'fsm_display_generic_faqs', 'fsm_display_generic_faqs_shortcode' );
function fsm_display_generic_faqs_shortcode() {
	$current_post_id = get_the_ID();

	if ( ! $current_post_id ) {
		return '';
	}

	// Enqueue assets on every call (including cache hits) so styling always loads.
	if ( function_exists( 'fsm_faq_enqueue_frontend_assets' ) ) {
		fsm_faq_enqueue_frontend_assets( 'generic' );
	}

	$cache_key     = 'fsm_faqs_generic_' . absint( $current_post_id ) . '_v' . fsm_faq_cache_token();
	$cached_output = wp_cache_get( $cache_key );

	if ( false !== $cached_output ) {
		return $cached_output;
	}

	$data = fsm_faq_get_faq_data( $current_post_id );

	if ( empty( $data['items'] ) ) {
		wp_cache_set( $cache_key, '', '', HOUR_IN_SECONDS );
		return '';
	}

	$html = fsm_faq_render_generic_markup( $data['items'] );

	$final_output  = fsm_faq_get_inline_schema_script( $data['schema_questions'] );
	$final_output .= $html;

	wp_cache_set( $cache_key, $final_output, '', HOUR_IN_SECONDS );

	return $final_output;
}