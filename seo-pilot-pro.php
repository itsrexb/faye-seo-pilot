<?php
/**
 * Plugin Name:       SEO Pilot Pro
 * Plugin URI:        https://github.com/centraleffects/seo-pilot-pro
 * Description:       Audits WordPress posts and pages using AI, then proposes SEO-optimised titles, meta descriptions, and rewritten body content. Every suggestion is reviewed and approved field-by-field before any change is saved.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Rex Bengil
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-pilot-pro
 * Domain Path:       /languages
 *
 * @package SeoPilotPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEOPILOT_VERSION', '1.0.0' );
define( 'SEOPILOT_PLUGIN_FILE', __FILE__ );
define( 'SEOPILOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOPILOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOPILOT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SEOPILOT_MIN_WP', '6.3' );
define( 'SEOPILOT_MIN_PHP', '8.1' );

/**
 * PSR-4 style autoloader for the SeoPilotPro namespace.
 */
spl_autoload_register( function ( string $class ): void {
	$prefix = 'SeoPilotPro\\';
	$base   = SEOPILOT_PLUGIN_DIR . 'src/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = $base . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Bootstrap the plugin once WordPress and all plugins are loaded.
 */
add_action( 'plugins_loaded', function (): void {
	( new SeoPilotPro\Plugin() )->init();
} );

/**
 * Activation hook — install DB tables.
 */
register_activation_hook( __FILE__, function (): void {
	( new SeoPilotPro\Storage\Installer() )->install();
	flush_rewrite_rules();
} );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, function (): void {
	flush_rewrite_rules();
} );
