/**
 * Furiosity FAQ – Allow open Divi toggles to be closed (settings-aware).
 *
 * Divi's accordion always keeps one item open. Closing the currently open item is not
 * native Divi behavior; Foundation 5.0 adds it in the child-theme WCAG kit
 * (accessibility/js/divi-accordion-close.js). This scoped handler owns close behavior
 * for [fsm_display_faqs] only, driven by data-allow-close on .fsm-faq-divi.
 *
 * Why capture phase (not a jQuery bubble handler):
 *
 * 1. Divi 5 binds on `body` and calls stopPropagation(), so a document-level bubble
 *    handler never runs — we must bind on the title (or use capture).
 * 2. The Foundation kit may also bind on `.et_pb_toggle_title`. If both that kit and
 *    this script slideToggle the same click, the close reverses. Capture +
 *    stopImmediatePropagation on FAQ titles lets this plugin win for .fsm-faq-divi
 *    without depending on whether the kit handle is enqueued (an empty/commented kit
 *    file still registers as "loaded" in WP).
 * 3. When data-allow-close="0", we still intercept open-item clicks so a global kit
 *    cannot close FAQs against the settings checkbox.
 * 4. Closed-item clicks are left alone so Divi can open/switch items as usual.
 * 5. slideToggle(700) + class swap in the callback matches the kit: Divi's expand
 *    path bails unless the item has `et_pb_toggle_close`.
 *
 * @since 1.1.0
 */
(function () {
	'use strict';

	function closest(el, selector) {
		if (!el) {
			return null;
		}
		if (el.closest) {
			return el.closest(selector);
		}
		// Older browsers: minimal polyfill.
		while (el && el.nodeType === 1) {
			if (el.matches && el.matches(selector)) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	function onTitleClick(e) {
		var title = closest(e.target, '.fsm-faq-divi .et_pb_toggle_title');
		if (!title) {
			return;
		}

		var toggle = closest(title, '.et_pb_toggle');
		var accordion = closest(title, '.fsm-faq-divi');
		if (!toggle || !accordion) {
			return;
		}

		if (toggle.classList.contains('et_pb_accordion_toggling')) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}

		var allowClose = accordion.getAttribute('data-allow-close') !== '0';
		var isOpen = toggle.classList.contains('et_pb_toggle_open');

		// Only intercept clicks on an already-open item. Closed items stay Divi's job.
		if (!isOpen) {
			return;
		}

		// Always stop the Foundation kit (and other bubble handlers) on open FAQ titles.
		e.preventDefault();
		e.stopImmediatePropagation();

		if (!allowClose) {
			return;
		}

		var content = toggle.querySelector('.et_pb_toggle_content');
		if (!content || typeof window.jQuery === 'undefined') {
			// Fallback without jQuery animation.
			content.style.display = 'none';
			toggle.classList.remove('et_pb_toggle_open');
			toggle.classList.add('et_pb_toggle_close');
			return;
		}

		var $ = window.jQuery;
		var $toggle = $(toggle);
		var $accordion = $(accordion);

		$accordion.addClass('et_pb_accordion_toggling');
		$toggle.find('.et_pb_toggle_content').slideToggle(700, function () {
			$toggle.removeClass('et_pb_toggle_open').addClass('et_pb_toggle_close');
		});

		window.setTimeout(function () {
			$accordion.removeClass('et_pb_accordion_toggling');
		}, 750);
	}

	document.addEventListener('click', onTitleClick, true);
})();
