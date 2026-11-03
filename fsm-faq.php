<?php
/**
 * Plugin Name: FSM FAQ
 * Description: Custom FAQ post type with ACF fields, page assignment, and [fsm_display_faqs] shortcode. Requires Advanced Custom Fields Pro.
 * Version: 1.1.10
 * Author: Full Spectrum Marketing
 * Author URI: https://fsm.agency
 * Text Domain: fsm-faq
 * Requires at least: 5.9
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSM_FAQ_VERSION', '1.1.10' );
define( 'FSM_FAQ_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Updates via FSM Cloudflare broker (Plugin Update Checker).
 * PUC must be present in vendor/plugin-update-checker/ or plugin-update-checker-5.6/.
 * Override with FSM_FAQ_UPDATE_URL, or agency GitHub override (see GITHUB_UPDATES.md).
 */
$puc_loader = FSM_FAQ_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( ! file_exists( $puc_loader ) ) {
	$puc_loader = FSM_FAQ_PATH . 'plugin-update-checker-5.6/plugin-update-checker.php';
}
if ( file_exists( $puc_loader ) ) {
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-updater.php';
}

/**
 * Require ACF for FAQ fields (display_on_pages, faq_answer) and shortcode.
 */
add_action( 'plugins_loaded', 'fsm_faq_bootstrap', 5 );
function fsm_faq_bootstrap() {
	if ( ! function_exists( 'get_field' ) ) {
		add_action( 'admin_notices', 'fsm_faq_acf_required_notice' );
		return;
	}
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-cpt.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-acf-fields.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-admin.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-settings.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-schema.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-shortcode.php';
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-order.php';
}

function fsm_faq_acf_required_notice() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'plugins' ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'FSM FAQ requires Advanced Custom Fields Pro. Install and activate ACF Pro to use FAQ fields and the shortcode.', 'fsm-faq' );
	echo '</p></div>';
}

/**
 * Grant FAQ capabilities on activation so Editors/Admins can manage FAQs
 * immediately, even before the first admin_init after ACF is available.
 *
 * @since 1.1.3
 */
register_activation_hook( __FILE__, 'fsm_faq_activate' );
function fsm_faq_activate() {
	require_once FSM_FAQ_PATH . 'includes/class-fsm-faq-cpt.php';
	fsm_faq_grant_capabilities();
}
