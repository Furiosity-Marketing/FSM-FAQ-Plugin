=== Furiosity FAQ ===

Contributors: furiositymarketing
Requires at least: 5.9
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.1.10
License: GPLv2 or later

Custom FAQ post type with page assignment and [fsm_display_faqs] shortcode. For use with the Foundation theme and ACF Pro.

== Description ==

* Registers the `faq` post type (admin only, no front-end archive).
* Requires Advanced Custom Fields Pro. Registers ACF field groups automatically:
  - **FAQs** (on FAQ post type): Answer (WYSIWYG), Display On (Post Object, multiple pages)
  - **Page FAQs** (on Page): Relationship to FAQs, bidirectional with Display On
* Maintains `_has_faqs` post meta on pages for Divi conditional logic (show/hide FAQ section).
* Shortcode: [fsm_display_faqs] – outputs FAQs for the current page with FAQPage schema.
* Admin list column: "Assigned to Pages" with links.

Use a parent section with class `faq-section` and Divi visibility based on custom field `_has_faqs` so the section is hidden when the page has no FAQs.

== Settings (FAQs -> Settings) ==

A no-code settings page (FAQs -> Settings, requires manage_options) controls display without touching the theme:

* **Brand colors** – Toggle background (closed), toggle background (hover), toggle background (open), question text, and toggle icon color. Applied to both the Divi toggle markup and the generic accordion. The question and its answer share one background so each toggle reads as a single connected unit. When close is afforded (`data-allow-close="1"`), hover also applies to open items.
* **Toggle icon** – Choose an icon library (ET Modules built-in Divi font or bundled SVG), an icon style (Plus/Minus, Chevron, Caret, Angle, or No icon), and icon size in pixels (8–64, default 16). Chevron/caret/angle rotate on open; Plus/Minus morphs on the generic accordion and swaps glyphs on Divi.
* **Border & shape** – Border thickness, border color, and corner radius. The border wraps the entire toggle, enclosing the question and its answer together. A thickness of 0 means no plugin border and leaves any existing theme border untouched.
* **Layout & behavior** – Open the first FAQ by default (Divi + generic), allow an open FAQ to be closed by clicking it again (on by default; also shows the open-state/close icon), and spacing between items. Only one FAQ is open at a time either way.

Note: Divi's accordion always keeps one item open. Closing the currently open item is added by this plugin’s scoped script on `[fsm_display_faqs]` (driven by the Allow close setting / `data-allow-close`). On Foundation sites the WCAG kit may also load `divi-accordion-close.js` globally; when that script handle is enqueued, FAQ close stays afforded (icon + click) even if the checkbox is unchecked, so the UI matches the kit. The plugin’s capture-phase handler still owns `.fsm-faq-divi` so the kit cannot double-fire.
* **Structured data** – See below.

Changing settings automatically invalidates the FAQ output cache.

== FAQ ordering ==

* **Global order** – Drag-and-drop the rows on the All FAQs screen to set display order (stored in menu_order). No extra plugin (e.g. Intuitive/Simple Custom Post Order) is required. Ordering is disabled while searching or filtering the list.
* **Page membership** – The ACF "Page FAQs" relationship (bidirectional with Display On) controls which FAQs appear on a page. Front-end output always sorts those FAQs by the global menu_order from All FAQs drag-and-drop.

== Structured data / SEO plugin schema ==

The "FAQ schema output" setting controls FAQPage JSON-LD:

* **Output from this plugin (default)** – Inline FAQPage JSON-LD after the FAQ markup, matching exactly what is rendered.
* **Merge into active SEO plugin schema graph** – Injects FAQ entities into Yoast SEO, Rank Math, or All in One SEO instead of emitting inline JSON-LD. Falls back to plugin output if no supported SEO plugin is active.
* **Do not output FAQ schema** – For sites that manage FAQ schema elsewhere.

Output only one FAQ schema per page. If a page also uses an SEO plugin's own FAQ block, set this to "Do not output" (or remove the block) to avoid duplicate-schema warnings.

== Installation ==

1. Install and activate Advanced Custom Fields Pro.
2. Upload the plugin folder to wp-content/plugins/ and activate "Furiosity FAQ". Field groups are registered automatically.

== Migration from theme-based FAQ ==

To move existing sites from the old fragmented implementation (FAQ code in theme + native ACF groups) to this plugin:

1. Deploy the Furiosity FAQ plugin and activate it (ACF Pro must be active).
2. Remove FAQ code from the theme: delete faq-post-type.php, remove the FAQ block from core.php (fsm_has_faqs_for_page, fsm_update_faq_status_on_save, admin columns), and remove the [fsm_display_faqs] shortcode from shortcodes.php. Or deploy the updated Foundation theme that already has that code removed.
3. On first load, the plugin runs a one-time migration: it removes any existing "FAQs" and "Page FAQs" field groups from the ACF database (by key) so the plugin's local field groups are the single source of truth. FAQ post content and post meta (faq_answer, display_on_pages) are not touched; only the field group definitions move from DB to plugin code.
4. Clear any object cache (Redis/Memcached) or wait for TTL so [fsm_display_faqs] output is fresh.
5. Verify: edit an FAQ, edit a page, view a page that uses the shortcode.

== Updates (Cloudflare broker) ==

Production installs receive updates from the Furiosity Cloudflare update broker (not GitHub). No wp-config tokens are required for clients.

1. Default metadata URL: https://updates.furiositymarketing.com/fsm-faq.json
2. Optional override: define( 'FSM_FAQ_UPDATE_URL', 'https://updates.furiositymarketing.com/fsm-faq.json' );
3. Agency-only GitHub override (both required): FSM_FAQ_GITHUB_REPO + FSM_FAQ_GITHUB_TOKEN — do not use on client sites.
4. To release: bump Version / Stable tag, publish a GitHub Release; CI syncs the zip to the broker. See GITHUB_UPDATES.md and update-broker/.

Cutover: keep the GitHub repo public until sites are on 1.1.0+, then make the repo private. Details in update-broker/CUTOVER.md.

== Changelog ==

= 1.1.10 =
* Security: The `display_on_pages` fallback query now only returns published FAQs, and FAQ shortcode output is no longer written to the object cache during logged-in or admin-area renders, so private/draft FAQs cannot be cached and served to anonymous visitors.
* Updates: default broker metadata URL is now https://updates.furiositymarketing.com/fsm-faq.json. The old updates.fullspectrummarketing.com hostname keeps working for sites that have not updated yet.
* Rebrand to Furiosity Marketing: plugin name, author and docs. Folder, slug, shortcodes and settings are unchanged, so existing installs keep working.

= 1.1.9 =
* Fix: Re-apply live `data-allow-close` on Divi FAQ cache hits so a settings-only cache cannot keep the attribute at `0` after Foundation’s `fsm-divi-accordion-close` kit is enqueued (which previously hid the close icon and blocked kit close via capture-phase `stopImmediatePropagation`).

= 1.1.8 =
* New: Icon size setting (px) under FAQs → Settings → Toggle Icon, applied to Divi and generic accordion icons.
* Fix: Open-state (close) toggle icon is shown only when “Allow an open FAQ to be closed…” is checked; otherwise it stays hidden like default Divi. Visibility is gated by `data-allow-close` so Divi’s open icon cannot stay forced visible via `display:inline-block !important`.
* Fix: FAQ close JS always loads for Divi markup and respects `data-allow-close` in capture phase, so an enqueued (even empty/commented) Foundation `divi-accordion-close.js` can no longer disable or double-fire close on `[fsm_display_faqs]`.
* Fix: When Foundation’s `fsm-divi-accordion-close` handle is enqueued, Divi FAQ markup keeps `data-allow-close="1"` (close icon visible) even if the settings checkbox is unchecked, matching the kit’s global close behavior.
* Fix: Toggle hover background also applies to open FAQ items when close is afforded (`data-allow-close="1"`), for both Divi and generic markup.
* Fix: Generic accordion title padding and button height scale with icon size so large icons (up to 64px) do not overlap question text or get clipped by `overflow:hidden`.
* New: Animated toggle icons — chevron/caret/angle rotate 180° in place; Plus/Minus morphs via CSS bars on the generic accordion and still swaps glyphs instantly on Divi.

= 1.1.7 =
* Fix: Keep the open-item toggle icon visible when closing is allowed — Divi accordion CSS sets `display:none` on `.et_pb_toggle_open > .et_pb_toggle_title::before`; Settings-driven SVG/ET icons now force `display:inline-block !important` on open state when allow_close is on.

= 1.1.6 =
* Fix: When Page FAQs exceeds the query limit, keep globally earlier FAQs (by `menu_order`) instead of dropping them based on ACF relationship list order.

= 1.1.5 =
* Fix: All FAQs drag-and-drop uses Screen Options `edit_faq_per_page` for pagination offsets so incomplete last pages no longer corrupt global `menu_order`.
* Fix: Front-end shortcode always orders by `menu_order`; Page FAQs is membership only (bidirectional sync no longer blocks global drag order).
* Fix: FAQ answer sanitize runs `wpautop` only when content has no block-level HTML, avoiding empty paragraphs from double-autop on save and display.
* Fix: Allow `div` (and `figure` id/style) in answer kses so non-HTML5 `div.wp-caption` wrappers from Add Media captions are preserved.

= 1.1.4 =
* New: Settings page (FAQs → Settings) for brand colors, toggle icons (ET Modules / Font Awesome / SVG), border thickness/color, first-open and closable-toggle behavior, corner radius, and item spacing.
* New: Native drag-and-drop ordering on the All FAQs screen (`menu_order`); ACF Page FAQs relationship order takes precedence per page when set.
* New: FAQ schema output modes — inline JSON-LD (default), merge into Yoast / Rank Math / All in One SEO, or off.
* Security: Harden FAQ capabilities and content escaping.
* Updates: Production installs use the Furiosity Cloudflare update broker (no per-site GitHub tokens). Agency GitHub override retained when both `FSM_FAQ_GITHUB_REPO` and `FSM_FAQ_GITHUB_TOKEN` are set.

= 1.1.3 =
* Fix: Divi close-on-click matches Foundation’s WCAG kit script and skips loading when that kit is already present.

= 1.1.2 =
* Iterative settings/ordering preview builds (superseded by 1.1.4).

= 1.1.1 =
* Harden release packaging: explicit allowlist so root secret files cannot ship in the public update zip.

= 1.1.0 =
* Bridge release: default updates via Furiosity Cloudflare broker (no per-site GitHub tokens).
* Agency GitHub override retained when both FSM_FAQ_GITHUB_REPO and FSM_FAQ_GITHUB_TOKEN are set.

= 1.0.5 =
* Normalize typographic apostrophes in FAQ question titles (same as answers) so titles display correctly with Divi and other processors.

= 1.0.3 =
* Release 1.0.3.

= 1.0.0 =
* Initial release. CPT, ACF field groups (FAQs + Page FAQs), admin columns, save_post (_has_faqs + cache invalidation), [fsm_display_faqs] shortcode.
