<?php
/**
 * Admin dashboard — menus, pages, and AJAX handlers.
 *
 * @package TahhanConflictDetective
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

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

	const PAGE_SLUG = 'tahhan-conflict-detective';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		// Top-level menu item.
		// Position 65.1 sits just after the Plugins menu (core uses 65) to avoid
		// a collision that would cause WordPress to silently increment our position.
		add_menu_page(
			__( 'Conflict Detective', 'tahhan-conflict-detective' ),
			__( 'Conflict Detective', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-search',
			65.1
		);

		// Add sub-pages so the tab links appear in the sidebar as well.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dashboard', 'tahhan-conflict-detective' ),
			__( 'Dashboard', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Conflict Scanner', 'tahhan-conflict-detective' ),
			__( 'Conflict Scanner', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=scanner',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Conflict Wizard', 'tahhan-conflict-detective' ),
			__( 'Conflict Wizard', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=wizard',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Error Log', 'tahhan-conflict-detective' ),
			__( 'Error Log', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=errors',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Change History', 'tahhan-conflict-detective' ),
			__( 'Change History', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=history',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Health Scan', 'tahhan-conflict-detective' ),
			__( 'Health Scan', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=scan',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Safe Mode', 'tahhan-conflict-detective' ),
			__( 'Safe Mode', 'tahhan-conflict-detective' ),
			'manage_options',
			self::PAGE_SLUG . '&tab=safe-mode',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'tahcd-admin',
			TAHCD_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			(string) filemtime( TAHCD_PLUGIN_DIR . 'admin/css/admin.css' )
		);

		wp_enqueue_script(
			'tahcd-admin',
			TAHCD_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			(string) filemtime( TAHCD_PLUGIN_DIR . 'admin/js/admin.js' ),
			true
		);

		wp_localize_script( 'tahcd-admin', 'tahcdData', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'tahcd_nonce' ),
			'scanning'      => __( 'Scanning…',          'tahhan-conflict-detective' ),
			'done'          => __( 'Scan complete!',      'tahhan-conflict-detective' ),
			'runScan'       => __( 'Run Scan Now',        'tahhan-conflict-detective' ),
			'clearing'      => __( 'Clearing…',           'tahhan-conflict-detective' ),
			'cleared'       => __( 'Log cleared.',        'tahhan-conflict-detective' ),
			'clearLog'      => __( 'Clear debug.log',     'tahhan-conflict-detective' ),
			'issuesFound'   => __( 'issues found',        'tahhan-conflict-detective' ),
			'unknownError'  => __( 'Unknown error',       'tahhan-conflict-detective' ),
			'requestFailed' => __( 'Request failed. Please try again.', 'tahhan-conflict-detective' ),
			'confirmClear'  => __( 'Are you sure you want to clear debug.log? This cannot be undone.', 'tahhan-conflict-detective' ),
			'couldNotClear' => __( 'Could not clear log.', 'tahhan-conflict-detective' ),
			'stopSafeMode'    => __( 'Stop Safe Mode',     'tahhan-conflict-detective' ),
			'startSafeMode'   => __( 'Start Safe Mode',    'tahhan-conflict-detective' ),
			'safeModeLoading' => __( 'Activating…',        'tahhan-conflict-detective' ),
			'safeModeStop'    => __( 'Stopping…',          'tahhan-conflict-detective' ),
		) );
	}

	/**
	 * Registers AJAX handlers — must be called on init/plugins_loaded.
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_tahcd_run_scan',   array( __CLASS__, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_tahcd_clear_log',  array( __CLASS__, 'ajax_clear_log' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_run_scan(): void {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

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
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$log_file = WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $log_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Log file not found.', 'tahhan-conflict-detective' ) ) );
		}

		// Use WP_Filesystem to write the file, as required by WordPress.org guidelines.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem->put_contents( $log_file, '', FS_CHMOD_FILE ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not clear log file (permission denied).', 'tahhan-conflict-detective' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'tahhan-conflict-detective' ) ) );
	}

	// -------------------------------------------------------------------------
	// Page router
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tahhan-conflict-detective' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		// Tab definitions: label + dashicon class.
		$tabs = array(
			'dashboard' => array( 'label' => __( 'Dashboard',       'tahhan-conflict-detective' ), 'icon' => 'dashicons-dashboard' ),
			'scanner'   => array( 'label' => __( 'Conflict Scanner','tahhan-conflict-detective' ), 'icon' => 'dashicons-search' ),
			'wizard'    => array( 'label' => __( 'Conflict Wizard', 'tahhan-conflict-detective' ), 'icon' => 'dashicons-editor-help' ),
			'errors'    => array( 'label' => __( 'Error Log',       'tahhan-conflict-detective' ), 'icon' => 'dashicons-warning' ),
			'history'   => array( 'label' => __( 'Change History',  'tahhan-conflict-detective' ), 'icon' => 'dashicons-backup' ),
			'scan'      => array( 'label' => __( 'Health Scan',     'tahhan-conflict-detective' ), 'icon' => 'dashicons-shield' ),
			'safe-mode' => array( 'label' => __( 'Safe Mode',       'tahhan-conflict-detective' ), 'icon' => 'dashicons-shield-alt' ),
		);

		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'dashboard';
		}

		echo '<div class="wrap pcd-wrap">';
		printf(
			'<h1 class="pcd-title"><span class="dashicons dashicons-search" aria-hidden="true"></span> %s</h1>',
			esc_html__( 'Conflict Detective', 'tahhan-conflict-detective' )
		);

		echo '<nav class="pcd-tabs" aria-label="' . esc_attr__( 'Sections', 'tahhan-conflict-detective' ) . '">';
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
			case 'scanner':   self::render_scanner();   break;
			case 'wizard':    Wizard::render();         break;
			case 'errors':    self::render_errors();    break;
			case 'history':   self::render_history();   break;
			case 'scan':      self::render_scan();      break;
			case 'safe-mode': self::render_safe_mode(); break;
			default:          self::render_dashboard();
		}
		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Dashboard tab
	// -------------------------------------------------------------------------

	private static function render_dashboard(): void {
		// Guard: if tables are missing (e.g. FTP deploy without activation),
		// run install now and show a one-time notice instead of raw DB errors.
		if ( ! Database::tables_exist() ) {
			Database::install();
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Conflict Detective: database tables were just created. Reload this page to see your data.', 'tahhan-conflict-detective' )
				. '</p></div>';
		}

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
			__( 'Active Plugins', 'tahhan-conflict-detective' ),
			'plugin',
			'neutral'
		);
		self::stat_card(
			(string) $total_changes,
			__( 'Changes Logged', 'tahhan-conflict-detective' ),
			'history',
			'neutral'
		);
		self::stat_card(
			(string) $total_errors,
			__( 'Log Entries', 'tahhan-conflict-detective' ),
			'error',
			$total_errors > 0 ? 'warning' : 'ok'
		);
		self::stat_card(
			(string) $total_issues,
			__( 'Health Issues', 'tahhan-conflict-detective' ),
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
					_n( '%d error attributed to this plugin', '%d errors attributed to this plugin', $culprit['error_count'], 'tahhan-conflict-detective' ),
					$culprit['error_count']
				) ),
				esc_html__( 'Last action:', 'tahhan-conflict-detective' ),
				esc_html( self::action_label( $culprit['action'] ) ),
				esc_html__( 'at', 'tahhan-conflict-detective' ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $culprit['changed_at'] ) ) ),
				esc_html__( 'Most likely cause of recent errors.', 'tahhan-conflict-detective' )
			);
		}

		// ── Dashboard grid: 4 cards in a 2×2 layout ─────────────────────────
		echo '<div class="pcd-dash-grid">';

		// System info
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'System Overview', 'tahhan-conflict-detective' ) . '</h2>';
		echo '<table class="pcd-table">';
		$rows = array(
			__( 'WordPress',    'tahhan-conflict-detective' ) => esc_html( $wp_version ),
			__( 'PHP',          'tahhan-conflict-detective' ) => esc_html( PHP_VERSION ),
			__( 'Theme',        'tahhan-conflict-detective' ) => esc_html( $theme->get( 'Name' ) . ' v' . $theme->get( 'Version' ) ),
			__( 'Memory Limit', 'tahhan-conflict-detective' ) => esc_html( WP_MEMORY_LIMIT ),
			__( 'Debug Mode',   'tahhan-conflict-detective' ) => ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				? '<span class="pcd-badge pcd-badge--warning">ON</span>'
				: '<span class="pcd-badge pcd-badge--ok">OFF</span>',
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
		}
		echo '</table></div>';

		// Active plugins
		echo '<div class="pcd-card">';
		printf(
			'<h2 class="pcd-card__title">%s <span class="pcd-count">%d</span></h2>',
			esc_html__( 'Active Plugins', 'tahhan-conflict-detective' ),
			absint( count( $active_plugins ) )
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

		// (grid continues — no wrapper change needed)

		// Recent changes
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Recent Changes', 'tahhan-conflict-detective' ) . '</h2>';
		if ( empty( $recent_changes ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet. Plugin changes are tracked automatically from the moment this plugin is activated.', 'tahhan-conflict-detective' ) . '</p>';
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
			esc_html__( 'View all changes', 'tahhan-conflict-detective' )
		);
		echo '</div>';

		// Recent errors
		echo '<div class="pcd-card">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Recent Errors', 'tahhan-conflict-detective' ) . '</h2>';
		if ( empty( $error_entries ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No errors found in the log file.', 'tahhan-conflict-detective' ) . '</p>';
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
			esc_html__( 'View all errors', 'tahhan-conflict-detective' )
		);
		echo '</div>';

		echo '</div>'; // .pcd-dash-grid
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
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Error Log', 'tahhan-conflict-detective' ) . '</h2>';

		// Clear log button.
		if ( $log_info['exists'] && $log_info['writable'] ) {
			printf(
				'<button id="pcd-clear-log" class="button pcd-btn-danger" type="button">%s</button>',
				esc_html__( 'Clear debug.log', 'tahhan-conflict-detective' )
			);
		}
		echo '</div>';

		// Log file status banner.
		echo '<div class="pcd-log-info">';
		if ( $log_info['exists'] ) {
			printf(
				'<span class="pcd-badge pcd-badge--ok">debug.log</span> <span class="pcd-log-meta">%s %s &middot; %s %s</span>',
				esc_html__( 'Size:', 'tahhan-conflict-detective' ),
				esc_html( size_format( $log_info['size'] ) ),
				esc_html__( 'Modified:', 'tahhan-conflict-detective' ),
				esc_html( $log_info['modified'] )
			);
		} else {
			echo '<span class="pcd-badge pcd-badge--info">' . esc_html__( 'No debug.log found', 'tahhan-conflict-detective' ) . '</span>';
			if ( ! $log_info['debug_enabled'] ) {
				echo '<p class="pcd-tip">' . esc_html__( 'Add to wp-config.php to enable logging:', 'tahhan-conflict-detective' ) . '<br>';
				echo '<code>define(\'WP_DEBUG\', true);<br>define(\'WP_DEBUG_LOG\', true);</code></p>';
			}
		}
		echo '</div>';

		if ( empty( $all ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No error entries found.', 'tahhan-conflict-detective' ) . '</p>';
			echo '</div>';
			return;
		}

		// Filter bar.
		$type_counts = array_count_values( array_column( $all, 'type' ) );
		echo '<div class="pcd-filter-bar" role="toolbar">';
		$filters = array(
			'all'        => __( 'All', 'tahhan-conflict-detective' ),
			'fatal'      => __( 'Fatal', 'tahhan-conflict-detective' ),
			'warning'    => __( 'Warning', 'tahhan-conflict-detective' ),
			'notice'     => __( 'Notice', 'tahhan-conflict-detective' ),
			'deprecated' => __( 'Deprecated', 'tahhan-conflict-detective' ),
		);
		foreach ( $filters as $key => $label ) {
			$count = $key === 'all' ? $total : ( $type_counts[ $key ] ?? 0 );
			printf(
				'<button class="pcd-filter-btn%s" data-filter="%s" type="button">%s <span class="pcd-filter-count">%d</span></button>',
				$key === 'all' ? ' pcd-filter-btn--active' : '',
				esc_attr( $key ),
				esc_html( $label ),
				absint( $count )
			);
		}
		echo '</div>';

		echo '<table class="pcd-errors-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Type',    'tahhan-conflict-detective' ),
			__( 'Time',    'tahhan-conflict-detective' ),
			__( 'Plugin',  'tahhan-conflict-detective' ),
			__( 'Message', 'tahhan-conflict-detective' ),
			__( 'File',    'tahhan-conflict-detective' ),
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
	// Conflict Scanner tab
	// -------------------------------------------------------------------------

	private static function render_scanner(): void {
		$suspects = Conflict_Scanner::analyse( 10 );

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Conflict Scanner', 'tahhan-conflict-detective' ) . '</h2>';
		printf(
			'<a href="%s" class="button button-primary">%s</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'wizard' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Open Wizard →', 'tahhan-conflict-detective' )
		);
		echo '</div>';

		echo '<p class="pcd-scanner-intro">' . esc_html__( 'The scanner analyses your plugin change history against the error log and ranks each plugin by how likely it is to be causing the current problem.', 'tahhan-conflict-detective' ) . '</p>';

		if ( empty( $suspects ) ) {
			echo '<div class="pcd-notice pcd-notice--info">';
			esc_html_e( 'No suspects found. This usually means there are no errors in the log, no plugin changes have been recorded yet, or the errors cannot be attributed to a specific plugin.', 'tahhan-conflict-detective' );
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
				$i === 0 ? '<span class="pcd-badge pcd-badge--error">' . esc_html__( 'Top suspect', 'tahhan-conflict-detective' ) . '</span>' : '',
				esc_attr( $bclass ),
				absint( $suspect['confidence'] ),
				esc_attr( $bclass ),
				absint( $bar ),
				esc_html( $suspect['reason'] ),
				esc_html__( 'Action', 'tahhan-conflict-detective' ),
				esc_html( self::action_label( $suspect['action'] ) ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $suspect['changed_at'] ) ) ),
				$suspect['error_count'] > 0
					? '<span>' . esc_html( sprintf(
						/* translators: %d: number of errors */
						_n( '%d error', '%d errors', $suspect['error_count'], 'tahhan-conflict-detective' ),
						$suspect['error_count']
					) ) . '</span>'
					: ''
			);
		}
		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Safe Mode tab
	// -------------------------------------------------------------------------

	private static function render_safe_mode(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_active      = Safe_Mode::is_active();
		$disabled       = Safe_Mode::get_disabled_plugins();
		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( $is_active ) {
			// ── Active state: amber banner + plugin list card ─────────────────────

			// Banner (outside the card, before the card).
			echo '<div class="pcd-safe-mode-banner">';
			echo '<div class="pcd-safe-mode-banner__body">';
			echo '<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>';
			echo '<div>';
			echo '<strong>' . esc_html__( 'Safe Mode Active', 'tahhan-conflict-detective' ) . '</strong>';
			printf(
				'<span>%s</span>',
				esc_html( sprintf(
					/* translators: %d number of disabled plugins */
					_n(
						'%d plugin disabled for your session — visitors see the full site.',
						'%d plugins disabled for your session — visitors see the full site.',
						count( $disabled ),
						'tahhan-conflict-detective'
					),
					count( $disabled )
				) )
			);
			echo '</div></div>';
			printf(
				'<button id="pcd-toggle-safe-mode" class="button pcd-btn-stop" type="button">%s</button>',
				esc_html__( 'Stop Safe Mode', 'tahhan-conflict-detective' )
			);
			echo '</div>'; // .pcd-safe-mode-banner

			// Plugin list card.
			echo '<div class="pcd-card pcd-card--full">';

			$testable = array_filter( $active_plugins, static function ( $file ) {
				return strpos( $file, 'tahhan-conflict-detective/tahhan-conflict-detective.php' ) === false;
			} );

			if ( empty( $testable ) ) {
				echo '<p class="pcd-empty">' . esc_html__( 'No other active plugins found.', 'tahhan-conflict-detective' ) . '</p>';
			} else {
				$disabled_count = count( $disabled );
				$total_count    = count( $testable );

				printf(
					'<p class="pcd-safe-mode-count">%s</p>',
					esc_html( sprintf(
						/* translators: 1: number of disabled plugins, 2: total plugins */
						__( '%1$d of %2$d plugins disabled', 'tahhan-conflict-detective' ),
						$disabled_count,
						$total_count
					) )
				);

				echo '<ul class="pcd-plugin-toggle-list">';
				foreach ( $testable as $plugin_file ) {
					$data        = $all_plugins[ $plugin_file ] ?? array( 'Name' => $plugin_file, 'Version' => '' );
					$is_disabled = in_array( $plugin_file, $disabled, true );

					$badge = $is_disabled
						? '<span class="pcd-badge pcd-badge--warning">OFF (test)</span>'
						: '<span class="pcd-badge pcd-badge--ok">ON</span>';

					printf(
						'<li class="pcd-plugin-toggle-item%s">
							<label class="pcd-toggle-switch" aria-label="%s">
								<input type="checkbox" class="pcd-plugin-toggle-input" data-plugin="%s"%s>
								<span class="pcd-toggle-slider"></span>
							</label>
							<span class="pcd-plugin-toggle-info">
								<strong>%s</strong>
								<span class="pcd-version">v%s</span>
							</span>
							<span class="pcd-toggle-label">%s</span>
						</li>',
						esc_attr( $is_disabled ? ' pcd-plugin-toggle-item--off' : '' ),
						esc_attr( $data['Name'] ),
						esc_attr( $plugin_file ),
						$is_disabled ? '' : ' checked',
						esc_html( $data['Name'] ),
						esc_html( $data['Version'] ),
						wp_kses_post( $badge )
					);
				}
				echo '</ul>';
			}

			echo '</div>'; // .pcd-card

		} else {
			// ── Inactive state: single card with Start button + how-to steps ─────

			echo '<div class="pcd-card pcd-card--full">';
			echo '<div class="pcd-card__header">';
			echo '<h2 class="pcd-card__title">' . esc_html__( 'Safe Testing Mode', 'tahhan-conflict-detective' ) . '</h2>';
			printf(
				'<button id="pcd-toggle-safe-mode" class="button button-primary" type="button">%s</button>',
				esc_html__( 'Start Safe Mode', 'tahhan-conflict-detective' )
			);
			echo '</div>';

			echo '<p class="pcd-safe-mode-tip">'
				. esc_html__( 'Safe Mode lets you disable plugins for your admin session only — visitors see the site completely normally. Enable Safe Mode, then use the toggles below to deactivate plugins one by one and check if your problem disappears.', 'tahhan-conflict-detective' )
				. '</p>';

			echo '<ol class="pcd-safe-mode-steps">';
			printf( '<li>%s</li>', wp_kses_post( sprintf(
				/* translators: button label in bold */
				__( 'Click <strong>%s</strong>', 'tahhan-conflict-detective' ),
				__( 'Start Safe Mode', 'tahhan-conflict-detective' )
			) ) );
			echo '<li>' . esc_html__( 'Disable the plugins you want to test using the toggles that appear', 'tahhan-conflict-detective' ) . '</li>';
			echo '<li>' . esc_html__( 'Browse your site — did the problem disappear?', 'tahhan-conflict-detective' ) . '</li>';
			printf( '<li>%s</li>', wp_kses_post( sprintf(
				/* translators: button label in bold */
				__( 'Click <strong>%s</strong> when you are done testing', 'tahhan-conflict-detective' ),
				__( 'Stop Safe Mode', 'tahhan-conflict-detective' )
			) ) );
			echo '</ol>';

			echo '</div>'; // .pcd-card
		}
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
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Plugin Change History', 'tahhan-conflict-detective' ) . '</h2>';

		if ( empty( $all ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No changes logged yet. Changes are tracked from activation.', 'tahhan-conflict-detective' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="pcd-history-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Date & Time', 'tahhan-conflict-detective' ),
			__( 'Plugin',      'tahhan-conflict-detective' ),
			__( 'Action',      'tahhan-conflict-detective' ),
			__( 'Version',     'tahhan-conflict-detective' ),
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
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Health Scan', 'tahhan-conflict-detective' ) . '</h2>';
		printf(
			'<button id="pcd-run-scan" class="button button-primary" type="button">%s</button>',
			esc_html__( 'Run Scan Now', 'tahhan-conflict-detective' )
		);
		echo '</div>';

		echo '<div id="pcd-scan-status"></div>';

		if ( $last_scan ) {
			printf(
				'<p class="pcd-scan-meta" id="pcd-scan-meta">%s %s &nbsp;|&nbsp; <strong>%s</strong> %s</p>',
				esc_html__( 'Last scan:', 'tahhan-conflict-detective' ),
				esc_html( date_i18n( 'd-m-Y H:i', strtotime( $last_scan['scanned_at'] ) ) ),
				esc_html( (string) $last_scan['issues_found'] ),
				/* translators: %d: number of issues */
				esc_html( _n( 'issue found', 'issues found', $last_scan['issues_found'], 'tahhan-conflict-detective' ) )
			);
		}

		echo '<div id="pcd-scan-results">';
		if ( $last_scan ) {
			echo self::build_scan_results_html( $last_scan ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_scan_results_html() escapes all output internally
		} else {
			echo '<p class="pcd-empty">' . esc_html__( 'No scan run yet. Click "Run Scan Now" to start.', 'tahhan-conflict-detective' ) . '</p>';
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
			__( 'Plugins', 'tahhan-conflict-detective' ) => $data['plugins'] ?? array(),
			__( 'Theme',   'tahhan-conflict-detective' ) => $data['theme']   ?? array(),
			__( 'Server',  'tahhan-conflict-detective' ) => $data['server']  ?? array(),
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
						__( 'No issues found in %s.', 'tahhan-conflict-detective' ),
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
				absint( $i )
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
			'activated'   => __( 'Activated',   'tahhan-conflict-detective' ),
			'deactivated' => __( 'Deactivated', 'tahhan-conflict-detective' ),
			'updated'     => __( 'Updated',     'tahhan-conflict-detective' ),
			'deleted'     => __( 'Deleted',     'tahhan-conflict-detective' ),
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
