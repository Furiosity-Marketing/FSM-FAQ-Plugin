/**
 * Furiosity FAQ – Generic accordion toggle (theme-agnostic fallback).
 * Uses W3Schools-style pattern: button + panel, toggle .active and maxHeight.
 *
 * Only one panel is open at a time. Behavior is controlled per-container via data
 * attributes set by the shortcode:
 * - data-first-open="1|0"   Open the first panel on load.
 * - data-allow-close="1|0"  Allow clicking an open panel to close it.
 *
 * @see https://www.w3schools.com/howto/howto_js_accordion.asp
 * @since 1.1.0
 */

(function () {
	'use strict';

	var CONTAINER_SELECTOR = '.fsm-faq-accordion';
	var BTN_SELECTOR = '.fsm-faq-accordion__btn';
	var PANEL_SELECTOR = '.fsm-faq-accordion__panel';
	var ACTIVE_CLASS = 'fsm-faq-accordion__btn--active';

	function openPanel(btn, panel) {
		btn.classList.add(ACTIVE_CLASS);
		btn.setAttribute('aria-expanded', 'true');
		if (panel && panel.style) {
			panel.style.maxHeight = panel.scrollHeight + 'px';
		}
	}

	function closePanel(btn, panel) {
		btn.classList.remove(ACTIVE_CLASS);
		btn.setAttribute('aria-expanded', 'false');
		if (panel && panel.style) {
			panel.style.maxHeight = null;
		}
	}

	function init() {
		var containers = document.querySelectorAll(CONTAINER_SELECTOR);
		containers.forEach(function (container) {
			var firstOpen = container.getAttribute('data-first-open') !== '0';
			var allowClose = container.getAttribute('data-allow-close') !== '0';
			var buttons = container.querySelectorAll(BTN_SELECTOR);
			var panels = container.querySelectorAll(PANEL_SELECTOR);

			buttons.forEach(function (btn, index) {
				var panel = panels[index];
				var shouldOpen = firstOpen && index === 0;
				if (shouldOpen) {
					openPanel(btn, panel);
				} else {
					btn.setAttribute('aria-expanded', 'false');
				}
				btn.addEventListener('click', function () {
					togglePanel(container, btn, allowClose);
				});
			});
		});
	}

	function togglePanel(container, clickedBtn, allowClose) {
		var isActive = clickedBtn.classList.contains(ACTIVE_CLASS);
		var panels = container.querySelectorAll(PANEL_SELECTOR);
		var buttons = container.querySelectorAll(BTN_SELECTOR);
		var clickedIndex = Array.prototype.indexOf.call(buttons, clickedBtn);

		if (isActive) {
			if (allowClose) {
				closePanel(clickedBtn, panels[clickedIndex]);
			}
			return;
		}

		buttons.forEach(function (btn, i) {
			closePanel(btn, panels[i]);
		});

		openPanel(clickedBtn, panels[clickedIndex]);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
