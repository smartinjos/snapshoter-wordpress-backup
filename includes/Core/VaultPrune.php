<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Prunes vault archives, temps, and stale job dirs by keep-count / age policy.
 */
final class VaultPrune
{
	const CRON_HOOK = 'snapshoter_vault_prune';

	const DEFAULT_KEEP_FULL = 5;

	const DEFAULT_KEEP_PARTIAL = 3;

	const DEFAULT_MAX_AGE_DAYS = 30;

	/**
	 * Working files younger than this are assumed to belong to a live job.
	 */
	const TEMP_MIN_AGE_SECONDS = 3600;

	/**
	 * A job directory is prunable once its job has been idle this long.
	 */
	const JOB_STALE_SECONDS = 21600;

	/** @var array */
	private static $pruned_jobs = array();

	const FULL_EXTENSIONS = array('smartin');

	const PARTIAL_EXTENSIONS = array('sql', 'zip');

	const TEMP_EXTENSIONS = array('part', 'partial', 'tmp', 'download');

	/**
	 * Resolve retention policy from constants, disk pressure, and filters.
	 *
	 * @return array
	 */
	public static function policy()
	{
		$policy = array(
			'enabled'      => defined('SNAPSHOTER_AUTO_PRUNE') ? (bool) SNAPSHOTER_AUTO_PRUNE : true,
			'keep_full'    => defined('SNAPSHOTER_PRUNE_KEEP_FULL') ? (int) SNAPSHOTER_PRUNE_KEEP_FULL : self::DEFAULT_KEEP_FULL,
			'keep_partial' => defined('SNAPSHOTER_PRUNE_KEEP_PARTIAL') ? (int) SNAPSHOTER_PRUNE_KEEP_PARTIAL : self::DEFAULT_KEEP_PARTIAL,
			'max_age_days' => defined('SNAPSHOTER_PRUNE_MAX_AGE_DAYS') ? (int) SNAPSHOTER_PRUNE_MAX_AGE_DAYS : self::DEFAULT_MAX_AGE_DAYS,
		);

		$policy['keep_full'] = max(1, $policy['keep_full']);
		$policy['keep_partial'] = max(1, $policy['keep_partial']);
		$policy['max_age_days'] = max(0, $policy['max_age_days']);

		$free = self::free_disk_bytes();
		if ($free !== null) {
			$gb = defined('GB_IN_BYTES') ? GB_IN_BYTES : 1073741824;

			if ($free < (2 * $gb)) {
				$policy['keep_full'] = 1;
				$policy['keep_partial'] = 1;
				$policy['max_age_days'] = $policy['max_age_days'] > 0 ? min($policy['max_age_days'], 3) : 3;
				$policy['disk_pressure'] = 'critical';
			} elseif ($free < (5 * $gb)) {
				$policy['keep_full'] = min($policy['keep_full'], 2);
				$policy['keep_partial'] = 1;
				$policy['max_age_days'] = $policy['max_age_days'] > 0 ? min($policy['max_age_days'], 7) : 7;
				$policy['disk_pressure'] = 'low';
			}
		}

		if (!isset($policy['disk_pressure'])) {
			$policy['disk_pressure'] = 'none';
		}

		/**
		 * Filters the Snapshoter auto-clean policy.
		 *
		 * @param array $policy Keys: enabled, keep_full, keep_partial, max_age_days, disk_pressure.
		 */
		$filtered = apply_filters('snapshoter_prune_policy', $policy);
		if (!is_array($filtered)) {
			return $policy;
		}

		$filtered['enabled'] = !empty($filtered['enabled']);
		$filtered['keep_full'] = isset($filtered['keep_full']) ? max(1, (int) $filtered['keep_full']) : $policy['keep_full'];
		$filtered['keep_partial'] = isset($filtered['keep_partial']) ? max(1, (int) $filtered['keep_partial']) : $policy['keep_partial'];
		$filtered['max_age_days'] = isset($filtered['max_age_days']) ? max(0, (int) $filtered['max_age_days']) : $policy['max_age_days'];

		return $filtered;
	}

	/**
	 * Prune after an export finished. Protects the archive that just landed.
	 *
	 * @param string $job_id    Job id.
	 * @param array  $job_state Job state as passed by the completion hook.
	 * @return array
	 */
	public static function after_backup($job_id = '', $job_state = array())
	{
		$job_id = is_string($job_id) ? sanitize_text_field($job_id) : '';

		if ($job_id !== '') {
			if (isset(self::$pruned_jobs[$job_id])) {
				return self::empty_result('already_pruned');
			}
			self::$pruned_jobs[$job_id] = true;
		}

		$state = is_array($job_state) ? $job_state : array();
		if (empty($state['type']) || $state['type'] !== 'export') {
			return self::empty_result('not_export');
		}

		$protect = self::paths_from_state($state);

		if ($job_id !== '') {
			try {
				$store = new StateStore();
				$protect = array_merge($protect, self::paths_from_state($store->read($job_id)));
			} catch (\Throwable $e) {
			}
		}

		return self::run(array('full', 'partial', 'temp', 'jobs'), $protect);
	}

	/**
	 * Post-restore prune: temps and job dirs only.
	 *
	 * @return array
	 */
	public static function after_restore()
	{
		return self::run(array('temp', 'jobs'));
	}

	/**
	 * Run every bucket. Used by callers that want a full sweep on demand.
	 *
	 * @param array $protect Extra absolute paths to keep.
	 * @return array
	 */
	public static function prune_full_vault($protect = array())
	{
		return self::run(array('full', 'partial', 'temp', 'jobs'), is_array($protect) ? $protect : array());
	}

	/**
	 * @param array $buckets Any of: full, partial, temp, jobs.
	 * @param array $protect Absolute paths that must survive.
	 * @return array
	 */
	public static function run($buckets, $protect = array())
	{
		$result = self::empty_result('ok');

		if (!defined('SNAPSHOTER_STORAGE')) {
			return self::empty_result('no_storage');
		}

		$policy = self::policy();
		if (empty($policy['enabled'])) {
			return self::empty_result('disabled');
		}

		$buckets = is_array($buckets) ? $buckets : array();
		$protect = self::normalize_protect_paths($protect);

		if (in_array('full', $buckets, true)) {
			self::merge_result($result, self::prune_archives(self::FULL_EXTENSIONS, (int) $policy['keep_full'], (int) $policy['max_age_days'], $protect));
		}

		if (in_array('partial', $buckets, true)) {
			self::merge_result($result, self::prune_archives(self::PARTIAL_EXTENSIONS, (int) $policy['keep_partial'], (int) $policy['max_age_days'], $protect));
		}

		if (in_array('temp', $buckets, true)) {
			self::merge_result($result, self::prune_temp_files($protect));
		}

		if (in_array('jobs', $buckets, true)) {
			self::merge_result($result, self::prune_job_dirs());
			self::prune_empty_snapshot_folders();
		}

		return $result;
	}

	/**
	 * Keep newest N of given extensions; drop older than age limit.
	 *
	 * @param array $extensions Archive extensions in this group.
	 */
	private static function prune_archives(array $extensions, $keep, $max_age_days, array $protect)
	{
		$result = self::empty_result('ok');
		$files = array();

		foreach (self::archive_roots() as $root) {
			$files = array_merge($files, self::collect_files($root, $extensions, true));
		}

		if (empty($files)) {
			return $result;
		}

		$unique = array();
		foreach ($files as $file) {
			$unique[$file['path']] = $file;
		}
		$files = array_values($unique);

		usort(
			$files,
			static function ($a, $b) {
				return $b['mtime'] - $a['mtime'];
			}
		);

		$cutoff = $max_age_days > 0 ? (time() - ($max_age_days * DAY_IN_SECONDS)) : 0;
		$kept = 0;

		foreach ($files as $file) {
			if (self::is_protected($file['path'], $protect)) {
				continue;
			}

			$too_many = ($kept >= max(1, (int) $keep));
			$too_old = ($cutoff > 0 && $file['mtime'] < $cutoff && $kept > 0);

			if (!$too_many && !$too_old) {
				$kept++;
				continue;
			}

			if (self::delete_file($file['path'])) {
				$result['deleted'][] = $file['path'];
				$result['bytes'] += $file['size'];
			}
		}

		return $result;
	}

	/**
	 * Remove abandoned chunk / temp files under storage.
	 *
	 * @param array $protect Normalized protected paths.
	 * @return array
	 */
	private static function prune_temp_files(array $protect)
	{
		$result = self::empty_result('ok');
		$threshold = time() - self::TEMP_MIN_AGE_SECONDS;

		$roots = self::archive_roots();
		if (defined('SNAPSHOTER_STORAGE')) {
			$roots[] = SNAPSHOTER_STORAGE;
		}

		foreach (array_unique($roots) as $root) {
			foreach (self::collect_files($root, self::TEMP_EXTENSIONS, true) as $file) {
				if ($file['mtime'] > $threshold) {
					continue;
				}
				if (self::is_protected($file['path'], $protect)) {
					continue;
				}
				if (self::delete_file($file['path'])) {
					$result['deleted'][] = $file['path'];
					$result['bytes'] += $file['size'];
				}
			}
		}

		return $result;
	}

	/**
	 * Remove job directories for finished or long-idle jobs.
	 *
	 * @return array
	 */
	private static function prune_job_dirs()
	{
		$result = self::empty_result('ok');

		if (!defined('SNAPSHOTER_JOB_DIR') || !is_dir(SNAPSHOTER_JOB_DIR)) {
			return $result;
		}

		try {
			$store = new StateStore();
			$job_ids = $store->list_job_ids();
		} catch (\Throwable $e) {
			return $result;
		}

		foreach ($job_ids as $job_id) {
			try {
				$state = $store->read($job_id);
			} catch (\Throwable $e) {
				continue;
			}

			$terminal = self::state_is_terminal($state);
			$idle_for = self::state_idle_seconds($state);

			if (!$terminal && $idle_for < self::JOB_STALE_SECONDS) {
				continue;
			}

			if ($terminal && $idle_for < self::TEMP_MIN_AGE_SECONDS) {
				continue;
			}

			try {
				$store->delete($job_id);
				$result['deleted'][] = $job_id;
			} catch (\Throwable $e) {
				continue;
			}
		}

		return $result;
	}

	/**
	 * Remove date folders left empty after archives were pruned.
	 *
	 * @return void
	 */
	private static function prune_empty_snapshot_folders()
	{
		if (!defined('SNAPSHOTER_SNAPSHOTS_DIR') || !is_dir(SNAPSHOTER_SNAPSHOTS_DIR)) {
			return;
		}

		$folders = glob(rtrim(SNAPSHOTER_SNAPSHOTS_DIR, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
		if (!is_array($folders)) {
			return;
		}

		foreach ($folders as $folder) {
			$entries = @scandir($folder);
			if (!is_array($entries)) {
				continue;
			}

			$remaining = array();
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$remaining[] = $entry;
			}

			if ($remaining === array('index.php')) {
				wp_delete_file($folder . DIRECTORY_SEPARATOR . 'index.php');
				$remaining = array();
			}

			if (empty($remaining)) {
				@rmdir($folder);
			}
		}
	}

	/**
	 * @return array
	 */
	private static function archive_roots()
	{
		$roots = array();

		foreach (array('SNAPSHOTER_SNAPSHOTS_DIR', 'SNAPSHOTER_ARCHIVE_DIR', 'SNAPSHOTER_RESTORES_DIR') as $const) {
			if (defined($const)) {
				$dir = (string) constant($const);
				if ($dir !== '' && is_dir($dir)) {
					$roots[] = $dir;
				}
			}
		}

		return array_unique($roots);
	}

	/**
	 * @param string $root       Directory to scan.
	 * @param array  $extensions Extensions without dot.
	 * @param bool   $recursive  Whether to walk one or more levels down.
	 * @return array
	 */
	private static function collect_files($root, array $extensions, $recursive = true)
	{
		$found = array();

		if (!is_dir($root)) {
			return $found;
		}

		$entries = @scandir($root);
		if (!is_array($entries)) {
			return $found;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
				continue;
			}

			$path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $entry;

			if (is_dir($path)) {
				if ($recursive) {
					$found = array_merge($found, self::collect_files($path, $extensions, false));
				}
				continue;
			}

			if (!is_file($path)) {
				continue;
			}

			$ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
			if (!in_array($ext, $extensions, true)) {
				continue;
			}

			$found[] = array(
				'path'  => $path,
				'mtime' => (int) @filemtime($path),
				'size'  => (int) @filesize($path),
			);
		}

		return $found;
	}

	/**
	 * @param array $protect Caller supplied paths.
	 * @return array
	 */
	private static function normalize_protect_paths($protect)
	{
		$paths = is_array($protect) ? $protect : array();

		foreach (self::active_job_paths() as $path) {
			$paths[] = $path;
		}

		/**
		 * Filters absolute paths that Snapshoter auto-clean must never delete.
		 *
		 * @param array $paths Absolute file paths.
		 */
		$filtered = apply_filters('snapshoter_prune_protect_paths', $paths);
		if (!is_array($filtered)) {
			$filtered = $paths;
		}

		$out = array();
		foreach ($filtered as $path) {
			if (!is_string($path) || $path === '') {
				continue;
			}
			$out[self::normalize_path($path)] = true;
		}

		return $out;
	}

	/**
	 * @param array $state Job state.
	 * @return array
	 */
	private static function paths_from_state($state)
	{
		$paths = array();

		if (!is_array($state)) {
			return $paths;
		}

		foreach (array('archive_path', 'snapshot_path', 'source_path', 'upload_path') as $key) {
			if (!empty($state[$key]) && is_string($state[$key])) {
				$paths[] = $state[$key];
			}
		}

		return $paths;
	}

	/**
	 * Archives referenced by jobs that are still running.
	 *
	 * @return array
	 */
	private static function active_job_paths()
	{
		$paths = array();

		if (!defined('SNAPSHOTER_JOB_DIR')) {
			return $paths;
		}

		try {
			$store = new StateStore();
			foreach ($store->list_job_ids() as $job_id) {
				$state = $store->read($job_id);
				if (self::state_is_terminal($state) && self::state_idle_seconds($state) > self::TEMP_MIN_AGE_SECONDS) {
					continue;
				}
				$paths = array_merge($paths, self::paths_from_state($state));
			}
		} catch (\Throwable $e) {
			return $paths;
		}

		return $paths;
	}

	/**
	 * @param mixed $state Job state.
	 * @return bool
	 */
	private static function state_is_terminal($state)
	{
		if (!is_array($state) || empty($state)) {
			return true;
		}

		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';

		return in_array($status, array('completed', 'partial', 'failed', 'cancelled'), true)
			|| in_array($step, array('completed', 'failed', 'cancelled'), true);
	}

	/**
	 * @param mixed $state Job state.
	 * @return int
	 */
	private static function state_idle_seconds($state)
	{
		if (!is_array($state)) {
			return PHP_INT_MAX;
		}

		$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
		$updated = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
		$started = isset($state['start_time']) ? (int) $state['start_time'] : 0;
		$last = max($tick_at, $updated, $started);

		if ($last <= 0) {
			return PHP_INT_MAX;
		}

		return max(0, time() - $last);
	}

	/**
	 * @param string $path    Candidate path.
	 * @param array  $protect Normalized protect map.
	 * @return bool
	 */
	private static function is_protected($path, array $protect)
	{
		$normalized = self::normalize_path($path);
		if (isset($protect[$normalized])) {
			return true;
		}

		$real = @realpath($path);
		if (is_string($real) && $real !== '' && isset($protect[self::normalize_path($real)])) {
			return true;
		}

		return false;
	}

	/**
	 * Delete a file, but only inside the Snapshoter storage folder.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function delete_file($path)
	{
		if (!is_string($path) || $path === '' || !is_file($path)) {
			return false;
		}

		if (!defined('SNAPSHOTER_STORAGE')) {
			return false;
		}

		$storage = rtrim(self::normalize_path(SNAPSHOTER_STORAGE), '/');
		$target = self::normalize_path($path);

		if ($storage === '' || strpos($target, $storage . '/') !== 0) {
			return false;
		}

		wp_delete_file($path);

		return !file_exists($path);
	}

	/**
	 * @param string $path Path to normalize.
	 * @return string
	 */
	private static function normalize_path($path)
	{
		$path = (string) $path;

		if (function_exists('wp_normalize_path')) {
			return wp_normalize_path($path);
		}

		return str_replace('\\', '/', $path);
	}

	/**
	 * @return int|null Free bytes on the storage volume, or null when unknown.
	 */
	private static function free_disk_bytes()
	{
		if (!function_exists('disk_free_space')) {
			return null;
		}

		$target = defined('SNAPSHOTER_STORAGE') && is_dir(SNAPSHOTER_STORAGE) ? SNAPSHOTER_STORAGE : ABSPATH;

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions
		$free = @disk_free_space($target);

		if ($free === false || !is_numeric($free)) {
			return null;
		}

		return (int) $free;
	}

	/**
	 * @param string $reason Why the run ended the way it did.
	 * @return array
	 */
	private static function empty_result($reason)
	{
		return array(
			'reason'  => $reason,
			'deleted' => array(),
			'bytes'   => 0,
		);
	}

	/**
	 * @param array $target Accumulator (by reference).
	 * @param array $add    Result to fold in.
	 * @return void
	 */
	private static function merge_result(array &$target, array $add)
	{
		if (!empty($add['deleted']) && is_array($add['deleted'])) {
			$target['deleted'] = array_merge($target['deleted'], $add['deleted']);
		}

		if (!empty($add['bytes'])) {
			$target['bytes'] += (int) $add['bytes'];
		}
	}
}
