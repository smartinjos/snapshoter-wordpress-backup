<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class ServerCapacityGate
{
	const HARD_FREE_FLOOR = 104857600;
	const WARN_FREE_FLOOR = 268435456;
	const MEMORY_WARN = 268435456;
	const MEMORY_CRITICAL = 134217728;

	public static function disk_free_bytes($path = '')
	{
		$path = (string) $path;
		if ($path === '' || !is_dir($path)) {
			if (defined('SNAPSHOTER_STORAGE') && is_dir(SNAPSHOTER_STORAGE)) {
				$path = SNAPSHOTER_STORAGE;
			} elseif (defined('WP_CONTENT_DIR')) {
				$path = WP_CONTENT_DIR;
			} else {
				$path = ABSPATH;
			}
		}

		$free = @disk_free_space($path);
		return ($free === false) ? 0 : (int) $free;
	}

	public static function evaluate_restore($archive_bytes, $direct_apply = false)
	{
		$archive_bytes = max(0, (int) $archive_bytes);
		$free = self::disk_free_bytes();
		$memory = TickCapacity::parse_ini_bytes((string) ini_get('memory_limit'));
		$warnings = array();
		$block = false;
		$engine = $direct_apply ? 'direct_v1' : 'legacy_extract';

		if ($free > 0 && $free < self::HARD_FREE_FLOOR) {
			$block = true;
			$warnings[] = sprintf(

				// translators: %s: Free disk space
				__('Not enough free disk (%s). Free at least 100 MB before restoring.', 'snapshoter'),
				size_format($free)
			);
		} elseif ($free > 0 && $free < self::WARN_FREE_FLOOR) {
			$warnings[] = sprintf(

				// translators: %s: Free disk space
				__('Low free disk (%s). Large restores may fail - free more space if possible.', 'snapshoter'),
				size_format($free)
			);
		}

		if (!$direct_apply && $archive_bytes > 0 && $free > 0) {
			$need = (int) ($archive_bytes * 1.15) + (1024 * 1024 * 1024);
			if ($free < $need) {
				$warnings[] = sprintf(

					// translators: %1$s: Recommended free space; %2$s: Available free space
					__('Legacy extract path may need ~%1$s free (have %2$s). Engine will prefer direct-apply when enabled.', 'snapshoter'),
					size_format($need),
					size_format($free)
				);

			}
		}

		if ($direct_apply && $archive_bytes > 0 && $free > 0) {
			$need = max(self::WARN_FREE_FLOOR, (int) ($archive_bytes * 0.15) + (50 * 1024 * 1024));
			if ($free < $need && $free < self::HARD_FREE_FLOOR * 3) {
				$warnings[] = sprintf(

					// translators: %1$s: Recommended free space; %2$s: Available free space
					__('Tight disk for direct restore (recommend ~%1$s free, have %2$s).', 'snapshoter'),
					size_format($need),
					size_format($free)
				);
			}
		}

		if ($memory > 0 && $memory < self::MEMORY_CRITICAL) {
			$warnings[] = sprintf(

				// translators: %s: PHP memory_limit
				__('PHP memory_limit is critical (%s). Restore will use tiny ticks - do not raise php.ini; Snapshoter adapts.', 'snapshoter'),
				size_format($memory)
			);
		} elseif ($memory > 0 && $memory < self::MEMORY_WARN) {
			$warnings[] = sprintf(

				// translators: %s: PHP memory_limit
				__('PHP memory_limit is low (%s). Recommended 256M+; Snapshoter will not require a raise.', 'snapshoter'),
				size_format($memory)
			);
		}

		return array(
			'ok'           => !$block,
			'block'        => $block,
			'warnings'     => $warnings,
			'free_bytes'   => $free,
			'memory_bytes' => $memory,
			'engine'       => $engine,
			'can_extend'   => Environment::can_extend_time(),
		);
	}
}
