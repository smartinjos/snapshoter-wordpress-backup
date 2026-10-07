<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.WP.AlternativeFunctions

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Keep snapshots/archives unless SNAPSHOTER_DELETE_DATA_ON_UNINSTALL (wp-config).

function snapshoter_uninstall_rrmdir($dir)
{
	if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
		return;
	}

	$items = @scandir($dir);
	if (!is_array($items)) {
		return;
	}

	foreach ($items as $item) {
		if ('.' === $item || '..' === $item) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if (is_dir($path)) {
			snapshoter_uninstall_rrmdir($path);
		} elseif (function_exists('wp_delete_file')) {
			wp_delete_file($path);
		} else {
			@unlink($path);
		}
	}

	@rmdir($dir);
}

function snapshoter_uninstall_delete_option_likes($wpdb, $table)
{
	if (!is_object($wpdb) || !is_string($table) || $table === '') {
		return;
	}

	$patterns = array(
		$wpdb->esc_like('SNAPSHOTER_') . '%',
		$wpdb->esc_like('snapshoter_') . '%',
		$wpdb->esc_like('_transient_SNAPSHOTER_') . '%',
		$wpdb->esc_like('_transient_timeout_SNAPSHOTER_') . '%',
		$wpdb->esc_like('_transient_snapshoter_') . '%',
		$wpdb->esc_like('_transient_timeout_snapshoter_') . '%',
		$wpdb->esc_like('_site_transient_SNAPSHOTER_') . '%',
		$wpdb->esc_like('_site_transient_timeout_SNAPSHOTER_') . '%',
		$wpdb->esc_like('_site_transient_snapshoter_') . '%',
		$wpdb->esc_like('_site_transient_timeout_snapshoter_') . '%',
	);

	$table_sql = esc_sql($table);
	foreach ($patterns as $like) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table_sql = esc_sql( $wpdb->options|sitemeta ).
		$wpdb->query($wpdb->prepare("DELETE FROM `{$table_sql}` WHERE option_name LIKE %s", $like));
	}
}

function snapshoter_uninstall_purge_restore_artifacts()
{
	$safe_mode = dirname(__FILE__) . '/includes/Import/RestoreSafeMode.php';
	if (is_readable($safe_mode)) {
		require_once $safe_mode;
		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::purge_install_artifacts();
			return;
		}
	}

	if (defined('WPMU_PLUGIN_DIR')) {
		$mu_dir = WPMU_PLUGIN_DIR;
		$mu_file = trailingslashit($mu_dir) . 'snapshoter-restore-safe.php';
		if (file_exists($mu_file)) {
			wp_delete_file($mu_file);
		}
		if (is_dir($mu_dir)) {
			$items = @scandir($mu_dir);
			if (is_array($items)) {
				$suffix = '.snapshoter-off';
				$len = strlen($suffix);
				foreach ($items as $item) {
					if (substr($item, -$len) !== $suffix) {
						continue;
					}
					$full = trailingslashit($mu_dir) . $item;
					$orig = substr($full, 0, -$len);
					if (!file_exists($orig) && is_readable($full)) {
						if (@copy($full, $orig)) {
							wp_delete_file($full);
						}
					}
				}
			}
		}
	}
	if (function_exists('get_theme_root')) {
		$emerg = trailingslashit(get_theme_root()) . 'snapshoter-safe';
		if (is_dir($emerg)) {
			foreach (array('style.css', 'index.php', 'functions.php') as $f) {
				$p = $emerg . '/' . $f;
				if (file_exists($p)) {
					wp_delete_file($p);
				}
			}
			@rmdir($emerg);
		}
	}
}

function snapshoter_uninstall()
{
	global $wpdb;

	if (function_exists('wp_clear_scheduled_hook')) {
		wp_clear_scheduled_hook('snapshoter_vault_prune');
		wp_clear_scheduled_hook('snapshoter_background_job_tick');
	}

	snapshoter_uninstall_purge_restore_artifacts();

	if (isset($wpdb) && $wpdb instanceof wpdb) {
		snapshoter_uninstall_delete_option_likes($wpdb, $wpdb->options);

		if (is_multisite() && !empty($wpdb->sitemeta)) {
			$patterns = array(
				$wpdb->esc_like('SNAPSHOTER_') . '%',
				$wpdb->esc_like('snapshoter_') . '%',
			);
			$sitemeta_sql = esc_sql($wpdb->sitemeta);
			foreach ($patterns as $like) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- sitemeta_sql = esc_sql( $wpdb->sitemeta ).
				$wpdb->query($wpdb->prepare("DELETE FROM `{$sitemeta_sql}` WHERE meta_key LIKE %s", $like));
			}
		}
	}

	if (!function_exists('wp_upload_dir')) {
		return;
	}

	$upload_dir = wp_upload_dir();
	if (!empty($upload_dir['error']) || empty($upload_dir['basedir'])) {
		return;
	}

	$basedir = trailingslashit($upload_dir['basedir']);
	$storage_dir = $basedir . 'snapshoter';
	$legacy_dir = $basedir . 'snapshoters';

	if (is_dir($legacy_dir)) {
		snapshoter_uninstall_rrmdir($legacy_dir);
	}

	if (!is_dir($storage_dir)) {
		return;
	}

	$purge_all = defined('SNAPSHOTER_DELETE_DATA_ON_UNINSTALL') && SNAPSHOTER_DELETE_DATA_ON_UNINSTALL;
	if ($purge_all) {
		snapshoter_uninstall_rrmdir($storage_dir);
		return;
	}

	foreach (array('jobs', '.jobs', 'restores') as $subdir) {
		$path = $storage_dir . DIRECTORY_SEPARATOR . $subdir;
		if (is_dir($path)) {
			snapshoter_uninstall_rrmdir($path);
		}
	}

	$log_files = glob($storage_dir . DIRECTORY_SEPARATOR . '*.log');
	if (is_array($log_files)) {
		foreach ($log_files as $lf) {
			if (is_string($lf) && $lf !== '') {
				wp_delete_file($lf);
			}
		}
	}
}

snapshoter_uninstall();
