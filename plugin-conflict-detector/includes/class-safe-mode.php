<?php
/**
 * Safe Testing Mode — disables selected plugins for the admin only.
 * Visitors are completely unaffected.
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
 * How Safe Mode works
 * --------------------
 * 1. Admin enables Safe Mode → a signed token is stored in user meta
 *    and set as a secure HTTP-only cookie.
 * 2. On EVERY request (front end and back end), pre_option_active_plugins
 *    fires very early.  We check the cookie, verify the token against the
 *    stored user meta, and if valid we strip the admin's "disabled" plugins
 *    from the active list before WordPress loads them.
 * 3. Because the cookie is only present for the admin's browser, all other
 *    visitors load the site completely normally.
 * 4. The admin tests the site, then disables Safe Mode — the cookie is
 *    deleted and normal plugin loading resumes immediately.
 *
 * Security notes
 * ---------------
 * - The cookie value is a 32-byte random token (not the user ID or a guessable value).
 * - The token is stored in user meta and must match the cookie on every request.
 * - The cookie is HttpOnly, SameSite=Strict, and Secure when the site is HTTPS.
 *
 * @since 2.0.0
 */
final class Safe_Mode {

	const COOKIE_NAME       = 'pcd_safe_mode';
	const META_TOKEN        = '_pcd_safe_token';
	const META_DISABLED     = '_pcd_disabled_plugins';
	const COOKIE_EXPIRY     = HOUR_IN_SECONDS * 8;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public static function init(): void {
		// Must run as early as possible so the filter fires before plugins load.
		add_filter( 'pre_option_active_plugins', array( __CLASS__, 'maybe_filter_active_plugins' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_pcd_safe_mode_toggle',         array( __CLASS__, 'ajax_toggle_mode' ) );
		add_action( 'wp_ajax_pcd_safe_mode_toggle_plugin',  array( __CLASS__, 'ajax_toggle_plugin' ) );
	}

	// -------------------------------------------------------------------------
	// Active-plugins filter (runs on every request, front + back end)
	// -------------------------------------------------------------------------

	/**
	 * Filters the active_plugins option for the admin in Safe Mode.
	 * Returns $pre_option unchanged for all other visitors.
	 *
	 * @param  mixed $pre_option  Passed-through by WP filter chain (usually null).
	 * @return mixed
	 */
	public static function maybe_filter_active_plugins( $pre_option ) {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return $pre_option;
		}

		$cookie_token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$user_id      = self::verify_token( $cookie_token );

		if ( ! $user_id ) {
			return $pre_option;
		}

		$disabled = get_user_meta( $user_id, self::META_DISABLED, true );
		if ( empty( $disabled ) || ! is_array( $disabled ) ) {
			return $pre_option;
		}

		// Return the real option minus the disabled list.
		$active = get_option( 'active_plugins', array() );
		return array_values( array_diff( (array) $active, $disabled ) );
	}

	// -------------------------------------------------------------------------
	// Public state helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the current user has Safe Mode active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return false;
		}
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		return (bool) self::verify_token( $token );
	}

	/**
	 * Returns the list of plugins disabled for the current admin in Safe Mode.
	 *
	 * @return string[]
	 */
	public static function get_disabled_plugins(): array {
		if ( ! self::is_active() ) {
			return array();
		}
		$disabled = get_user_meta( get_current_user_id(), self::META_DISABLED, true );
		return is_array( $disabled ) ? $disabled : array();
	}

	// -------------------------------------------------------------------------
	// Enable / Disable
	// -------------------------------------------------------------------------

	/**
	 * Enables Safe Mode for the current user.
	 *
	 * @return bool True on success.
	 */
	public static function enable(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$token   = bin2hex( random_bytes( 32 ) );
		$user_id = get_current_user_id();

		update_user_meta( $user_id, self::META_TOKEN,    $token );
		update_user_meta( $user_id, self::META_DISABLED, array() );

		self::set_cookie( $token );

		return true;
	}

	/**
	 * Disables Safe Mode for the current user and clears all test data.
	 *
	 * @return void
	 */
	public static function disable(): void {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_TOKEN );
		delete_user_meta( $user_id, self::META_DISABLED );
		self::clear_cookie();
	}

	/**
	 * Toggles a single plugin in the disabled list.
	 *
	 * @param  string $plugin_file Relative path (e.g. woocommerce/woocommerce.php).
	 * @return bool   True = now disabled, false = now enabled.
	 */
	public static function toggle_plugin( string $plugin_file ): bool {
		$user_id  = get_current_user_id();
		$disabled = get_user_meta( $user_id, self::META_DISABLED, true );
		$disabled = is_array( $disabled ) ? $disabled : array();

		if ( in_array( $plugin_file, $disabled, true ) ) {
			$disabled = array_values( array_diff( $disabled, array( $plugin_file ) ) );
			$result   = false; // now enabled
		} else {
			$disabled[] = $plugin_file;
			$result     = true; // now disabled
		}

		update_user_meta( $user_id, self::META_DISABLED, $disabled );
		return $result;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_toggle_mode(): void {
		check_ajax_referer( 'pcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		if ( self::is_active() ) {
			self::disable();
			wp_send_json_success( array( 'active' => false, 'message' => __( 'Safe Mode disabled. All plugins are active again.', 'plugin-conflict-detector' ) ) );
		} else {
			self::enable();
			wp_send_json_success( array( 'active' => true, 'message' => __( 'Safe Mode enabled. You can now disable plugins for testing.', 'plugin-conflict-detector' ) ) );
		}
	}

	public static function ajax_toggle_plugin(): void {
		check_ajax_referer( 'pcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		if ( ! self::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Safe Mode is not active.', 'plugin-conflict-detector' ) ) );
		}

		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		if ( empty( $plugin_file ) ) {
			wp_send_json_error( array( 'message' => 'Invalid plugin.' ) );
		}

		// Validate the supplied slug against the actual list of installed plugins
		// so only real plugin files can be stored in user meta.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! array_key_exists( $plugin_file, get_plugins() ) ) {
			wp_send_json_error( array( 'message' => 'Unknown plugin.' ) );
		}

		$now_disabled = self::toggle_plugin( $plugin_file );
		wp_send_json_success( array(
			'disabled' => $now_disabled,
			'message'  => $now_disabled
				? sprintf( __( '"%s" disabled for your session.', 'plugin-conflict-detector' ), $plugin_file )
				: sprintf( __( '"%s" re-enabled.', 'plugin-conflict-detector' ), $plugin_file ),
		) );
	}

	// -------------------------------------------------------------------------
	// Cookie helpers
	// -------------------------------------------------------------------------

	private static function set_cookie( string $token ): void {
		$secure = is_ssl();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie(
			self::COOKIE_NAME,
			$token,
			array(
				'expires'  => time() + self::COOKIE_EXPIRY,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		// Also set in the superglobal so is_active() works in the same request.
		$_COOKIE[ self::COOKIE_NAME ] = $token;
	}

	private static function clear_cookie(): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie( self::COOKIE_NAME, '', array(
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		) );
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	/**
	 * Verifies a cookie token and returns the owning user ID, or 0 on failure.
	 *
	 * @param  string $token Cookie value to verify.
	 * @return int           User ID on success, 0 on failure.
	 */
	private static function verify_token( string $token ): int {
		if ( strlen( $token ) !== 64 ) { // 32 bytes → 64 hex chars
			return 0;
		}

		// We need user meta lookup. get_users() is available at pre_option stage.
		$users = get_users( array(
			'meta_key'   => self::META_TOKEN,  // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $token,            // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => 1,
			'fields'     => 'ID',
		) );

		if ( empty( $users ) ) {
			return 0;
		}

		return (int) $users[0];
	}
}
