<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class RestoreSessionGuard
{
	const ACTIVE_INTERVAL = 60;
	const STALE_SECONDS = 7200;

	/**
	 * @var bool|null
	 */
	private static $active = null;

	public static function register()
	{
		if (!self::has_active_restore()) {
			return;
		}

		add_filter('wp_auth_check_load', array(__CLASS__, 'filter_auth_check_load'), 99);
		add_filter('heartbeat_settings', array(__CLASS__, 'filter_heartbeat_settings'), 99);
	}

	public static function filter_auth_check_load($load)
	{
		return false;
	}

	public static function filter_heartbeat_settings($settings)
	{
		if (!is_array($settings)) {
			return $settings;
		}

		$settings['interval'] = self::ACTIVE_INTERVAL;
		$settings['minimalInterval'] = self::ACTIVE_INTERVAL;

		return $settings;
	}

	public static function has_active_restore()
	{
		if (self::$active !== null) {
			return self::$active;
		}

		self::$active = false;

		if (!defined('SNAPSHOTER_JOB_DIR')) {
			return self::$active;
		}

		try {
			$store = new StateStore();
			foreach ($store->list_job_ids() as $job_id) {
				$state = $store->read($job_id);
				if (empty($state) || empty($state['type']) || $state['type'] !== 'import') {
					continue;
				}
				if (self::state_is_terminal($state) || self::state_is_stale($state)) {
					continue;
				}

				self::$active = true;
				break;
			}
		} catch (\Throwable $e) {
			self::$active = false;
		}

		return self::$active;
	}

	/**
	 * @param array $state Job state.
	 * @return bool
	 */
	private static function state_is_terminal(array $state)
	{
		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';

		return in_array($status, array('completed', 'partial', 'failed', 'cancelled'), true)
			|| in_array($step, array('completed', 'failed', 'cancelled'), true);
	}

	/**
	 * @param array $state Job state.
	 * @return bool
	 */
	private static function state_is_stale(array $state)
	{
		$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
		$updated = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
		$last = max($tick_at, $updated);

		if ($last <= 0) {
			return false;
		}

		return (time() - $last) >= self::STALE_SECONDS;
	}
}
