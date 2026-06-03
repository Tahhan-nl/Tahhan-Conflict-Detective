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
							// Use text nodes to avoid XSS — only wrap static markup around them.
							$meta.empty()
								.append( document.createTextNode( response.data.scanned_at + '  |  ' ) )
								.append( $('<strong>').text( response.data.issues ) )
								.append( document.createTextNode( ' ' + pcdData.issuesFound ) );
						}
					} else {
						var errMsg = (response.data && response.data.message) ? response.data.message : pcdData.unknownError;
						$status.html(
							'<div class="pcd-notice pcd-notice--error">Error: ' +
							$('<div>').text( errMsg ).html() +
							'</div>'
						);
					}

					setTimeout(function () {
						$btn.text(pcdData.runScan);
						$status.html('');
					}, 3000);
				}
			).fail(function () {
				$btn.prop('disabled', false).text(pcdData.runScan);
				$status.html('<div class="pcd-notice pcd-notice--error">' + pcdData.requestFailed + '</div>');
			});
		});

		// ── Safe Mode — toggle on/off ─────────────────────────────────────────
		$('#pcd-toggle-safe-mode').on('click', function () {
			var $btn   = $(this);
			var $panel = $btn.closest('.pcd-safe-mode-panel');
			var $body  = $('#pcd-safe-mode-body');

			$btn.prop('disabled', true);

			$.post(
				pcdData.ajaxUrl,
				{ action: 'pcd_safe_mode_toggle', nonce: pcdData.nonce },
				function (response) {
					$btn.prop('disabled', false);
					if ( ! response.success ) { return; }

					var active = response.data.active;
					$panel.toggleClass('pcd-safe-mode-panel--active', active);
					$body[ active ? 'slideDown' : 'slideUp' ]( 200 );
					$btn.text( active ? pcdData.stopSafeMode : pcdData.startSafeMode );
					$btn.toggleClass('button-primary', !active).toggleClass('button-secondary', active);
				}
			);
		});

		// ── Safe Mode — toggle individual plugin ──────────────────────────────
		$(document).on('change', '.pcd-plugin-toggle-input', function () {
			var $input  = $(this);
			var plugin  = $input.data('plugin');
			var $item   = $input.closest('.pcd-plugin-toggle-item');
			var $label  = $item.find('.pcd-toggle-label');

			$input.prop('disabled', true);

			$.post(
				pcdData.ajaxUrl,
				{ action: 'pcd_safe_mode_toggle_plugin', nonce: pcdData.nonce, plugin: plugin },
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
			if ( ! window.confirm( pcdData.confirmClear ) ) {
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
						$btn.prop('disabled', false).text(pcdData.clearLog);
						alert(response.data && response.data.message ? response.data.message : pcdData.couldNotClear);
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text(pcdData.clearLog);
			});
		});

	});

}(jQuery));
