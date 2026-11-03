/**
 * Plugins served by the broker. Each slug gets /<slug>.json and /<slug>.zip.
 *
 * `secret` names the Worker secret that authorizes /admin/sync for that slug,
 * so one plugin's CI cannot overwrite another plugin's release.
 * `requires` / `tested` / `requires_php` are fallbacks; sync requests can
 * override them with X-Plugin-Requires / X-Plugin-Tested / X-Plugin-Requires-PHP.
 */
export const PLUGINS = {
	'fsm-faq': {
		name: 'FSM FAQ',
		homepage: 'https://fsm.agency',
		requires: '5.9',
		tested: '6.4',
		requires_php: '8.0',
		description: 'Custom FAQ post type with ACF fields and shortcode.',
		github: 'Furiosity-Marketing/FSM-FAQ-Plugin',
		secret: 'SYNC_SECRET',
	},
	'click-id-utm-capture': {
		name: 'Click ID & UTM Capture',
		homepage: 'https://furiositymarketing.com/',
		requires: '6.0',
		tested: '7.1',
		requires_php: '7.4',
		description: 'Captures ad click IDs and UTM parameters and passes them into Gravity Forms hidden fields.',
		github: 'Furiosity-Marketing/click-id-utm-capture',
		secret: 'SYNC_SECRET_CLICK_ID_UTM_CAPTURE',
	},
};

// /admin/sync without a slug predates multi-plugin support and means FSM FAQ.
export const DEFAULT_SLUG = 'fsm-faq';
