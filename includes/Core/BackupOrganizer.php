<?php

namespace Snapshoter\Core;

use Snapshoter\Core\StateStore;
use Snapshoter\Core\LoggerTrait;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Moves finished local exports into the snapshot vault.
 */
class BackupOrganizer
{
	use LoggerTrait;

	public function handle_export_completion($job_id, $job_state)
	{
		if (empty($job_state['type']) || $job_state['type'] !== 'export') {
			return;
		}

		$step = !empty($job_state['step']) ? (string) $job_state['step'] : '';
		$status = !empty($job_state['status']) ? (string) $job_state['status'] : '';
		$is_valid_phase = in_array($step, array('hooks', 'finalize', 'completed', 'upload'), true) || $status === 'completed' || $status === 'running';
		if (!$is_valid_phase) {
			return;
		}

		$archive_path = $job_state['archive_path'] ?? '';
		if (empty($archive_path) || !file_exists($archive_path)) {
			return;
		}

		if (empty($job_state['is_snapshot'])) {
			return;
		}

		$destination = $this->save_snapshot($archive_path);
		if ($destination) {
			$store = new StateStore();
			$store->update($job_id, array(
				'snapshot_path' => $destination,
				'archive_path'  => $destination,
				'filename'      => basename($destination),
			));
		} else {
			$this->debug_log('Snapshot save failed', array('job_id' => substr($job_id, 0, 20) . '...'));
		}
	}

	private function save_snapshot($archive_path)
	{
		if (!file_exists($archive_path)) {
			$this->debug_log('ERROR - Source archive file does not exist', array(
				'filename' => basename($archive_path),
				'directory_exists' => is_dir(dirname($archive_path)) ? 'YES' : 'NO',
			));
			return false;
		}

		$src_basename = basename((string) $archive_path);

		$timestamp = time();
		$date_folder = function_exists('wp_date') ? wp_date('Y-m-d', $timestamp) : gmdate('Y-m-d', $timestamp);
		if (preg_match('/(\d{4}-\d{2}-\d{2})/', $src_basename, $stamp_m)) {
			$date_folder = $stamp_m[1];
		}

		$site_host = wp_parse_url(home_url(), PHP_URL_HOST);
		if (empty($site_host)) {
			$site_host = sanitize_title(get_bloginfo('name'));
		}
		$site_slug = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) $site_host);
		$time_formatted = function_exists('wp_date') ? wp_date('H.i.s', $timestamp) : gmdate('H.i.s', $timestamp);
		$base_filename = !empty($site_slug)
			? sprintf('%s-%s-%s', $site_slug, $date_folder, $time_formatted)
			: sprintf('%s-%s', $date_folder, $time_formatted);
		$ext = pathinfo($archive_path, PATHINFO_EXTENSION);
		if (empty($ext)) {
			$ext = 'smartin';
		}

		$snapshots_dir = SNAPSHOTER_SNAPSHOTS_DIR;
		if (!wp_mkdir_p($snapshots_dir)) {
			$this->debug_log('Failed to create snapshots directory', array('directory' => basename($snapshots_dir)));
			return false;
		}
		$target_dir = $snapshots_dir . DIRECTORY_SEPARATOR . $date_folder;
		if (!wp_mkdir_p($target_dir)) {
			$this->debug_log('Failed to create date directory', array('directory' => basename($target_dir)));
			return false;
		}

		$filename = $base_filename . '.' . $ext;
		$destination = $target_dir . DIRECTORY_SEPARATOR . $filename;
		$suffix = 1;
		while (file_exists($destination) && realpath($destination) !== realpath($archive_path)) {
			$suffix++;
			$filename = sprintf('%s-%02d.%s', $base_filename, $suffix, $ext);
			$destination = $target_dir . DIRECTORY_SEPARATOR . $filename;
		}

		$move_result = @rename($archive_path, $destination);
		if ($move_result) {
			$this->debug_log('Snapshot moved using rename()', array('destination' => basename($destination)));
		} else {
			$this->debug_log('Rename failed, falling back to copy()', array('source' => basename($archive_path)));
			$move_result = copy($archive_path, $destination);
		}

		if ($move_result) {
			clearstatcache(true, $destination);
			if (file_exists($destination) && filesize($destination) > 0) {
				$this->debug_log('Snapshot saved successfully', array('destination' => basename($destination)));
				$this->cleanup_old_snapshots();
				return $destination;
			}
			$this->debug_log('CRITICAL ERROR - Snapshot save returned true but file is missing or empty', array(
				'filename' => basename($destination),
				'exists' => file_exists($destination) ? 'YES' : 'NO',
				'size' => file_exists($destination) ? filesize($destination) : 'N/A',
			));
			return false;
		}

		$error = error_get_last();
		$this->debug_log('CRITICAL ERROR - Failed to save snapshot', array(
			'source' => basename($archive_path),
			'destination' => basename($destination),
			'source_exists' => file_exists($archive_path) ? 'YES' : 'NO',
			'source_readable' => (file_exists($archive_path) && is_readable($archive_path)) ? 'YES' : 'NO',
			'dest_dir_exists' => is_dir($target_dir) ? 'YES' : 'NO',
			'dest_dir_writable' => (is_dir($target_dir) && is_writable($target_dir)) ? 'YES' : 'NO',
			'php_error' => isset($error['message']) ? $error['message'] : 'Unknown error',
		));
		return false;
	}

	private function cleanup_old_snapshots()
	{
		if (class_exists('\\Snapshoter\\Core\\VaultPrune')) {
			\Snapshoter\Core\VaultPrune::prune_full_vault();
		}
	}
}
