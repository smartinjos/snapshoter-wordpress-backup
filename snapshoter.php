<?php
/**
 * Plugin Name: Snapshoter: Free Unlimited Backup and Restore
 * Plugin URI: https://www.smartin.in/wordpress-backup-and-migration/
 * Description: Backup and restore your WordPress site as a single .smartin archive. Migrate to another domain with automatic URL rewriting.
 * Version: 1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Snapshoter
 * Author URI: https://www.smartin.in
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: snapshoter
 */

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

if (version_compare(PHP_VERSION, '7.4', '<')) {
	if (function_exists('add_action')) {
		add_action(
			'admin_notices',
			function () {
				if (!current_user_can('activate_plugins')) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
					esc_html__('Snapshoter requires PHP 7.4 or later.', 'snapshoter'),
					esc_html(
						sprintf(

							// translators: %s: Currently installed PHP version
							__('This server is running PHP %s. Please ask your host to upgrade PHP (8.2 or later is recommended), then reactivate Snapshoter.', 'snapshoter'),
							PHP_VERSION
						)
					)
				);
			}
		);
	}
	return;
}

if (defined('SNAPSHOTER_LOADED')) {
	if (!function_exists('SNAPSHOTER_duplicate_notice')) {
		function SNAPSHOTER_duplicate_notice()
		{
			if (!current_user_can('manage_options')) {
				return;
			}

			$active_plugins = get_option('active_plugins', array());
			$duplicates = array();

			foreach ($active_plugins as $plugin) {
				if (strpos($plugin, 'snapshoter') !== false) {
					$duplicates[] = $plugin;
				}
			}

			if (count($duplicates) > 1) {
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
					esc_html__('Multiple snapshoter installations detected!', 'snapshoter'),
					esc_html__('Please deactivate all but one instance to prevent conflicts.', 'snapshoter'),
					esc_html__('Go to Plugins → Installed Plugins and deactivate duplicate installations.', 'snapshoter')
				);
			}
		}
		add_action('admin_notices', 'SNAPSHOTER_duplicate_notice');
	}
	return;
}

define('SNAPSHOTER_LOADED', true);

if (!class_exists('ZipArchive')) {
	if (function_exists('add_action')) {
		add_action(
			'admin_notices',
			function () {
				if (!current_user_can('activate_plugins')) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
					esc_html__('Snapshoter needs the PHP zip extension (ext-zip) to back up and restore your site.', 'snapshoter'),
					esc_html__('Please ask your host to enable the zip extension for PHP, then reactivate Snapshoter. On most cPanel hosts this is a one-click toggle under "Select PHP Version" → "Extensions".', 'snapshoter')
				);
			}
		);
	}

	return;
}

$SNAPSHOTER_entry_file = defined('SNAPSHOTER_ENTRY_FILE') ? SNAPSHOTER_ENTRY_FILE : __FILE__;

$SNAPSHOTER_base_path = defined('SNAPSHOTER_BASE_PATH') ? SNAPSHOTER_BASE_PATH : plugin_dir_path(__FILE__);

$SNAPSHOTER_base_url = defined('SNAPSHOTER_BASE_URL') ? SNAPSHOTER_BASE_URL : plugin_dir_url(__FILE__);

if (!defined('SNAPSHOTER_FILE')) {
	define('SNAPSHOTER_FILE', $SNAPSHOTER_entry_file);
}

if (!defined('SNAPSHOTER_PATH')) {
	define('SNAPSHOTER_PATH', $SNAPSHOTER_base_path);
}

if (!defined('SNAPSHOTER_URL')) {
	define('SNAPSHOTER_URL', $SNAPSHOTER_base_url);
}

$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
if (in_array($action, array('SNAPSHOTER_run_job', 'SNAPSHOTER_upload_chunk', 'SNAPSHOTER_get_status'), true)) {
	if (!defined('DIEONDBERROR')) {

		define('DIEONDBERROR', false);
	}
}

require_once SNAPSHOTER_PATH . 'includes/constants.php';
require_once SNAPSHOTER_PATH . 'includes/Autoloader.php';

Snapshoter\Autoloader::register(SNAPSHOTER_PATH . 'includes/');

if (function_exists('is_multisite') && is_multisite()) {
	register_activation_hook(
		SNAPSHOTER_FILE,
		static function () {
			if (!function_exists('deactivate_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins(plugin_basename(SNAPSHOTER_FILE), true);
			wp_die(
				esc_html__('Snapshoter does not support WordPress Multisite. It only works on single-site installs.', 'snapshoter'),
				esc_html__('Plugin activation blocked', 'snapshoter'),
				array('back_link' => true)
			);
		}
	);

	$snapshoter_ms_notice = static function () {
		if (!current_user_can('activate_plugins') && !current_user_can('manage_network_plugins')) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__('Snapshoter does not support WordPress Multisite.', 'snapshoter'),
			esc_html__('Deactivate it and use a single-site WordPress install.', 'snapshoter')
		);
	};
	add_action('admin_notices', $snapshoter_ms_notice);
	add_action('network_admin_notices', $snapshoter_ms_notice);
	return;
}

Snapshoter\Core\Plugin::boot();

