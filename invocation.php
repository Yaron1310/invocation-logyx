<?php
/**
 * Plugin Name:       Invocation - AI Page & Section Builder
 * Plugin URI:        https://danilob.codes
 * Description:       Build styled Gutenberg page content with AI, using your own block theme. Brings your own AI provider via WordPress Connectors.
 * Version:           0.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Danilo Benovic
 * Author URI:        https://danilob.codes
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       invocation
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INVOCATION_VERSION', '0.2.0' );
define( 'INVOCATION_FILE', __FILE__ );
define( 'INVOCATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'INVOCATION_URL', plugin_dir_url( __FILE__ ) );

// Load bundled dependencies (the WordPress MCP Adapter) via the Jetpack
// Autoloader, so they coexist with any other plugin shipping the same packages.
if ( is_readable( INVOCATION_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once INVOCATION_DIR . 'vendor/autoload_packages.php';
}

/**
 * Verify the runtime dependencies Invocation relies on from WordPress 7.0 core:
 * the Abilities API and the PHP AI Client. If anything is missing we bail early
 * and surface an actionable admin notice instead of fataling.
 *
 * @return bool True when all dependencies are present.
 */
function invocation_check_dependencies(): bool {
	$missing = array();

	if ( ! class_exists( 'WP_Ability' ) ) {
		$missing[] = __( 'the Abilities API', 'invocation' );
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		$missing[] = __( 'the AI Client', 'invocation' );
	}

	if ( empty( $missing ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $missing ): void {
			$message = sprintf(
				/* translators: %s: comma-separated list of missing WordPress features. */
				esc_html__( 'Invocation requires WordPress 7.0 or later. Missing: %s.', 'invocation' ),
				esc_html( implode( ', ', $missing ) )
			);
			wp_admin_notice( $message, array( 'type' => 'error' ) );
		}
	);

	return false;
}

/**
 * Boot the plugin once we know the platform can support it.
 */
function invocation_bootstrap(): void {
	if ( ! invocation_check_dependencies() ) {
		return;
	}

	require_once INVOCATION_DIR . 'inc/abilities.php';
	require_once INVOCATION_DIR . 'inc/search-media.php';
	require_once INVOCATION_DIR . 'inc/internal-links.php';
	require_once INVOCATION_DIR . 'inc/patterns.php';
	require_once INVOCATION_DIR . 'inc/context.php';
	require_once INVOCATION_DIR . 'inc/generate-layout.php';
	require_once INVOCATION_DIR . 'inc/refine-block.php';
	require_once INVOCATION_DIR . 'inc/pages.php';
	require_once INVOCATION_DIR . 'inc/templates.php';
	require_once INVOCATION_DIR . 'inc/save-pattern.php';
	require_once INVOCATION_DIR . 'inc/site-brief.php';
	require_once INVOCATION_DIR . 'inc/mcp.php';
	require_once INVOCATION_DIR . 'inc/admin.php';
	require_once INVOCATION_DIR . 'inc/editor.php';
}
add_action( 'plugins_loaded', 'invocation_bootstrap' );
