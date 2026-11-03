<?php
/**
 * Furiosity FAQ: FAQPage JSON-LD schema output.
 *
 * Three modes (see Settings -> Structured Data):
 * - shortcode  : inline <script type="application/ld+json"> after the FAQ markup (default).
 * - seo_plugin : merge FAQ entities into the active SEO plugin's schema graph
 *                (Yoast, Rank Math, or All in One SEO). Inline output is suppressed.
 * - off        : no schema output from this plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect a supported SEO plugin.
 *
 * @return string One of 'yoast', 'rankmath', 'aioseo', or '' when none detected.
 * @since 1.1.0
 */
function fsm_faq_detect_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
		return 'yoast';
	}
	if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return 'rankmath';
	}
	if ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
		return 'aioseo';
	}
	return '';
}

/**
 * Effective schema mode: 'seo_plugin' downgrades to 'shortcode' when no supported
 * SEO plugin is active, so schema is never silently dropped.
 *
 * @return string 'shortcode', 'seo_plugin', or 'off'.
 * @since 1.1.0
 */
function fsm_faq_effective_schema_mode() {
	$settings = function_exists( 'fsm_faq_get_settings' ) ? fsm_faq_get_settings() : array( 'schema_mode' => 'shortcode' );
	$mode     = isset( $settings['schema_mode'] ) ? $settings['schema_mode'] : 'shortcode';

	if ( 'seo_plugin' === $mode && '' === fsm_faq_detect_seo_plugin() ) {
		return 'shortcode';
	}
	return $mode;
}

/**
 * Build the inline JSON-LD <script> for the FAQ shortcodes.
 *
 * Returns an empty string unless the effective schema mode is 'shortcode', so the
 * shortcodes never emit duplicate schema when an SEO plugin owns the graph.
 *
 * @param array $schema_questions Array of Question nodes from fsm_faq_get_faq_data().
 * @return string Script tag or empty string.
 * @since 1.1.0
 */
function fsm_faq_get_inline_schema_script( $schema_questions ) {
	if ( empty( $schema_questions ) || 'shortcode' !== fsm_faq_effective_schema_mode() ) {
		return '';
	}

	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => $schema_questions,
	);

	// JSON_HEX_TAG / JSON_HEX_AMP encode <, >, and & so a payload cannot close this <script> tag.
	return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>';
}

/**
 * Get the FAQ Question nodes for the currently queried singular object.
 *
 * Used by the SEO-plugin graph adapters, which run during wp_head before the
 * shortcode executes, so they compute their own data from the queried page.
 *
 * @return array Question nodes (possibly empty).
 * @since 1.1.0
 */
function fsm_faq_get_current_schema_questions() {
	if ( ! is_singular() || ! function_exists( 'fsm_faq_get_faq_data' ) ) {
		return array();
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return array();
	}
	$data = fsm_faq_get_faq_data( $post_id );
	return ! empty( $data['schema_questions'] ) ? $data['schema_questions'] : array();
}

/* -------------------------------------------------------------------------
 * SEO plugin graph adapters (only act when schema_mode === 'seo_plugin')
 * ---------------------------------------------------------------------- */

/**
 * True when FAQ entities should be merged into an SEO plugin graph.
 *
 * @return bool
 * @since 1.1.0
 */
function fsm_faq_should_merge_into_seo_graph() {
	$settings = function_exists( 'fsm_faq_get_settings' ) ? fsm_faq_get_settings() : array();
	return isset( $settings['schema_mode'] ) && 'seo_plugin' === $settings['schema_mode'];
}

/**
 * Yoast SEO: append a FAQPage node to the schema graph.
 *
 * @param array $graph   Yoast schema graph pieces.
 * @param mixed $context Yoast meta tags context (unused; canonical read defensively).
 * @return array Modified graph.
 * @since 1.1.0
 */
add_filter( 'wpseo_schema_graph', 'fsm_faq_yoast_schema_graph', 11, 2 );
function fsm_faq_yoast_schema_graph( $graph, $context = null ) {
	if ( ! is_array( $graph ) || ! fsm_faq_should_merge_into_seo_graph() ) {
		return $graph;
	}
	$questions = fsm_faq_get_current_schema_questions();
	if ( empty( $questions ) ) {
		return $graph;
	}

	$canonical = '';
	if ( is_object( $context ) && isset( $context->canonical ) ) {
		$canonical = $context->canonical;
	}
	if ( ! $canonical ) {
		$canonical = get_permalink( get_queried_object_id() );
	}

	$graph[] = array(
		'@type'      => 'FAQPage',
		'@id'        => $canonical . '#faq',
		'mainEntity' => $questions,
	);
	return $graph;
}

/**
 * Rank Math: append a FAQPage node to the JSON-LD data.
 *
 * @param array $data   Rank Math JSON-LD nodes keyed by identifier.
 * @param mixed $jsonld Rank Math JsonLD instance (unused).
 * @return array Modified data.
 * @since 1.1.0
 */
add_filter( 'rank_math/json_ld', 'fsm_faq_rankmath_json_ld', 99, 2 );
function fsm_faq_rankmath_json_ld( $data, $jsonld = null ) {
	if ( ! is_array( $data ) || ! fsm_faq_should_merge_into_seo_graph() ) {
		return $data;
	}
	$questions = fsm_faq_get_current_schema_questions();
	if ( empty( $questions ) ) {
		return $data;
	}

	$data['fsm_faq_faqpage'] = array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( get_queried_object_id() ) . '#faq',
		'mainEntity' => $questions,
	);
	return $data;
}

/**
 * All in One SEO: append a FAQPage graph node to the schema output.
 *
 * @param array $graphs AIOSEO schema graph array.
 * @return array Modified graphs.
 * @since 1.1.0
 */
add_filter( 'aioseo_schema_output', 'fsm_faq_aioseo_schema_output', 99 );
function fsm_faq_aioseo_schema_output( $graphs ) {
	if ( ! is_array( $graphs ) || ! fsm_faq_should_merge_into_seo_graph() ) {
		return $graphs;
	}
	$questions = fsm_faq_get_current_schema_questions();
	if ( empty( $questions ) ) {
		return $graphs;
	}

	$graphs[] = array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( get_queried_object_id() ) . '#faq',
		'mainEntity' => $questions,
	);
	return $graphs;
}
