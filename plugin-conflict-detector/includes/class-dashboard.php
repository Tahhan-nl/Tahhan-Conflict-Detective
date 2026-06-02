<?php
/**
 * Admin dashboard — menus, pages, and AJAX glue.
 *
 * @package PluginConflictDetector
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace PluginConflictDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu item under Tools and renders all four tabs:
 *
 *   Dashboard        — system snapshot + recent activity at a glance
 *   Error Log        — parsed PHP / WP error log with plugin attribution
 *   Change History   — full audit trail of plugin lifecycle events
 *   Health Scan      — on-demand multi-section site health analysis
 *
 * All output is routed through WordPress escaping helpers. No raw HTML
 * is ever interpolated from user-supplied or database-sourced data.
 *
 * @since 1.0.0
 */
final class Dashboard {

	/** The page slug registered with add_submenu_page(). */
	const PAGE_SLUG = 'plugin-conflict-detector';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Plugin Conflict Detector', 'plugin-conflict-detector' ),
			__( 'Conflict Detector', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueues CSS and JS only on our own admin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'pcd-admin',
			PCD_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			PCD_VERSION
		);

		wp_enqueue_script(
			'pcd-admin',
			PCD_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			PCD_VERSION,
			true
		);

		wp_localize_script(
			'pcd-admin',
			'pcdData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pcd_nonce' ),
				'i18n'    => array(
					'scanning' => __( 'Scanning…', 'plugin-conflict-detector' ),
					'done'     => __( 'Scan complete!', 'plugin-conflict-detector' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page router
	// -------------------------------------------------------------------------

	/**
	 * Master render callback — validates the tab and dispatches to the
	 * correct renderer.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugin-conflict-detector' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		$tabs = array(
			'dashboard' => __( 'Dashboard',        'plugin-conflict-detector' ),
			'errors'    => __( 'Error Log',         'plugin-conflict-detector' ),
			'history'   => __( 'Change History',    'plugin-conflict-detector' ),
			'scan'      => __( 'Health Scan',       'plugin-conflict-detector' ),
		);

		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'dashboard';
		}

		echo '<div class="wrap pcd-wrap">';

		printf(
			'<h1 class="pcd-title">%s %s</h1>',
			'<span aria-hidden="true">&#128269;</span>',
			esc_html__( 'Plugin Conflict Detector', 'plugin-conflict-detector' )
		);

		// Tab bar.
		echo '<nav class="pcd-tabs" aria-label="' . esc_attr__( 'Plugin Conflict Detector tabs', 'plugin-conflict-detector' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url    = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'tools.php' ) );
			$active = $key === $tab;
			printf(
				'<a href="%s" class="pcd-tab%s"%s>%s</a>',
				esc_url( $url ),
				$active ? ' pcd-tab--active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="pcd-content">';
		switch ( $tab ) {
			case 'errors':
				self::render_errors();
				break;
			case 'history':
				self::render_history();
				break;
			case 'scan':
				self::render_scan();
				break;
			default:
				self::render_dashboard();
		}
		echo '</div>'; // .pcd-content
		echo '</div>'; // .pcd-wrap
	}

	// -------------------------------------------------------------------------
	// Tab renderers
	// -------------------------------------------------------------------------

	/** @return void */
	private static function render_dashboard(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $wp_version;

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$theme          = wp_get_theme();
		$recent_changes = Change_History::get_recent( 5 );
		$error_entries  = Error_Log::get_entries( 5 );

		echo '<div class="pcd-grid">';

		// --- System info card -------------------------------------------------
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'System Overview', 'plugin-conflict-detector' ) . '</h2>';
		echo '<table class="pcd-table">';

		$rows = array(
			__( 'WordPress',    'plugin-conflict-detector' ) => esc_html( $wp_version ),
			__( 'PHP',          'plugin-conflict-detector' ) => esc_html( PHP_VERSION ),
			__( 'Theme',        'plugin-conflict-detector' ) => esc_html( $theme->get( 'Name' ) . ' v' . $theme->get( 'Version' ) ),
			__( 'Memory Limit', 'plugin-conflict-detector' ) => esc_html( WP_MEMORY_LIMIT ),
			__( 'Debug Mode',   'plugin-conflict-detector' ) => ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				? '<span class="pcd-badge pcd-badge--warning">ON</span>'
				: '<span class="pcd-badge pcd-badge--ok">OFF</span>',
		);

		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
		}

		echo '</table></div>';

		// --- Active plugins card ----------------------------------------------
		echo '<div class="pcd-card">';
		printf(
			'<h2 class="pcd-card__title">%s <span class="pcd-count">%d</span></h2>',
			esc_html__( 'Active Plugins', 'plugin-conflict-detector' ),
			count( $active_plugins )
		);
		echo '<ul class="pcd-plugin-list">';
		foreach ( $active_plugins as $plugin_file ) {
			$data = $all_plugins[ $plugin_file ] ?? array( 'Name' => $plugin_file, 'Version' => '' );
			printf(
				'<li><strong>%s</strong> <span class="pcd-version">v%s</span></li>',
				esc_html( $data['Name'] ),
				esc_html( $data['Version'] )
			);
		}
		echo '</ul></div>';

		// --- Recent changes card ----------------------------------------------
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Recent Changes', 'plugin-conflict-detector' ) . '</h2>';

		if ( empty( $recent_changes ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet.', 'plugin-conflict-detector' ) . '</p>';
		} else {
			echo '<ul class="pcd-change-list">';
			foreach ( $recent_changes as $change ) {
				printf(
					'<li>
						<span class="pcd-action-icon" aria-hidden="true">%s</span>
						<span class="pcd-change-name">%s</span>
						<span class="pcd-badge pcd-badge--action-%s">%s</span>
						<span class="pcd-change-time">%s</span>
					</li>',
					esc_html( self::action_icon( $change->action ) ),
					esc_html( $change->plugin_name ),
					esc_attr( $change->action ),
					esc_html( self::action_label( $change->action ) ),
					esc_html( date_i18n( 'd-m-Y H:i', strtotime( $change->changed_at ) ) )
				);
			}
			echo '</ul>';
		}

		printf(
			'<a href="%s" class="pcd-link">%s</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'history' ), admin_url( 'tools.php' ) ) ),
			esc_html__( 'View all changes →', 'plugin-conflict-detector' )
		);
		echo '</div>';

		// --- Recent errors card -----------------------------------------------
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Recent Errors', 'plugin-conflict-detector' ) . '</h2>';

		if ( empty( $error_entries ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No errors found in log files.', 'plugin-conflict-detector' ) . '</p>';
		} else {
			echo '<ul class="pcd-error-list">';
			foreach ( $error_entries as $entry ) {
				printf(
					'<li class="pcd-error pcd-error--%s">
						<div class="pcd-error__type">%s</div>
						<div class="pcd-error__message">%s</div>
						%s
					</li>',
					esc_attr( $entry['type'] ),
					esc_html( strtoupper( $entry['type'] ) ),
					esc_html( wp_trim_words( $entry['message'], 15 ) ),
					$entry['plugin_name']
						? '<div class="pcd-error__plugin">Plugin: ' . esc_html( $entry['plugin_name'] ) . '</div>'
						: ''
				);
			}
			echo '</ul>';
		}

		printf(
			'<a href="%s" class="pcd-link">%s</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'errors' ), admin_url( 'tools.php' ) ) ),
			esc_html__( 'View all errors →', 'plugin-conflict-detector' )
		);
		echo '</div>';

		echo '</div>'; // .pcd-grid
	}

	/** @return void */
	private static function render_errors(): void {
		$entries  = Error_Log::get_entries( 200 );
		$log_info = Error_Log::get_log_file_info();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Error Log', 'plugin-conflict-detector' ) . '</h2>';

		// Log file status banner.
		echo '<div class="pcd-log-info">';
		if ( $log_info['exists'] ) {
			printf(
				'<span class="pcd-badge pcd-badge--ok">debug.log</span> <span class="pcd-log-meta">%s %s &middot; %s %s</span>',
				esc_html__( 'Size:', 'plugin-conflict-detector' ),
				esc_html( size_format( $log_info['size'] ) ),
				esc_html__( 'Modified:', 'plugin-conflict-detector' ),
				esc_html( $log_info['modified'] )
			);
		} else {
			echo '<span class="pcd-badge pcd-badge--info">' . esc_html__( 'No debug.log found', 'plugin-conflict-detector' ) . '</span>';
			if ( ! $log_info['debug_enabled'] ) {
				echo '<p class="pcd-tip">' . esc_html__( 'To enable logging, add to wp-config.php:', 'plugin-conflict-detector' ) . '<br>';
				echo '<code>define(\'WP_DEBUG\', true);<br>define(\'WP_DEBUG_LOG\', true);</code></p>';
			}
		}
		echo '</div>';

		if ( empty( $entries ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No error entries found.', 'plugin-conflict-detector' ) . '</p>';
			echo '</div>';
			return;
		}

		// Filter bar.
		echo '<div class="pcd-filter-bar" role="toolbar" aria-label="' . esc_attr__( 'Filter errors by type', 'plugin-conflict-detector' ) . '">';
		$filter_labels = array(
			'all'        => __( 'All',        'plugin-conflict-detector' ),
			'fatal'      => __( 'Fatal',      'plugin-conflict-detector' ),
			'warning'    => __( 'Warning',    'plugin-conflict-detector' ),
			'notice'     => __( 'Notice',     'plugin-conflict-detector' ),
			'deprecated' => __( 'Deprecated', 'plugin-conflict-detector' ),
		);
		foreach ( $filter_labels as $key => $label ) {
			printf(
				'<button class="pcd-filter-btn%s" data-filter="%s" type="button">%s</button>',
				$key === 'all' ? ' pcd-filter-btn--active' : '',
				esc_attr( $key ),
				esc_html( $label )
			);
		}
		echo '</div>';

		echo '<table class="pcd-errors-table">';
		echo '<thead><tr>';
		foreach ( array( 'Type', 'Time', 'Plugin', 'Message', 'File' ) as $heading ) {
			echo '<th>' . esc_html__( $heading, 'plugin-conflict-detector' ) . '</th>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$short_file = $entry['file'] ? str_replace( ABSPATH, '', $entry['file'] ) : '—';
			printf(
				'<tr class="pcd-error-row" data-type="%s">
					<td><span class="pcd-badge pcd-badge--%s">%s</span></td>
					<td class="pcd-time">%s</td>
					<td>%s</td>
					<td class="pcd-error-msg">%s</td>
					<td class="pcd-error-file"><span title="%s">%s%s</span></td>
				</tr>',
				esc_attr( $entry['type'] ),
				esc_attr( self::error_badge_class( $entry['type'] ) ),
				esc_html( strtoupper( $entry['type'] ) ),
				esc_html( $entry['time'] ),
				esc_html( $entry['plugin_name'] ?: '—' ),
				esc_html( $entry['message'] ),
				esc_attr( $entry['file'] ),
				esc_html( $short_file ),
				$entry['line'] ? ':' . esc_html( $entry['line'] ) : ''
			);
		}

		echo '</tbody></table></div>';
	}

	/** @return void */
	private static function render_history(): void {
		$changes = Change_History::get_recent( 200 );

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Plugin Change History', 'plugin-conflict-detector' ) . '</h2>';

		if ( empty( $changes ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet. Changes will be tracked from the moment this plugin was activated.', 'plugin-conflict-detector' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="pcd-history-table">';
		echo '<thead><tr>';
		foreach ( array( 'Date & Time', 'Plugin', 'Action', 'Version' ) as $heading ) {
			echo '<th>' . esc_html__( $heading, 'plugin-conflict-detector' ) . '</th>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		echo '</tr></thead><tbody>';

		foreach ( $changes as $change ) {
			$version_cell = ( $change->action === 'updated' && $change->previous_version )
				? esc_html( $change->previous_version ) . ' &rarr; ' . esc_html( $change->plugin_version )
				: esc_html( $change->plugin_version );

			printf(
				'<tr>
					<td class="pcd-time">%s</td>
					<td><strong>%s</strong></td>
					<td><span class="pcd-badge pcd-badge--action-%s">%s</span></td>
					<td>%s</td>
				</tr>',
				esc_html( date_i18n( 'd-m-Y H:i:s', strtotime( $change->changed_at ) ) ),
				esc_html( $change->plugin_name ),
				esc_attr( $change->action ),
				esc_html( self::action_label( $change->action ) ),
				wp_kses_post( $version_cell )
			);
		}

		echo '</tbody></table></div>';
	}

	/** @return void */
	private static function render_scan(): void {
		// Handle form submission.
		$scan_result = null;
		$notice      = '';

		if ( isset( $_POST['pcd_run_scan'] ) ) {
			if ( ! check_admin_referer( 'pcd_run_scan', 'pcd_scan_nonce' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'plugin-conflict-detector' ) );
			}
			$scan_result = Health_Scan::run();
			$notice      = 'success';
		}

		$last_scan = $scan_result ?? Health_Scan::get_last_scan();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Health Scan', 'plugin-conflict-detector' ) . '</h2>';

		// Trigger form.
		echo '<form method="post" action="">';
		wp_nonce_field( 'pcd_run_scan', 'pcd_scan_nonce' );
		printf(
			'<button type="submit" name="pcd_run_scan" class="button button-primary pcd-scan-btn">%s</button>',
			esc_html__( 'Run Scan Now', 'plugin-conflict-detector' )
		);
		echo '</form>';

		if ( $notice === 'success' ) {
			echo '<div class="pcd-notice pcd-notice--success">' . esc_html__( 'Scan completed successfully.', 'plugin-conflict-detector' ) . '</div>';
		}

		if ( ! $last_scan ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No scan has been run yet. Click "Run Scan Now" to start.', 'plugin-conflict-detector' ) . '</p>';
			echo '</div>';
			return;
		}

		printf(
			'<p class="pcd-scan-meta">%s %s &nbsp;|&nbsp; %s %s</p>',
			esc_html__( 'Last scan:', 'plugin-conflict-detector' ),
			esc_html( date_i18n( 'd-m-Y H:i', strtotime( $last_scan['scanned_at'] ) ) ),
			esc_html( $last_scan['issues_found'] ),
			esc_html( _n( 'issue found', 'issues found', $last_scan['issues_found'], 'plugin-conflict-detector' ) )
		);

		// Render each section.
		self::render_scan_section(
			__( 'Plugins', 'plugin-conflict-detector' ),
			$last_scan['plugins'] ?? array()
		);
		self::render_scan_section(
			__( 'Theme', 'plugin-conflict-detector' ),
			$last_scan['theme'] ?? array(),
			$last_scan['theme']['info'] ?? array()
		);
		self::render_scan_section(
			__( 'Server', 'plugin-conflict-detector' ),
			$last_scan['server'] ?? array(),
			$last_scan['server']['info'] ?? array()
		);

		echo '</div>';
	}

	/**
	 * Renders a single scan section (plugins / theme / server).
	 *
	 * @param string               $title   Section heading.
	 * @param array<string, mixed> $section Section data (must have 'issues' key).
	 * @param array<string, mixed> $info    Optional key/value info table.
	 * @return void
	 */
	private static function render_scan_section( string $title, array $section, array $info = array() ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';

		if ( ! empty( $info ) ) {
			echo '<table class="pcd-table" style="margin-bottom:12px">';
			foreach ( $info as $key => $value ) {
				if ( is_bool( $value ) ) {
					$cell = $value
						? '<span class="pcd-badge pcd-badge--warning">ON</span>'
						: '<span class="pcd-badge pcd-badge--ok">OFF</span>';
				} else {
					$cell = esc_html( (string) $value );
				}
				printf(
					'<tr><th>%s</th><td>%s</td></tr>',
					esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ),
					wp_kses_post( $cell )
				);
			}
			echo '</table>';
		}

		$issues = $section['issues'] ?? array();

		if ( empty( $issues ) ) {
			echo '<div class="pcd-notice pcd-notice--success">';
			printf(
				/* translators: section title */
				esc_html__( 'No issues found in %s.', 'plugin-conflict-detector' ),
				esc_html( strtolower( $title ) )
			);
			echo '</div>';
			return;
		}

		echo '<ul class="pcd-issue-list">';
		foreach ( $issues as $issue ) {
			printf(
				'<li class="pcd-issue pcd-issue--%s"><span class="pcd-issue-icon" aria-hidden="true">%s</span>%s</li>',
				esc_attr( $issue['type'] ),
				esc_html( self::issue_icon( $issue['type'] ) ),
				esc_html( $issue['message'] )
			);
		}
		echo '</ul>';
	}

	// -------------------------------------------------------------------------
	// Presentational helpers
	// -------------------------------------------------------------------------

	private static function action_icon( string $action ): string {
		return array(
			'activated'   => '✅',
			'deactivated' => '⏸',
			'updated'     => '🔄',
			'deleted'     => '🗑',
		)[ $action ] ?? '•';
	}

	private static function action_label( string $action ): string {
		return array(
			'activated'   => __( 'Activated',   'plugin-conflict-detector' ),
			'deactivated' => __( 'Deactivated', 'plugin-conflict-detector' ),
			'updated'     => __( 'Updated',     'plugin-conflict-detector' ),
			'deleted'     => __( 'Deleted',     'plugin-conflict-detector' ),
		)[ $action ] ?? $action;
	}

	private static function error_badge_class( string $type ): string {
		return array(
			'fatal'      => 'error',
			'warning'    => 'warning',
			'deprecated' => 'warning',
			'notice'     => 'info',
		)[ $type ] ?? 'info';
	}

	private static function issue_icon( string $type ): string {
		$errors   = array( 'incompatible', 'missing-parent', 'missing-file', 'php-version', 'memory-low' );
		$updates  = array( 'update-available', 'wp-update', 'outdated' );
		if ( in_array( $type, $errors, true ) ) {
			return '❌';
		}
		if ( in_array( $type, $updates, true ) ) {
			return '🔄';
		}
		return '⚠️';
	}
}
