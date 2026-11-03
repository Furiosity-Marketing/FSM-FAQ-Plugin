<?php
/**
 * Furiosity FAQ: Settings page (brand colors, toggle icons, accordion behavior, schema mode)
 * and the front-end styling engine that turns saved options into scoped CSS.
 *
 * Settings are stored in a single option array (fsm_faq_settings) and applied to both
 * the Divi toggle markup and the generic accordion fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option key holding all FAQ display settings. */
define( 'FSM_FAQ_SETTINGS_OPTION', 'fsm_faq_settings' );

/**
 * Default settings. The default icon library depends on whether Divi is active
 * (ET Modules on Divi sites; bundled SVG elsewhere).
 *
 * @return array<string,mixed> Default settings.
 * @since 1.1.0
 */
function fsm_faq_get_default_settings() {
	$is_divi = function_exists( 'fsm_faq_is_divi_active' ) ? fsm_faq_is_divi_active() : false;

	return array(
		'toggle_bg'       => '#f4f4f4',
		'toggle_bg_hover' => '#e8e8e8',
		'toggle_bg_open'  => '#e0e0e0',
		'toggle_text'     => '#333333',
		'icon_color'      => '#666666',
		'border_color'    => '#cccccc',
		'border_width'    => 0,
		'icon_library'    => $is_divi ? 'et_modules' : 'svg',
		'icon_pair'       => 'plus_minus',
		'icon_size'       => 16,
		'first_open'      => '1',
		'allow_close'     => '1',
		'border_radius'   => 0,
		'item_spacing'    => 0,
		'schema_mode'     => 'shortcode',
	);
}

/**
 * Map a stored icon library to one that does not load a third-party CDN.
 *
 * Legacy "fontawesome" values become ET Modules on Divi and bundled SVG elsewhere.
 *
 * @param string $library Saved or submitted library key.
 * @return string 'et_modules' or 'svg'.
 * @since 1.1.3
 */
function fsm_faq_normalize_icon_library( $library ) {
	if ( 'et_modules' === $library || 'svg' === $library ) {
		return $library;
	}

	$is_divi = function_exists( 'fsm_faq_is_divi_active' ) ? fsm_faq_is_divi_active() : false;
	return $is_divi ? 'et_modules' : 'svg';
}

/**
 * Get merged settings (saved values over defaults).
 *
 * @return array<string,mixed> Effective settings.
 * @since 1.1.0
 */
function fsm_faq_get_settings() {
	$defaults = fsm_faq_get_default_settings();
	$saved    = get_option( FSM_FAQ_SETTINGS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings                 = wp_parse_args( $saved, $defaults );
	$settings['icon_library'] = fsm_faq_normalize_icon_library( $settings['icon_library'] );
	return $settings;
}

/**
 * Short hash of current settings, used to bust the FAQ HTML cache when styling changes.
 *
 * @return string 8-char hash.
 * @since 1.1.0
 */
function fsm_faq_settings_hash() {
	return substr( md5( maybe_serialize( fsm_faq_get_settings() ) ), 0, 8 );
}

/**
 * Allowed values for select-type settings. Keys are option keys.
 *
 * @return array<string,string[]>
 * @since 1.1.0
 */
function fsm_faq_settings_allowed_values() {
	return array(
		'icon_library' => array( 'et_modules', 'svg' ),
		'icon_pair'    => array( 'plus_minus', 'chevron', 'caret', 'angle', 'none' ),
		'schema_mode'  => array( 'shortcode', 'seo_plugin', 'off' ),
	);
}

/* -------------------------------------------------------------------------
 * Admin: menu, registration, sanitize, and page render
 * ---------------------------------------------------------------------- */

/**
 * Register the FAQs -> Settings submenu page.
 *
 * @since 1.1.0
 */
add_action( 'admin_menu', 'fsm_faq_register_settings_page' );
function fsm_faq_register_settings_page() {
	$hook = add_submenu_page(
		'edit.php?post_type=faq',
		__( 'FAQ Settings', 'fsm-faq' ),
		__( 'Settings', 'fsm-faq' ),
		'manage_options',
		'fsm-faq-settings',
		'fsm_faq_render_settings_page'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'fsm_faq_settings_page_loaded' );
	}
}

/**
 * Flag used by the asset enqueue to know we are on the settings screen.
 *
 * @since 1.1.0
 */
function fsm_faq_settings_page_loaded() {
	add_action( 'admin_enqueue_scripts', 'fsm_faq_enqueue_settings_assets' );
}

/**
 * Enqueue the color picker and settings admin script on the settings screen.
 *
 * @since 1.1.0
 */
function fsm_faq_enqueue_settings_assets() {
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script(
		'fsm-faq-admin-settings',
		plugin_dir_url( dirname( __FILE__ ) ) . 'assets/fsm-faq-admin-settings.js',
		array( 'wp-color-picker', 'jquery' ),
		FSM_FAQ_VERSION,
		true
	);
}

/**
 * Register the setting and sanitize callback.
 *
 * @since 1.1.0
 */
add_action( 'admin_init', 'fsm_faq_register_settings' );
function fsm_faq_register_settings() {
	register_setting(
		'fsm_faq_settings_group',
		FSM_FAQ_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'fsm_faq_sanitize_settings',
			'default'           => fsm_faq_get_default_settings(),
		)
	);
}

/**
 * Sanitize all settings on save.
 *
 * @param array $input Raw submitted values.
 * @return array Clean settings.
 * @since 1.1.0
 */
function fsm_faq_sanitize_settings( $input ) {
	$defaults = fsm_faq_get_default_settings();
	$allowed  = fsm_faq_settings_allowed_values();
	$input    = is_array( $input ) ? $input : array();
	$clean    = array();

	$color_keys = array( 'toggle_bg', 'toggle_bg_hover', 'toggle_bg_open', 'toggle_text', 'icon_color', 'border_color' );
	foreach ( $color_keys as $key ) {
		$value        = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
		$clean[ $key ] = $value ? $value : $defaults[ $key ];
	}

	$clean['icon_library'] = fsm_faq_normalize_icon_library(
		( isset( $input['icon_library'] ) && in_array( $input['icon_library'], $allowed['icon_library'], true ) )
			? $input['icon_library']
			: $defaults['icon_library']
	);

	$clean['icon_pair'] = ( isset( $input['icon_pair'] ) && in_array( $input['icon_pair'], $allowed['icon_pair'], true ) )
		? $input['icon_pair']
		: $defaults['icon_pair'];

	$clean['schema_mode'] = ( isset( $input['schema_mode'] ) && in_array( $input['schema_mode'], $allowed['schema_mode'], true ) )
		? $input['schema_mode']
		: $defaults['schema_mode'];

	$clean['first_open']  = empty( $input['first_open'] ) ? '0' : '1';
	$clean['allow_close'] = empty( $input['allow_close'] ) ? '0' : '1';

	$clean['border_radius'] = isset( $input['border_radius'] ) ? min( 100, absint( $input['border_radius'] ) ) : $defaults['border_radius'];
	$clean['item_spacing']  = isset( $input['item_spacing'] ) ? min( 100, absint( $input['item_spacing'] ) ) : $defaults['item_spacing'];
	$clean['border_width']  = isset( $input['border_width'] ) ? min( 20, absint( $input['border_width'] ) ) : $defaults['border_width'];
	$clean['icon_size']     = isset( $input['icon_size'] ) ? max( 8, min( 64, absint( $input['icon_size'] ) ) ) : $defaults['icon_size'];

	return $clean;
}

/**
 * Whether “allow close” is enabled in settings.
 *
 * Strict string compare — do not use empty(), which treats the saved value
 * `'0'` as empty and would mis-detect the setting.
 *
 * @param array|null $settings Optional settings; defaults to fsm_faq_get_settings().
 * @return bool
 * @since 1.1.8
 */
function fsm_faq_is_allow_close_enabled( $settings = null ) {
	if ( null === $settings ) {
		$settings = fsm_faq_get_settings();
	}
	return isset( $settings['allow_close'] ) && '1' === (string) $settings['allow_close'];
}

/**
 * Whether Divi FAQ markup should show the close affordance and allow close-on-click.
 *
 * True when the settings checkbox is on, OR when Foundation’s WCAG kit has
 * enqueued `fsm-divi-accordion-close` (that script closes Divi accordions
 * globally, so hiding the FAQ close icon would mismatch real behavior).
 *
 * @param array|null $settings Optional settings; defaults to fsm_faq_get_settings().
 * @return bool
 * @since 1.1.8
 */
function fsm_faq_is_divi_faq_close_afforded( $settings = null ) {
	if ( fsm_faq_is_allow_close_enabled( $settings ) ) {
		return true;
	}
	return fsm_faq_divi_close_script_already_loaded();
}

/**
 * Bust the FAQ HTML cache whenever settings are saved.
 *
 * @since 1.1.0
 */
add_action( 'update_option_' . FSM_FAQ_SETTINGS_OPTION, 'fsm_faq_settings_updated' );
add_action( 'add_option_' . FSM_FAQ_SETTINGS_OPTION, 'fsm_faq_settings_updated' );
function fsm_faq_settings_updated() {
	if ( function_exists( 'fsm_faq_bump_cache' ) ) {
		fsm_faq_bump_cache();
	}
}

/**
 * Render the settings page.
 *
 * @since 1.1.0
 */
function fsm_faq_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s          = fsm_faq_get_settings();
	$detected   = function_exists( 'fsm_faq_detect_seo_plugin' ) ? fsm_faq_detect_seo_plugin() : '';
	$icon_pairs = array(
		'plus_minus' => __( 'Plus / Minus', 'fsm-faq' ),
		'chevron'    => __( 'Chevron', 'fsm-faq' ),
		'caret'      => __( 'Caret', 'fsm-faq' ),
		'angle'      => __( 'Angle', 'fsm-faq' ),
		'none'       => __( 'No icon', 'fsm-faq' ),
	);
	$icon_libs  = array(
		'et_modules' => __( 'ET Modules (Divi built-in font)', 'fsm-faq' ),
		'svg'        => __( 'SVG (bundled icons)', 'fsm-faq' ),
	);
	$schema_modes = array(
		'shortcode'  => __( 'Output from this plugin (recommended)', 'fsm-faq' ),
		'seo_plugin' => __( 'Merge into active SEO plugin schema graph', 'fsm-faq' ),
		'off'        => __( 'Do not output FAQ schema', 'fsm-faq' ),
	);
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'FAQ Settings', 'fsm-faq' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'fsm_faq_settings_group' ); ?>

			<h2 class="title"><?php echo esc_html__( 'Brand Colors', 'fsm-faq' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Applied to both the Divi toggle markup and the generic accordion. The question and its answer share one background so each toggle reads as a single connected unit.', 'fsm-faq' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$color_fields = array(
					'toggle_bg'       => __( 'Toggle background (closed)', 'fsm-faq' ),
					'toggle_bg_hover' => __( 'Toggle background (hover)', 'fsm-faq' ),
					'toggle_bg_open'  => __( 'Toggle background (open)', 'fsm-faq' ),
					'toggle_text'     => __( 'Question text', 'fsm-faq' ),
					'icon_color'      => __( 'Toggle icon color', 'fsm-faq' ),
				);
				foreach ( $color_fields as $key => $label ) :
					?>
					<tr>
						<th scope="row"><label for="fsm-faq-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input
								type="text"
								class="fsm-faq-color-field"
								id="fsm-faq-<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION . '[' . $key . ']' ); ?>"
								value="<?php echo esc_attr( $s[ $key ] ); ?>"
								data-default-color="<?php echo esc_attr( fsm_faq_get_default_settings()[ $key ] ); ?>"
							/>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2 class="title"><?php echo esc_html__( 'Toggle Icon', 'fsm-faq' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fsm-faq-icon_library"><?php echo esc_html__( 'Icon library', 'fsm-faq' ); ?></label></th>
					<td>
						<select id="fsm-faq-icon_library" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[icon_library]">
							<?php foreach ( $icon_libs as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['icon_library'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'ET Modules uses the icon font shipped with Divi. SVG uses lightweight icons bundled with this plugin.', 'fsm-faq' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fsm-faq-icon_pair"><?php echo esc_html__( 'Icon style', 'fsm-faq' ); ?></label></th>
					<td>
						<select id="fsm-faq-icon_pair" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[icon_pair]">
							<?php foreach ( $icon_pairs as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['icon_pair'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'The exact glyph depends on the selected icon library.', 'fsm-faq' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fsm-faq-icon_size"><?php echo esc_html__( 'Icon size (px)', 'fsm-faq' ); ?></label></th>
					<td>
						<input type="number" min="8" max="64" step="1" id="fsm-faq-icon_size" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[icon_size]" value="<?php echo esc_attr( $s['icon_size'] ); ?>" class="small-text" />
						<p class="description"><?php echo esc_html__( 'Size of the open/close toggle icon. Default is 16.', 'fsm-faq' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php echo esc_html__( 'Border &amp; Shape', 'fsm-faq' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'The border wraps the entire toggle, enclosing the question and its answer together.', 'fsm-faq' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fsm-faq-border_width"><?php echo esc_html__( 'Border thickness (px)', 'fsm-faq' ); ?></label></th>
					<td>
						<input type="number" min="0" max="20" step="1" id="fsm-faq-border_width" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[border_width]" value="<?php echo esc_attr( $s['border_width'] ); ?>" class="small-text" />
						<p class="description"><?php echo esc_html__( 'Set to 0 for no border. When 0, any border from your theme is left untouched.', 'fsm-faq' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fsm-faq-border_color"><?php echo esc_html__( 'Border color', 'fsm-faq' ); ?></label></th>
					<td>
						<input
							type="text"
							class="fsm-faq-color-field"
							id="fsm-faq-border_color"
							name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION . '[border_color]' ); ?>"
							value="<?php echo esc_attr( $s['border_color'] ); ?>"
							data-default-color="<?php echo esc_attr( fsm_faq_get_default_settings()['border_color'] ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fsm-faq-border_radius"><?php echo esc_html__( 'Corner radius (px)', 'fsm-faq' ); ?></label></th>
					<td><input type="number" min="0" max="100" step="1" id="fsm-faq-border_radius" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[border_radius]" value="<?php echo esc_attr( $s['border_radius'] ); ?>" class="small-text" /></td>
				</tr>
			</table>

			<h2 class="title"><?php echo esc_html__( 'Layout &amp; Behavior', 'fsm-faq' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'First item', 'fsm-faq' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[first_open]" value="1" <?php checked( $s['first_open'], '1' ); ?> />
							<?php echo esc_html__( 'Open the first FAQ by default', 'fsm-faq' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Closing', 'fsm-faq' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[allow_close]" value="1" <?php checked( $s['allow_close'], '1' ); ?> />
							<?php echo esc_html__( 'Allow an open FAQ to be closed by clicking it again', 'fsm-faq' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Only one FAQ is open at a time either way. When checked, the open-state (close) icon is shown so visitors can tell the item can be closed. Unchecked hides that icon and blocks close-on-click on [fsm_display_faqs] — unless the Foundation WCAG kit’s divi-accordion-close script is enqueued, which closes Divi accordions globally; in that case the close icon stays visible so it matches real behavior.', 'fsm-faq' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fsm-faq-item_spacing"><?php echo esc_html__( 'Spacing between items (px)', 'fsm-faq' ); ?></label></th>
					<td><input type="number" min="0" max="100" step="1" id="fsm-faq-item_spacing" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[item_spacing]" value="<?php echo esc_attr( $s['item_spacing'] ); ?>" class="small-text" /></td>
				</tr>
			</table>

			<h2 class="title"><?php echo esc_html__( 'Structured Data (Schema)', 'fsm-faq' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fsm-faq-schema_mode"><?php echo esc_html__( 'FAQ schema output', 'fsm-faq' ); ?></label></th>
					<td>
						<select id="fsm-faq-schema_mode" name="<?php echo esc_attr( FSM_FAQ_SETTINGS_OPTION ); ?>[schema_mode]">
							<?php foreach ( $schema_modes as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['schema_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php echo esc_html__( 'Output only one FAQ schema per page to avoid duplicate-schema warnings in Google Search Console.', 'fsm-faq' ); ?>
							<?php if ( 'seo_plugin' === $s['schema_mode'] ) : ?>
								<br />
								<?php if ( $detected ) : ?>
									<strong><?php echo esc_html( sprintf( /* translators: %s: SEO plugin name. */ __( 'Detected SEO plugin: %s. FAQ schema will be merged into its graph.', 'fsm-faq' ), fsm_faq_seo_plugin_label( $detected ) ) ); ?></strong>
								<?php else : ?>
									<strong><?php echo esc_html__( 'No supported SEO plugin detected. Falling back to plugin-generated schema.', 'fsm-faq' ); ?></strong>
								<?php endif; ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Human-readable label for a detected SEO plugin slug.
 *
 * @param string $slug Detected plugin slug.
 * @return string Label.
 * @since 1.1.0
 */
function fsm_faq_seo_plugin_label( $slug ) {
	$labels = array(
		'yoast'    => 'Yoast SEO',
		'rankmath' => 'Rank Math',
		'aioseo'   => 'All in One SEO',
	);
	return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
}

/* -------------------------------------------------------------------------
 * Icon definitions and SVG loading
 * ---------------------------------------------------------------------- */

/**
 * Map of icon library -> pair -> closed/open glyph data.
 *
 * ET Modules values are font glyph codepoints; SVG values are bundled file
 * bundled file basenames under assets/icons/.
 *
 * Directional pairs (chevron/caret/angle) animate via rotate using the closed
 * glyph only; the open glyph remains for Divi/legacy swap fallbacks if needed.
 *
 * @return array<string,array<string,array<string,string>>>
 * @since 1.1.0
 */
function fsm_faq_icon_definitions() {
	return array(
		'et_modules'  => array(
			'plus_minus' => array( 'closed' => '\e050', 'open' => '\e04f' ),
			'chevron'    => array( 'closed' => '\33', 'open' => '\32' ),
			'caret'      => array( 'closed' => '\e044', 'open' => '\e043' ),
			'angle'      => array( 'closed' => '\37', 'open' => '\36' ),
		),
		'svg'         => array(
			'plus_minus' => array( 'closed' => 'plus', 'open' => 'minus' ),
			'chevron'    => array( 'closed' => 'chevron-down', 'open' => 'chevron-up' ),
			'caret'      => array( 'closed' => 'caret-down', 'open' => 'caret-up' ),
			'angle'      => array( 'closed' => 'chevrons-down', 'open' => 'chevrons-up' ),
		),
	);
}

/**
 * Motion strategy for an icon pair.
 *
 * - rotate: single closed glyph, 180° on open (chevron/caret/angle)
 * - morph:  two-bar CSS plus→minus (generic accordion only; Divi uses swap)
 * - swap:   instant closed/open glyph change (Divi plus/minus)
 * - none:   no icon
 *
 * @param string $pair Icon pair key.
 * @return string One of rotate|morph|swap|none.
 * @since 1.1.8
 */
function fsm_faq_icon_motion( $pair ) {
	switch ( $pair ) {
		case 'chevron':
		case 'caret':
		case 'angle':
			return 'rotate';
		case 'plus_minus':
			return 'morph';
		case 'none':
			return 'none';
		default:
			return 'swap';
	}
}

/**
 * Read a bundled SVG icon and return a base64 data URI suitable for CSS mask-image.
 *
 * @param string $name Icon basename (without extension).
 * @return string Data URI, or empty string when the file is missing.
 * @since 1.1.0
 */
function fsm_faq_svg_data_uri( $name ) {
	static $cache = array();
	$name = preg_replace( '/[^a-z0-9\-]/', '', (string) $name );
	if ( '' === $name ) {
		return '';
	}
	if ( isset( $cache[ $name ] ) ) {
		return $cache[ $name ];
	}
	$path = FSM_FAQ_PATH . 'assets/icons/' . $name . '.svg';
	if ( ! is_readable( $path ) ) {
		$cache[ $name ] = '';
		return '';
	}
	$svg            = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading bundled plugin asset.
	$cache[ $name ] = 'data:image/svg+xml;base64,' . base64_encode( $svg );
	return $cache[ $name ];
}

/* -------------------------------------------------------------------------
 * Front-end asset enqueue and dynamic CSS
 * ---------------------------------------------------------------------- */

/**
 * Enqueue front-end assets (dynamic CSS, and for the generic path the accordion
 * CSS/JS) for the given render context. Runs on every shortcode call so styling
 * loads even when the FAQ HTML is served from cache.
 *
 * @param string $context Either 'divi' or 'generic'.
 * @since 1.1.0
 */
function fsm_faq_enqueue_frontend_assets( $context ) {
	$settings = fsm_faq_get_settings();
	$base_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';

	if ( 'generic' === $context ) {
		wp_enqueue_style( 'fsm-faq-accordion', $base_url . 'fsm-faq-accordion.css', array(), FSM_FAQ_VERSION );
		wp_enqueue_script( 'fsm-faq-accordion', $base_url . 'fsm-faq-accordion.js', array(), FSM_FAQ_VERSION, true );
		$css = fsm_faq_build_dynamic_css( 'generic', $settings );
		if ( $css ) {
			wp_add_inline_style( 'fsm-faq-accordion', $css );
		}
		return;
	}

	// Always load the FAQ-scoped close handler for Divi markup. It respects
	// data-allow-close and uses capture-phase stopImmediatePropagation so a
	// Foundation WCAG kit (or an empty/commented kit file that is still enqueued)
	// cannot double-fire or override the settings checkbox on .fsm-faq-divi.
	wp_enqueue_script(
		'fsm-faq-divi-toggle',
		$base_url . 'fsm-faq-divi-toggle.js',
		array( 'jquery' ),
		FSM_FAQ_VERSION,
		true
	);

	// Divi context: inline-only style handle.
	$handle = 'fsm-faq-divi-inline';
	if ( ! wp_style_is( $handle, 'registered' ) ) {
		wp_register_style( $handle, false, array(), FSM_FAQ_VERSION );
	}
	wp_enqueue_style( $handle );
	$css = fsm_faq_build_dynamic_css( 'divi', $settings );
	if ( $css ) {
		wp_add_inline_style( $handle, $css );
	}
}

/**
 * True when the Foundation WCAG kit has enqueued its Divi accordion close script.
 *
 * Kept for diagnostics/back-compat. FAQ close is no longer skipped when this is
 * true — `fsm-faq-divi-toggle.js` always owns `.fsm-faq-divi` via capture phase
 * so an empty or active kit file cannot override the Allow close setting.
 *
 * @return bool
 * @since 1.1.0
 */
function fsm_faq_divi_close_script_already_loaded() {
	return wp_script_is( 'fsm-divi-accordion-close', 'enqueued' )
		|| wp_script_is( 'fsm-divi-accordion-close', 'done' );
}

/**
 * Build the full dynamic CSS block for a render context.
 *
 * @param string $context 'divi' or 'generic'.
 * @param array  $s       Settings.
 * @return string CSS (unescaped; contains only sanitized values).
 * @since 1.1.0
 */
function fsm_faq_build_dynamic_css( $context, $s ) {
	$css = '';
	if ( 'generic' === $context ) {
		$css .= fsm_faq_build_generic_color_css( $s );
		$css .= fsm_faq_build_generic_icon_css( $s );
	} else {
		$css .= fsm_faq_build_divi_color_css( $s );
		$css .= fsm_faq_build_divi_icon_css( $s );
	}
	return $css;
}

/**
 * Generic accordion color/layout CSS via custom properties.
 *
 * @param array $s Settings.
 * @return string CSS.
 * @since 1.1.0
 */
function fsm_faq_build_generic_color_css( $s ) {
	$radius  = (int) $s['border_radius'];
	$spacing = (int) $s['item_spacing'];
	$icon_sz = isset( $s['icon_size'] ) ? max( 8, min( 64, (int) $s['icon_size'] ) ) : 16;

	$vars = sprintf(
		'.fsm-faq-accordion{--fsm-faq-toggle-bg:%1$s;--fsm-faq-toggle-bg-hover:%2$s;--fsm-faq-toggle-bg-open:%3$s;--fsm-faq-toggle-text:%4$s;--fsm-faq-icon-color:%5$s;--fsm-faq-icon-size:%6$dpx;--fsm-faq-radius:%7$dpx;--fsm-faq-border-width:%8$dpx;--fsm-faq-border-color:%9$s;}',
		fsm_faq_css_hex( $s['toggle_bg'] ),
		fsm_faq_css_hex( $s['toggle_bg_hover'] ),
		fsm_faq_css_hex( $s['toggle_bg_open'] ),
		fsm_faq_css_hex( $s['toggle_text'] ),
		fsm_faq_css_hex( $s['icon_color'] ),
		$icon_sz,
		$radius,
		(int) $s['border_width'],
		fsm_faq_css_hex( $s['border_color'] )
	);

	$css = $vars;
	if ( $spacing > 0 ) {
		$css .= sprintf( '.fsm-faq-accordion .fsm-faq-accordion__item{margin-bottom:%dpx;}', $spacing );
	}
	return $css;
}

/**
 * Generic accordion toggle icon CSS.
 *
 * Motion by pair: rotate (chevron/caret/angle), morph (plus/minus two-bar),
 * or none. Open-state icon visibility is gated by [data-allow-close] so it
 * always matches the shortcode attribute / JS behavior.
 *
 * @param array $s Settings.
 * @return string CSS.
 * @since 1.1.0
 */
function fsm_faq_build_generic_icon_css( $s ) {
	$pair   = $s['icon_pair'];
	$motion = fsm_faq_icon_motion( $pair );

	if ( 'none' === $motion ) {
		return '.fsm-faq-accordion .fsm-faq-accordion__btn::before,'
			. '.fsm-faq-accordion .fsm-faq-accordion__btn::after{content:none;}'
			. '.fsm-faq-accordion .fsm-faq-accordion__title{padding-right:0;}'
			. '.fsm-faq-accordion .fsm-faq-accordion__btn{min-height:0;padding-top:1em;padding-bottom:1em;}';
	}

	// Always hide open-state icons when closing is disabled (DOM attribute).
	$hide_open = '.fsm-faq-accordion[data-allow-close="0"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::before,'
		. '.fsm-faq-accordion[data-allow-close="0"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{'
		. 'content:none !important;opacity:0 !important;'
		. '}';

	// Two-bar plus → minus morph (library-agnostic).
	if ( 'morph' === $motion ) {
		$bar = 'max(2px,calc(var(--fsm-faq-icon-size)*0.125))';
		return '.fsm-faq-accordion .fsm-faq-accordion__btn::before,'
			. '.fsm-faq-accordion .fsm-faq-accordion__btn::after{'
			. 'content:"";display:block;position:absolute;top:50%;right:1.25em;'
			. 'padding:0;margin:0;border:none;box-sizing:border-box;'
			. 'background-color:var(--fsm-faq-icon-color);'
			. 'transition:transform 0.25s ease,opacity 0.2s ease,background-color 0.2s ease;'
			. '-webkit-mask:none;mask:none;font-size:0;color:transparent;line-height:0;'
			. '}'
			. '.fsm-faq-accordion .fsm-faq-accordion__btn::before{'
			. 'width:var(--fsm-faq-icon-size);height:' . $bar . ';'
			. 'transform:translateY(-50%);'
			. '}'
			. '.fsm-faq-accordion .fsm-faq-accordion__btn::after{'
			. 'width:' . $bar . ';height:var(--fsm-faq-icon-size);'
			. 'right:calc(1.25em + (var(--fsm-faq-icon-size) - ' . $bar . ')/2);'
			. 'transform:translateY(-50%);'
			. '}'
			. '.fsm-faq-accordion[data-allow-close="1"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{'
			. 'transform:translateY(-50%) scaleY(0);'
			. '}'
			. $hide_open;
	}

	$defs = fsm_faq_icon_definitions();
	$lib  = $s['icon_library'];
	if ( ! isset( $defs[ $lib ][ $pair ] ) ) {
		return '';
	}
	$glyph = $defs[ $lib ][ $pair ];

	// Clear morph bars if switching away from plus/minus.
	$clear_before = '.fsm-faq-accordion .fsm-faq-accordion__btn::before{content:none;}';

	if ( 'svg' === $lib ) {
		$closed = fsm_faq_svg_data_uri( $glyph['closed'] );
		if ( ! $closed ) {
			return '';
		}
		$css = $clear_before . sprintf(
			'.fsm-faq-accordion .fsm-faq-accordion__btn::after{'
			. 'content:"";width:var(--fsm-faq-icon-size);height:var(--fsm-faq-icon-size);'
			. 'font-size:var(--fsm-faq-icon-size);background-color:var(--fsm-faq-icon-color);'
			. '-webkit-mask:url(\'%1$s\') no-repeat center/contain;mask:url(\'%1$s\') no-repeat center/contain;'
			. 'transform:translateY(-50%%) rotate(0deg);'
			. 'transition:transform 0.25s ease,color 0.2s ease,background-color 0.2s ease;'
			. '}',
			$closed
		);

		if ( 'rotate' === $motion ) {
			$css .= '.fsm-faq-accordion[data-allow-close="1"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{'
				. 'transform:translateY(-50%) rotate(180deg);'
				. '}';
			return $css . $hide_open;
		}

		$open = fsm_faq_svg_data_uri( $glyph['open'] );
		if ( $open ) {
			$css .= sprintf(
				'.fsm-faq-accordion[data-allow-close="1"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{'
				. '-webkit-mask:url(\'%1$s\') no-repeat center/contain;mask:url(\'%1$s\') no-repeat center/contain;'
				. '}',
				$open
			);
		}
		return $css . $hide_open;
	}

	// ET Modules font glyphs.
	$css = $clear_before . sprintf(
		'.fsm-faq-accordion .fsm-faq-accordion__btn::after{'
		. 'content:"%1$s";font-family:ETmodules;font-size:var(--fsm-faq-icon-size);'
		. 'color:var(--fsm-faq-icon-color);background:none;-webkit-mask:none;mask:none;'
		. 'transform:translateY(-50%%) rotate(0deg);'
		. 'transition:transform 0.25s ease,color 0.2s ease;'
		. '}',
		$glyph['closed']
	);

	if ( 'rotate' === $motion ) {
		$css .= '.fsm-faq-accordion[data-allow-close="1"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{'
			. 'transform:translateY(-50%) rotate(180deg);'
			. '}';
		return $css . $hide_open;
	}

	$css .= sprintf(
		'.fsm-faq-accordion[data-allow-close="1"] .fsm-faq-accordion__btn.fsm-faq-accordion__btn--active::after{content:"%1$s";}',
		$glyph['open']
	);
	return $css . $hide_open;
}

/**
 * Divi toggle color CSS, scoped to the plugin's .fsm-faq-divi wrapper.
 *
 * @param array $s Settings.
 * @return string CSS.
 * @since 1.1.0
 */
function fsm_faq_build_divi_color_css( $s ) {
	$radius  = (int) $s['border_radius'];
	$spacing = (int) $s['item_spacing'];

	// Background sits on the toggle wrapper so the question and its answer read as one
	// unit; the content area stays transparent so it inherits that background.
	$css  = sprintf( '.fsm-faq-divi .et_pb_toggle{background-color:%s;}', fsm_faq_css_hex( $s['toggle_bg'] ) );
	$css .= sprintf( '.fsm-faq-divi .et_pb_toggle:not(.et_pb_toggle_open):hover{background-color:%s;}', fsm_faq_css_hex( $s['toggle_bg_hover'] ) );
	$css .= sprintf( '.fsm-faq-divi .et_pb_toggle.et_pb_toggle_open{background-color:%s;}', fsm_faq_css_hex( $s['toggle_bg_open'] ) );
	// Closable open items use hover color on hover (matches close affordance / kit).
	$css .= sprintf(
		'.fsm-faq-divi[data-allow-close="1"] .et_pb_toggle.et_pb_toggle_open:hover{background-color:%s;}',
		fsm_faq_css_hex( $s['toggle_bg_hover'] )
	);
	$css .= sprintf( '.fsm-faq-divi .et_pb_toggle_title{color:%s;}', fsm_faq_css_hex( $s['toggle_text'] ) );
	$css .= '.fsm-faq-divi .et_pb_toggle_content{background-color:transparent;}';

	// Border wraps the whole toggle (question + answer). Skipped at 0 so an existing
	// theme border is left alone rather than silently removed.
	$border_width = (int) $s['border_width'];
	if ( $border_width > 0 ) {
		$css .= sprintf( '.fsm-faq-divi .et_pb_toggle{border:%dpx solid %s;}', $border_width, fsm_faq_css_hex( $s['border_color'] ) );
	}

	if ( $radius > 0 ) {
		$css .= sprintf( '.fsm-faq-divi .et_pb_toggle{border-radius:%dpx;overflow:hidden;}', $radius );
	}
	if ( $spacing > 0 ) {
		$css .= sprintf( '.fsm-faq-divi .et_pb_toggle{margin-bottom:%dpx;}', $spacing );
	}
	return $css;
}

/**
 * Divi toggle icon override CSS (targets .et_pb_toggle_title:before).
 *
 * Directional pairs rotate the closed glyph; plus/minus keeps an instant
 * closed/open swap (Divi only exposes one title ::before).
 *
 * Open-state visibility is gated by [data-allow-close] on .fsm-faq-divi so the
 * close affordance only appears when the shortcode marks the accordion closable.
 * The base rule no longer forces display on open items (that overrode Divi’s
 * native hide and kept the icon visible when allow_close was off).
 *
 * @param array $s Settings.
 * @return string CSS.
 * @since 1.1.0
 */
function fsm_faq_build_divi_icon_css( $s ) {
	$pair   = $s['icon_pair'];
	$motion = fsm_faq_icon_motion( $pair );

	if ( 'none' === $motion ) {
		return '.fsm-faq-divi .et_pb_toggle_title:before{content:none !important;}';
	}

	$defs = fsm_faq_icon_definitions();
	$lib  = $s['icon_library'];
	if ( ! isset( $defs[ $lib ][ $pair ] ) ) {
		return '';
	}
	$glyph      = $defs[ $lib ][ $pair ];
	$color      = fsm_faq_css_hex( $s['icon_color'] );
	$size       = isset( $s['icon_size'] ) ? max( 8, min( 64, (int) $s['icon_size'] ) ) : 16;
	$use_rotate = ( 'rotate' === $motion );

	// Hide open-item icon unless the accordion explicitly allows closing.
	$hide_open = '.fsm-faq-divi[data-allow-close="0"] .et_pb_toggle_open .et_pb_toggle_title:before{'
		. 'display:none !important;'
		. '}';

	if ( 'svg' === $lib ) {
		$closed = fsm_faq_svg_data_uri( $glyph['closed'] );
		if ( ! $closed ) {
			return '';
		}

		// Closed toggles only — do not force display on open (Divi hides those by default).
		$css = sprintf(
			'.fsm-faq-divi .et_pb_toggle_close .et_pb_toggle_title:before,'
			. '.fsm-faq-divi .et_pb_toggle:not(.et_pb_toggle_open) .et_pb_toggle_title:before{'
			. 'content:"" !important;display:inline-block !important;'
			. 'width:%3$dpx;height:%3$dpx;font-size:%3$dpx;background-color:%2$s;'
			. '-webkit-mask:url(\'%1$s\') no-repeat center/contain;mask:url(\'%1$s\') no-repeat center/contain;'
			. 'transform:rotate(0deg);transition:transform 0.25s ease;'
			. '}',
			$closed,
			$color,
			$size
		);

		if ( $use_rotate ) {
			$css .= sprintf(
				'.fsm-faq-divi[data-allow-close="1"] .et_pb_toggle_open .et_pb_toggle_title:before{'
				. 'content:"" !important;display:inline-block !important;'
				. 'width:%2$dpx;height:%2$dpx;font-size:%2$dpx;background-color:%3$s;'
				. '-webkit-mask:url(\'%1$s\') no-repeat center/contain;mask:url(\'%1$s\') no-repeat center/contain;'
				. 'transform:rotate(180deg);transition:transform 0.25s ease;'
				. '}',
				$closed,
				$size,
				$color
			);
			return $css . $hide_open;
		}

		$open = fsm_faq_svg_data_uri( $glyph['open'] );
		if ( $open ) {
			$css .= sprintf(
				'.fsm-faq-divi[data-allow-close="1"] .et_pb_toggle_open .et_pb_toggle_title:before{'
				. 'content:"" !important;display:inline-block !important;'
				. 'width:%3$dpx;height:%3$dpx;font-size:%3$dpx;background-color:%2$s;'
				. '-webkit-mask:url(\'%1$s\') no-repeat center/contain;mask:url(\'%1$s\') no-repeat center/contain;'
				. '}',
				$open,
				$color,
				$size
			);
		}
		return $css . $hide_open;
	}

	// ET Modules.
	$css = sprintf(
		'.fsm-faq-divi .et_pb_toggle_close .et_pb_toggle_title:before,'
		. '.fsm-faq-divi .et_pb_toggle:not(.et_pb_toggle_open) .et_pb_toggle_title:before{'
		. 'content:"%1$s" !important;display:inline-block !important;'
		. 'font-family:ETmodules !important;font-size:%3$dpx !important;color:%2$s !important;'
		. 'transform:rotate(0deg);transition:transform 0.25s ease;'
		. '}',
		$glyph['closed'],
		$color,
		$size
	);

	if ( $use_rotate ) {
		$css .= sprintf(
			'.fsm-faq-divi[data-allow-close="1"] .et_pb_toggle_open .et_pb_toggle_title:before{'
			. 'content:"%1$s" !important;display:inline-block !important;'
			. 'font-family:ETmodules !important;font-size:%3$dpx !important;color:%2$s !important;'
			. 'transform:rotate(180deg);transition:transform 0.25s ease;'
			. '}',
			$glyph['closed'],
			$color,
			$size
		);
		return $css . $hide_open;
	}

	$css .= sprintf(
		'.fsm-faq-divi[data-allow-close="1"] .et_pb_toggle_open .et_pb_toggle_title:before{'
		. 'content:"%1$s" !important;display:inline-block !important;'
		. 'font-family:ETmodules !important;font-size:%3$dpx !important;color:%2$s !important;'
		. '}',
		$glyph['open'],
		$color,
		$size
	);
	return $css . $hide_open;
}

/**
 * Validate a hex color for use in inline CSS; falls back to a safe default.
 *
 * @param string $value Hex color.
 * @return string Safe hex color.
 * @since 1.1.0
 */
function fsm_faq_css_hex( $value ) {
	$clean = sanitize_hex_color( $value );
	return $clean ? $clean : '#000000';
}
