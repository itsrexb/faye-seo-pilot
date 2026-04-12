<?php
/**
 * Uninstall — runs when the plugin is deleted from the WP admin.
 * Removes all custom tables and plugin options.
 *
 * @package SeoPilotPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Storage/Installer.php';

( new SeoPilotPro\Storage\Installer() )->uninstall();
