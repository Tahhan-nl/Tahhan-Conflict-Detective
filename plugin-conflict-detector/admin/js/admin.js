/* Plugin Conflict Detector – Admin JS */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// Foutlog filter knoppen
		$('.pcd-filter-btn').on('click', function () {
			var filter = $(this).data('filter');

			$('.pcd-filter-btn').removeClass('pcd-filter-btn--active');
			$(this).addClass('pcd-filter-btn--active');

			if (filter === 'all') {
				$('.pcd-error-row').show();
			} else {
				$('.pcd-error-row').each(function () {
					var type = $(this).data('type') || '';
					if (type === filter) {
						$(this).show();
					} else {
						$(this).hide();
					}
				});
			}
		});

	});

}(jQuery));
