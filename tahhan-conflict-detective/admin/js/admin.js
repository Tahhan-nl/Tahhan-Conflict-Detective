/* Conflict Detective – Admin JS v1.2 */
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
			var $btn     = $(this);
			var $status  = $('#pcd-scan-status');
			var $results = $('#pcd-scan-results');
			var $meta    = $('#pcd-scan-meta');

			$btn.prop('disabled', true).text(tahcdData.scanning);
			$status.html('<div class="pcd-notice pcd-notice--info">' + tahcdData.scanning + '</div>');

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_run_scan', nonce: tahcdData.nonce },
				function (response) {
					$btn.prop('disabled', false).text(tahcdData.done);

					if (response.success) {
						$status.html(
							'<div class="pcd-notice pcd-notice--success">' + tahcdData.done + '</div>'
						);
						$results.html(response.data.html);
						if ($meta.length) {
							// Use text nodes to avoid XSS — only wrap static markup around them.
							$meta.empty()
								.append( document.createTextNode( response.data.scanned_at + '  |  ' ) )
								.append( $('<strong>').text( response.data.issues ) )
								.append( document.createTextNode( ' ' + tahcdData.issuesFound ) );
						}
					} else {
						var errMsg = (response.data && response.data.message) ? response.data.message : tahcdData.unknownError;
						$status.html(
							'<div class="pcd-notice pcd-notice--error">Error: ' +
							$('<div>').text( errMsg ).html() +
							'</div>'
						);
					}

					setTimeout(function () {
						$btn.text(tahcdData.runScan);
						$status.html('');
					}, 3000);
				}
			).fail(function () {
				$btn.prop('disabled', false).text(tahcdData.runScan);
				$status.html('<div class="pcd-notice pcd-notice--error">' + tahcdData.requestFailed + '</div>');
			});
		});

		// ── Safe Mode — toggle on/off ─────────────────────────────────────────
		$(document).on('click', '#pcd-toggle-safe-mode', function () {
			var $btn     = $(this);
			var stopping = $btn.hasClass('pcd-btn-stop');
			var origText = $btn.text();

			$btn.prop('disabled', true).text( stopping
				? ( tahcdData.safeModeStop    || 'Stopping…'   )
				: ( tahcdData.safeModeLoading || 'Activating…' )
			);

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_safe_mode_toggle', nonce: tahcdData.nonce }
			)
			.done(function (response) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					var msg = (response && response.data && response.data.message)
						? response.data.message
						: tahcdData.unknownError;
					alert( msg );
					$btn.prop('disabled', false).text(origText);
				}
			})
			.fail(function () {
				alert( tahcdData.requestFailed );
				$btn.prop('disabled', false).text(origText);
			});
		});

		// ── Safe Mode — toggle individual plugin ──────────────────────────────
		$(document).on('change', '.pcd-plugin-toggle-input', function () {
			var $input  = $(this);
			var plugin  = $input.data('plugin');
			var $item   = $input.closest('.pcd-plugin-toggle-item');
			var $label  = $item.find('.pcd-toggle-label');

			$input.prop('disabled', true);

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_safe_mode_toggle_plugin', nonce: tahcdData.nonce, plugin: plugin },
				function (response) {
					$input.prop('disabled', false);
					if ( ! response.success ) {
						$input.prop('checked', !$input.prop('checked'));
						return;
					}
					var disabled = response.data.disabled;
					$item.toggleClass('pcd-plugin-toggle-item--off', disabled);
					$label.html( disabled
						? '<span class="pcd-badge pcd-badge--warning">OFF (test)</span>'
						: '<span class="pcd-badge pcd-badge--ok">ON</span>'
					);
				}
			).fail(function () {
				$input.prop('disabled', false);
				$input.prop('checked', !$input.prop('checked'));
			});
		});

		// ── Clear debug.log ──────────────────────────────────────────────────
		$('#pcd-clear-log').on('click', function () {
			if ( ! window.confirm( tahcdData.confirmClear ) ) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(tahcdData.clearing);

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_clear_log', nonce: tahcdData.nonce },
				function (response) {
					if (response.success) {
						$btn.text(tahcdData.cleared);
						// Reload page after short delay to reflect empty log
						setTimeout(function () { window.location.reload(); }, 1200);
					} else {
						$btn.prop('disabled', false).text(tahcdData.clearLog);
						alert(response.data && response.data.message ? response.data.message : tahcdData.couldNotClear);
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text(tahcdData.clearLog);
			});
		});

	});

}(jQuery));
