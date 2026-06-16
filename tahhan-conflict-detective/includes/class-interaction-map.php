<?php
/**
 * Plugin Interaction Map — visualises plugin dependency clusters.
 *
 * Reads all installed plugins, checks the `Requires Plugins` header
 * (WP 6.5+ plugin dependencies) and matches slugs against a built-in
 * `KNOWN_ECOSYSTEMS` map to group them into ecosystem clusters.
 *
 * Renders as a pure HTML/CSS flexbox tree — no JavaScript library required.
 *
 * @package TahhanConflictDetective
 * @since   2.6.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and renders the plugin interaction / dependency map.
 *
 * @since 2.6.0
 */
final class Interaction_Map {

	/**
	 * Maps plugin slugs (the folder/file part before the first slash) to their
	 * ecosystem group label. Add more entries here as ecosystems expand.
	 *
	 * @var array<string, string>
	 */
	const KNOWN_ECOSYSTEMS = array(
		// WooCommerce.
		'woocommerce'                           => 'WooCommerce',
		'woocommerce-gateway-stripe'            => 'WooCommerce',
		'woocommerce-paypal-payments'           => 'WooCommerce',
		'woocommerce-subscriptions'             => 'WooCommerce',
		'woocommerce-memberships'               => 'WooCommerce',
		'woocommerce-bookings'                  => 'WooCommerce',
		'woocommerce-product-bundles'           => 'WooCommerce',
		'woocommerce-sequential-order-numbers'  => 'WooCommerce',
		'woo-gutenberg-products-block'          => 'WooCommerce',
		'automatewoo'                           => 'WooCommerce',
		'woocommerce-pdf-invoices-packing-slips' => 'WooCommerce',

		// Elementor.
		'elementor'                             => 'Elementor',
		'elementor-pro'                         => 'Elementor',
		'elementor-addon-elements'              => 'Elementor',
		'essential-addons-for-elementor-lite'   => 'Elementor',
		'ultimate-addons-for-elementor'         => 'Elementor',
		'elementor-header-footer-builder'       => 'Elementor',
		'anywhere-elementor'                    => 'Elementor',
		'dynamic-content-for-elementor'         => 'Elementor',
		'jetwidgets-for-elementor'              => 'Elementor',
		'powerpack-elements'                    => 'Elementor',

		// Yoast.
		'wordpress-seo'                         => 'Yoast SEO',
		'wordpress-seo-premium'                 => 'Yoast SEO',
		'wpseo-news'                            => 'Yoast SEO',
		'wpseo-video'                           => 'Yoast SEO',
		'wpseo-woocommerce'                     => 'Yoast SEO',
		'wpseo-local'                           => 'Yoast SEO',

		// Jetpack.
		'jetpack'                               => 'Jetpack',
		'jetpack-backup'                        => 'Jetpack',
		'jetpack-boost'                         => 'Jetpack',
		'jetpack-search'                        => 'Jetpack',
		'jetpack-social'                        => 'Jetpack',
		'jetpack-protect'                       => 'Jetpack',

		// ACF.
		'advanced-custom-fields'                => 'ACF',
		'advanced-custom-fields-pro'            => 'ACF',
		'acf-to-rest-api'                       => 'ACF',
		'acf-extended'                          => 'ACF',
		'acf-content-analysis-for-yoast-seo'    => 'ACF',

		// Gravity Forms.
		'gravityforms'                          => 'Gravity Forms',
		'gravityformsmailchimp'                 => 'Gravity Forms',
		'gravityformszapier'                    => 'Gravity Forms',
		'gravityformsstripe'                    => 'Gravity Forms',
		'gravityformspolls'                     => 'Gravity Forms',
		'gravityformscoupons'                   => 'Gravity Forms',

		// Contact Form 7.
		'contact-form-7'                        => 'Contact Form 7',
		'flamingo'                              => 'Contact Form 7',
		'cf7-honeypot'                          => 'Contact Form 7',
		'contact-form-7-to-database-extension'  => 'Contact Form 7',

		// WPML.
		'sitepress-multilingual-cms'            => 'WPML',
		'wpml-string-translation'               => 'WPML',
		'wpml-translation-management'           => 'WPML',
		'woocommerce-multilingual'              => 'WPML',

		// BuddyPress / BuddyBoss.
		'buddypress'                            => 'BuddyPress',
		'bp-better-messages'                    => 'BuddyPress',
		'buddyboss-platform'                    => 'BuddyBoss',
		'buddyboss-theme'                       => 'BuddyBoss',

		// LearnDash.
		'sfwd-lms'                              => 'LearnDash',
		'learndash-course-grid'                 => 'LearnDash',
		'learndash-woocommerce'                 => 'LearnDash',

		// Divi / Extra.
		'divi-builder'                          => 'Divi',
		'extra'                                 => 'Divi',
		'monarch'                               => 'Divi',

		// WP Rocket.
		'wp-rocket'                             => 'WP Rocket',
		'imagify'                               => 'WP Rocket',
		'rocketcdn-plugin'                      => 'WP Rocket',
	);

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/**
	 * Builds a structured dependency/ecosystem map from all installed plugins.
	 *
	 * Returns an array with two top-level keys:
	 *   'ecosystems'  — array<string, array<string, mixed>>  (ecosystem name => plugins)
	 *   'standalone'  — array<string, array<string, mixed>>  (plugin basename => plugin data)
	 *
	 * @return array<string, mixed>
	 */
	public static function build_map(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$ecosystems     = array();
		$standalone     = array();

		foreach ( $all_plugins as $basename => $data ) {
			$slug      = self::slug_from_basename( $basename );
			$ecosystem = self::KNOWN_ECOSYSTEMS[ $slug ] ?? null;

			// Also check WP 6.5+ `Requires Plugins` header for explicit dependencies.
			$requires = isset( $data['RequiresPlugins'] ) ? (string) $data['RequiresPlugins'] : '';
			if ( '' === $requires ) {
				// Some versions may use 'Requires Plugins' with a space.
				$requires = isset( $data['Requires Plugins'] ) ? (string) $data['Requires Plugins'] : '';
			}

			$required_slugs = array_filter( array_map( 'trim', explode( ',', $requires ) ) );

			$plugin_entry = array(
				'basename'       => $basename,
				'name'           => $data['Name']    ?? $basename,
				'version'        => $data['Version'] ?? '',
				'active'         => in_array( $basename, $active_plugins, true ),
				'slug'           => $slug,
				'requires'       => $required_slugs,
			);

			if ( null !== $ecosystem ) {
				if ( ! isset( $ecosystems[ $ecosystem ] ) ) {
					$ecosystems[ $ecosystem ] = array();
				}
				$ecosystems[ $ecosystem ][ $basename ] = $plugin_entry;
			} else {
				$standalone[ $basename ] = $plugin_entry;
			}
		}

		// Sort ecosystems alphabetically.
		ksort( $ecosystems );

		// Sort standalone plugins by name.
		uasort( $standalone, static function ( $a, $b ) {
			return strcmp( (string) $a['name'], (string) $b['name'] );
		} );

		return array(
			'ecosystems' => $ecosystems,
			'standalone' => $standalone,
		);
	}

	/**
	 * Extracts the plugin slug (folder name) from a plugin basename.
	 * For single-file plugins (no slash) the full filename minus .php is used.
	 *
	 * @param  string $basename Plugin basename, e.g. "woocommerce/woocommerce.php".
	 * @return string
	 */
	private static function slug_from_basename( string $basename ): string {
		if ( strpos( $basename, '/' ) !== false ) {
			return explode( '/', $basename )[0];
		}
		return str_replace( '.php', '', $basename );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Plugin Interaction Map tab.
	 *
	 * @return void
	 */
	public static function render(): void {
		$map = self::build_map();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Plugin Interaction Map', 'tahhan-conflict-detective' ) . '</h2>';
		echo '</div>';

		echo '<p class="pcd-scanner-intro">'
			. esc_html__( 'Shows how your plugins are grouped into known ecosystems (WooCommerce, Elementor, Yoast, etc.) and highlights their declared dependencies. Ecosystem clusters are more likely to interact — and potentially conflict — with each other.', 'tahhan-conflict-detective' )
			. '</p>';

		$ecosystems = $map['ecosystems'];
		$standalone = $map['standalone'];

		echo '<div class="tahcd-interaction-tree">';

		// ── Ecosystem clusters ────────────────────────────────────────────────

		if ( ! empty( $ecosystems ) ) {
			echo '<div class="tahcd-tree-section">';
			echo '<h3 class="tahcd-tree-section__title">'
				. esc_html__( 'Ecosystem Clusters', 'tahhan-conflict-detective' )
				. '</h3>';
			echo '<div class="tahcd-ecosystem-grid">';

			foreach ( $ecosystems as $ecosystem_name => $plugins ) {
				$active_count = count( array_filter( $plugins, static function ( $p ) {
					return (bool) $p['active'];
				} ) );
				$total_count  = count( $plugins );

				echo '<div class="tahcd-ecosystem-cluster">';
				printf(
					'<div class="tahcd-cluster-header">
						<span class="tahcd-cluster-name">%s</span>
						<span class="pcd-badge pcd-badge--info">%s</span>
					</div>',
					esc_html( $ecosystem_name ),
					esc_html( sprintf(
						/* translators: 1: active count, 2: total count */
						__( '%1$d / %2$d active', 'tahhan-conflict-detective' ),
						$active_count,
						$total_count
					) )
				);

				echo '<ul class="tahcd-cluster-plugins">';
				foreach ( $plugins as $plugin ) {
					$active_class  = $plugin['active'] ? 'tahcd-plugin-node--active' : 'tahcd-plugin-node--inactive';
					$active_label  = $plugin['active']
						? '<span class="pcd-badge pcd-badge--ok">' . esc_html__( 'Active', 'tahhan-conflict-detective' ) . '</span>'
						: '<span class="pcd-badge pcd-badge--action-deactivated">' . esc_html__( 'Inactive', 'tahhan-conflict-detective' ) . '</span>';

					printf(
						'<li class="tahcd-plugin-node %s">
							<span class="tahcd-plugin-node__name">%s</span>
							<span class="tahcd-plugin-node__ver">v%s</span>
							%s
						</li>',
						esc_attr( $active_class ),
						esc_html( (string) $plugin['name'] ),
						esc_html( (string) $plugin['version'] ),
						$active_label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above
					);

					// Show declared dependencies if any.
					if ( ! empty( $plugin['requires'] ) ) {
						echo '<ul class="tahcd-dep-list">';
						foreach ( $plugin['requires'] as $dep_slug ) {
							printf(
								'<li class="tahcd-dep-node"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span> %s</li>',
								esc_html( (string) $dep_slug )
							);
						}
						echo '</ul>';
					}
				}
				echo '</ul>';
				echo '</div>'; // .tahcd-ecosystem-cluster
			}

			echo '</div>'; // .tahcd-ecosystem-grid
			echo '</div>'; // .tahcd-tree-section
		}

		// ── Standalone plugins ────────────────────────────────────────────────

		if ( ! empty( $standalone ) ) {
			$active_standalone   = array_filter( $standalone, static function ( $p ) { return (bool) $p['active']; } );
			$inactive_standalone = array_filter( $standalone, static function ( $p ) { return ! (bool) $p['active']; } );

			echo '<div class="tahcd-tree-section">';
			printf(
				'<h3 class="tahcd-tree-section__title">%s <span class="pcd-count">%d</span></h3>',
				esc_html__( 'Standalone Plugins', 'tahhan-conflict-detective' ),
				absint( count( $standalone ) )
			);

			if ( ! empty( $active_standalone ) ) {
				echo '<div class="tahcd-standalone-grid">';
				foreach ( $active_standalone as $plugin ) {
					self::render_standalone_card( $plugin, true );
				}
				echo '</div>';
			}

			if ( ! empty( $inactive_standalone ) ) {
				echo '<details class="tahcd-inactive-wrap">';
				printf(
					'<summary class="tahcd-inactive-summary">%s</summary>',
					esc_html( sprintf(
						/* translators: %d: number of inactive plugins */
						__( 'Show %d inactive standalone plugins', 'tahhan-conflict-detective' ),
						count( $inactive_standalone )
					) )
				);
				echo '<div class="tahcd-standalone-grid">';
				foreach ( $inactive_standalone as $plugin ) {
					self::render_standalone_card( $plugin, false );
				}
				echo '</div>';
				echo '</details>';
			}

			echo '</div>'; // .tahcd-tree-section
		}

		echo '</div>'; // .tahcd-interaction-tree
		echo '</div>'; // .pcd-card
	}

	/**
	 * Renders a single standalone plugin card.
	 *
	 * @param  array<string, mixed> $plugin Plugin entry from build_map().
	 * @param  bool                 $active Whether the plugin is active.
	 * @return void
	 */
	private static function render_standalone_card( array $plugin, bool $active ): void {
		$status_badge = $active
			? '<span class="pcd-badge pcd-badge--ok">' . esc_html__( 'Active', 'tahhan-conflict-detective' ) . '</span>'
			: '<span class="pcd-badge pcd-badge--action-deactivated">' . esc_html__( 'Inactive', 'tahhan-conflict-detective' ) . '</span>';

		$extra_class = $active ? 'tahcd-standalone-card--active' : 'tahcd-standalone-card--inactive';

		printf(
			'<div class="tahcd-standalone-card %s">
				<div class="tahcd-standalone-card__header">
					<strong class="tahcd-standalone-card__name">%s</strong>
					%s
				</div>
				<span class="pcd-version">v%s</span>',
			esc_attr( $extra_class ),
			esc_html( (string) $plugin['name'] ),
			$status_badge, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
			esc_html( (string) $plugin['version'] )
		);

		// Declared dependencies.
		if ( ! empty( $plugin['requires'] ) ) {
			echo '<ul class="tahcd-dep-list tahcd-dep-list--card">';
			foreach ( $plugin['requires'] as $dep ) {
				printf(
					'<li class="tahcd-dep-node"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span> %s</li>',
					esc_html( (string) $dep )
				);
			}
			echo '</ul>';
		}

		echo '</div>'; // .tahcd-standalone-card
	}
}
