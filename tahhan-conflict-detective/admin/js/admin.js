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

		// ── Phase 3: Performance Monitor — refresh button ────────────────────
		$(document).on('click', '#tahcd-refresh-perf', function () {
			var $btn  = $(this);
			var $wrap = $('#tahcd-perf-table-wrap');

			$btn.prop('disabled', true).text(tahcdData.refreshing || 'Refreshing…');

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_get_performance', nonce: tahcdData.nonce },
				function (response) {
					$btn.prop('disabled', false).text(tahcdData.refreshData || 'Refresh Data');
					if (response.success && response.data.html) {
						$wrap.html(response.data.html);
					} else {
						var msg = (response.data && response.data.message)
							? response.data.message
							: (tahcdData.perfRefreshError || 'Failed to refresh performance data.');
						$wrap.html('<div class="pcd-notice pcd-notice--error">' + $('<div>').text(msg).html() + '</div>');
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text(tahcdData.refreshData || 'Refresh Data');
				$wrap.html('<div class="pcd-notice pcd-notice--error">' + (tahcdData.requestFailed || 'Request failed.') + '</div>');
			});
		});

		// ── Phase 3: Cron Monitor — manually run a cron event ────────────────
		$(document).on('click', '.tahcd-run-cron-btn', function () {
			var $btn    = $(this);
			var hook    = $btn.data('hook');
			var $result = $('#tahcd-cron-run-result');

			$btn.prop('disabled', true).text(tahcdData.running || 'Running…');

			$.post(
				tahcdData.ajaxUrl,
				{ action: 'tahcd_run_cron_event', nonce: tahcdData.nonce, hook: hook },
				function (response) {
					$btn.prop('disabled', false).text(tahcdData.runNow || 'Run Now');
					if (response.success) {
						var msg = response.data && response.data.message ? response.data.message : 'Done.';
						$result.html(
							'<div class="pcd-notice pcd-notice--success">' + $('<div>').text(msg).html() + '</div>'
						);
					} else {
						var errMsg = (response.data && response.data.message)
							? response.data.message
							: (tahcdData.cronRunError || 'Failed to run cron event.');
						$result.html(
							'<div class="pcd-notice pcd-notice--error">' + $('<div>').text(errMsg).html() + '</div>'
						);
					}
					// Auto-hide the notice after 4 seconds.
					setTimeout(function () { $result.html(''); }, 4000);
				}
			).fail(function () {
				$btn.prop('disabled', false).text(tahcdData.runNow || 'Run Now');
				$result.html('<div class="pcd-notice pcd-notice--error">' + (tahcdData.requestFailed || 'Request failed.') + '</div>');
			});
		});

		// ── Phase 4: Agency tab ──────────────────────────────────────────────
		function agencyNotice(type, msg) {
			var $box = $('#tahcd-agency-notice');
			$box.empty().append(
				$('<div>').addClass('pcd-notice pcd-notice--' + type).text(msg || tahcdData.unknownError)
			);
			$('html, body').animate({ scrollTop: $box.offset().top - 60 }, 150);
		}

		// Posts an Agency action. onSuccess(data) runs on success; failures show a notice.
		// If onSuccess returns false the control stays disabled (e.g. one-time actions).
		function agencyPost(action, payload, $btn, onSuccess) {
			var origText = $btn ? $btn.text() : '';
			var keepDisabled = false;
			if ($btn) {
				$btn.prop('disabled', true);
				if ($btn.is('button')) { $btn.text(tahcdData.working); }
			}

			return $.post(tahcdData.ajaxUrl, $.extend({ action: action, nonce: tahcdData.nonce }, payload))
				.done(function (response) {
					var data = (response && response.data) || {};
					if (response && response.success) {
						keepDisabled = onSuccess(data) === false;
					} else {
						agencyNotice('error', data.message);
					}
				})
				.fail(function () { agencyNotice('error', tahcdData.requestFailed); })
				.always(function () {
					if ($btn) {
						if (! keepDisabled) { $btn.prop('disabled', false); }
						if ($btn.is('button')) { $btn.text(origText); }
					}
				});
		}

		function reloadSoon() { setTimeout(function () { window.location.reload(); }, 900); }

		function confirmed($btn) {
			var msg = $btn.data('confirm');
			return ! msg || window.confirm(msg);
		}

		$('#tahcd-key-generate').on('click', function () {
			var $btn = $(this);
			if (! confirmed($btn)) { return; }
			agencyPost('tahcd_agency_generate_key', { expiry: $('#tahcd-key-expiry').val() }, $btn, function (data) {
				$('#tahcd-key-value').val(data.key);
				$('#tahcd-key-output').prop('hidden', false);
				$('#tahcd-key-revoke, #tahcd-key-expiry').prop('disabled', true);
				agencyNotice('success', data.message);
				// A second click would silently rotate the key the user is copying.
				return false;
			});
		});

		$('#tahcd-key-copy').on('click', function () {
			var $btn   = $(this);
			var $input = $('#tahcd-key-value');
			var report = function (ok) {
				$btn.text(ok ? tahcdData.copied : tahcdData.copyFailed);
				setTimeout(function () { $btn.text(tahcdData.copy); }, 2000);
			};
			var legacy = function () {
				$input.trigger('focus').trigger('select');
				var ok = false;
				try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
				report(ok);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText($input.val()).then(function () { report(true); }, legacy);
			} else {
				legacy();
			}
		});

		$('#tahcd-key-done').on('click', function () {
			$('#tahcd-key-value').val('');
			window.location.reload();
		});

		$('#tahcd-key-revoke').on('click', function () {
			var $btn = $(this);
			if (! confirmed($btn)) { return; }
			agencyPost('tahcd_agency_revoke_key', {}, $btn, function (data) {
				agencyNotice('success', data.message);
				reloadSoon();
			});
		});

		$('#tahcd-agency-add').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			agencyPost('tahcd_agency_add_site', {
				label: $form.find('[name=label]').val(),
				url:   $form.find('[name=url]').val(),
				key:   $form.find('[name=key]').val()
			}, $form.find('button[type=submit]'), function (data) {
				$form.find('[name=key]').val('');
				agencyNotice('success', data.message);
				reloadSoon();
			});
		});

		$(document).on('click', '.tahcd-agency-poll', function () {
			var $btn = $(this);
			agencyPost('tahcd_agency_poll_site', { id: $btn.data('id') }, $btn, function (data) {
				agencyNotice('success', data.message);
				reloadSoon();
			});
		});

		$(document).on('click', '.tahcd-agency-remove', function () {
			var $btn = $(this);
			if (! window.confirm(tahcdData.confirmRemoveSite)) { return; }
			agencyPost('tahcd_agency_remove_site', { id: $btn.data('id') }, $btn, function (data) {
				agencyNotice('success', data.message);
				if (data.empty) {
					reloadSoon();
				} else {
					$btn.closest('tr').remove();
				}
			});
		});

		var $interval = $('#tahcd-agency-interval');
		$interval.data('saved', $interval.val());
		$interval.on('change', function () {
			agencyPost('tahcd_agency_save_settings', { interval: $interval.val() }, $interval, function (data) {
				$interval.data('saved', $interval.val());
				agencyNotice('success', data.message);
			}).fail(function () {
				$interval.val($interval.data('saved'));
			}).done(function (response) {
				if (! response || ! response.success) { $interval.val($interval.data('saved')); }
			});
		});

		$('#tahcd-alerts-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var checked = function (name) { return $form.find('[name=' + name + ']').is(':checked') ? '1' : '0'; };
			agencyPost('tahcd_alerts_save', {
				enabled:        checked('enabled'),
				recipients:     $form.find('[name=recipients]').val(),
				severity:       $form.find('[name=severity]').val(),
				frequency:      $form.find('[name=frequency]').val(),
				hub:            checked('hub'),
				webhook_format: $form.find('[name=webhook_format]').val(),
				webhook_url:    $form.find('[name=webhook_url]').val(),
				webhook_remove: checked('webhook_remove')
			}, $form.find('button[type=submit]'), function (data) {
				agencyNotice('success', data.message);
				// The webhook URL is a secret: never keep it in the page, and
				// reload so the saved hint and "remove" option are shown.
				$form.find('[name=webhook_url]').val('');
				reloadSoon();
			});
		});

		$('#tahcd-alerts-test').on('click', function () {
			agencyPost('tahcd_alerts_test', {}, $(this), function (data) {
				agencyNotice('success', data.message);
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
