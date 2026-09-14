<?php
/**
 * Plugin Name:       AIWP Designer
 * Plugin URI:        https://example.com/aiwp-designer
 * Description:       AI-native website design system for WordPress. Your own AI (Claude / ChatGPT) designs pages through MCP; the plugin is the trusted execution environment.
 * Version:           0.11.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            AIWP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aiwp-designer
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

namespace AIWP\Designer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIWP_VERSION', '0.11.0' );
define( 'AIWP_PLUGIN_FILE', __FILE__ );
define( 'AIWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIWP_TEMPLATE_LANGUAGE_VERSION', 1 );
define( 'AIWP_MCP_PROTOCOL_VERSION', '2026-07-28' );
define( 'AIWP_REST_NAMESPACE', 'aiwp-designer/v1' );

// Composer autoloader when present, otherwise the bundled PSR-4 loader.
if ( file_exists( AIWP_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AIWP_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	require_once AIWP_PLUGIN_DIR . 'src/autoload.php';
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);
