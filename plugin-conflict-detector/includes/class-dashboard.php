<?php
/**
 * Admin dashboard — menus, pages, and AJAX handlers.
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
 * Registers the admin menu and renders all four tabs:
 *
 *   Dashboard       — system snapshot, stats, "likely culprit" widget
 *   Error Log       — parsed PHP / WP error log with plugin attribution + filter
 *   Change History  — full audit trail of plugin lifecycle events
 *   Health Scan     — on-demand multi-section site health analysis (AJAX)
 *
 * @since 1.0.0
 */
final class Dashboard {

	const PAGE_SLUG = 'plugin-conflict-detector';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		// Top-level menu item — position 65 sits between "Plugins" (65) and "Users" (70).
		add_menu_page(
			__( 'Plugin Conflict Detector', 'plugin-conflict-detector' ),
			__( 'Conflict Detector', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-search',
			65
		);

		// Add sub-pages so the tab links appear in the sidebar as well.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dashboard', 'plugin-conflict-detector' ),
			__( 'Dashboard', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Conflict Scanner', 'plugin-conflict-detector' ),
			__( 'Conflict Scanner', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=scanner',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Conflict Wizard', 'plugin-conflict-detector' ),
			__( 'Conflict Wizard', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=wizard',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Error Log', 'plugin-conflict-detector' ),
			__( 'Error Log', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=errors',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Change History', 'plugin-conflict-detector' ),
			__( 'Change History', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=history',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Health Scan', 'plugin-conflict-detector' ),
			__( 'Health Scan', 'plugin-conflict-detector' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=scan',
			array( __CLASS__, 'render_page' )
		);
	}

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

		wp_localize_script( 'pcd-admin', 'pcdData', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pcd_nonce' ),
			'scanning' => __( 'Scanning…', 'plugin-conflict-detector' ),
			'done'     => __( 'Scan complete!', 'plugin-conflict-detector' ),
			'clearing' => __( 'Clearing…', 'plugin-conflict-detector' ),
			'cleared'  => __( 'Log cleared.', 'plugin-conflict-detector' ),
		) );
	}

	/**
	 * Registers AJAX handlers — must be called on init/plugins_loaded.
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_pcd_run_scan',   array( __CLASS__, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_pcd_clear_log',  array( __CLASS__, 'ajax_clear_log' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_run_scan(): void {
		check_ajax_referer( 'pcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$results    = Health_Scan::run();
		$scanned_ts = strtotime( $results['scanned_at'] );
		wp_send_json_success( array(
			'issues'     => $results['issues_found'],
			'scanned_at' => $scanned_ts ? date_i18n( 'd-m-Y H:i', $scanned_ts ) : '',
			'html'       => self::build_scan_results_html( $results ),
		) );
	}

	public static function ajax_clear_log(): void {
		check_ajax_referer( 'pcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$log_file = WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $log_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Log file not found.', 'plugin-conflict-detector' ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( file_put_contents( $log_file, '' ) === false ) {
			wp_send_json_error( array( 'message' => __( 'Could not clear log file (permission denied).', 'plugin-conflict-detector' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'plugin-conflict-detector' ) ) );
	}

	// -------------------------------------------------------------------------
	// Page router
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugin-conflict-detector' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		// Tab definitions: label + dashicon class.
		$tabs = array(
			'dashboard' => array( 'label' => __( 'Dashboard',       'plugin-conflict-detector' ), 'icon' => 'dashicons-dashboard' ),
			'scanner'   => array( 'label' => __( 'Conflict Scanner','plugin-conflict-detector' ), 'icon' => 'dashicons-search' ),
			'wizard'    => array( 'label' => __( 'Conflict Wizard', 'plugin-conflict-detector' ), 'icon' => 'dashicons-editor-help' ),
			'errors'    => array( 'label' => __( 'Error Log',       'plugin-conflict-detector' ), 'icon' => 'dashicons-warning' ),
			'history'   => array( 'label' => __( 'Change History',  'plugin-conflict-detector' ), 'icon' => 'dashicons-backup' ),
			'scan'      => array( 'label' => __( 'Health Scan',     'plugin-conflict-detector' ), 'icon' => 'dashicons-shield' ),
		);

		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'dashboard';
		}

		echo '<div class="wrap pcd-wrap">';
		printf(
			'<h1 class="pcd-title"><span class="pcd-title-icon" aria-hidden="true"><span class="dashicons dashicons-search"></span></span> %s</h1>',
			esc_html__( 'Plugin Conflict Detector', 'plugin-conflict-detector' )
		);

		echo '<nav class="pcd-tabs" aria-label="' . esc_attr__( 'Sections', 'plugin-conflict-detector' ) . '">';
		foreach ( $tabs as $key => $def ) {
			$active = $key === $tab;
			printf(
				'<a href="%s" class="pcd-tab%s"%s><span class="dashicons %s" aria-hidden="true"></span>%s</a>',
				esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				$active ? ' pcd-tab--active' : '',
				$active ? ' aria-current="page"' : '',
				esc_attr( $def['icon'] ),
				esc_html( $def['label'] )
			);
		}
		echo '</nav>';

		echo '<div class="pcd-content">';
		switch ( $tab ) {
			case 'scanner': self::render_scanner(); break;
			case 'wizard':  Wizard::render();       break;
			case 'errors':  self::render_errors();  break;
			case 'history': self::render_history(); break;
			case 'scan':    self::render_scan();    break;
			default:        self::render_dashboard();
		}
		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Dashboard tab
	// -------------------------------------------------------------------------

	private static function render_dashboard(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $wp_version;

		$all_plugins     = get_plugins();
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$theme           = wp_get_theme();
		$recent_changes  = Change_History::get_recent( 5 );
		$error_entries   = Error_Log::get_entries( 5 );
		$culprit         = Health_Scan::get_likely_culprit();
		$last_scan       = Health_Scan::get_last_scan();

		// --- Stats bar -------------------------------------------------------
		$total_errors   = count( Error_Log::get_entries( 500 ) );
		$total_changes  = count( Change_History::get_recent( 500 ) );
		$total_issues   = $last_scan ? (int) $last_scan['issues_found'] : 0;

		echo '<div class="pcd-stats-bar">';
		self::stat_card(
			(string) count( $active_plugins ),
			__( 'Active Plugins', 'plugin-conflict-detector' ),
			'plugin',
			'neutral'
		);
		self::stat_card(
			(string) $total_changes,
			__( 'Changes Logged', 'plugin-conflict-detector' ),
			'history',
			'neutral'
		);
		self::stat_card(
			(string) $total_errors,
			__( 'Log Entries', 'plugin-conflict-detector' ),
			'error',
			$total_errors > 0 ? 'warning' : 'ok'
		);
		self::stat_card(
			(string) $total_issues,
			__( 'Health Issues', 'plugin-conflict-detector' ),
			'scan',
			$total_issues > 0 ? 'danger' : 'ok'
		);
		echo '</div>';

		// --- Likely culprit banner -------------------------------------------
		if ( $culprit ) {
			printf(
				'<div class="pcd-culprit-banner">
					<span class="pcd-culprit-icon" aria-hidden="true"><span class="dashicons dashicons-info"></span></span>
					<div class="pcd-culprit-body">
						<strong>%s</strong> — %s
						<span class="pcd-culprit-meta">%s %s &middot; %s %s &middot; %s</span>
					</div>
				</div>',
				esc_html( $culprit['plugin_name'] ),
				esc_html( sprintf(
					/* translators: number of errors */
					_n( '%d error attributed to this plugin', '%d errors attributed to this plugin', $culprit['error_count'], 'plugin-conflict-detector' ),
					$culprit['error_count']
				) ),
				esc_html__( 'Last action:', 'plugin-conflict-detector' ),
				esc_html( self::action_label( $culprit['action'] ) ),
				esc_html__( 'at', 'plugin-conflict-detector' ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $culprit['changed_at'] ) ) ),
				esc_html__( 'Most likely cause of recent errors.', 'plugin-conflict-detector' )
			);
		}

		echo '<div class="pcd-grid">';

		// --- System info -----------------------------------------------------
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

		// --- Active plugins --------------------------------------------------
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

		// --- Recent changes --------------------------------------------------
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Recent Changes', 'plugin-conflict-detector' ) . '</h2>';
		if ( empty( $recent_changes ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet. Changes are tracked from the moment this plugin was activated.', 'plugin-conflict-detector' ) . '</p>';
		} else {
			echo '<ul class="pcd-change-list">';
			foreach ( $recent_changes as $change ) {
				printf(
					'<li>
						<span class="pcd-action-icon">%s</span>
						<span class="pcd-change-name">%s</span>
						<span class="pcd-badge pcd-badge--action-%s">%s</span>
						<span class="pcd-change-time">%s</span>
					</li>',
					wp_kses_post( self::action_icon( $change->action ) ),
					esc_html( $change->plugin_name ),
					esc_attr( $change->action ),
					esc_html( self::action_label( $change->action ) ),
					esc_html( date_i18n( 'd-m-Y H:i', strtotime( $change->changed_at ) ) )
				);
			}
			echo '</ul>';
		}
		printf(
			'<a href="%s" class="pcd-link">%s &#8594;</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'history' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'View all changes', 'plugin-conflict-detector' )
		);
		echo '</div>';

		// --- Recent errors ---------------------------------------------------
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
					$entry['plugin_name'] ? '<div class="pcd-error__plugin">Plugin: ' . esc_html( $entry['plugin_name'] ) . '</div>' : ''
				);
			}
			echo '</ul>';
		}
		printf(
			'<a href="%s" class="pcd-link">%s &#8594;</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'errors' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'View all errors', 'plugin-conflict-detector' )
		);
		echo '</div>';

		echo '</div>'; // .pcd-grid
	}

	// -------------------------------------------------------------------------
	// Error Log tab
	// -------------------------------------------------------------------------

	private static function render_errors(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['pcd_page'] ) ? max( 1, (int) $_GET['pcd_page'] ) : 1;
		$per_page = 50;
		$all     = Error_Log::get_entries( 500 );
		$total   = count( $all );
		$entries = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
		$pages   = (int) ceil( $total / $per_page );
		$log_info = Error_Log::get_log_file_info();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Error Log', 'plugin-conflict-detector' ) . '</h2>';

		// Clear log button.
		if ( $log_info['exists'] && $log_info['writable'] ) {
			printf(
				'<button id="pcd-clear-log" class="button pcd-btn-danger" type="button">%s</button>',
				esc_html__( 'Clear debug.log', 'plugin-conflict-detector' )
			);
		}
		echo '</div>';

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
				echo '<p class="pcd-tip">' . esc_html__( 'Add to wp-config.php to enable logging:', 'plugin-conflict-detector' ) . '<br>';
				echo '<code>define(\'WP_DEBUG\', true);<br>define(\'WP_DEBUG_LOG\', true);</code></p>';
			}
		}
		echo '</div>';

		if ( empty( $all ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No error entries found.', 'plugin-conflict-detector' ) . '</p>';
			echo '</div>';
			return;
		}

		// Filter bar.
		$type_counts = array_count_values( array_column( $all, 'type' ) );
		echo '<div class="pcd-filter-bar" role="toolbar">';
		$filters = array(
			'all'        => __( 'All', 'plugin-conflict-detector' ),
			'fatal'      => __( 'Fatal', 'plugin-conflict-detector' ),
			'warning'    => __( 'Warning', 'plugin-conflict-detector' ),
			'notice'     => __( 'Notice', 'plugin-conflict-detector' ),
			'deprecated' => __( 'Deprecated', 'plugin-conflict-detector' ),
		);
		foreach ( $filters as $key => $label ) {
			$count = $key === 'all' ? $total : ( $type_counts[ $key ] ?? 0 );
			printf(
				'<button class="pcd-filter-btn%s" data-filter="%s" type="button">%s <span class="pcd-filter-count">%d</span></button>',
				$key === 'all' ? ' pcd-filter-btn--active' : '',
				esc_attr( $key ),
				esc_html( $label ),
				$count
			);
		}
		echo '</div>';

		echo '<table class="pcd-errors-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Type',    'plugin-conflict-detector' ),
			__( 'Time',    'plugin-conflict-detector' ),
			__( 'Plugin',  'plugin-conflict-detector' ),
			__( 'Message', 'plugin-conflict-detector' ),
			__( 'File',    'plugin-conflict-detector' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
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
		echo '</tbody></table>';

		self::render_pagination( $page, $pages, 'errors' );
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Change History tab
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Conflict Scanner tab
	// -------------------------------------------------------------------------

	private static function render_scanner(): void {
		$suspects = Conflict_Scanner::analyse( 10 );

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Conflict Scanner', 'plugin-conflict-detector' ) . '</h2>';
		printf(
			'<a href="%s" class="button button-primary">%s</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'wizard' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Open Wizard →', 'plugin-conflict-detector' )
		);
		echo '</div>';

		echo '<p class="pcd-scanner-intro">' . esc_html__( 'The scanner analyses your plugin change history against the error log and ranks each plugin by how likely it is to be causing the current problem.', 'plugin-conflict-detector' ) . '</p>';

		if ( empty( $suspects ) ) {
			echo '<div class="pcd-notice pcd-notice--info">';
			esc_html_e( 'No suspects found. This usually means there are no errors in the log, no plugin changes have been recorded yet, or the errors cannot be attributed to a specific plugin.', 'plugin-conflict-detector' );
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<div class="pcd-suspect-list">';
		foreach ( $suspects as $i => $suspect ) {
			$bar    = min( 100, $suspect['confidence'] );
			$bclass = $suspect['confidence'] >= 70 ? 'danger' : ( $suspect['confidence'] >= 40 ? 'warning' : 'ok' );

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
						<span>%s: %s</span>
						<span>%s</span>
						%s
					</div>
				</div>',
				$i === 0 ? ' pcd-suspect-card--top' : '',
				esc_html( $suspect['plugin_name'] ),
				$i === 0 ? '<span class="pcd-badge pcd-badge--error">' . esc_html__( 'Top suspect', 'plugin-conflict-detector' ) . '</span>' : '',
				esc_attr( $bclass ),
				$suspect['confidence'],
				esc_attr( $bclass ),
				$bar,
				esc_html( $suspect['reason'] ),
				esc_html__( 'Action', 'plugin-conflict-detector' ),
				esc_html( self::action_label( $suspect['action'] ) ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $suspect['changed_at'] ) ) ),
				$suspect['error_count'] > 0
					? '<span>' . esc_html( sprintf(
						_n( '%d error', '%d errors', $suspect['error_count'], 'plugin-conflict-detector' ),
						$suspect['error_count']
					) ) . '</span>'
					: ''
			);
		}
		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Change History tab
	// -------------------------------------------------------------------------

	private static function render_history(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = isset( $_GET['pcd_page'] ) ? max( 1, (int) $_GET['pcd_page'] ) : 1;
		$per_page = 50;
		$all      = Change_History::get_recent( 500 );
		$total    = count( $all );
		$changes  = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
		$pages    = (int) ceil( $total / $per_page );

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Plugin Change History', 'plugin-conflict-detector' ) . '</h2>';

		if ( empty( $all ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet. Changes are tracked from activation.', 'plugin-conflict-detector' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="pcd-history-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Date & Time', 'plugin-conflict-detector' ),
			__( 'Plugin',      'plugin-conflict-detector' ),
			__( 'Action',      'plugin-conflict-detector' ),
			__( 'Version',     'plugin-conflict-detector' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
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
		echo '</tbody></table>';

		self::render_pagination( $page, $pages, 'history' );
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Health Scan tab
	// -------------------------------------------------------------------------

	private static function render_scan(): void {
		$last_scan = Health_Scan::get_last_scan();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Health Scan', 'plugin-conflict-detector' ) . '</h2>';
		printf(
			'<button id="pcd-run-scan" class="button button-primary" type="button">%s</button>',
			esc_html__( 'Run Scan Now', 'plugin-conflict-detector' )
		);
		echo '</div>';

		echo '<div id="pcd-scan-status"></div>';

		if ( $last_scan ) {
			printf(
				'<p class="pcd-scan-meta" id="pcd-scan-meta">%s %s &nbsp;|&nbsp; <strong>%s</strong> %s</p>',
				esc_html__( 'Last scan:', 'plugin-conflict-detector' ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $last_scan['scanned_at'] ) ) ),
				esc_html( (string) $last_scan['issues_found'] ),
				esc_html( _n( 'issue found', 'issues found', $last_scan['issues_found'], 'plugin-conflict-detector' ) )
			);
		}

		echo '<div id="pcd-scan-results">';
		if ( $last_scan ) {
			echo self::build_scan_results_html( $last_scan ); // phpcs:ignore WordPress.Security.EscapeOutput — builder escapes internally
		} else {
			echo '<p class="pcd-empty">' . esc_html__( 'No scan run yet. Click "Run Scan Now" to start.', 'plugin-conflict-detector' ) . '</p>';
		}
		echo '</div>';

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Scan results HTML builder (used by both render_scan and AJAX)
	// -------------------------------------------------------------------------

	/**
	 * Builds the inner HTML for scan results.
	 * All output is escaped — safe to pass directly to the page or AJAX response.
	 *
	 * @param  array<string, mixed> $data Scan result array from Health_Scan::run() or get_last_scan().
	 * @return string
	 */
	public static function build_scan_results_html( array $data ): string {
		ob_start();

		$sections = array(
			__( 'Plugins', 'plugin-conflict-detector' ) => $data['plugins'] ?? array(),
			__( 'Theme',   'plugin-conflict-detector' ) => $data['theme']   ?? array(),
			__( 'Server',  'plugin-conflict-detector' ) => $data['server']  ?? array(),
		);

		foreach ( $sections as $title => $section ) {
			echo '<h3>' . esc_html( $title ) . '</h3>';

			// For theme and server, render the info key→value table.
			$info = $section['info'] ?? array();
			if ( ! empty( $info ) && ! isset( $info[0] ) ) { // skip indexed arrays (plugins list)
				echo '<table class="pcd-table pcd-table--scan">';
				foreach ( $info as $key => $value ) {
					if ( is_array( $value ) ) {
						continue;
					}
					if ( is_bool( $value ) ) {
						$cell = $value
							? '<span class="pcd-badge pcd-badge--warning">ON</span>'
							: '<span class="pcd-badge pcd-badge--ok">OFF</span>';
					} else {
						$cell = esc_html( (string) $value );
					}
					printf(
						'<tr><th>%s</th><td>%s</td></tr>',
						esc_html( ucwords( str_replace( '_', ' ', $key ) ) ),
						wp_kses_post( $cell )
					);
				}
				echo '</table>';
			}

			$issues = $section['issues'] ?? array();
			if ( empty( $issues ) ) {
				printf(
					'<div class="pcd-notice pcd-notice--success">%s</div>',
					esc_html( sprintf(
						/* translators: section name */
						__( 'No issues found in %s.', 'plugin-conflict-detector' ),
						strtolower( $title )
					) )
				);
			} else {
				echo '<ul class="pcd-issue-list">';
				foreach ( $issues as $issue ) {
					printf(
						'<li class="pcd-issue pcd-issue--%s"><span class="pcd-issue-icon">%s</span>%s</li>',
						esc_attr( $issue['type'] ),
						wp_kses_post( self::issue_icon( $issue['type'] ) ),
						esc_html( $issue['message'] )
					);
				}
				echo '</ul>';
			}
		}

		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	private static function stat_card( string $value, string $label, string $icon_key, string $status ): void {
		$icons = array(
			'plugin'  => 'dashicons-admin-plugins',
			'history' => 'dashicons-backup',
			'error'   => 'dashicons-warning',
			'scan'    => 'dashicons-shield',
		);
		$icon_class = $icons[ $icon_key ] ?? 'dashicons-marker';
		printf(
			'<div class="pcd-stat pcd-stat--%s">
				<span class="pcd-stat__icon" aria-hidden="true"><span class="dashicons %s"></span></span>
				<span class="pcd-stat__value">%s</span>
				<span class="pcd-stat__label">%s</span>
			</div>',
			esc_attr( $status ),
			esc_attr( $icon_class ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private static function render_pagination( int $current, int $total_pages, string $tab ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<div class="pcd-pagination">';
		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url = add_query_arg( array(
				'page'     => self::PAGE_SLUG,
				'tab'      => $tab,
				'pcd_page' => $i,
			), admin_url( 'admin.php' ) );
			printf(
				'<a href="%s" class="pcd-page-btn%s">%d</a>',
				esc_url( $url ),
				$i === $current ? ' pcd-page-btn--active' : '',
				$i
			);
		}
		echo '</div>';
	}

	/**
	 * Returns a dashicons <span> for a plugin action.
	 * Output is HTML — use wp_kses_post() at the call site.
	 */
	private static function action_icon( string $action ): string {
		$map = array(
			'activated'   => 'dashicons-yes-alt',
			'deactivated' => 'dashicons-controls-pause',
			'updated'     => 'dashicons-update',
			'deleted'     => 'dashicons-trash',
		);
		$class = $map[ $action ] ?? 'dashicons-marker';
		return '<span class="dashicons ' . esc_attr( $class ) . '" aria-hidden="true"></span>';
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

	/**
	 * Returns a dashicons <span> for a scan issue type.
	 * Output is HTML — use wp_kses_post() at the call site.
	 */
	private static function issue_icon( string $type ): string {
		$errors  = array( 'incompatible', 'missing-parent', 'missing-file', 'php-version', 'memory-low' );
		$updates = array( 'update-available', 'wp-update', 'outdated' );
		if ( in_array( $type, $errors, true ) ) {
			return '<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>';
		}
		if ( in_array( $type, $updates, true ) ) {
			return '<span class="dashicons dashicons-update" aria-hidden="true"></span>';
		}
		return '<span class="dashicons dashicons-warning" aria-hidden="true"></span>';
	}
}
