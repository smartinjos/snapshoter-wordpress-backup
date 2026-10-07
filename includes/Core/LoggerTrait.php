<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

trait LoggerTrait
{

	protected function log_prefix()
	{
		return 'Snapshoter: ';
	}

	protected function debug_log($message, $data = array())
	{
		if (!defined('SNAPSHOTER_DEBUG') || !SNAPSHOTER_DEBUG) {
			return;
		}

		$sanitized_data = $this->sanitize_log_data($data);
		$log_message = $this->log_prefix() . $message;
		if (!empty($sanitized_data)) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$log_message .= ' ' . print_r($sanitized_data, true);
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log($log_message);
	}

	protected function sanitize_log_data($data)
	{
		if (!is_array($data)) {
			return $data;
		}

		$sensitive_keys = array('passphrase', 'password', 'token', 'nonce', 'auth', 'secret', 'key', 'credential');
		$sanitized = array();

		foreach ($data as $key => $value) {
			$key_lower = strtolower($key);
			$is_sensitive = false;
			foreach ($sensitive_keys as $sensitive) {
				if (strpos($key_lower, $sensitive) !== false) {
					$is_sensitive = true;
					break;
				}
			}

			if ($is_sensitive) {
				$sanitized[$key] = '[REDACTED]';
			} elseif (is_array($value)) {
				$sanitized[$key] = $this->sanitize_log_data($value);
			} else {
				$sanitized[$key] = $value;
			}
		}

		return $sanitized;
	}
}
