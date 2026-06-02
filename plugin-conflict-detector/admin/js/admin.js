/* Plugin Conflict Detector – Admin JS v1.1 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// ── Error log filter ─────────────────────────────────────────────────
		$(document).on('click', '.pcd-filter-btn', function () {
			var filter = $(this).data('filter');

			$('.pcd-filter-btn').removeClass('pcd-filter-btn--active');
			$(this).addClass('pcd-filter-btn--active');

			if (filter === 'all') {
				$('.pcd-error-row').show();
			} else {
				$('.pcd-error-row').each(function () {
					$(this)[ $(this).data('type') === filter ? 'show' : 'hide' ]();
				});
			}
		});

		// ── AJAX health scan ─────────────────────────────────────────────────
		$('#pcd-run-scan').on('click', function () {
			var $btn    = $(this);
			var $status = $('#pcd-scan-status');
			var $results = $('#pcd-scan-results');
			var $meta    = $('#pcd-scan-meta');

			$btn.prop('disabled', true).text(pcdData.scanning);
			$status.html('<div class="pcd-notice pcd-notice--info">' + pcdData.scanning + '</div>');

			$.post(
				pcdData.ajaxUrl,
				{ action: 'pcd_run_scan', nonce: pcdData.nonce },
				function (response) {
					$btn.prop('disabled', false).text(pcdData.done);

					if (response.success) {
						$status.html(
							'<div class="pcd-notice pcd-notice--success">' + pcdData.done + '</div>'
						);
						$results.html(response.data.html);
						if ($meta.length) {
							$meta.html(
								response.data.scanned_at + ' &nbsp;|&nbsp; <strong>' +
								response.data.issues + '</strong> issues found'
							);
						}
					} else {
						$status.html(
							'<div class="pcd-notice pcd-notice--error">Error: ' +
							(response.data && response.data.message ? response.data.message : 'Unknown error') +
							'</div>'
						);
					}

					setTimeout(function () {
						$btn.text('Run Scan Now');
						$status.html('');
					}, 3000);
				}
			).fail(function () {
				$btn.prop('disabled', false).text('Run Scan Now');
				$status.html('<div class="pcd-notice pcd-notice--error">Request failed. Please try again.</div>');
			});
		});

		// ── Clear debug.log ──────────────────────────────────────────────────
		$('#pcd-clear-log').on('click', function () {
			if ( ! window.confirm('Are you sure you want to clear debug.log? This cannot be undone.') ) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(pcdData.clearing);

			$.post(
				pcdData.ajaxUrl,
				{ action: 'pcd_clear_log', nonce: pcdData.nonce },
				function (response) {
					if (response.success) {
						$btn.text(pcdData.cleared);
						// Reload page after short delay to reflect empty log
						setTimeout(function () { window.location.reload(); }, 1200);
					} else {
						$btn.prop('disabled', false).text('Clear debug.log');
						alert(response.data && response.data.message ? response.data.message : 'Could not clear log.');
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text('Clear debug.log');
			});
		});

	});

}(jQuery));
