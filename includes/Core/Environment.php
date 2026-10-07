<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable Squiz.PHP.DiscouragedFunctions

class Environment
{

	private static $can_extend_time = null;

	public static function configure()
	{
		@ignore_user_abort(true);



		if (function_exists('apache_setenv')) {
			@apache_setenv('noabort', '1');
			@apache_setenv('noconntimeout', '1');
		}

		self::probe_time_limit_support();

		if (function_exists('session_status')) {
			$status = session_status();
			if ($status === PHP_SESSION_ACTIVE) {
				@session_write_close();
			}
		} elseif (function_exists('session_id') && session_id() !== '') {
			@session_write_close();
		}

		@ini_set('max_input_time', '-1');

		@ini_set('pcre.backtrack_limit', PHP_INT_MAX);

		if (@function_exists('mb_internal_encoding') && (@ini_get('mbstring.func_overload') & 2)) {
			@mb_internal_encoding('ISO-8859-1');
		}

		if (@ob_get_length()) {
			@ob_end_clean();
		}
	}

	public static function can_extend_time()
	{
		if (self::$can_extend_time === null) {
			self::probe_time_limit_support();
		}
		return self::$can_extend_time;
	}

	private static function probe_time_limit_support()
	{
		if (!self::is_function_callable('set_time_limit')) {
			self::$can_extend_time = false;
			return;
		}



		self::$can_extend_time = (bool) @set_time_limit(0);
	}

	public static function is_function_callable($name)
	{
		if (!is_string($name) || $name === '' || !function_exists($name)) {
			return false;
		}

		$disabled = ini_get('disable_functions');
		if (is_string($disabled) && $disabled !== '') {
			$list = array_map('trim', explode(',', strtolower($disabled)));
			if (in_array(strtolower($name), $list, true)) {
				return false;
			}
		}

		return true;
	}
}
