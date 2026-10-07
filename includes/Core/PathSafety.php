<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class PathSafety
{
	/**
	 * @param string $name Raw zip entry name.
	 * @return bool
	 */
	public static function is_safe_zip_entry($name)
	{
		return self::normalize_zip_entry($name) !== null;
	}

	/**
	 * @param string $name Raw zip entry name.
	 * @return string|null Relative path, or null if unsafe.
	 */
	public static function normalize_zip_entry($name)
	{
		if (!is_string($name) || $name === '') {
			return null;
		}
		if (strpos($name, "\0") !== false) {
			return null;
		}

		$clean = str_replace('\\', '/', $name);
		$clean = str_replace("\0", '', $clean);

		if (isset($clean[0]) && $clean[0] === '/') {
			return null;
		}
		if (preg_match('#^[A-Za-z]:/#', $clean)) {
			return null;
		}
		if (strpos($clean, '//') === 0) {
			return null;
		}

		$parts = array();
		foreach (explode('/', $clean) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				return null;
			}
			$parts[] = $segment;
		}

		if (empty($parts)) {
			return null;
		}

		return implode('/', $parts);
	}

	/**
	 * @param string $name Zip entry (may start with files/).
	 * @return string|null
	 */
	public static function site_relative_from_zip_entry($name)
	{
		$clean = self::normalize_zip_entry($name);
		if ($clean === null) {
			return null;
		}

		if (strpos($clean, 'files/') === 0) {
			$clean = substr($clean, strlen('files/'));
		}
		$clean = ltrim($clean, '/');
		if ($clean === '' || !self::is_safe_zip_entry($clean)) {
			return null;
		}

		return $clean;
	}

	/**
	 * @param string $root     Absolute root (e.g. ABSPATH).
	 * @param string $relative Relative path (no leading slash).
	 * @return string|null Absolute path under $root, or null if unsafe.
	 */
	public static function resolve_under_root($root, $relative)
	{
		$root = self::normalize_fs_path($root);
		$relative = self::normalize_zip_entry($relative);
		if ($root === '' || $relative === null) {
			return null;
		}

		$root = rtrim($root, '/');
		$candidate = self::normalize_fs_path($root . '/' . $relative);

		$root_prefix = $root . '/';
		if ($candidate !== $root && strpos($candidate . '/', $root_prefix) !== 0) {
			return null;
		}

		$parent = dirname($candidate);
		if (is_dir($parent)) {
			$real_parent = realpath($parent);
			$real_root = realpath($root);
			if ($real_parent !== false && $real_root !== false) {
				$real_parent = self::normalize_fs_path($real_parent);
				$real_root = self::normalize_fs_path($real_root);
				if ($real_parent !== $real_root && strpos($real_parent . '/', $real_root . '/') !== 0) {
					return null;
				}
			}
		}

		return $candidate;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public static function normalize_fs_path($path)
	{
		$path = (string) $path;
		if (function_exists('wp_normalize_path')) {
			$path = wp_normalize_path($path);
		} else {
			$path = str_replace('\\', '/', $path);
		}
		return $path;
	}
}
