/**
 * Furiosity FAQ – Settings screen: initialize WordPress color pickers.
 *
 * @since 1.1.0
 */
(function ($) {
	'use strict';

	$(function () {
		if ($.fn.wpColorPicker) {
			$('.fsm-faq-color-field').wpColorPicker();
		}
	});
})(jQuery);
