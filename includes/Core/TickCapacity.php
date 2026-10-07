<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

class TickCapacity
{
	const RESTORE_BYTES_FLOOR = 8388608;
	const RESTORE_BYTES_DEFAULT = 20971520;
	const RESTORE_BYTES_CEILING = 62914560;
	const RESTORE_BATCH_FLOOR = 200;
	const RESTORE_BATCH_DEFAULT = 2000;
	const RESTORE_BATCH_CEILING = 4000;
	const FAST_STREAK_TO_BUMP = 2;

	const UPLOAD_SECONDS_FLOOR = 12.0;
	const UPLOAD_SECONDS_DEFAULT = 22.0;
	const UPLOAD_SECONDS_CEILING = 50.0;

	const UPLOAD_PART_FLOOR = 8388608;
	const UPLOAD_PART_DEFAULT = 8388608;
	const UPLOAD_PART_CEILING = 16777216;

	public static function initial_restore_bytes($can_extend_time, $memory_bytes = 0)
	{
		$can_extend_time = (bool) $can_extend_time;
		$memory_bytes = (int) $memory_bytes;

		if (!$can_extend_time) {

			return self::RESTORE_BYTES_FLOOR + (2 * 1024 * 1024);
		}

		if ($memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024) {
			return self::RESTORE_BYTES_FLOOR + (2 * 1024 * 1024);
		}

		if ($memory_bytes >= 512 * 1024 * 1024) {
			return 32 * 1024 * 1024;
		}

		return self::RESTORE_BYTES_DEFAULT;
	}

	public static function initial_restore_batch($can_extend_time)
	{
		return $can_extend_time ? self::RESTORE_BATCH_DEFAULT : 800;
	}

	public static function next_restore_bytes(
		$current_budget,
		$elapsed,
		$time_budget,
		$bytes_copied,
		$hit_byte_cap,
		$hit_time_cap,
		$fast_streak = 0
	) {
		$current_budget = max(self::RESTORE_BYTES_FLOOR, (int) $current_budget);
		$elapsed = (float) $elapsed;
		$time_budget = max(1.0, (float) $time_budget);
		$bytes_copied = max(0, (int) $bytes_copied);
		$fast_streak = max(0, (int) $fast_streak);
		$ratio = $elapsed / $time_budget;

		if ($hit_time_cap || $ratio >= 0.70) {
			$next = max(self::RESTORE_BYTES_FLOOR, (int) ($current_budget * 0.6));
			return array(
				'budget' => $next,
				'fast_streak' => 0,
				'action' => $next < $current_budget ? 'backoff' : 'hold',
			);
		}

		$comfortable = ($ratio < 0.35 && (!$hit_byte_cap || $bytes_copied < (int) ($current_budget * 0.5)));
		if ($comfortable) {
			$fast_streak++;
			if ($fast_streak >= self::FAST_STREAK_TO_BUMP) {
				$next = min(self::RESTORE_BYTES_CEILING, (int) ($current_budget * 1.25));
				return array(
					'budget' => $next,
					'fast_streak' => ($next > $current_budget) ? 0 : $fast_streak,
					'action' => $next > $current_budget ? 'bump' : 'hold',
				);
			}
			return array(
				'budget' => $current_budget,
				'fast_streak' => $fast_streak,
				'action' => 'hold',
			);
		}

		return array(
			'budget' => $current_budget,
			'fast_streak' => 0,
			'action' => 'hold',
		);
	}

	public static function throttle_restore_bytes($current_budget)
	{
		$current_budget = max(self::RESTORE_BYTES_FLOOR, (int) $current_budget);
		return max(self::RESTORE_BYTES_FLOOR, (int) ($current_budget * 0.5));
	}

	public static function initial_upload_seconds($can_extend_time, $memory_bytes = 0)
	{
		$can_extend_time = (bool) $can_extend_time;
		$memory_bytes = (int) $memory_bytes;

		if (!$can_extend_time) {
			return self::UPLOAD_SECONDS_FLOOR;
		}
		if ($memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024) {
			return self::UPLOAD_SECONDS_FLOOR;
		}
		if ($memory_bytes >= 512 * 1024 * 1024) {
			return 35.0;
		}
		return self::UPLOAD_SECONDS_DEFAULT;
	}

	public static function initial_upload_part_bytes($can_extend_time, $memory_bytes = 0)
	{
		$can_extend_time = (bool) $can_extend_time;
		$memory_bytes = (int) $memory_bytes;

		if (!$can_extend_time || ($memory_bytes > 0 && $memory_bytes < 256 * 1024 * 1024)) {
			return self::UPLOAD_PART_FLOOR;
		}
		if ($memory_bytes >= 512 * 1024 * 1024) {
			return 12 * 1024 * 1024;
		}
		return self::UPLOAD_PART_DEFAULT;
	}

	public static function next_upload_seconds(
		$current_seconds,
		$elapsed,
		$bytes_sent_this_tick,
		$hit_time_cap,
		$completed_upload = false,
		$fast_streak = 0
	) {
		$current_seconds = self::clamp_upload_seconds($current_seconds);
		$elapsed = (float) $elapsed;
		$bytes_sent_this_tick = max(0, (int) $bytes_sent_this_tick);
		$fast_streak = max(0, (int) $fast_streak);
		$ratio = $elapsed / max(1.0, $current_seconds);

		if ($hit_time_cap || $ratio >= 0.70) {
			$next = max(self::UPLOAD_SECONDS_FLOOR, round($current_seconds * 0.7, 1));
			return array(
				'seconds' => $next,
				'fast_streak' => 0,
				'action' => $next < $current_seconds ? 'backoff' : 'hold',
			);
		}

		$comfortable = !$completed_upload
			&& $bytes_sent_this_tick > 0
			&& $ratio < 0.40;
		if ($comfortable) {
			$fast_streak++;
			if ($fast_streak >= self::FAST_STREAK_TO_BUMP) {
				$next = min(self::UPLOAD_SECONDS_CEILING, round($current_seconds * 1.25, 1));
				return array(
					'seconds' => $next,
					'fast_streak' => ($next > $current_seconds) ? 0 : $fast_streak,
					'action' => $next > $current_seconds ? 'bump' : 'hold',
				);
			}
			return array(
				'seconds' => $current_seconds,
				'fast_streak' => $fast_streak,
				'action' => 'hold',
			);
		}

		return array(
			'seconds' => $current_seconds,
			'fast_streak' => 0,
			'action' => 'hold',
		);
	}

	public static function throttle_upload_seconds($current_seconds)
	{
		$current_seconds = self::clamp_upload_seconds($current_seconds);
		return max(self::UPLOAD_SECONDS_FLOOR, round($current_seconds * 0.5, 1));
	}

	public static function clamp_upload_seconds($seconds)
	{
		$seconds = (float) $seconds;
		if ($seconds < self::UPLOAD_SECONDS_FLOOR) {
			return self::UPLOAD_SECONDS_FLOOR;
		}
		if ($seconds > self::UPLOAD_SECONDS_CEILING) {
			return self::UPLOAD_SECONDS_CEILING;
		}
		return $seconds;
	}

	public static function clamp_upload_part_bytes($bytes)
	{
		$bytes = (int) $bytes;
		if ($bytes < self::UPLOAD_PART_FLOOR) {
			return self::UPLOAD_PART_FLOOR;
		}
		if ($bytes > self::UPLOAD_PART_CEILING) {
			return self::UPLOAD_PART_CEILING;
		}
		return $bytes;
	}

	public static function clamp_restore_bytes($budget)
	{
		$budget = (int) $budget;
		if ($budget < self::RESTORE_BYTES_FLOOR) {
			return self::RESTORE_BYTES_FLOOR;
		}
		if ($budget > self::RESTORE_BYTES_CEILING) {
			return self::RESTORE_BYTES_CEILING;
		}
		return $budget;
	}

	public static function parse_ini_bytes($value)
	{
		$value = trim((string) $value);
		if ($value === '' || $value === '-1') {
			return 0;
		}
		if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?B?$/i', $value, $m)) {
			if (ctype_digit($value)) {
				return (int) $value;
			}
			return 0;
		}
		$n = (float) $m[1];
		$u = isset($m[2]) ? strtoupper($m[2]) : '';
		if ($u === 'K') {
			$n *= 1024;
		} elseif ($u === 'M') {
			$n *= 1024 * 1024;
		} elseif ($u === 'G') {
			$n *= 1024 * 1024 * 1024;
		}
		return (int) $n;
	}
}
