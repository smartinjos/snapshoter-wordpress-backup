<?php

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions

if (!defined('SNAPSHOTER_VERSION')) {
	define('SNAPSHOTER_VERSION', '1.0');
}

// Restore safety (Action Scheduler / Woo / payment URLs). Kill switch: SNAPSHOTER_RESTORE_SAFETY.
if (!defined('SNAPSHOTER_RESTORE_SAFETY')) {
	define('SNAPSHOTER_RESTORE_SAFETY', true);
}

if (!defined('SNAPSHOTER_MIN_WP')) {
	define('SNAPSHOTER_MIN_WP', '6.0');
}

if (!function_exists('snapshoter_resolve_storage_base')) {
	function snapshoter_resolve_storage_base()
	{

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
		$db_suppressed = in_array(
			$action,
			array('SNAPSHOTER_run_job', 'SNAPSHOTER_upload_chunk', 'SNAPSHOTER_get_status'),
			true
		);

		if ($db_suppressed || !function_exists('wp_upload_dir')) {
			return defined('WP_CONTENT_DIR') ? (WP_CONTENT_DIR . '/uploads') : (ABSPATH . 'wp-content/uploads');
		}

		$info = wp_upload_dir();
		return isset($info['basedir']) ? $info['basedir'] : (defined('WP_CONTENT_DIR') ? (WP_CONTENT_DIR . '/uploads') : (ABSPATH . 'wp-content/uploads'));
	}
}

$SNAPSHOTER_storage_base = snapshoter_resolve_storage_base();

if (!defined('SNAPSHOTER_STORAGE')) {
	$SNAPSHOTER_legacy_storage = $SNAPSHOTER_storage_base . DIRECTORY_SEPARATOR . 'snapshoters';
	$SNAPSHOTER_new_storage = $SNAPSHOTER_storage_base . DIRECTORY_SEPARATOR . 'snapshoter';
	if (is_dir($SNAPSHOTER_legacy_storage) && !is_dir($SNAPSHOTER_new_storage)) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		@rename($SNAPSHOTER_legacy_storage, $SNAPSHOTER_new_storage);
	}
	define('SNAPSHOTER_STORAGE', $SNAPSHOTER_new_storage);
}
if (!defined('SNAPSHOTER_ARCHIVE_DIR')) {
	define('SNAPSHOTER_ARCHIVE_DIR', SNAPSHOTER_STORAGE . DIRECTORY_SEPARATOR . 'archives');
}
if (!defined('SNAPSHOTER_JOB_DIR')) {
	define('SNAPSHOTER_JOB_DIR', SNAPSHOTER_STORAGE . DIRECTORY_SEPARATOR . 'jobs');
}
if (!defined('SNAPSHOTER_SNAPSHOTS_DIR')) {
	define('SNAPSHOTER_SNAPSHOTS_DIR', SNAPSHOTER_STORAGE . DIRECTORY_SEPARATOR . 'snapshots');
}

if (!defined('SNAPSHOTER_RESTORES_DIR')) {
	define('SNAPSHOTER_RESTORES_DIR', SNAPSHOTER_STORAGE . DIRECTORY_SEPARATOR . 'restores');
}

if (!defined('SNAPSHOTER_MAX_CHUNK_SIZE')) {
	define('SNAPSHOTER_MAX_CHUNK_SIZE', 32 * 1024 * 1024);
}

if (!defined('SNAPSHOTER_CAPABILITY')) {
	define('SNAPSHOTER_CAPABILITY', 'manage_options');
}
if (!defined('SNAPSHOTER_NONCE_ACTION')) {
	define('SNAPSHOTER_NONCE_ACTION', 'SNAPSHOTER_nonce');
}
if (!defined('SNAPSHOTER_NONCE_NAME')) {
	define('SNAPSHOTER_NONCE_NAME', 'SNAPSHOTER_nonce');
}

if (!defined('SNAPSHOTER_DEBUG')) {
	define('SNAPSHOTER_DEBUG', false);
}

