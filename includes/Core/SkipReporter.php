<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class SkipReporter
{
	const MAX_SAMPLES = 100;

	const REASON_UNREADABLE = 'unreadable';
	const REASON_GONE = 'gone';
	const REASON_ZIP_REJECTED = 'zip_rejected';
	const REASON_NOT_EXTRACTED = 'not_extracted';
	const REASON_COPY_FAILED = 'copy_failed';

	public static function empty_state()
	{
		return array(
			'count' => 0,
			'reasons' => array(),
			'samples' => array(),
		);
	}

	public static function record($state, $relative, $reason)
	{
		$state = self::normalize($state);
		$relative = is_string($relative) ? $relative : '';
		$reason = self::normalize_reason($reason);

		$state['count']++;

		if (!isset($state['reasons'][$reason])) {
			$state['reasons'][$reason] = 0;
		}
		$state['reasons'][$reason]++;

		if (count($state['samples']) < self::MAX_SAMPLES && $relative !== '') {
			$state['samples'][] = array(
				'path' => $relative,
				'reason' => $reason,
			);
		}

		return $state;
	}

	public static function normalize($state)
	{
		if (!is_array($state)) {
			return self::empty_state();
		}

		$count = isset($state['count']) ? max(0, (int) $state['count']) : 0;

		$reasons = array();
		if (isset($state['reasons']) && is_array($state['reasons'])) {
			foreach ($state['reasons'] as $key => $value) {
				$key = is_string($key) ? $key : (string) $key;
				$reasons[$key] = max(0, (int) $value);
			}
		}

		$samples = array();
		if (isset($state['samples']) && is_array($state['samples'])) {
			foreach ($state['samples'] as $entry) {
				if (!is_array($entry)) {
					continue;
				}
				$path = isset($entry['path']) ? (string) $entry['path'] : '';
				$reason = isset($entry['reason']) ? (string) $entry['reason'] : '';
				if ($path === '') {
					continue;
				}
				$samples[] = array('path' => $path, 'reason' => self::normalize_reason($reason));
				if (count($samples) >= self::MAX_SAMPLES) {
					break;
				}
			}
		}

		return array(
			'count' => $count,
			'reasons' => $reasons,
			'samples' => $samples,
		);
	}

	public static function summarize($state)
	{
		$state = self::normalize($state);
		return array(
			'count' => $state['count'],
			'reasons' => $state['reasons'],
		);
	}

	private static function normalize_reason($reason)
	{
		$reason = is_string($reason) ? trim($reason) : '';
		if ($reason === '') {
			return 'other';
		}

		$reason = strtolower($reason);
		$reason = preg_replace('/[^a-z0-9_]/', '_', $reason);
		return $reason === '' ? 'other' : $reason;
	}
}
