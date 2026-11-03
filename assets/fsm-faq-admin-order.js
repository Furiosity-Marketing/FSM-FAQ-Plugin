/**
 * Furiosity FAQ – Admin list-table drag-and-drop ordering.
 *
 * Makes the All FAQs table rows sortable and persists menu_order via AJAX.
 * perPage comes from Screen Options (localized), not the visible row count,
 * so incomplete last pages do not corrupt global menu_order offsets.
 *
 * @since 1.1.0
 */
(function ($) {
	'use strict';

	function getPaged() {
		var match = window.location.search.match(/[?&]paged=(\d+)/);
		if (match) {
			return parseInt(match[1], 10) || 1;
		}
		return 1;
	}

	$(function () {
		var $list = $('#the-list');
		if (!$list.length || typeof fsmFaqOrder === 'undefined') {
			return;
		}

		var perPage = parseInt(fsmFaqOrder.perPage, 10) || 20;

		$list.sortable({
			items: 'tr',
			axis: 'y',
			cursor: 'move',
			placeholder: 'fsm-faq-sort-placeholder',
			helper: function (e, ui) {
				// Preserve cell widths while dragging.
				ui.children().each(function () {
					$(this).width($(this).width());
				});
				return ui;
			},
			start: function (e, ui) {
				ui.placeholder.height(ui.item.height());
			},
			update: function () {
				var order = [];
				$list.find('tr').each(function () {
					var id = ($(this).attr('id') || '').replace('post-', '');
					if (id) {
						order.push(id);
					}
				});

				if (!order.length) {
					return;
				}

				$list.find('tr').addClass('fsm-faq-saving');

				$.post(fsmFaqOrder.ajaxUrl, {
					action: 'fsm_faq_update_order',
					nonce: fsmFaqOrder.nonce,
					order: order,
					paged: getPaged(),
					perPage: perPage
				}).always(function () {
					$list.find('tr').removeClass('fsm-faq-saving');
				});
			}
		});
	});
})(jQuery);
