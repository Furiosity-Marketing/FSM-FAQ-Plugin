<?php
/**
 * Furiosity FAQ: plugin updates via Plugin Update Checker (PUC).
 *
 * Production (default): Cloudflare update broker JSON metadata URL.
 * Client sites do not need GitHub tokens.
 *
 * Internal override (agency only): if both FSM_FAQ_GITHUB_REPO and
 * FSM_FAQ_GITHUB_TOKEN are defined, check GitHub directly (private-repo testing).
 * Do not use the GitHub override on client installs.
 *
 * Optional: FSM_FAQ_UPDATE_URL overrides the broker metadata URL.
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$puc_path = FSM_FAQ_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( ! file_exists( $puc_path ) ) {
	$puc_path = FSM_FAQ_PATH . 'plugin-update-checker-5.6/plugin-update-checker.php';
}
if ( ! file_exists( $puc_path ) ) {
	return;
}

require_once $puc_path;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$fsm_faq_use_github = defined( 'FSM_FAQ_GITHUB_REPO' ) && FSM_FAQ_GITHUB_REPO
	&& defined( 'FSM_FAQ_GITHUB_TOKEN' ) && FSM_FAQ_GITHUB_TOKEN;

if ( $fsm_faq_use_github ) {
	// Agency/dev escape hatch: direct GitHub (requires token when repo is private).
	$fsm_faq_update_checker = PucFactory::buildUpdateChecker(
		FSM_FAQ_GITHUB_REPO,
		FSM_FAQ_PATH . 'fsm-faq.php',
		'fsm-faq'
	);
	$fsm_faq_update_checker->setBranch( apply_filters( 'fsm_faq_update_branch', 'main' ) );
	$fsm_faq_update_checker->setAuthentication( FSM_FAQ_GITHUB_TOKEN );
} else {
	// Production: Furiosity Cloudflare broker (no GitHub credentials on the site).
	$fsm_faq_metadata = ( defined( 'FSM_FAQ_UPDATE_URL' ) && FSM_FAQ_UPDATE_URL )
		? FSM_FAQ_UPDATE_URL
		: 'https://updates.furiositymarketing.com/fsm-faq.json';

	$fsm_faq_update_checker = PucFactory::buildUpdateChecker(
		$fsm_faq_metadata,
		FSM_FAQ_PATH . 'fsm-faq.php',
		'fsm-faq'
	);
}
