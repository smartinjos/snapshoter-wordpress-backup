<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions

class StateStore
{

	public function job_dir($job_id)
	{

		$base_dir = rtrim(SNAPSHOTER_JOB_DIR, '/\\') . '/';
		$safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $job_id);

		if (empty($safe_id) || strlen($safe_id) > 128) {
			throw new \InvalidArgumentException(esc_html__('Invalid job ID format.', 'snapshoter'));
		}

		$final_path = $base_dir . $safe_id;
		$real_base = realpath($base_dir);

		if ($real_base) {
			$real_path = realpath(dirname($final_path));
			if (!$real_path || strpos($real_path, $real_base) !== 0) {
				throw new \RuntimeException(esc_html__('Path traversal attempt detected.', 'snapshoter'));
			}
		}

		return $final_path;
	}

	public function ensure_job_dir($job_id)
	{
		$path = $this->job_dir($job_id);
		if (!is_dir($path)) {

			$result = @mkdir($path, 0755, true);
			if (!$result && !is_dir($path)) {

				// translators: %s: Directory path
				throw new \RuntimeException(sprintf(esc_html__('Unable to create job directory: %s', 'snapshoter'), esc_html($path)));
			}
		}
	}

	public function exists($job_id)
	{
		try {
			$file = $this->job_dir($job_id) . '/state.json';
			return file_exists($file);
		} catch (\Throwable $e) {
			return false;
		}
	}

	public function update($job_id, array $changes)
	{
		$state = $this->read($job_id);
		$merged = array_merge(is_array($state) ? $state : array(), $changes);
		$this->write($job_id, $merged);
		return $merged;
	}

	public function read($job_id)
	{
		$file = $this->job_dir($job_id) . '/state.json';

		if (!file_exists($file)) {
			return array();
		}

		$handle = @fopen($file, 'rb');
		if (!$handle) {

			return array();
		}

		$contents = '';
		if (@flock($handle, LOCK_SH)) {
			$contents = stream_get_contents($handle);
			@flock($handle, LOCK_UN);
		} else {
			$contents = stream_get_contents($handle);
		}

		@fclose($handle);

		$data = json_decode($contents, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return array();
		}

		return is_array($data) ? $data : array();
	}

	public function write($job_id, array $state)
	{
		$dir = $this->job_dir($job_id);

		if (!is_dir($dir)) {

			@mkdir($dir, 0775, true);
			if (!is_dir($dir)) {
				@mkdir($dir, 0777, true);
			}
			if (!is_dir($dir) && function_exists('wp_mkdir_p')) {
				@wp_mkdir_p($dir);
			}
		}

		$file = $dir . '/state.json';

		$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);


		$result = @file_put_contents($file, $json, LOCK_EX);





		$expected_bytes = strlen($json);
		if ($result !== $expected_bytes) {
			throw new \RuntimeException(
				sprintf(

					// translators: %1$d: Expected byte count; %2$d: Bytes written; %3$s: File path
					esc_html__('State file write failed. Expected %1$d bytes, wrote %2$d bytes. File: %3$s', 'snapshoter'),
					absint($expected_bytes),
					absint($result),
					esc_html($file)
				)
			);
		}

	}

	public function delete($job_id)
	{
		$path = $this->job_dir($job_id);
		if (!is_dir($path)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $fileinfo) {
			if ($fileinfo->isDir()) {

				rmdir($fileinfo->getRealPath());
			} else {

				wp_delete_file($fileinfo->getRealPath());
			}
		}

		rmdir($path);
	}

	public function log($job_id, $message)
	{
		$message = is_string($message) ? $message : (string) $message;
		if (!preg_match('/\b(error|fail|critical|exception|unable|missing|denied|corrupt|warn|abort|cancel)/i', $message)) {
			return;
		}
		$file = $this->job_dir($job_id) . '/activity.log';
		$line = sprintf("[%s] %s\n", gmdate('c'), $message);
		@file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
	}

	public function get_log($job_id)
	{
		$file = $this->job_dir($job_id) . '/activity.log';
		if (!file_exists($file)) {
			return '';
		}

		return file_get_contents($file);
	}

	public function list_job_ids()
	{
		$base = rtrim(SNAPSHOTER_JOB_DIR, '/\\');
		if ($base === '' || !is_dir($base)) {
			return array();
		}

		$ids = array();
		$entries = @scandir($base);
		if (!is_array($entries)) {
			return array();
		}

		foreach ($entries as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$dir = $base . '/' . $name;
			if (!is_dir($dir)) {
				continue;
			}
			if (!file_exists($dir . '/state.json')) {
				continue;
			}
			$ids[] = $name;
		}

		return $ids;
	}
}

