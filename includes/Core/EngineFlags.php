<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class EngineFlags
{
	const OPTION_KEY = 'snapshoter_engine_v2';

	const DIRECT_APPLY_BYTES = 33554432;

	public static function enabled()
	{
		if (defined('SNAPSHOTER_ENGINE_V2')) {
			return (bool) SNAPSHOTER_ENGINE_V2;
		}

		$opt = get_option(self::OPTION_KEY, null);
		if ($opt === null) {
			return true;
		}

		return (bool) $opt;
	}

	public static function use_direct_apply($archive_bytes = 0)
	{
		if (!self::enabled()) {
			return false;
		}

		$archive_bytes = (int) $archive_bytes;
		if ($archive_bytes > 0 && $archive_bytes < self::DIRECT_APPLY_BYTES) {

			return false;
		}

		return class_exists('\\ZipArchive');
	}
}
