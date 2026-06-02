<?php
/**
 * Conflict Wizard — step-by-step guided diagnosis.
 *
 * @package PluginConflictDetector
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace PluginConflictDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A four-step wizard that narrows down a conflict based on the symptom
 * the user describes.
 *
 * Step 1 — Choose symptom
 * Step 2 — Automated analysis for that symptom
 * Step 3 — Suggested suspects + recommended actions
 * Step 4 — Outcome (fixed / still broken / mark as resolved)
 *
 * @since 2.0.0
 */
final class Wizard {

	/** Supported symptom slugs and their labels. */
	const SYMPTOMS = array(
		'white-screen'   => 'White screen / blank page',
		'login-issue'    => 'Cannot log in to WordPress',
		'woocommerce'    => 'WooCommerce not working',
		'slow-site'      => 'Site is very slow',
		'admin-broken'   => 'WordPress admin is broken',
		'frontend-error' => 'Errors showing on the front end',
		'other'          => 'Something else',
	);

	// -------------------------------------------------------------------------
	// Render entry point
	// -------------------------------------------------------------------------

	/**
	 * Renders the full wizard UI.
	 * Called by Dashboard::render_wizard().
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step    = isset( $_GET['wizard_step'] ) ? (int) $_GET['wizard_step'] : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$symptom = isset( $_GET['symptom'] ) ? sanitize_key( $_GET['symptom'] ) : '';

		if ( ! array_key_exists( $symptom, self::SYMPTOMS ) ) {
			$symptom = '';
		}

		echo '<div class="pcd-wizard">';

		self::render_progress( $step );

		switch ( $step ) {
			case 2:
				self::render_step2( $symptom );
				break;
			case 3:
				self::render_step3( $symptom );
				break;
			default:
				self::render_step1();
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Step renderers
	// -------------------------------------------------------------------------

	/** Step 1 — Choose symptom */
	private static function render_step1(): void {
		echo '<div class="pcd-wizard__step">';
		echo '<h2 class="pcd-wizard__heading">' . esc_html__( 'What is not working?', 'plugin-conflict-detector' ) . '</h2>';
		echo '<p class="pcd-wizard__sub">' . esc_html__( 'Choose the symptom that best describes the problem. The detector will analyse your site and suggest the most likely cause.', 'plugin-conflict-detector' ) . '</p>';

		echo '<div class="pcd-symptom-grid">';
		$icons = array(
			'white-screen'   => '⬜',
			'login-issue'    => '🔒',
			'woocommerce'    => '🛒',
			'slow-site'      => '🐢',
			'admin-broken'   => '⚙️',
			'frontend-error' => '❌',
			'other'          => '❓',
		);

		foreach ( self::SYMPTOMS as $slug => $label ) {
			$url = add_query_arg( array(
				'page'        => Dashboard::PAGE_SLUG,
				'tab'         => 'wizard',
				'wizard_step' => 2,
				'symptom'     => $slug,
			), admin_url( 'admin.php' ) );

			printf(
				'<a href="%s" class="pcd-symptom-card">
					<span class="pcd-symptom-icon" aria-hidden="true">%s</span>
					<span class="pcd-symptom-label">%s</span>
				</a>',
				esc_url( $url ),
				$icons[ $slug ] ?? '❓',
				esc_html( $label )
			);
		}
		echo '</div></div>';
	}

	/** Step 2 — Analyse and show suspects */
	private static function render_step2( string $symptom ): void {
		echo '<div class="pcd-wizard__step">';
		printf(
			'<h2 class="pcd-wizard__heading">%s: <em>%s</em></h2>',
			esc_html__( 'Analysing', 'plugin-conflict-detector' ),
			esc_html( self::SYMPTOMS[ $symptom ] ?? $symptom )
		);

		$suspects = Conflict_Scanner::analyse( 5 );
		$changes  = Change_History::get_recent( 5 );
		$errors   = Error_Log::get_entries( 10 );

		// ---- Top suspects --------------------------------------------------
		if ( ! empty( $suspects ) ) {
			echo '<h3>' . esc_html__( 'Most likely suspects', 'plugin-conflict-detector' ) . '</h3>';
			echo '<div class="pcd-suspect-list">';
			foreach ( $suspects as $i => $s ) {
				self::render_suspect_card( $s, $i === 0 );
			}
			echo '</div>';
		} else {
			echo '<div class="pcd-notice pcd-notice--info">';
			esc_html_e( 'Not enough data to identify a suspect yet. The more plugin changes and errors are logged, the better the analysis.', 'plugin-conflict-detector' );
			echo '</div>';
		}

		// ---- Symptom-specific advice ---------------------------------------
		echo '<h3>' . esc_html__( 'Recommended actions', 'plugin-conflict-detector' ) . '</h3>';
		echo '<ol class="pcd-advice-list">';
		foreach ( self::get_advice( $symptom, $suspects ) as $advice ) {
			echo '<li>' . wp_kses_post( $advice ) . '</li>';
		}
		echo '</ol>';

		// ---- Recent timeline snapshot --------------------------------------
		if ( ! empty( $changes ) || ! empty( $errors ) ) {
			echo '<h3>' . esc_html__( 'Recent timeline', 'plugin-conflict-detector' ) . '</h3>';
			echo '<div class="pcd-timeline">';

			$timeline = array();
			foreach ( $changes as $c ) {
				$ts = strtotime( $c->changed_at );
				if ( ! $ts ) continue;
				$timeline[] = array(
					'ts'    => $ts,
					'type'  => 'change',
					'label' => sprintf( '%s — %s', $c->plugin_name, self::action_label( $c->action ) ),
					'time'  => $c->changed_at,
				);
			}
			foreach ( $errors as $e ) {
				if ( empty( $e['time'] ) ) continue;
				$ts = strtotime( $e['time'] );
				if ( ! $ts ) continue;
				$timeline[] = array(
					'ts'    => $ts,
					'type'  => 'error',
					'label' => sprintf( '[%s] %s', strtoupper( $e['type'] ), wp_trim_words( $e['message'], 10 ) ),
					'time'  => $e['time'],
				);
			}
			usort( $timeline, static function ( $a, $b ) { return $b['ts'] - $a['ts']; } );

			foreach ( array_slice( $timeline, 0, 10 ) as $event ) {
				printf(
					'<div class="pcd-timeline__event pcd-timeline__event--%s">
						<span class="pcd-timeline__dot" aria-hidden="true">%s</span>
						<span class="pcd-timeline__time">%s</span>
						<span class="pcd-timeline__label">%s</span>
					</div>',
					esc_attr( $event['type'] ),
					$event['type'] === 'error' ? '🔴' : '🔵',
					esc_html( date_i18n( 'd-m-Y H:i', $event['ts'] ) ),
					esc_html( $event['label'] )
				);
			}
			echo '</div>';
		}

		// ---- Navigation ----------------------------------------------------
		$next_url = add_query_arg( array(
			'page'        => Dashboard::PAGE_SLUG,
			'tab'         => 'wizard',
			'wizard_step' => 3,
			'symptom'     => $symptom,
		), admin_url( 'admin.php' ) );

		$back_url = add_query_arg( array(
			'page' => Dashboard::PAGE_SLUG,
			'tab'  => 'wizard',
		), admin_url( 'admin.php' ) );

		echo '<div class="pcd-wizard__nav">';
		printf( '<a href="%s" class="button">%s</a>', esc_url( $back_url ), esc_html__( '← Start over', 'plugin-conflict-detector' ) );
		printf( '<a href="%s" class="button button-primary">%s</a>', esc_url( $next_url ), esc_html__( 'Use Safe Mode to test →', 'plugin-conflict-detector' ) );
		echo '</div></div>';
	}

	/** Step 3 — Safe Mode launcher */
	private static function render_step3( string $symptom ): void {
		$suspects = Conflict_Scanner::analyse( 3 );

		echo '<div class="pcd-wizard__step">';
		echo '<h2 class="pcd-wizard__heading">' . esc_html__( 'Test with Safe Mode', 'plugin-conflict-detector' ) . '</h2>';
		echo '<p class="pcd-wizard__sub">' . esc_html__( 'Safe Mode lets you disable suspected plugins only for your browser session. Visitors see nothing different.', 'plugin-conflict-detector' ) . '</p>';

		// Safe Mode toggle + plugin list.
		$is_active = Safe_Mode::is_active();
		$disabled  = Safe_Mode::get_disabled_plugins();

		printf(
			'<div class="pcd-safe-mode-panel %s">',
			$is_active ? 'pcd-safe-mode-panel--active' : ''
		);

		printf(
			'<div class="pcd-safe-mode-header">
				<div>
					<strong>%s</strong>
					<span class="pcd-badge %s">%s</span>
				</div>
				<button id="pcd-toggle-safe-mode" class="button %s" type="button">%s</button>
			</div>',
			esc_html__( 'Safe Testing Mode', 'plugin-conflict-detector' ),
			$is_active ? 'pcd-badge--warning' : 'pcd-badge--ok',
			$is_active ? esc_html__( 'ACTIVE', 'plugin-conflict-detector' ) : esc_html__( 'OFF', 'plugin-conflict-detector' ),
			$is_active ? 'button-secondary' : 'button-primary',
			$is_active ? esc_html__( 'Stop Safe Mode', 'plugin-conflict-detector' ) : esc_html__( 'Start Safe Mode', 'plugin-conflict-detector' )
		);

		echo '<div id="pcd-safe-mode-body" ' . ( $is_active ? '' : 'style="display:none"' ) . '>';

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		echo '<p class="pcd-safe-mode-tip">' . esc_html__( 'Toggle plugins OFF to test without them. Only you see these changes — your visitors are unaffected.', 'plugin-conflict-detector' ) . '</p>';

		// Show suspected plugins at the top.
		$suspect_files = array();
		foreach ( $suspects as $s ) {
			foreach ( $active_plugins as $file ) {
				if ( strpos( $file, $s['plugin_slug'] . '/' ) === 0 ) {
					$suspect_files[] = $file;
				}
			}
		}

		if ( ! empty( $suspect_files ) ) {
			echo '<p class="pcd-safe-mode-section-label">' . esc_html__( '⚠ Suspected plugins', 'plugin-conflict-detector' ) . '</p>';
			self::render_plugin_toggle_list( $suspect_files, $all_plugins, $disabled );
			$remaining = array_diff( $active_plugins, $suspect_files );
		} else {
			$remaining = $active_plugins;
		}

		if ( ! empty( $remaining ) ) {
			echo '<p class="pcd-safe-mode-section-label">' . esc_html__( 'Other active plugins', 'plugin-conflict-detector' ) . '</p>';
			self::render_plugin_toggle_list( $remaining, $all_plugins, $disabled );
		}

		echo '</div></div>';

		$back_url = add_query_arg( array(
			'page'        => Dashboard::PAGE_SLUG,
			'tab'         => 'wizard',
			'wizard_step' => 2,
			'symptom'     => $symptom,
		), admin_url( 'admin.php' ) );

		echo '<div class="pcd-wizard__nav">';
		printf( '<a href="%s" class="button">%s</a>', esc_url( $back_url ), esc_html__( '← Back to analysis', 'plugin-conflict-detector' ) );
		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Reusable partials
	// -------------------------------------------------------------------------

	private static function render_progress( int $step ): void {
		$steps = array(
			1 => __( 'Choose symptom', 'plugin-conflict-detector' ),
			2 => __( 'Analysis',       'plugin-conflict-detector' ),
			3 => __( 'Test & fix',     'plugin-conflict-detector' ),
		);

		echo '<div class="pcd-wizard-progress" aria-label="' . esc_attr__( 'Wizard progress', 'plugin-conflict-detector' ) . '">';
		foreach ( $steps as $n => $label ) {
			$class = 'pcd-wizard-progress__step';
			if ( $n < $step )  $class .= ' pcd-wizard-progress__step--done';
			if ( $n === $step ) $class .= ' pcd-wizard-progress__step--active';
			printf(
				'<div class="%s"><span class="pcd-wizard-progress__num">%d</span><span class="pcd-wizard-progress__label">%s</span></div>',
				esc_attr( $class ),
				$n,
				esc_html( $label )
			);
			if ( $n < count( $steps ) ) {
				echo '<div class="pcd-wizard-progress__connector' . ( $n < $step ? ' pcd-wizard-progress__connector--done' : '' ) . '"></div>';
			}
		}
		echo '</div>';
	}

	private static function render_suspect_card( array $s, bool $is_top ): void {
		$bar_width = min( 100, $s['confidence'] );
		$bar_class = $s['confidence'] >= 70 ? 'danger' : ( $s['confidence'] >= 40 ? 'warning' : 'ok' );

		printf(
			'<div class="pcd-suspect-card%s">
				<div class="pcd-suspect-header">
					<div>
						<strong class="pcd-suspect-name">%s</strong>
						%s
					</div>
					<div class="pcd-confidence-badge pcd-confidence--%s">%d%%</div>
				</div>
				<div class="pcd-confidence-bar-track">
					<div class="pcd-confidence-bar pcd-confidence-bar--%s" style="width:%d%%"></div>
				</div>
				<p class="pcd-suspect-reason">%s</p>
				<div class="pcd-suspect-meta">
					<span>%s %s</span>
					%s
				</div>
			</div>',
			$is_top ? ' pcd-suspect-card--top' : '',
			esc_html( $s['plugin_name'] ),
			$is_top ? '<span class="pcd-badge pcd-badge--error">' . esc_html__( 'Top suspect', 'plugin-conflict-detector' ) . '</span>' : '',
			esc_attr( $bar_class ),
			$s['confidence'],
			esc_attr( $bar_class ),
			$bar_width,
			esc_html( $s['reason'] ),
			esc_html__( 'Action:', 'plugin-conflict-detector' ),
			esc_html( self::action_label( $s['action'] ) ),
			$s['error_count'] > 0
				? '<span>' . esc_html( sprintf(
					_n( '%d error', '%d errors', $s['error_count'], 'plugin-conflict-detector' ),
					$s['error_count']
				) ) . '</span>'
				: ''
		);
	}

	private static function render_plugin_toggle_list( array $plugin_files, array $all_plugins, array $disabled ): void {
		echo '<ul class="pcd-plugin-toggle-list">';
		foreach ( $plugin_files as $file ) {
			$data        = $all_plugins[ $file ] ?? array( 'Name' => $file, 'Version' => '' );
			$is_disabled = in_array( $file, $disabled, true );
			printf(
				'<li class="pcd-plugin-toggle-item%s">
					<div class="pcd-plugin-toggle-info">
						<strong>%s</strong>
						<span class="pcd-version">v%s</span>
					</div>
					<label class="pcd-toggle-switch" title="%s">
						<input type="checkbox" class="pcd-plugin-toggle-input" data-plugin="%s" %s>
						<span class="pcd-toggle-slider"></span>
					</label>
					<span class="pcd-toggle-label">%s</span>
				</li>',
				$is_disabled ? ' pcd-plugin-toggle-item--off' : '',
				esc_html( $data['Name'] ),
				esc_html( $data['Version'] ),
				esc_attr__( 'Toggle this plugin on/off for your session', 'plugin-conflict-detector' ),
				esc_attr( $file ),
				$is_disabled ? '' : 'checked',
				$is_disabled
					? '<span class="pcd-badge pcd-badge--warning">' . esc_html__( 'OFF (test)', 'plugin-conflict-detector' ) . '</span>'
					: '<span class="pcd-badge pcd-badge--ok">' . esc_html__( 'ON', 'plugin-conflict-detector' ) . '</span>'
			);
		}
		echo '</ul>';
	}

	// -------------------------------------------------------------------------
	// Advice engine
	// -------------------------------------------------------------------------

	/**
	 * Returns a list of recommended actions based on the symptom and suspects.
	 *
	 * @param  string $symptom  Symptom slug.
	 * @param  array  $suspects Array of suspect records from Conflict_Scanner.
	 * @return string[]
	 */
	private static function get_advice( string $symptom, array $suspects ): array {
		$advice = array();

		if ( ! empty( $suspects ) ) {
			$top = $suspects[0];
			$advice[] = sprintf(
				/* translators: plugin name */
				__( '<strong>Start with "%s"</strong> — it has the highest confidence score. Use Safe Mode (next step) to disable it and check if the problem disappears.', 'plugin-conflict-detector' ),
				$top['plugin_name']
			);
		}

		$symptom_advice = array(
			'white-screen' => array(
				__( 'Enable <code>WP_DEBUG</code> and <code>WP_DEBUG_LOG</code> in <code>wp-config.php</code> to capture the fatal error causing the blank page.', 'plugin-conflict-detector' ),
				__( 'Check the Error Log tab for recent <em>Fatal</em> errors — the file path will point to the conflicting plugin.', 'plugin-conflict-detector' ),
				__( 'If you cannot access the admin, connect via FTP/SFTP and rename <code>wp-content/plugins</code> to disable all plugins at once.', 'plugin-conflict-detector' ),
			),
			'login-issue' => array(
				__( 'Security and login-protection plugins are the most common cause. Disable Wordfence, iThemes Security, or similar plugins via Safe Mode.', 'plugin-conflict-detector' ),
				__( 'Clear your browser cookies and try a private/incognito window.', 'plugin-conflict-detector' ),
				__( 'Check the Error Log for <em>Warning</em> entries in session or authentication files.', 'plugin-conflict-detector' ),
			),
			'woocommerce' => array(
				__( 'Disable WooCommerce payment gateway and shipping plugins first — they are the most frequent source of WooCommerce conflicts.', 'plugin-conflict-detector' ),
				__( 'Switch to the Storefront theme temporarily to rule out a theme conflict.', 'plugin-conflict-detector' ),
				__( 'Check the Error Log for errors in <code>woocommerce/</code> files and cross-reference with the Change History.', 'plugin-conflict-detector' ),
			),
			'slow-site' => array(
				__( 'Caching and optimisation plugins can conflict. If you have multiple caching plugins active, the Health Scan "duplicate functionality" check will flag them.', 'plugin-conflict-detector' ),
				__( 'Use Safe Mode to disable heavy page-builder plugins (Elementor, Divi) one at a time and measure load time.', 'plugin-conflict-detector' ),
				__( 'Check the Health Scan → Server section: a low memory limit (&lt;256 MB) degrades performance under heavy plugins.', 'plugin-conflict-detector' ),
			),
			'admin-broken' => array(
				__( 'Admin-bar, dashboard-widget, and analytics plugins often break the WP admin after updates. Check the Change History for recent updates.', 'plugin-conflict-detector' ),
				__( 'Open your browser console (F12) and look for JavaScript errors — they usually point directly to the conflicting plugin file.', 'plugin-conflict-detector' ),
			),
			'frontend-error' => array(
				__( 'Check the Error Log tab and filter for <em>Fatal</em> and <em>Warning</em> entries — the file column identifies the plugin.', 'plugin-conflict-detector' ),
				__( 'Cross-reference the timestamp of the first error with the Change History to find the triggering update.', 'plugin-conflict-detector' ),
			),
			'other' => array(
				__( 'Open the Error Log tab and look for entries around the time the problem started.', 'plugin-conflict-detector' ),
				__( 'Check the Change History for any plugin updates or activations shortly before the issue appeared.', 'plugin-conflict-detector' ),
				__( 'Run a Health Scan to detect known incompatibilities and configuration issues.', 'plugin-conflict-detector' ),
			),
		);

		$extra = $symptom_advice[ $symptom ] ?? $symptom_advice['other'];
		return array_merge( $advice, $extra );
	}

	private static function action_label( string $action ): string {
		return array(
			'activated'   => __( 'Activated',   'plugin-conflict-detector' ),
			'deactivated' => __( 'Deactivated', 'plugin-conflict-detector' ),
			'updated'     => __( 'Updated',     'plugin-conflict-detector' ),
			'deleted'     => __( 'Deleted',     'plugin-conflict-detector' ),
		)[ $action ] ?? $action;
	}
}
