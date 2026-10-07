<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class ExportLock
{
	const TRANSIENT = 'snapshoter_active_export_job';

	const STALE_SECONDS = 900;

	public static function find_active(StateStore $store, $exclude_job_id = '')
	{
		$exclude_job_id = (string) $exclude_job_id;
		$transient_id = get_transient(self::TRANSIENT);
		if (is_string($transient_id) && $transient_id !== '' && $transient_id !== $exclude_job_id) {
			$state = $store->read($transient_id);
			if (self::state_blocks_new_export($state)) {
				return self::describe($transient_id, $state);
			}
			self::release($transient_id);
		}

		$base = rtrim(SNAPSHOTER_JOB_DIR, '/\\');
		if ($base === '' || !is_dir($base)) {
			return null;
		}

		$dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
		if (!is_array($dirs)) {
			return null;
		}

		foreach ($dirs as $dir) {
			$job_id = basename($dir);
			if ($job_id === '' || $job_id === $exclude_job_id) {
				continue;
			}

			$state = $store->read($job_id);
			if (!self::state_blocks_new_export($state)) {
				continue;
			}

			self::register($job_id);
			return self::describe($job_id, $state);
		}

		return null;
	}

	public static function register($job_id)
	{
		$job_id = sanitize_text_field((string) $job_id);
		if ($job_id === '') {
			return;
		}
		set_transient(self::TRANSIENT, $job_id, DAY_IN_SECONDS);
	}

	public static function release($job_id)
	{
		$job_id = sanitize_text_field((string) $job_id);
		if ($job_id === '') {
			return;
		}
		$current = get_transient(self::TRANSIENT);
		if ($current === $job_id) {
			delete_transient(self::TRANSIENT);
		}
	}

	public static function force_clear()
	{
		delete_transient(self::TRANSIENT);
	}

	public static function state_is_open_export($state)
	{
		if (!is_array($state) || empty($state)) {
			return false;
		}
		if (empty($state['type']) || $state['type'] !== 'export') {
			return false;
		}

		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';

		if (in_array($status, array('completed', 'partial', 'failed', 'cancelled'), true)) {
			return false;
		}
		if (in_array($step, array('completed', 'failed', 'cancelled'), true)) {
			return false;
		}

		return true;
	}

	public static function list_open_exports(StateStore $store)
	{
		$out = array();

		$transient_id = get_transient(self::TRANSIENT);
		if (is_string($transient_id) && $transient_id !== '') {
			$state = $store->read($transient_id);
			if (!is_array($state) || empty($state) || self::state_is_open_export($state)) {
				$out[ $transient_id ] = true;
			}
		}

		$base = rtrim(SNAPSHOTER_JOB_DIR, '/\\');
		if ($base !== '' && is_dir($base)) {
			$dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
			if (is_array($dirs)) {
				foreach ($dirs as $dir) {
					$job_id = basename($dir);
					if ($job_id === '') {
						continue;
					}
					$state = $store->read($job_id);
					if (self::state_is_open_export($state)) {
						$out[ $job_id ] = true;
					}
				}
			}
		}

		return array_keys($out);
	}

	public static function state_blocks_new_export($state)
	{
		if (!is_array($state) || empty($state)) {
			return false;
		}
		if (empty($state['type']) || $state['type'] !== 'export') {
			return false;
		}

		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';

		if (in_array($status, array('completed', 'partial', 'failed', 'cancelled'), true)) {
			return false;
		}
		if (in_array($step, array('completed', 'failed', 'cancelled'), true)) {
			return false;
		}

		if ($status === 'running') {
			return !self::state_is_stale($state);
		}

		if ($status === '' || $status === 'starting') {
			$started = 0;
			if (!empty($state['start_time'])) {
				$started = (int) $state['start_time'];
			} elseif (!empty($state['started_at'])) {
				$started = (int) $state['started_at'];
			}
			if ($started <= 0) {

				return false;
			}
			return (time() - $started) < 90;
		}

		return false;
	}

	public static function export_state_is_stale(array $state)
	{
		return self::state_is_stale($state);
	}

	private static function state_is_stale(array $state)
	{
		$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
		$now = time();

		if ($tick_at > 0) {
			return ($now - $tick_at) > self::STALE_SECONDS;
		}

		$manifest_created = 0;
		if (!empty($state['manifest']['created_at'])) {
			$parsed = strtotime((string) $state['manifest']['created_at']);

		}

		if ($manifest_created > 0 && ($now - $manifest_created) > 120) {
			return true;
		}

		return false;
	}

	private static function describe($job_id, array $state)
	{
		return array(
			'job_id' => (string) $job_id,
			'step' => isset($state['step']) ? (string) $state['step'] : '',
			'status' => isset($state['status']) ? (string) $state['status'] : 'running',
		);
	}
}
