<?php

namespace Snapshoter\Core;

use Snapshoter\Export\ExportJob;
use Snapshoter\Import\ImportJob;
use Snapshoter\Core\Environment;
use Snapshoter\Core\LoggerTrait;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.PHPErrorReporting
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// phpcs:disable WordPress.WP.AlternativeFunctions

class JobController
{
	use LoggerTrait;

	private $store;

	const BACKGROUND_TICK_HOOK = 'snapshoter_background_job_tick';

	const BACKGROUND_STALE_SECONDS = 5;

	const CANCEL_LOCK_WAIT_SECONDS = 30;

	const SUPERSEDE_LOCK_WAIT_SECONDS = 8;

	public function __construct()
	{
		$this->store = new StateStore();
	}

	protected function log_prefix()
	{
		return 'Snapshoter Backup: ';
	}

	public function start_job()
	{

		if (ob_get_level() === 0) {
			ob_start();
		}

		if (!defined('DIEONDBERROR')) {

			define('DIEONDBERROR', false);
		}

		$this->snapshoter_suppress_wordpress_db_checks();

		try {
			$this->guard();

			$type = isset($_POST['job_type']) ? sanitize_key(wp_unslash($_POST['job_type'])) : '';

			if (!in_array($type, array('export', 'import'), true)) {
				$this->snapshoter_send_json_error(array('message' => __('Unknown job type.', 'snapshoter')), 400);
				return;
			}

			if ($type === 'export') {

				$this->supersede_open_exports();
			}

			$job_id = uniqid('snapshoter_', true);

			try {
				$this->store->ensure_job_dir($job_id);
			} catch (\Throwable $e) {
				$this->debug_log('start_job() ensure_job_dir failed', array('error' => $e->getMessage()));
				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}

			$passphrase = $this->generate_job_passphrase();

			$initial_state = array(
				'job_passphrase_hash' => $this->hash_job_passphrase($passphrase),
				'type' => $type,
			);
			$this->remember_job_passphrase($job_id, $passphrase);

			if ($type === 'export') {

				if (isset($_POST['is_snapshot'])) {
					$raw = sanitize_text_field(wp_unslash($_POST['is_snapshot']));
					$initial_state['is_snapshot'] = in_array(
						strtolower($raw),
						array('1', 'true', 'yes', 'on'),
						true
					);
				}
				if (isset($_POST['scope'])) {
					$scope = sanitize_key(wp_unslash($_POST['scope']));
					if (in_array($scope, array('full', 'database', 'files'), true)) {
						$initial_state['scope'] = $scope;
					}
				}

			}

			try {
				$this->store->write($job_id, $initial_state);
			} catch (\Throwable $e) {
				$this->debug_log('start_job() write failed', array('error' => $e->getMessage()));
				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}

			try {
				$job = $this->create_job($type, $job_id);
				$response = $job->init();

				if ($type === 'export') {
					ExportLock::register($job_id);
				}

				$response['jobPassphrase'] = $passphrase;

				if (ob_get_level() > 0) {
					$output = ob_get_contents();
					if (!empty($output) && !$this->is_json_output($output)) {

					}
					ob_end_clean();
				}

				$this->snapshoter_send_json_success($response);
			} catch (\Throwable $e) {
				$this->debug_log('Exception in start_job()', array('error' => $e->getMessage()));
				if ($type === 'export') {
					ExportLock::release($job_id);
					try {
						$failed = $this->store->read($job_id);
						if (!is_array($failed)) {
							$failed = array();
						}
						$failed['status'] = 'failed';
						$failed['step'] = 'failed';
						$failed['message'] = $e->getMessage();
						$failed['error'] = $e->getMessage();
						$this->store->write($job_id, $failed);
						$this->clear_job_passphrase($job_id);
					} catch (\Throwable $ignore) {

					}
				}

				while (ob_get_level() > 0) {
					ob_end_clean();
				}

				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}
		} catch (\Throwable $e) {
			$this->debug_log('Exception in start_job()', array('error' => $e->getMessage()));

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			$this->snapshoter_send_json_error(array('message' => $e->getMessage()), 500);
			return;
		}

	}

	public function cancel_job()
	{

		if (ob_get_level() === 0) {
			ob_start();
		}

		if (!defined('DIEONDBERROR')) {

			define('DIEONDBERROR', false);
		}

		try {
			$this->guard();

			$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
			$job_passphrase = isset($_POST['job_passphrase']) ? sanitize_text_field(wp_unslash($_POST['job_passphrase'])) : '';

			if (empty($job_id)) {
				while (ob_get_level() > 0) {
					ob_end_clean();
				}
				wp_send_json_error(array('message' => __('Missing job id.', 'snapshoter')), 400);
				return;
			}

			if (strlen($job_id) > 128 || strlen($job_passphrase) > 128) {
				while (ob_get_level() > 0) {
					ob_end_clean();
				}
				wp_send_json_error(array('message' => __('Input exceeds maximum length.', 'snapshoter')), 400);
				return;
			}

			$this->guard($job_id, $job_passphrase);

			$current_state = $this->store->read($job_id);

			if (empty($current_state)) {
				while (ob_get_level() > 0) {
					ob_end_clean();
				}
				wp_send_json_error(array('message' => __('Job not found.', 'snapshoter')), 404);
				return;
			}

			$this->snapshoter_clear_background_driver($job_id);

			$previous_status = isset($current_state['status']) ? (string) $current_state['status'] : '';

			$this->store->write(
				$job_id,
				array_merge(
					$current_state,
					array('status' => 'cancelled', 'driver' => null)
				)
			);

			$lock_handle = $this->snapshoter_acquire_job_lock($job_id);
			$this->snapshoter_wait_for_exclusive_job_lock($lock_handle, self::CANCEL_LOCK_WAIT_SECONDS);

			$this->snapshoter_run_cancel_cleanup($job_id, $current_state, $previous_status);

			if (is_resource($lock_handle)) {
				$this->snapshoter_release_job_lock($lock_handle);
			}

			try {
				$this->store->log($job_id, 'Job cancellation requested by user.');
			} catch (\Exception $log_error) {

				$this->debug_log('Failed to log cancellation', array('error' => $log_error->getMessage()));
			}

			$this->clear_job_passphrase($job_id);

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			wp_send_json_success(array('message' => __('Job cancelled.', 'snapshoter')));
		} catch (\Throwable $e) {

			$this->debug_log('Exception in cancel_job()', array('error' => $e->getMessage()));

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			wp_send_json_error(array('message' => __('Failed to cancel the job.', 'snapshoter')), 500);
			return;
		}
	}

	public function run_job()
	{
		if (!defined('DIEONDBERROR')) {

			define('DIEONDBERROR', false);
		}

		$this->snapshoter_suppress_wordpress_db_checks();

		$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
		$job_passphrase = isset($_POST['job_passphrase']) ? sanitize_text_field(wp_unslash($_POST['job_passphrase'])) : '';

		if (empty($job_id)) {
			$this->snapshoter_send_json_error(array('message' => __('Missing job id.', 'snapshoter')), 400);
		}

		if (strlen($job_id) > 128 || strlen($job_passphrase) > 128) {
			$this->snapshoter_send_json_error(array('message' => __('Input exceeds maximum length.', 'snapshoter')), 400);
		}

		$this->guard($job_id, $job_passphrase);

		Environment::configure();

		$state = $this->store->read($job_id);

		if (empty($state)) {

			$this->snapshoter_send_json_success(array('completed' => true, 'job_not_found_graceful' => true));
			return;
		}

		$is_admin = function_exists('is_user_logged_in')
			&& is_user_logged_in()
			&& function_exists('current_user_can')
			&& current_user_can(SNAPSHOTER_CAPABILITY);
		if (!$is_admin && (empty($state['type']) || $state['type'] !== 'import')) {
			$this->snapshoter_send_json_error(
				array(
					'message' => __('Permission denied. Export jobs require an administrator session.', 'snapshoter'),
				),
				403
			);
			return;
		}

		if (empty($state) || empty($state['type'])) {
			$this->snapshoter_send_json_error(array('message' => __('Job not found.', 'snapshoter')), 404);
		}

		if ($state['type'] === 'import') {
			$this->snapshoter_suppress_wordpress_db_checks();
		}

		@ini_set('display_errors', '0');
		ob_start();
		$ob_started = true;

		register_shutdown_function(function () use ($job_id) {
			$error = error_get_last();
			if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
				while (ob_get_level() > 0) {
					ob_end_clean();
				}
				if (!headers_sent()) {
					header('Content-Type: application/json; charset=utf-8');
					http_response_code(200);
					header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				}
				$msg = isset($error['message']) ? $error['message'] : 'Server error occurred.';
				echo json_encode(array(
					'success' => false,
					'data' => array(
						'jobId' => $job_id,
						'message' => 'PHP Server Error: ' . $msg,
						'step' => 'failed',
						'status' => 'failed',
					)
				), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		});

		$lock_handle = $this->snapshoter_acquire_job_lock($job_id);
		$lock_busy = false;

		try {
			$job = $this->create_job($state['type'], $job_id);

				$acquired = @flock($lock_handle, LOCK_EX | LOCK_NB);
				if (!$acquired) {

					$lock_busy = true;
					$response = $this->snapshoter_build_busy_response($job_id, $state);
				} else {
					$response = $job->tick();
				}

		} catch (\Throwable $e) {
			$this->debug_log('Exception in run_job()', array('error' => $e->getMessage()));
			if ($ob_started && ob_get_level() > 0) {
				ob_end_clean();
			}
			$this->snapshoter_release_job_lock($lock_handle);
			$this->snapshoter_send_json_error(array('message' => $e->getMessage()), 500);
			return;
		}

		$this->snapshoter_release_job_lock($lock_handle);

		if ($ob_started && ob_get_level() > 0) {
			$output = ob_get_contents();
			if (!empty($output)) {

			}
			ob_end_clean();
		}

		if (!$lock_busy) {
			$this->snapshoter_stamp_tick_at($job_id);
			$this->snapshoter_continue_job_if_needed($response);
			$this->snapshoter_maybe_release_export_lock($job_id, $response);
		}

		$this->snapshoter_send_json_success($response);
	}

	public function get_status()
	{
		if (!defined('DIEONDBERROR')) {

			define('DIEONDBERROR', false);
		}

		@ini_set('display_errors', '0');
		if (ob_get_level() === 0) {
			ob_start();
		}

		if (function_exists('nocache_headers') && !headers_sent()) {
			nocache_headers();
		}

		try {

			$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
			$job_passphrase = isset($_POST['job_passphrase']) ? sanitize_text_field(wp_unslash($_POST['job_passphrase'])) : '';

			if (empty($job_id)) {
				$this->snapshoter_send_json_error(array('message' => __('Missing job id.', 'snapshoter')), 400);
				return;
			}

			if (strlen($job_id) > 128 || strlen($job_passphrase) > 128) {
				$this->snapshoter_send_json_error(array('message' => __('Input exceeds maximum length.', 'snapshoter')), 400);
				return;
			}

			$is_privileged = function_exists('current_user_can') && current_user_can(SNAPSHOTER_CAPABILITY);

			if (!$is_privileged) {
				$this->send_public_status($job_id, $job_passphrase);
				return;
			}

			$this->guard($job_id, $job_passphrase);

			$state = $this->store->read($job_id);
			if (empty($state) || empty($state['type'])) {

				$this->snapshoter_send_json_success(
					array(
						'completed' => true,
						'step' => 'completed',
						'status' => 'completed',
						'job_not_found_graceful' => true,
					)
				);
				return;
			}

			$response = $this->snapshoter_build_status_response($job_id, $state);

			$this->maybe_continue_stale_job($job_id, $state);

			while (ob_get_level() > 0) {
				ob_end_clean();
			}
			$this->snapshoter_send_json_success($response);
		} catch (\Throwable $e) {
			$this->debug_log('Exception in get_status()', array('error' => $e->getMessage()));
			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			$this->snapshoter_send_json_error(
				array(
					'message' => __('Could not read job status.', 'snapshoter'),
					'detail' => $e->getMessage(),
				),
				200
			);
		}
	}

	/**
	 * @param string $job_id         Job id.
	 * @param string $job_passphrase Passphrase issued when the job started.
	 * @return void
	 */
	private function send_public_status($job_id, $job_passphrase)
	{
		$authorized = $this->authorize_status_read($job_id, $job_passphrase);

		if (empty($authorized['ok'])) {
			$code = isset($authorized['code']) ? (string) $authorized['code'] : 'status_denied';
			$status = ($code === 'status_throttled') ? 429 : 403;

			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			$this->snapshoter_send_json_error(
				array(
					'message' => __('Status is not available for this job.', 'snapshoter'),
					'code' => $code,
				),
				$status
			);
			return;
		}

		$state = $authorized['state'];
		$response = $this->build_public_status_response($job_id, $state);

		$this->maybe_continue_stale_job($job_id, $state);

		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		$this->snapshoter_send_json_success($response);
	}

	/**
	 * @param string $job_id         Job id.
	 * @param string $job_passphrase Passphrase issued when the job started.
	 * @return array { ok: bool, code: string, state: array }
	 */
	private function authorize_status_read($job_id, $job_passphrase)
	{
		$deny = array('ok' => false, 'code' => 'status_denied', 'state' => array());

		if (!is_string($job_id) || !preg_match('/^[A-Za-z0-9_.\-]{8,128}$/', $job_id)) {
			return $deny;
		}

		if (!is_string($job_passphrase) || !preg_match('/^[A-Za-z0-9]{16,128}$/', $job_passphrase)) {
			return $deny;
		}

		try {
			$state = $this->store->read($job_id);
		} catch (\Throwable $e) {
			return $deny;
		}

		if (empty($state) || empty($state['type']) || $state['type'] !== 'import') {
			return $deny;
		}

		if (!$this->verify_job_passphrase($job_id, $job_passphrase, $state)) {
			return $deny;
		}

		if ($this->snapshoter_job_is_terminal($state)) {
			$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
			$updated = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
			$finished = max($tick_at, $updated);

			if ($finished > 0 && (time() - $finished) > (30 * MINUTE_IN_SECONDS)) {
				return $deny;
			}
		}

		if (!$this->status_read_throttle_ok($job_id)) {
			return array('ok' => false, 'code' => 'status_throttled', 'state' => array());
		}

		return array('ok' => true, 'code' => 'ok', 'state' => $state);
	}

	/**
	 * @param string $job_id Job id.
	 * @return bool
	 */
	private function status_read_throttle_ok($job_id)
	{
		try {
			$marker = $this->store->job_dir($job_id) . '/.status-read';
		} catch (\Throwable $e) {
			return true;
		}

		$now = microtime(true);

		if (file_exists($marker)) {
			$last = (float) @file_get_contents($marker);
			if ($last > 0 && ($now - $last) < 0.5) {
				return false;
			}
		}

		@file_put_contents($marker, (string) $now, LOCK_EX);

		return true;
	}

	/**
	 * @param string $job_id Job id.
	 * @param array  $state  Job state.
	 * @return array
	 */
	private function build_public_status_response($job_id, array $state)
	{
		$progress = null;
		if (isset($state['percent']) && is_numeric($state['percent'])) {
			$progress = (float) $state['percent'];
		} elseif (isset($state['progress']) && is_numeric($state['progress'])) {
			$progress = (float) $state['progress'];
		}

		$status = isset($state['status']) ? (string) $state['status'] : 'running';

		return array(
			'jobId' => $job_id,
			'type' => 'import',
			'step' => isset($state['step']) ? (string) $state['step'] : '',
			'status' => $status,
			'progress' => $progress,
			'percent' => $progress,
			'message' => $this->scrub_public_message(isset($state['message']) ? (string) $state['message'] : ''),
			'completed' => $this->snapshoter_job_is_terminal($state)
				&& in_array($status, array('completed', 'partial'), true),
			'lock_busy' => false,
			'public_status' => true,
		);
	}

	/**
	 * @param string $message Raw message.
	 * @return string
	 */
	private function scrub_public_message($message)
	{
		$message = wp_strip_all_tags((string) $message);
		if ($message === '') {
			return '';
		}

		$message = preg_replace('#(?:[A-Za-z]:)?[/\\\\][^\s\'"]*[/\\\\][^\s\'"]*#', '...', $message);

		return trim((string) substr((string) $message, 0, 300));
	}

	/**
	 * @param string $job_id Job id.
	 * @param array  $state  Job state.
	 * @return void
	 */
	private function maybe_continue_stale_job($job_id, array $state)
	{
		if (
			$this->snapshoter_job_is_terminal($state)
			|| empty($state['archive_path'])
			|| !$this->snapshoter_job_is_stale($state)
		) {
			return;
		}

		$this->snapshoter_schedule_background_tick($job_id, 0);
		$this->snapshoter_continue_job_if_needed(
			array(
				'jobId' => $job_id,
				'completed' => false,
			)
		);

		if (function_exists('spawn_cron')) {
			spawn_cron(time());
		}
	}

	public function refresh_nonce()
	{
		if (!is_user_logged_in() || !current_user_can('manage_options')) {
			$this->snapshoter_send_json_error(
				array(
					'message' => __('Authentication required.', 'snapshoter'),
					'code' => 'auth_lost',
				),
				401
			);
			return;
		}

		$this->snapshoter_send_json_success(
			array(
				'nonce' => wp_create_nonce(SNAPSHOTER_NONCE_ACTION),
			)
		);
	}

	public function handle_background_tick($job_id)
	{
		$job_id = is_string($job_id) ? sanitize_text_field($job_id) : '';
		if ($job_id === '') {
			return;
		}

		$state = $this->store->read($job_id);
		if (empty($state) || empty($state['type'])) {
			return;
		}

		if ($this->snapshoter_job_is_terminal($state)) {
			$this->snapshoter_clear_background_driver($job_id);
			$this->clear_job_passphrase($job_id);
			return;
		}

		if ($state['type'] === 'import') {
			$this->snapshoter_suppress_wordpress_db_checks();
		}

		$tick = $this->snapshoter_execute_job_tick($job_id, $state);
		if ($tick === null) {
			$this->snapshoter_schedule_background_tick($job_id, 2);
			return;
		}

		if (!empty($tick['lock_busy'])) {
			$this->snapshoter_schedule_background_tick($job_id, 2);
			return;
		}

		$this->snapshoter_stamp_tick_at($job_id);
		$response = $tick['response'];
		$completed = !empty($response['completed']);
		$fresh = $this->store->read($job_id);

		if ($completed || $this->snapshoter_job_is_terminal($fresh)) {
			$this->snapshoter_clear_background_driver($job_id);
			$this->clear_job_passphrase($job_id);
			return;
		}

		$this->snapshoter_continue_job_if_needed($response);
	}

	private function snapshoter_acquire_job_lock($job_id)
	{
		try {
			$job_dir = $this->store->job_dir($job_id);
		} catch (\Throwable $e) {
			return null;
		}

		if (!is_dir($job_dir)) {
			return null;
		}

		$lock_path = $job_dir . '/.lock';

		return @fopen($lock_path, 'c');
	}

	private function snapshoter_release_job_lock($handle)
	{
		if (!is_resource($handle)) {
			return;
		}
		@flock($handle, LOCK_UN);

		@fclose($handle);
	}

	private function snapshoter_build_busy_response($job_id, array $state)
	{

		$fresh = $this->store->read($job_id);
		if (!empty($fresh) && is_array($fresh)) {
			$state = $fresh;
		}

		if (isset($state['job_passphrase'])) {
			unset($state['job_passphrase']);
		}
		if (isset($state['job_passphrase_hash'])) {
			unset($state['job_passphrase_hash']);
		}

		$progress = null;
		if (isset($state['percent']) && is_numeric($state['percent'])) {
			$progress = (float) $state['percent'];
		} elseif (isset($state['progress']) && is_numeric($state['progress'])) {
			$progress = (float) $state['progress'];
		}

		$engine = isset($state['engine']) ? (string) $state['engine'] : '';
		$restore_mode = isset($state['restore_mode']) ? (string) $state['restore_mode'] : '';

		$response = array(
			'jobId' => $job_id,
			'type' => isset($state['type']) ? $state['type'] : '',
			'step' => isset($state['step']) ? $state['step'] : '',
			'status' => isset($state['status']) ? $state['status'] : 'running',
			'progress' => $progress,
			'percent' => $progress,
			'engine' => $engine,
			'restore_mode' => $restore_mode,
			'message' => isset($state['message']) ? (string) $state['message'] : '',
			'state' => $state,
			'log' => $this->store->get_log($job_id),
			'lock_busy' => true,
		);

		$download = $this->snapshoter_export_download_fields($state);
		if (!empty($download)) {
			$response = array_merge($response, $download);
		}

		return $response;
	}

	private function snapshoter_export_download_fields(array $state)
	{
		if (empty($state['type']) || $state['type'] !== 'export') {
			return array();
		}

		$path = '';
		if (!empty($state['snapshot_path']) && is_string($state['snapshot_path']) && file_exists($state['snapshot_path'])) {
			$path = $state['snapshot_path'];
		} elseif (!empty($state['archive_path']) && is_string($state['archive_path']) && file_exists($state['archive_path'])) {
			$path = $state['archive_path'];
		}

		$filename = '';
		if ($path !== '') {
			$filename = basename($path);
		} elseif (!empty($state['filename']) && is_string($state['filename']) && preg_match('/\.(smartin|sql|zip)$/i', $state['filename'])) {
			$filename = basename($state['filename']);
		} elseif (!empty($state['snapshot_path']) && is_string($state['snapshot_path'])) {
			$filename = basename($state['snapshot_path']);
		} elseif (!empty($state['archive_path']) && is_string($state['archive_path'])) {
			$filename = basename($state['archive_path']);
		}

		if ($filename === '' || !preg_match('/\.(smartin|sql|zip)$/i', $filename)) {
			return array();
		}

		$download_url = add_query_arg(
			array(
				'action' => 'SNAPSHOTER_download_file',
				'file'   => $filename,
				'nonce'  => wp_create_nonce('SNAPSHOTER_download'),
			),
			admin_url('admin-ajax.php')
		);

		return array(
			'filename'     => $filename,
			'download_url' => $download_url,
			'downloadUrl'  => $download_url,
		);
	}

	private function snapshoter_suppress_wordpress_db_checks()
	{
		if (!defined('WP_IMPORTING')) {
			define('WP_IMPORTING', true);
		}

		add_filter('wp_check_database_version', '__return_true', 999);

		add_filter('pre_option_is_blog_installed', '__return_true', 999);

		add_filter('wp_db_version', function () {
			return 57155;
		}, 999);
	}

	private function snapshoter_send_json_error($data = null, $status_code = 400)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			http_response_code($status_code);

			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Cache-Control: post-check=0, pre-check=0', false);
			header('Pragma: no-cache');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
		}

		$response = array('success' => false);
		if (isset($data)) {
			$response['data'] = $data;
		}

		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	private function snapshoter_send_json_success($data = null)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			http_response_code(200);

			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Cache-Control: post-check=0, pre-check=0', false);
			header('Pragma: no-cache');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
		}

		$response = array('success' => true);
		if (isset($data)) {
			$response['data'] = $data;
		}

		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	private function snapshoter_continue_job_if_needed($response)
	{
		$completed = isset($response['completed']) && $response['completed'] === true;

		if ($completed) {
			$job_id_done = isset($response['jobId']) ? $response['jobId'] : '';
			if ($job_id_done !== '') {
				$this->snapshoter_clear_background_driver($job_id_done);
				$this->clear_job_passphrase($job_id_done);
			}
			return;
		}

		$job_id = isset($response['jobId']) ? $response['jobId'] : '';
		if (empty($job_id)) {
			return;
		}

		$state = $this->store->read($job_id);
		if (!is_array($state)) {
			$state = array();
		}

		if (!empty($state['type']) && $state['type'] === 'export') {
			return;
		}

		$server_driven = !empty($state['driver']) && $state['driver'] === 'server';

		if ($server_driven || (!empty($state['type']) && $state['type'] === 'import' && !empty($state['archive_path']))) {
			$this->snapshoter_schedule_background_tick($job_id, 1);
		}

		$url = add_query_arg(
			array(
				'action' => 'SNAPSHOTER_run_job',
				'job_id' => $job_id,
			),
			admin_url('admin-ajax.php')
		);

		$nonce = wp_create_nonce(SNAPSHOTER_NONCE_ACTION);
		$url = add_query_arg(SNAPSHOTER_NONCE_NAME, $nonce, $url);

		$job_passphrase = $this->recall_job_passphrase($job_id);
		$this->debug_log(
			'scheduling self-ping for job',
			array(
				'job_id' => substr($job_id, 0, 20) . '...',
				'has_passphrase' => !empty($job_passphrase),
				'server_driven' => $server_driven,
			)
		);

		wp_remote_request(
			$url,
			array(
				'method' => 'POST',
				'timeout' => 10,
				'blocking' => false,

				'sslverify' => (bool) apply_filters('snapshoter_self_ping_sslverify', true, $url),
				'headers' => array(
					'X-Requested-With' => 'XMLHttpRequest',
				),
				'body' => array(
					'job_id' => $job_id,
					'job_passphrase' => $job_passphrase,
				),
			)
		);
	}

	private function snapshoter_start_background_driver($job_id)
	{
		$state = $this->store->read($job_id);
		if (empty($state) || empty($state['type']) || $state['type'] !== 'import') {
			return;
		}

		$state['driver'] = 'server';
		$state['status'] = isset($state['status']) && $state['status'] !== '' ? $state['status'] : 'running';
		$this->store->write($job_id, $state);
		$this->store->log($job_id, 'Upload complete - server-driven restore started (PC polls status only).');

		$this->snapshoter_schedule_background_tick($job_id, 0);
		$this->snapshoter_continue_job_if_needed(
			array(
				'jobId' => $job_id,
				'completed' => false,
			)
		);

		if (function_exists('spawn_cron')) {
			spawn_cron(time());
		}
	}

	private function snapshoter_schedule_background_tick($job_id, $delay_seconds = 1)
	{
		$job_id = sanitize_text_field($job_id);
		if ($job_id === '') {
			return;
		}

		$delay_seconds = max(0, (int) $delay_seconds);
		$args = array($job_id);

		if (!wp_next_scheduled(self::BACKGROUND_TICK_HOOK, $args)) {
			wp_schedule_single_event(time() + $delay_seconds, self::BACKGROUND_TICK_HOOK, $args);
		}
	}

	private function snapshoter_clear_background_driver($job_id)
	{
		$job_id = sanitize_text_field($job_id);
		if ($job_id === '') {
			return;
		}

		wp_clear_scheduled_hook(self::BACKGROUND_TICK_HOOK, array($job_id));

	}

	private function snapshoter_stamp_tick_at($job_id)
	{
		$state = $this->store->read($job_id);
		if (empty($state) || !is_array($state)) {
			return;
		}
		$state['tick_at'] = time();
		$this->store->write($job_id, $state);
	}

	private function snapshoter_job_is_terminal(array $state)
	{
		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';
		return in_array($status, array('completed', 'partial', 'failed', 'cancelled'), true)
			|| in_array($step, array('completed', 'failed', 'cancelled'), true);
	}

	private function snapshoter_job_is_stale(array $state)
	{
		$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
		if ($tick_at <= 0) {

			return true;
		}
		return (time() - $tick_at) >= self::BACKGROUND_STALE_SECONDS;
	}

	private function snapshoter_build_status_response($job_id, array $state)
	{
		$response = $this->snapshoter_build_busy_response($job_id, $state);
		$response['lock_busy'] = false;
		$response['driver'] = isset($state['driver']) ? $state['driver'] : null;
		$status = isset($state['status']) ? (string) $state['status'] : '';

		$response['completed'] = $this->snapshoter_job_is_terminal($state)
			&& in_array($status, array('completed', 'partial'), true);
		return $response;
	}

	private function snapshoter_execute_job_tick($job_id, array $state)
	{
		$lock_handle = $this->snapshoter_acquire_job_lock($job_id);
		$lock_busy = false;

		try {
			$job = $this->create_job($state['type'], $job_id);

				$acquired = @flock($lock_handle, LOCK_EX | LOCK_NB);
				if (!$acquired) {
					$lock_busy = true;
					$response = $this->snapshoter_build_busy_response($job_id, $state);
				} else {
					$response = $job->tick();
				}

		} catch (\Throwable $e) {
			$this->debug_log('Exception in snapshoter_execute_job_tick()', array('error' => $e->getMessage()));
			$this->snapshoter_release_job_lock($lock_handle);
			return null;
		}

		$this->snapshoter_release_job_lock($lock_handle);

		return array(
			'response' => $response,
			'lock_busy' => $lock_busy,
		);
	}

	public function upload_chunk()
	{

		$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

		try {
			$this->guard();
			$this->debug_log('Authentication passed in upload_chunk()');
		} catch (\Throwable $e) {
			$this->debug_log('Authentication failed in upload_chunk()', array('error' => $e->getMessage()));
			wp_send_json_error(array('message' => __('Authentication failed.', 'snapshoter')), 403);
			return;
		}

		if (empty($job_id)) {
			wp_send_json_error(array('message' => __('Missing job id.', 'snapshoter')), 400);
			return;
		}

		if (strlen($job_id) > 128) {
			wp_send_json_error(array('message' => __('Input exceeds maximum length.', 'snapshoter')), 400);
			return;
		}

		try {
			$state = $this->store->read($job_id);
		} catch (\Throwable $e) {
			$this->debug_log('Failed to read job state in upload_chunk()', array('error' => $e->getMessage()));
			wp_send_json_error(array('message' => __('Failed to read job state.', 'snapshoter')), 500);
			return;
		}

		if (empty($state) || $state['type'] !== 'import') {
			wp_send_json_error(array('message' => __('Upload target not found.', 'snapshoter')), 404);
			return;
		}

		$upload_max = self::parse_size(ini_get('upload_max_filesize'), 0);
		$post_max = self::parse_size(ini_get('post_max_size'), 0);
		$max_chunk = self::max_chunk_size();

		if (empty($_POST) && empty($_FILES)) {
			wp_send_json_error(array(
				'message' => sprintf(

					// translators: %1$s: current post_max_size; %2$s: recommended post_max_size.
					__('No data received. post_max_size may be too small (current: %1$s). Increase post_max_size in php.ini to at least %2$s.', 'snapshoter'),
					esc_html(ini_get('post_max_size')),
					esc_html(size_format($max_chunk * 1.5))
				)
			), 400);
			return;
		}

		if (empty($_FILES['chunk'])) {
			wp_send_json_error(array(
				'message' => sprintf(

					// translators: %1$s: upload_max_filesize; %2$s: post_max_size; %3$s: recommended size.
					__('Chunk file not received. PHP limits: upload_max_filesize=%1$s, post_max_size=%2$s. Recommended: at least %3$s for each.', 'snapshoter'),
					esc_html(ini_get('upload_max_filesize')),
					esc_html(ini_get('post_max_size')),
					esc_html(size_format($max_chunk * 1.5))
				)
			), 400);
		}

		$total_chunks = isset($_POST['total_chunks']) ? absint($_POST['total_chunks']) : 0;
		$index = isset($_POST['chunk_index']) ? absint($_POST['chunk_index']) : 0;
		$filename = isset($_POST['file_name']) ? sanitize_file_name(wp_unslash($_POST['file_name'])) : 'upload.smartin';

		if ($total_chunks <= 0) {
			wp_send_json_error(array('message' => __('Invalid chunk meta.', 'snapshoter')), 400);
			return;
		}

		if (strlen($job_id) > 128 || strlen($filename) > 255) {
			wp_send_json_error(array('message' => __('Input exceeds maximum length.', 'snapshoter')), 400);
			return;
		}

		$target = $this->store->job_dir($job_id) . '/' . $filename . '.part';
		$parts_mode = isset($_POST['upload_mode']) && 'parts' === sanitize_key(wp_unslash($_POST['upload_mode']));
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$chunk = isset($_FILES['chunk']) ? $_FILES['chunk'] : null;

		if (empty($chunk) || !isset($chunk['tmp_name']) || empty($chunk['tmp_name'])) {
			if (isset($chunk['error']) && $chunk['error'] !== UPLOAD_ERR_OK) {
				$upload_errors = array(
					UPLOAD_ERR_INI_SIZE => __('Chunk exceeds upload_max_filesize OR total POST request exceeds post_max_size', 'snapshoter'),
					UPLOAD_ERR_FORM_SIZE => __('Chunk exceeds MAX_FILE_SIZE', 'snapshoter'),
					UPLOAD_ERR_PARTIAL => __('Chunk was only partially uploaded', 'snapshoter'),
					UPLOAD_ERR_NO_FILE => __('No chunk was uploaded', 'snapshoter'),
					UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder', 'snapshoter'),
					UPLOAD_ERR_CANT_WRITE => __('Failed to write chunk to disk', 'snapshoter'),
					UPLOAD_ERR_EXTENSION => __('Chunk upload stopped by extension', 'snapshoter'),
				);

				$error_msg = isset($upload_errors[$chunk['error']])
					? $upload_errors[$chunk['error']]
					: sprintf(

						// translators: %d: number
						__('Upload error code: %d', 'snapshoter'),
						absint($chunk['error'])
					);

				$diagnosis = '';
				if ($chunk['error'] === UPLOAD_ERR_INI_SIZE) {
					$reported_size = isset($chunk['size']) ? $chunk['size'] : 0;
					$upload_max_bytes = self::parse_size(ini_get('upload_max_filesize'), 0);
					$post_max_bytes = self::parse_size(ini_get('post_max_size'), 0);

					if ($reported_size > 0 && $reported_size <= $upload_max_bytes) {
						$diagnosis = sprintf(

							// translators: %1$s: chunk size; %2$s: upload_max_filesize; %3$s: post_max_size.
							__(' Chunk size (%1$s) is within upload_max_filesize (%2$s), but total POST request likely exceeds post_max_size (%3$s).', 'snapshoter'),
							esc_html(size_format($reported_size)),
							esc_html(size_format($upload_max_bytes)),
							esc_html(size_format($post_max_bytes))
						);
					} else {
						$diagnosis = sprintf(

							// translators: %1$s: chunk size; %2$s: upload_max_filesize.
							__(' Chunk size (%1$s) exceeds upload_max_filesize (%2$s).', 'snapshoter'),
							$reported_size > 0 ? esc_html(size_format($reported_size)) : esc_html__('unknown', 'snapshoter'),
							esc_html(size_format($upload_max_bytes))
						);
					}
				}

				wp_send_json_error(array(
					'message' => sprintf(

						// translators: %1$s: error message; %2$s: diagnosis; %3$s: upload_max_filesize; %4$s: post_max_size; %5$s: expected chunk size.
						__('Chunk upload failed: %1$s.%2$s PHP limits: upload_max_filesize=%3$s, post_max_size=%4$s, Expected chunk size: %5$s', 'snapshoter'),
						esc_html($error_msg),
						esc_html($diagnosis),
						esc_html(ini_get('upload_max_filesize')),
						esc_html(ini_get('post_max_size')),
						esc_html(size_format($max_chunk))
					)
				), 400);
			}

			if (isset($_POST['chunk_index']) && isset($_POST['total_chunks'])) {
				$error_msg = sprintf(

					// translators: %1$s: upload_max_filesize; %2$s: post_max_size; %3$s: recommended size.
					__('Chunk file is missing or invalid. This may be due to PHP upload size limits. Current limits: upload_max_filesize=%1$s, post_max_size=%2$s. Recommended: at least %3$s for each.', 'snapshoter'),
					esc_html(ini_get('upload_max_filesize')),
					esc_html(ini_get('post_max_size')),
					esc_html(size_format($max_chunk * 1.5))
				);
			} else {
				$error_msg = __('Chunk file is missing or invalid.', 'snapshoter');
			}

			wp_send_json_error(array('message' => $error_msg), 400);
		}

		if (!file_exists($chunk['tmp_name'])) {
			wp_send_json_error(array('message' => __('Chunk file does not exist on server.', 'snapshoter')), 400);
		}

		$target_dir = dirname($target);
		if (!is_dir($target_dir)) {
			wp_mkdir_p($target_dir);
			if (!is_dir($target_dir)) {
				$this->debug_log('Failed to create target directory', array('directory' => basename($target_dir)));
				wp_send_json_error(array('message' => __('Unable to create target directory.', 'snapshoter')), 500);
				return;
			}
		}

		if ($parts_mode) {
			$this->upload_chunk_parts_mode($job_id, $state, $filename, $target, $chunk, $index, $total_chunks);
			return;
		}

		$handle = fopen($target, $index === 0 ? 'wb' : 'ab');

		$input = fopen($chunk['tmp_name'], 'rb');

		$bytes_copied = stream_copy_to_stream($input, $handle);

		fclose($handle);

		fclose($input);

		if ($index === 0 && file_exists($target) && filesize($target) >= 4) {

			$zip_handle = @fopen($target, 'rb');
			if ($zip_handle) {

				$magic_bytes = fread($zip_handle, 4);

				fclose($zip_handle);

				if ($magic_bytes !== "PK\x03\x04") {

					wp_delete_file($target);
					wp_send_json_error(array('message' => __('Invalid file format. Expected ZIP archive. Please ensure you are uploading a .smartin backup file.', 'snapshoter')), 400);
					return;
				}
			}
		}

		$received = isset($state['upload']) ? (int) $state['upload']['received'] : 0;
		$received++;

		$state['upload'] = array(
			'name' => $filename,
			'path' => $target,
			'received' => $received,
			'total' => $total_chunks,
			'completed' => ($received >= $total_chunks),
		);

		if ($state['upload']['completed']) {
			$final = $this->store->job_dir($job_id) . '/' . $filename;

			$zip_handle = @fopen($target, 'rb');
			if ($zip_handle) {

				$magic_bytes = fread($zip_handle, 4);

				fclose($zip_handle);

				if ($magic_bytes !== "PK\x03\x04") {
					$this->debug_log('Invalid ZIP file uploaded - magic number mismatch');
					wp_send_json_error(array('message' => __('Uploaded file is not a valid ZIP archive. File may be corrupted.', 'snapshoter')), 400);
					return;
				}
			}

			if (file_exists($target)) {
				clearstatcache(true, $target);
			}

			if (rename($target, $final)) {
				if (!file_exists($final) || !is_readable($final)) {
					$this->debug_log('Final file verification failed', array('filename' => basename($final)));
					wp_send_json_error(array('message' => __('Failed to verify final archive file.', 'snapshoter')), 500);
					return;
				}

				$state['archive_path'] = $final;
				$state['archive_size'] = filesize($final);
			} else {
				$this->debug_log('Failed to rename uploaded file', array('from' => basename($target), 'to' => basename($final)));
				wp_send_json_error(array('message' => __('Failed to finalize upload file.', 'snapshoter')), 500);
				return;
			}
		}

		try {
			$this->store->write($job_id, $state);
		} catch (\Throwable $e) {
			$this->debug_log('Failed to write state in upload_chunk()', array('error' => $e->getMessage()));
			wp_send_json_error(array('message' => __('Failed to save job state.', 'snapshoter')), 500);
			return;
		}

		$response_data = array(
			'jobId' => $job_id,
			'uploaded' => $state['upload']['received'],
			'total' => $state['upload']['total'],
			'complete' => $state['upload']['completed'],
			'driver' => !empty($state['upload']['completed']) ? 'server' : null,
		);

		if (!empty($state['upload']['completed'])) {
			$this->snapshoter_start_background_driver($job_id);
		}

		wp_send_json_success($response_data);
	}

	private function upload_chunk_parts_mode($job_id, array $state, $filename, $target, array $chunk, $index, $total_chunks)
	{
		$parts_dir = $this->store->job_dir($job_id) . '/upload_parts';
		if (!is_dir($parts_dir) && !wp_mkdir_p($parts_dir)) {
			wp_send_json_error(array('message' => __('Unable to create upload parts directory.', 'snapshoter')), 500);
			return;
		}

		$part_path = $parts_dir . '/' . $index;
		$ok_path = $part_path . '.ok';
		$handle = fopen($part_path, 'wb');
		if (!$handle) {
			wp_send_json_error(array('message' => __('Unable to open chunk part for writing.', 'snapshoter')), 500);
			return;
		}

		$input = fopen($chunk['tmp_name'], 'rb');
		if (!$input) {
			fclose($handle);
			wp_send_json_error(array('message' => __('Unable to read uploaded chunk.', 'snapshoter')), 500);
			return;
		}

		stream_copy_to_stream($input, $handle);
		fclose($handle);
		fclose($input);

		if ($index === 0 && file_exists($part_path) && filesize($part_path) >= 4) {
			$zip_handle = @fopen($part_path, 'rb');
			if ($zip_handle) {
				$magic_bytes = fread($zip_handle, 4);
				fclose($zip_handle);
				if ($magic_bytes !== "PK\x03\x04") {
					wp_delete_file($part_path);
					wp_send_json_error(array('message' => __('Invalid file format. Expected ZIP archive. Please ensure you are uploading a .smartin backup file.', 'snapshoter')), 400);
					return;
				}
			}
		}

		if (false === file_put_contents($ok_path, '1')) {
			wp_send_json_error(array('message' => __('Unable to mark chunk part complete.', 'snapshoter')), 500);
			return;
		}

		$lock_path = $parts_dir . '/.lock';
		$lock = fopen($lock_path, 'c+');
		if (!$lock) {
			wp_send_json_error(array('message' => __('Unable to lock upload parts.', 'snapshoter')), 500);
			return;
		}

		if (!flock($lock, LOCK_EX)) {
			fclose($lock);
			wp_send_json_error(array('message' => __('Unable to lock upload parts.', 'snapshoter')), 500);
			return;
		}

		try {
			$fresh = $this->store->read($job_id);
			if (is_array($fresh) && !empty($fresh)) {
				$state = $fresh;
			}
		} catch (\Throwable $e) {
		}

		$final = $this->store->job_dir($job_id) . '/' . $filename;
		$already_done = !empty($state['archive_path']) && file_exists((string) $state['archive_path']);
		$just_finalized = false;

		$received = 0;
		for ($i = 0; $i < $total_chunks; $i++) {
			if (file_exists($parts_dir . '/' . $i . '.ok')) {
				$received++;
			}
		}

		$completed = $already_done || ($received >= $total_chunks);
		$state['upload'] = array(
			'name' => $filename,
			'path' => $already_done ? (string) $state['archive_path'] : $target,
			'parts_dir' => $parts_dir,
			'received' => $already_done ? $total_chunks : $received,
			'total' => $total_chunks,
			'completed' => $completed,
			'mode' => 'parts',
		);

		if ($completed && !$already_done) {
			$assembled = $this->assemble_upload_parts($parts_dir, $total_chunks, $target);
			if (is_wp_error($assembled)) {
				flock($lock, LOCK_UN);
				fclose($lock);
				wp_send_json_error(array('message' => $assembled->get_error_message()), 500);
				return;
			}

			$zip_handle = @fopen($target, 'rb');
			if ($zip_handle) {
				$magic_bytes = fread($zip_handle, 4);
				fclose($zip_handle);
				if ($magic_bytes !== "PK\x03\x04") {
					flock($lock, LOCK_UN);
					fclose($lock);
					$this->debug_log('Invalid ZIP file uploaded - magic number mismatch');
					wp_send_json_error(array('message' => __('Uploaded file is not a valid ZIP archive. File may be corrupted.', 'snapshoter')), 400);
					return;
				}
			}

			clearstatcache(true, $target);
			if (!rename($target, $final)) {
				flock($lock, LOCK_UN);
				fclose($lock);
				$this->debug_log('Failed to rename uploaded file', array('from' => basename($target), 'to' => basename($final)));
				wp_send_json_error(array('message' => __('Failed to finalize upload file.', 'snapshoter')), 500);
				return;
			}

			if (!file_exists($final) || !is_readable($final)) {
				flock($lock, LOCK_UN);
				fclose($lock);
				$this->debug_log('Final file verification failed', array('filename' => basename($final)));
				wp_send_json_error(array('message' => __('Failed to verify final archive file.', 'snapshoter')), 500);
				return;
			}

			$state['archive_path'] = $final;
			$state['archive_size'] = filesize($final);
			$state['upload']['path'] = $final;
			$just_finalized = true;
		}

		try {
			$this->store->write($job_id, $state);
		} catch (\Throwable $e) {
			flock($lock, LOCK_UN);
			fclose($lock);
			$this->debug_log('Failed to write state in upload_chunk()', array('error' => $e->getMessage()));
			wp_send_json_error(array('message' => __('Failed to save job state.', 'snapshoter')), 500);
			return;
		}

		flock($lock, LOCK_UN);
		fclose($lock);

		$response_data = array(
			'jobId' => $job_id,
			'uploaded' => $state['upload']['received'],
			'total' => $state['upload']['total'],
			'complete' => $state['upload']['completed'],
			'driver' => $just_finalized ? 'server' : null,
		);

		if ($just_finalized) {
			$this->snapshoter_start_background_driver($job_id);
		}

		wp_send_json_success($response_data);
	}

	private function assemble_upload_parts($parts_dir, $total, $target)
	{
		$first = $parts_dir . '/0';
		if (!file_exists($first)) {
			return new \WP_Error('snapshoter_missing_part', __('Missing upload part 0.', 'snapshoter'));
		}

		if (file_exists($target)) {
			wp_delete_file($target);
		}

		if (!@rename($first, $target)) {
			if (!@copy($first, $target)) {
				return new \WP_Error('snapshoter_assemble_fail', __('Failed to assemble upload parts.', 'snapshoter'));
			}
			wp_delete_file($first);
		}
		wp_delete_file($parts_dir . '/0.ok');

		$out = fopen($target, 'ab');
		if (!$out) {
			return new \WP_Error('snapshoter_assemble_fail', __('Failed to assemble upload parts.', 'snapshoter'));
		}

		for ($i = 1; $i < $total; $i++) {
			$piece = $parts_dir . '/' . $i;
			if (!file_exists($piece)) {
				fclose($out);
				return new \WP_Error(
					'snapshoter_missing_part',
					sprintf(
						/* translators: %d: chunk index */
						__('Missing upload part %d.', 'snapshoter'),
						$i
					)
				);
			}
			$in = fopen($piece, 'rb');
			if (!$in) {
				fclose($out);
				return new \WP_Error('snapshoter_assemble_fail', __('Failed to assemble upload parts.', 'snapshoter'));
			}
			stream_copy_to_stream($in, $out);
			fclose($in);
			wp_delete_file($piece);
			wp_delete_file($piece . '.ok');
		}

		fclose($out);

		return true;
	}

	public function download_archive()
	{

		$job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';

		if (empty($job_id)) {
			wp_die(esc_html__('Missing job id.', 'snapshoter'));
		}

		$this->guard();

		$state = $this->store->read($job_id);

		if (empty($state) || $state['type'] !== 'export' || empty($state['archive_path'])) {
			wp_die(esc_html__('Archive not ready.', 'snapshoter'));
		}

		$archive_path = $state['archive_path'];

		if (!file_exists($archive_path)) {
			wp_die(esc_html__('Archive missing from disk.', 'snapshoter'));
		}

		$file_size = filesize($archive_path);

		if ($file_size === 0) {
			wp_die(esc_html__('Archive file is empty. Export may not have completed successfully.', 'snapshoter'));
		}

		while (ob_get_level()) {
			ob_end_clean();
		}

		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		nocache_headers();

		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . $file_size);
		header('Content-Disposition: attachment; filename="' . basename($archive_path) . '"');
		header('Content-Transfer-Encoding: binary');
		header('Pragma: no-cache');
		header('Expires: 0');

		$handle = @fopen($archive_path, 'rb');

		$chunk_size = 2 * 1024 * 1024;
		while (!feof($handle)) {

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo fread($handle, $chunk_size);
			flush();
			if (ob_get_level()) {
				ob_flush();
			}

			if (connection_aborted()) {
				break;
			}
		}

		fclose($handle);
		exit;
	}

	public static function max_chunk_size($upload_limit = null, $post_limit = null, $ceiling = null)
	{
		$ceiling = null === $ceiling ? (int) SNAPSHOTER_MAX_CHUNK_SIZE : (int) $ceiling;
		$upload = null === $upload_limit
			? self::parse_size(ini_get('upload_max_filesize'), $ceiling)
			: self::parse_size($upload_limit, $ceiling);
		$post = null === $post_limit
			? self::parse_size(ini_get('post_max_size'), $ceiling)
			: self::parse_size($post_limit, $ceiling);

		return self::compute_max_chunk_size($upload, $post, $ceiling);
	}

	public static function compute_max_chunk_size($upload, $post, $ceiling)
	{
		$upload = (int) $upload;
		$post = (int) $post;
		$ceiling = (int) $ceiling;
		$limit = $ceiling > 0 ? $ceiling : (32 * 1024 * 1024);

		if ($upload > 0) {
			$limit = min($limit, $upload);
		}

		if ($post > 0) {
			$reserve = max(256 * 1024, (int) min((int) ($post * 0.1), 2 * 1024 * 1024));
			$limit = min($limit, max(256 * 1024, $post - $reserve));
		}

		return max(256 * 1024, (int) $limit);
	}

	public static function recommended_upload_concurrency($chunk_bytes = null)
	{
		$chunk = null === $chunk_bytes ? self::max_chunk_size() : (int) $chunk_bytes;
		if ($chunk >= 8 * 1024 * 1024) {
			return 2;
		}

		return 3;
	}

	private static function parse_size($size, $default = 0)
	{
		if (is_numeric($size)) {
			return (int) $size;
		}

		if (!is_string($size)) {
			return $default;
		}

		$size = trim($size);
		if (empty($size)) {
			return $default;
		}

		if (preg_match('/^([0-9]+)\s*([kmg]?)$/i', $size, $matches)) {
			$value = (int) $matches[1];
			$unit = strtolower($matches[2] ?? '');

			switch ($unit) {
				case 'g':
					$value *= 1024;
				case 'm':
					$value *= 1024;
				case 'k':
					$value *= 1024;
			}

			return $value > 0 ? $value : $default;
		}

		return $default;
	}

	public static function max_upload_size()
	{
		$post_max = self::parse_size(ini_get('post_max_size'), 0);
		$max_chunk = self::max_chunk_size();

		$chunk_fits = $max_chunk < ($post_max * 0.8);

		if (!$chunk_fits) {
			return $post_max;
		}

		$backup_dir = SNAPSHOTER_STORAGE;
		$disk_free = 0;
		if (function_exists('disk_free_space') && is_dir($backup_dir)) {
			$disk_free = disk_free_space($backup_dir);
		}

		if ($disk_free > 0) {
			return $disk_free * 0.8;
		}

		return 1024 * 1024 * 1024 * 1024 * 1024;
	}

	public static function get_upload_limits_info($file_size = 0)
	{
		$post_max = self::parse_size(ini_get('post_max_size'), 0);
		$upload_max = self::parse_size(ini_get('upload_max_filesize'), 0);
		$memory_limit = self::parse_size(ini_get('memory_limit'), 0);
		$max_chunk = self::max_chunk_size();
		$max_upload = self::max_upload_size();

		$info = array(
			'limits' => array(
				'post_max_size' => array(
					'value' => ini_get('post_max_size'),
					'bytes' => $post_max,
					'formatted' => size_format($post_max),
				),
				'upload_max_filesize' => array(
					'value' => ini_get('upload_max_filesize'),
					'bytes' => $upload_max,
					'formatted' => size_format($upload_max),
				),
				'memory_limit' => array(
					'value' => ini_get('memory_limit'),
					'bytes' => $memory_limit,
					'formatted' => size_format($memory_limit),
				),
				'chunk_size' => array(
					'bytes' => $max_chunk,
					'formatted' => size_format($max_chunk),
				),
				'max_upload_size' => array(
					'bytes' => $max_upload,
					'formatted' => size_format($max_upload),
				),
			),
			'warnings' => array(),
			'recommendations' => array(),
		);

		$min_post_max = $max_chunk * 1.5;
		if ($post_max < $min_post_max) {
			$info['warnings'][] = sprintf(

				// translators: %1$s: current post_max_size; %2$s: recommended size.
				__('post_max_size (%1$s) is too small for chunked uploads. Recommended: at least %2$s.', 'snapshoter'),
				esc_html(size_format($post_max)),
				esc_html(size_format($min_post_max))
			);
			$info['recommendations'][] = sprintf(

				// translators: %s: recommended post_max_size.
				__('Increase post_max_size in php.ini to at least %s', 'snapshoter'),
				esc_html(size_format($min_post_max))
			);
		}

		if ($upload_max < $max_chunk) {
			$info['warnings'][] = sprintf(

				// translators: %1$s: upload_max_filesize; %2$s: chunk size; %3$s: recommended size.
				__('upload_max_filesize (%1$s) is smaller than chunk size (%2$s). Recommended: at least %3$s.', 'snapshoter'),
				esc_html(size_format($upload_max)),
				esc_html(size_format($max_chunk)),
				esc_html(size_format($max_chunk * 1.5))
			);
			$info['recommendations'][] = sprintf(

				// translators: %s: recommended upload_max_filesize.
				__('Increase upload_max_filesize in php.ini to at least %s', 'snapshoter'),
				esc_html(size_format($max_chunk * 1.5))
			);
		}

		$chunk_fits = $max_chunk < ($post_max * 0.8);

		if (!$chunk_fits) {
			$info['warnings'][] = sprintf(

				// translators: %1$s: chunk size; %2$s: post_max_size.
				__('Chunk size (%1$s) is too large for post_max_size (%2$s). Upload will fail.', 'snapshoter'),
				esc_html(size_format($max_chunk)),
				esc_html(size_format($post_max))
			);
			$info['recommendations'][] = sprintf(

				// translators: %s: recommended post_max_size.
				__('Increase post_max_size to at least %s', 'snapshoter'),
				esc_html(size_format($max_chunk * 1.5))
			);
		}

		if ($file_size > 0) {
			$backup_dir = SNAPSHOTER_STORAGE;
			$estimated_disk = $file_size * 2;
			$disk_free = 0;

			if (function_exists('disk_free_space') && is_dir($backup_dir)) {
				$disk_free = disk_free_space($backup_dir);
			}

			if ($disk_free > 0) {
				if ($estimated_disk > $disk_free) {
					$info['warnings'][] = sprintf(

						// translators: %1$s: file size; %2$s: estimated space needed; %3$s: available space.
						__('Insufficient disk space. File size: %1$s, Estimated disk space needed: %2$s, Available: %3$s', 'snapshoter'),
						esc_html(size_format($file_size)),
						esc_html(size_format($estimated_disk)),
						esc_html(size_format($disk_free))
					);
					$info['recommendations'][] = sprintf(

						// translators: %s: required free disk space.
						__('Free up at least %s of disk space before uploading.', 'snapshoter'),
						esc_html(size_format($estimated_disk - $disk_free))
					);
				} else {
					$free_after = $disk_free - $estimated_disk;
					if ($file_size > 1024 * 1024 * 1024) {
						$info['warnings'][] = sprintf(

							// translators: %1$s: file size; %2$s: estimated disk usage; %3$s: available space; %4$s: remaining space.
							__('Large file detected (%1$s). Estimated disk usage: %2$s, Available: %3$s, Remaining after upload: %4$s', 'snapshoter'),
							esc_html(size_format($file_size)),
							esc_html(size_format($estimated_disk)),
							esc_html(size_format($disk_free)),
							esc_html(size_format($free_after))
						);
					}
				}
			} else {
				if ($file_size > 1024 * 1024 * 1024) {
					$info['warnings'][] = sprintf(

						// translators: %1$s: file size; %2$s: required free disk space.
						__('Large file detected (%1$s). Ensure you have at least %2$s of free disk space.', 'snapshoter'),
						esc_html(size_format($file_size)),
						esc_html(size_format($estimated_disk))
					);
				}
			}
		}

		$min_memory = 256 * 1024 * 1024;
		if ($memory_limit > 0 && $memory_limit < $min_memory) {
			$info['warnings'][] = sprintf(

				// translators: %1$s: current memory_limit; %2$s: recommended memory_limit.
				__('memory_limit (%1$s) is low. Recommended: at least %2$s for large file processing.', 'snapshoter'),
				esc_html(size_format($memory_limit)),
				esc_html(size_format($min_memory))
			);
			$info['recommendations'][] = sprintf(

				// translators: %s: recommended memory_limit.
				__('Increase memory_limit in php.ini to at least %s', 'snapshoter'),
				esc_html(size_format($min_memory))
			);
		}

		return $info;
	}

	public function get_upload_limits()
	{
		$this->guard();

		$file_size = isset($_POST['file_size']) ? absint($_POST['file_size']) : 0;

		$info = self::get_upload_limits_info($file_size);

		wp_send_json_success($info);
	}

	public function export_database_only()
	{
		$this->guard();

		global $wpdb;

		@set_time_limit(600);
		@ini_set('max_execution_time', 600);

		$archive_dir = SNAPSHOTER_ARCHIVE_DIR;
		if (!file_exists($archive_dir)) {
			wp_mkdir_p($archive_dir);
		}

		if (!is_writable($archive_dir)) {
			wp_send_json_error(array('message' => __('Archive directory is not writable.', 'snapshoter')), 500);
			return;
		}

		$time_str = function_exists('wp_date') ? wp_date('Y-m-d-His') : gmdate('Y-m-d-His');
		$filename = 'snapshoter-database-' . $time_str . '.sql';
		$filepath = wp_normalize_path($archive_dir . DIRECTORY_SEPARATOR . $filename);

		if (file_exists($filepath)) {

			wp_delete_file($filepath);
		}

		$tables = $wpdb->get_col('SHOW FULL TABLES');

		if (empty($tables)) {
			wp_send_json_error(array('message' => __('No tables found in database.', 'snapshoter')), 400);
			return;
		}

		$sql_content = '';

		foreach ($tables as $table) {

			$table_escaped = esc_sql($table);

			$create = $wpdb->get_row("SHOW CREATE TABLE `{$table_escaped}`", ARRAY_N);

			if (empty($create) || empty($create[1])) {
				continue;
			}

			$sql_content .= "DROP TABLE IF EXISTS `{$table_escaped}`;\n" . $create[1] . ";\n\n";

			$rows = $wpdb->get_results("SELECT * FROM `{$table_escaped}`", ARRAY_A);

			if (!empty($rows)) {
				$insert = $this->build_insert_statements($table, $rows);
				$sql_content .= $insert;
			}
		}

		$download_url = admin_url('admin-ajax.php?action=SNAPSHOTER_download_file&file=' . urlencode($filename) . '&nonce=' . wp_create_nonce('SNAPSHOTER_download_' . $filename));

		$dest_id = 'local';
		$dest_label = 'Local Server';
		$status = 'success';
		$message = __('Database export created.', 'snapshoter');

		wp_send_json_success(array(
			'download_url' => $download_url,
			'downloadUrl'  => $download_url,
			'filename' => $filename,
			'size' => filesize($filepath),
		));
	}

	public function export_files_only()
	{
		$this->guard();

		if (!class_exists('\ZipArchive')) {
			wp_send_json_error(array('message' => __('ZipArchive extension is required.', 'snapshoter')), 500);
			return;
		}

		@set_time_limit(600);

		@ini_set('max_execution_time', 600);

		$archive_dir = SNAPSHOTER_ARCHIVE_DIR;
		if (!file_exists($archive_dir)) {
			wp_mkdir_p($archive_dir);
		}

		if (!is_writable($archive_dir)) {
			wp_send_json_error(array('message' => __('Archive directory is not writable.', 'snapshoter')), 500);
			return;
		}

		$time_str = function_exists('wp_date') ? wp_date('Y-m-d-His') : gmdate('Y-m-d-His');
		$filename = 'snapshoter-files-' . $time_str . '.zip';
		$filepath = wp_normalize_path($archive_dir . DIRECTORY_SEPARATOR . $filename);

		if (file_exists($filepath)) {

			wp_delete_file($filepath);
		}

		$root = trailingslashit(wp_normalize_path(ABSPATH));
		$excluded_patterns = array(
			'snapshoter',
			'snapshoters',
			'.git',
			'node_modules',
			'.DS_Store',
			'wp-config.php',
			'wp-content/object-cache.php',
			'wp-content/advanced-cache.php',
			'wp-content/db.php',
			'wp-content/cache/',
			'wp-content/w3tc-cache/',
			'wp-content/autoptimize/',
			'wp-content/litespeed/',
			'wp-content/breeze/',
			'wp-content/et_cache/',
			'wp-content/flying-press/',
			'wp-content/uploads/wp-rocket/',
			'wp-content/uploads/elementor/css/',
		);

		$files = $this->discover_files_for_export($root, $excluded_patterns);

		if (empty($files)) {
			wp_send_json_error(array('message' => __('No files found to export.', 'snapshoter')), 400);
			return;
		}

		$use_shell = $this->has_shell_zip();

		if ($use_shell) {

			$zip = new \ZipArchive();
			$open_result = $zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			if ($open_result !== true) {
				$this->debug_log('Failed to initialize zip for shell mode', array('error_code' => $open_result));
				$use_shell = false;
			} else {

				$zip->addFromString('.smartin-placeholder', 'This file ensures the zip structure is valid.');
				$zip->close();
			}
		}

		if ($use_shell) {
			$result = $this->archive_files_shell_chunked($filepath, $root, $files);
			if (!$result['success']) {
				$this->debug_log('Shell zip failed', array('error' => $result['message']));
				if (file_exists($filepath)) {

					wp_delete_file($filepath);
				}
				$use_shell = false;
			} elseif (file_exists($filepath) && filesize($filepath) === 0) {
				$this->debug_log('Shell zip created empty file, falling back to PHP ZipArchive');

				wp_delete_file($filepath);
				$use_shell = false;
			}
		}

		if (!$use_shell) {
			$result = $this->archive_files_php_chunked($filepath, $root, $files);
		}

		if (!$result['success']) {
			if (file_exists($filepath)) {

				wp_delete_file($filepath);
			}
			$this->debug_log('Archive creation failed', array('error' => $result['message']));
			wp_send_json_error(array('message' => $result['message']), 500);
			return;
		}

		if (!file_exists($filepath)) {
			$this->debug_log('Archive file does not exist after creation', array('filename' => basename($filepath)));
			wp_send_json_error(array('message' => __('Archive file was not created.', 'snapshoter')), 500);
			return;
		}

		$file_size = filesize($filepath);

		$zip = new \ZipArchive();
		$zip_test = $zip->open($filepath, \ZipArchive::CHECKCONS);
		if ($zip_test !== true) {
			$this->debug_log('Zip file validation failed', array('error_code' => $zip_test));

			wp_delete_file($filepath);
			wp_send_json_error(array('message' => __('Archive file is corrupted. Please try again.', 'snapshoter')), 500);
			return;
		}
		$zip->close();

		if (!is_readable($filepath)) {
			$this->debug_log('Archive file is not readable', array('filename' => basename($filepath)));
			wp_send_json_error(array('message' => __('Archive file is not readable. Please check file permissions.', 'snapshoter')), 500);
			return;
		}

		$download_url = admin_url('admin-ajax.php?action=SNAPSHOTER_download_file&file=' . urlencode($filename) . '&nonce=' . wp_create_nonce('SNAPSHOTER_download_' . $filename));

		$dest_id = 'local';
		$dest_label = 'Local Server';
		$status = 'success';
		$message = __('Files export created.', 'snapshoter');

		wp_send_json_success(array(
			'download_url' => $download_url,
			'downloadUrl'  => $download_url,
			'filename' => $filename,
			'size' => $file_size,
			'files_count' => $result['files_count'],
		));
	}

	private function archive_files_php_chunked($archive_path, $root, $files)
	{
		$queue = $files;
		$files_added = 0;

		$archive_dir = dirname($archive_path);

		if (!is_writable($archive_dir)) {
			$this->debug_log('Archive directory is not writable', array('directory' => basename($archive_dir)));
			return array('success' => false, 'message' => __('Archive directory is not writable.', 'snapshoter'));
		}

		$memory_limit = $this->get_memory_limit_bytes();
		$memory_available = $memory_limit > 0 ? ($memory_limit - memory_get_usage(true)) : PHP_INT_MAX;

		if ($memory_available < 64 * 1024 * 1024) {
			$batch_size = 20;
		} elseif ($memory_available < 128 * 1024 * 1024) {
			$batch_size = 50;
		} elseif ($memory_available < 256 * 1024 * 1024) {
			$batch_size = 200;
		} elseif ($memory_available < 512 * 1024 * 1024) {
			$batch_size = 500;
		} else {
			$batch_size = 1000;
		}

		$zip = new \ZipArchive();
		$open_result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if ($open_result !== true) {
			$this->debug_log('Failed to open zip archive', array('error_code' => $open_result, 'filename' => basename($archive_path)));
			return array('success' => false, 'message' => __('Unable to open archive for writing. Error code: ', 'snapshoter') . $open_result);
		}

		$max_execution_time = ini_get('max_execution_time');
		$time_limit = $max_execution_time > 0 && $max_execution_time < 300 ? ($max_execution_time - 5) : 300;
		$start_time = microtime(true);

		while (!empty($queue)) {
			if ((microtime(true) - $start_time) > $time_limit) {
				$zip->close();
				$this->debug_log('Archive creation timed out', array('time_limit' => $time_limit));
				return array('success' => false, 'message' => __('Archive creation timed out. Please try again.', 'snapshoter'));
			}

			$batch = array_splice($queue, 0, $batch_size);

			if (empty($batch)) {
				break;
			}

			$batch_start_time = microtime(true);
			$batch_timeout = 5;

			foreach ($batch as $relative) {
				if ((microtime(true) - $batch_start_time) > $batch_timeout) {
					break;
				}

				if ($memory_limit > 0 && $memory_limit < PHP_INT_MAX) {
					$memory_used = memory_get_usage(true);
					$memory_percent = ($memory_used / $memory_limit) * 100;

					if ($memory_percent > 80) {
						break;
					}
				}

				$absolute = $root . $relative;

				if (!file_exists($absolute) || !is_readable($absolute)) {
					continue;
				}

				if (is_dir($absolute)) {
					if ($zip->addEmptyDir($relative)) {
						$files_added++;
					}
					continue;
				}

				if (!is_file($absolute)) {
					continue;
				}

				if ($zip->addFile($absolute, $relative)) {
					$files_added++;
				} else {
					$this->debug_log('Failed to add file to zip', array('file' => basename($relative)));
				}
			}

			if (function_exists('gc_collect_cycles')) {
				gc_collect_cycles();
			}
		}

		$close_result = $zip->close();

		if (!$close_result) {
			$this->debug_log('Failed to close zip archive');
			return array('success' => false, 'message' => __('Failed to close ZIP archive.', 'snapshoter'));
		}

		if ($files_added === 0) {
			$this->debug_log('No files were added to archive');
			return array('success' => false, 'message' => __('No files were added to archive.', 'snapshoter'));
		}

		if (!file_exists($archive_path) || filesize($archive_path) === 0) {
			$this->debug_log('Archive file is empty after creation', array('filename' => basename($archive_path)));
			return array('success' => false, 'message' => __('Archive file is empty after creation.', 'snapshoter'));
		}

		return array('success' => true, 'files_count' => $files_added);
	}

	private function archive_files_shell_chunked($archive_path, $root, $queue)
	{
		$files_added = 0;
		$batch_size = 2000;

		$archive_path_normalized = wp_normalize_path($archive_path);
		$root_normalized = wp_normalize_path($root);

		if (file_exists($archive_path_normalized)) {
			$zip = new \ZipArchive();
			if ($zip->open($archive_path_normalized, \ZipArchive::CREATE) === true) {
				$zip->deleteName('.smartin-placeholder');
				$zip->close();
			}
		}

		while (!empty($queue)) {
			$batch = array_splice($queue, 0, $batch_size);

			if (empty($batch)) {
				break;
			}

			$file_list_path = tempnam(sys_get_temp_dir(), 'snapshoter_files_export_');
			if (!$file_list_path) {
				$this->debug_log('Failed to create temporary file list');
				return array('success' => false, 'message' => __('Failed to create temporary file list.', 'snapshoter'));
			}

			$file_list_content = '';

			foreach ($batch as $relative) {
				$absolute = $root_normalized . $relative;
				if (file_exists($absolute) && is_readable($absolute) && is_file($absolute)) {
					$file_list_content .= $relative . "\n";
				}
			}

			if (empty($file_list_content)) {

				wp_delete_file($file_list_path);
				continue;
			}

			$cmd = sprintf(
				'cd %s && zip -uq %s -@ < %s 2>&1',
				escapeshellarg($root_normalized),
				escapeshellarg($archive_path_normalized),
				escapeshellarg($file_list_path)
			);

			$output = shell_exec($cmd);

			wp_delete_file($file_list_path);

			if (!empty($output)) {
				$this->debug_log('Shell zip output', array('output' => trim($output)));
			}

			if (!file_exists($archive_path_normalized)) {
				$this->debug_log('Archive file does not exist after shell zip command');
				return array('success' => false, 'message' => __('Shell zip command failed to create archive.', 'snapshoter'));
			}

			$files_added += substr_count($file_list_content, "\n");
		}

		if (file_exists($archive_path_normalized)) {
			$zip = new \ZipArchive();
			$zip_result = $zip->open($archive_path_normalized, \ZipArchive::CHECKCONS);
			if ($zip_result === true) {
				$zip->close();
			} else {
				$this->debug_log('Failed to finalize zip file', array('error_code' => $zip_result));
			}
		}

		if ($files_added === 0) {
			$this->debug_log('No files were added via shell zip');
			return array('success' => false, 'message' => __('No files were added to archive.', 'snapshoter'));
		}

		if (!file_exists($archive_path_normalized) || filesize($archive_path_normalized) === 0) {
			$this->debug_log('Archive file is empty after shell zip', array('filename' => basename($archive_path_normalized)));
			return array('success' => false, 'message' => __('Archive file is empty after shell zip operation.', 'snapshoter'));
		}

		return array('success' => true, 'files_count' => $files_added);
	}

	private function has_shell_zip()
	{

		if (
			!Environment::is_function_callable('shell_exec')
			|| !Environment::is_function_callable('escapeshellarg')
		) {
			return false;
		}

		$output = shell_exec('which zip');
		if (empty($output)) {
			return false;
		}

		return true;
	}

	private function discover_files_for_export($root, $excluded_patterns)
	{
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$root,
				\RecursiveDirectoryIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				continue;
			}

			$file_path = $file->getRealPath();
			$relative_path = ltrim(str_replace($root, '', wp_normalize_path($file_path)), '/');

			if (empty($relative_path)) {
				continue;
			}

			if (strpos($file_path, wp_normalize_path(SNAPSHOTER_STORAGE)) === 0) {
				continue;
			}

			$should_exclude = false;
			foreach ($excluded_patterns as $pattern) {
				if (strpos($relative_path, $pattern) !== false) {
					$should_exclude = true;
					break;
				}
			}

			if ($should_exclude) {
				continue;
			}

			if (basename($file_path) === 'error_log' || substr($file_path, -4) === '.log') {
				continue;
			}

			if (!is_readable($file_path)) {
				continue;
			}

			$files[] = $relative_path;
		}

		return $files;
	}

	private function get_memory_limit_bytes()
	{
		$memory_limit = ini_get('memory_limit');
		if ($memory_limit == -1) {
			return 0;
		}

		$memory_limit = trim($memory_limit);
		$last = strtolower($memory_limit[strlen($memory_limit) - 1]);
		$value = (int) $memory_limit;

		switch ($last) {
			case 'g':
				$value *= 1024;
			case 'm':
				$value *= 1024;
			case 'k':
				$value *= 1024;
		}

		return $value;
	}

	private function calculate_batch_size($memory_limit, $total_files)
	{
		if ($memory_limit <= 0) {
			return 500;
		}

		$current_memory = memory_get_usage(true);
		$available_memory = $memory_limit - $current_memory;

		if ($available_memory < 10 * 1024 * 1024) {
			return 100;
		} elseif ($available_memory < 50 * 1024 * 1024) {
			return 250;
		} elseif ($available_memory < 100 * 1024 * 1024) {
			return 500;
		} elseif ($available_memory < 256 * 1024 * 1024) {
			return 1000;
		} else {
			return 2000;
		}
	}

	private function build_insert_statements($table, array $rows)
	{
		if (empty($rows)) {
			return '';
		}

		$columns = array_keys($rows[0]);
		$insert = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES\n";
		$chunks = array();

		foreach ($rows as $row) {
			$values = array();

			foreach ($columns as $column) {
				$value = isset($row[$column]) ? $row[$column] : null;

				if (null === $value) {
					$values[] = 'NULL';
				} elseif (is_numeric($value) && (string) (int) $value === (string) $value) {
					$values[] = $value;
				} else {
					$values[] = "'" . esc_sql($value) . "'";
				}
			}

			$chunks[] = '(' . implode(',', $values) . ')';
		}

		$insert .= implode(",\n", $chunks) . ";\n\n";

		return $insert;
	}

	private function is_downloadable_snapshot_basename($filename)
	{
		$filename = basename((string) $filename);
		if ($filename === '') {
			return false;
		}
		return (bool) preg_match('/\.(smartin|sql|zip)$/i', $filename);
	}

	public function download_file()
	{
		@set_time_limit(0);
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('admin');
		}

		$filename = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
		$nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

		if (empty($filename) || empty($nonce)) {
			$this->debug_log('download_file() - Missing filename or nonce');
			wp_die(esc_html__('Invalid request.', 'snapshoter'), '', array('response' => 400));
			return;
		}

		$valid_nonce = wp_verify_nonce($nonce, 'SNAPSHOTER_download')
			|| wp_verify_nonce($nonce, 'SNAPSHOTER_download_' . $filename)
			|| wp_verify_nonce($nonce, SNAPSHOTER_NONCE_ACTION);

		if (!$valid_nonce) {
			$this->debug_log('download_file() - Nonce verification failed', array('filename' => basename($filename)));
			wp_die(esc_html__('Security check failed.', 'snapshoter'), '', array('response' => 403));
			return;
		}

		if (!current_user_can(SNAPSHOTER_CAPABILITY)) {
			$this->debug_log('download_file() - Permission denied');
			wp_die(esc_html__('Permission denied.', 'snapshoter'), '', array('response' => 403));
			return;
		}

		if (!$this->is_downloadable_snapshot_basename($filename)) {
			$this->debug_log('download_file() - Invalid filename pattern', array('filename' => basename($filename)));
			wp_die(esc_html__('Invalid file type.', 'snapshoter'), '', array('response' => 400));
			return;
		}

		$filepath = self::find_snapshot_file($filename);

		if (!$filepath || !is_file($filepath)) {
			$filepath = null;
			$snapshots_dir = SNAPSHOTER_SNAPSHOTS_DIR;
			if (is_dir($snapshots_dir)) {
				$date_folders = glob($snapshots_dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
				if (is_array($date_folders)) {
					foreach ($date_folders as $date_folder) {
						$potential_path = wp_normalize_path($date_folder . DIRECTORY_SEPARATOR . $filename);
						if (file_exists($potential_path)) {
							$filepath = $potential_path;
							break;
						}
					}
				}
				if (!$filepath && file_exists(wp_normalize_path($snapshots_dir . DIRECTORY_SEPARATOR . $filename))) {
					$filepath = wp_normalize_path($snapshots_dir . DIRECTORY_SEPARATOR . $filename);
				}
			}

			if (!$filepath && defined('SNAPSHOTER_ARCHIVE_DIR') && is_dir(SNAPSHOTER_ARCHIVE_DIR)) {
				$potential = wp_normalize_path(SNAPSHOTER_ARCHIVE_DIR . DIRECTORY_SEPARATOR . $filename);
				if (file_exists($potential)) {
					$filepath = $potential;
				}
			}

			if (!$filepath && defined('SNAPSHOTER_RESTORES_DIR') && is_dir(SNAPSHOTER_RESTORES_DIR)) {
				$potential = wp_normalize_path(SNAPSHOTER_RESTORES_DIR . DIRECTORY_SEPARATOR . $filename);
				if (file_exists($potential)) {
					$filepath = $potential;
				}
			}
		}

		if (!$filepath || !file_exists($filepath)) {
			$this->debug_log('download_file() - File not found', array('filename' => basename($filename)));
			wp_die(esc_html__('File not found.', 'snapshoter'), '', array('response' => 404));
			return;
		}

		if (!is_readable($filepath)) {
			$this->debug_log('download_file() - File not readable', array('filename' => basename($filepath)));
			wp_die(esc_html__('File is not readable.', 'snapshoter'), '', array('response' => 403));
			return;
		}

		$file_size = filesize($filepath);

		while (ob_get_level()) {
			ob_end_clean();
		}

		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . $file_size);
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Expires: 0');

		$chunk_size = 8 * 1024 * 1024;

		$handle = @fopen($filepath, 'rb');

		while (!feof($handle)) {

			$chunk = fread($handle, $chunk_size);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $chunk;
			flush();

			if (connection_aborted()) {
				break;
			}
		}

		fclose($handle);

		if ($is_temp_download) {

			wp_delete_file($filepath);
		}

		exit;
	}

	public function restore_site_settings()
	{
		$stored_manifest = get_option('SNAPSHOTER_last_import_manifest', null);

		$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

		$has_stored_manifest = !empty($stored_manifest) && is_array($stored_manifest);

		if ($has_stored_manifest && (empty($job_id) || $job_id === 'stored')) {
			if (!current_user_can(SNAPSHOTER_CAPABILITY)) {
				wp_send_json_error(array('message' => __('Permission denied.', 'snapshoter')), 403);
				return;
			}
		} else {
			$this->guard();
		}

		$manifest = null;

		if ($has_stored_manifest) {
			$manifest = $stored_manifest;
		}

		if (empty($manifest) && !empty($job_id) && $job_id !== 'stored') {
			try {
				$state = $this->store->read($job_id);
				if (!empty($state) && isset($state['manifest']) && is_array($state['manifest'])) {
					$manifest = $state['manifest'];
				}
			} catch (\Throwable $e) {
				$this->debug_log('Could not read job state', array('error' => $e->getMessage()));
			}
		}

		if (empty($manifest)) {
			wp_send_json_error(array(
				'message' => __('Manifest not found. The import may have been completed and cleaned up, or no import has been performed yet.', 'snapshoter')
			), 404);
			return;
		}

		try {

			global $wpdb;
			$restored_items = array();
			$errors = array();

			if (isset($manifest['permalink_structure'])) {
				try {
					$permalink_structure = $manifest['permalink_structure'];

					$cleaned_structure = preg_replace('/[a-f0-9]{32,}/i', '', $permalink_structure);
					$cleaned_structure = preg_replace('/(\/[^\/]+)\1+/', '$1', $cleaned_structure);
					$cleaned_structure = trim($cleaned_structure, '/');
					if (!empty($cleaned_structure)) {
						$cleaned_structure = '/' . $cleaned_structure . '/';
					}

				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore permalink structure: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			if (isset($manifest['site_settings']) && is_array($manifest['site_settings'])) {
				try {
					$site_settings = $manifest['site_settings'];
					$settings_map = array(
						'blogname' => 'blogname',
						'blogdescription' => 'blogdescription',
						'custom_logo' => 'custom_logo',
						'site_icon' => 'site_icon',
						'show_on_front' => 'show_on_front',
						'page_on_front' => 'page_on_front',
						'page_for_posts' => 'page_for_posts',
						'default_ping_status' => 'default_ping_status',
						'default_comment_status' => 'default_comment_status',
						'thumbnail_size_w' => 'thumbnail_size_w',
						'thumbnail_size_h' => 'thumbnail_size_h',
						'medium_size_w' => 'medium_size_w',
						'medium_size_h' => 'medium_size_h',
						'large_size_w' => 'large_size_w',
						'large_size_h' => 'large_size_h',
					);

					foreach ($settings_map as $manifest_key => $option_name) {
						if (isset($site_settings[$manifest_key])) {
							update_option($option_name, $site_settings[$manifest_key]);
						}
					}

					$restored_items[] = __('Site settings (logo, reading, media, etc.)', 'snapshoter');
				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore site settings: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			if (isset($manifest['site_settings']['theme_mods']) && is_array($manifest['site_settings']['theme_mods'])) {
				try {
					$stylesheet = isset($manifest['stylesheet']) ? $manifest['stylesheet'] : get_option('stylesheet');
					if (!empty($stylesheet)) {
						$theme_mods = $manifest['site_settings']['theme_mods'];
						update_option('theme_mods_' . $stylesheet, $theme_mods);
						$restored_items[] = __('Theme customizer settings (logo, CSS, theme options)', 'snapshoter');
					}
				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore theme settings: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			if (isset($manifest['site_settings']['widgets']) && is_array($manifest['site_settings']['widgets'])) {
				try {
					foreach ($manifest['site_settings']['widgets'] as $widget_option_name => $widget_value) {
						update_option($widget_option_name, $widget_value);
					}

					// translators: %d: number
					$restored_items[] = sprintf(__('%d widget options', 'snapshoter'), count($manifest['site_settings']['widgets']));
				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore widgets: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			if (isset($manifest['site_settings']['sidebars_widgets']) && is_array($manifest['site_settings']['sidebars_widgets'])) {
				try {
					update_option('sidebars_widgets', $manifest['site_settings']['sidebars_widgets']);
					$restored_items[] = __('Widget positions', 'snapshoter');
				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore widget positions: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			if (isset($manifest['site_settings']['custom_css']) && is_array($manifest['site_settings']['custom_css'])) {
				try {
					$css_content = isset($manifest['site_settings']['custom_css']['post_content']) ? $manifest['site_settings']['custom_css']['post_content'] : '';
					if (!empty($css_content)) {
						$css_post_id = get_option('custom_css_post_id');
						if ($css_post_id) {
							wp_update_post(array(
								'ID' => $css_post_id,
								'post_content' => $css_content,
								'post_type' => 'custom_css',
							));
						} else {
							$css_post_id = wp_insert_post(array(
								'post_title' => isset($manifest['site_settings']['custom_css']['post_title']) ? $manifest['site_settings']['custom_css']['post_title'] : 'Custom CSS',
								'post_content' => $css_content,
								'post_type' => 'custom_css',
								'post_status' => 'publish',
							));
							if ($css_post_id) {
								update_option('custom_css_post_id', $css_post_id);
							}
						}
						$restored_items[] = __('Custom CSS', 'snapshoter');
					}
				} catch (\Throwable $e) {

					// translators: %s: error message.
					$errors[] = sprintf(__('Failed to restore custom CSS: %s', 'snapshoter'), esc_html($e->getMessage()));
				}
			}

			wp_cache_flush();
			wp_cache_delete('alloptions', 'options');

			if (!empty($restored_items)) {
				delete_option('SNAPSHOTER_last_import_manifest');
				$this->debug_log('Cleared stored manifest after successful restore');
			}

			$message = '';
			if (!empty($restored_items)) {
				$message = sprintf(

					// translators: %s: list of restored items.
					__('Successfully restored: %s', 'snapshoter'),
					implode(', ', $restored_items)
				);
			}

			if (!empty($errors)) {
				$message .= ' ' . implode(' ', $errors);
			}

			if (empty($restored_items) && empty($errors)) {
				$message = __('No settings found to restore in manifest.', 'snapshoter');
			}

			wp_send_json_success(array('message' => $message));
		} catch (\Throwable $e) {
			$this->debug_log('restore_site_settings() failed', array('error' => $e->getMessage()));
			wp_send_json_error(array('message' => __('Failed to restore site settings.', 'snapshoter')), 500);
		}
	}

	private function guard($job_id = '', $job_passphrase = '')
	{
		if ($job_id) {
			$this->debug_log(
				'guard invoked for job',
				array(
					'job_id' => substr($job_id, 0, 20) . '...',
					'has_passphrase' => $job_passphrase !== ''
				)
			);
		}

		if ($job_id && $job_passphrase) {
			$state = $this->store->read($job_id);
			if (!empty($state) && $this->verify_job_passphrase($job_id, $job_passphrase, $state)) {
				return;
			}

			$this->debug_log(
				'guard passphrase mismatch for job. Falling back to nonce',
				array(
					'job_id' => substr($job_id, 0, 20) . '...',
					'passphrase_sent' => $this->summarize_passphrase($job_passphrase),
				)
			);
		}

		if (!current_user_can(SNAPSHOTER_CAPABILITY)) {
			$this->snapshoter_send_json_error(array('message' => __('Permission denied.', 'snapshoter')), 403);
		}

		check_ajax_referer(SNAPSHOTER_NONCE_ACTION, SNAPSHOTER_NONCE_NAME);
	}

	private function create_job($type, $job_id)
	{
		switch ($type) {
			case 'export':
				return new ExportJob($this->store, $job_id);
			case 'import':
				return new ImportJob($this->store, $job_id);
			default:
				wp_send_json_error(array('message' => __('Unknown job type.', 'snapshoter')), 400);
		}
	}

	private function snapshoter_wait_for_exclusive_job_lock($handle, $max_wait_seconds = 30)
	{

		$deadline = microtime(true) + max(1, (int) $max_wait_seconds);
		while (microtime(true) < $deadline) {
			if (@flock($handle, LOCK_EX | LOCK_NB)) {
				return true;
			}
			usleep(200000);
		}

		$this->debug_log('cancel waited for tick lock - proceeding with cleanup anyway');
		return false;
	}

	private function snapshoter_maybe_release_export_lock($job_id, array $response)
	{
		if (empty($response['completed'])) {
			return;
		}

		$state = $this->store->read($job_id);
		if (!is_array($state) || empty($state['type']) || $state['type'] !== 'export') {
			return;
		}

		if ($this->snapshoter_job_is_terminal($state)) {
			ExportLock::release($job_id);
		}
	}

	public function supersede_open_exports()
	{
		$ids = ExportLock::list_open_exports($this->store);
		ExportLock::force_clear();

		$killed = array();
		foreach ($ids as $job_id) {
			$job_id = sanitize_text_field((string) $job_id);
			if ($job_id === '') {
				continue;
			}

			try {
				$current_state = $this->store->read($job_id);
				if (!is_array($current_state)) {
					$current_state = array();
				}

				$this->snapshoter_clear_background_driver($job_id);

				$previous_status = isset($current_state['status']) ? (string) $current_state['status'] : '';
				if (!empty($current_state)) {
					$this->store->write(
						$job_id,
						array_merge(
							$current_state,
							array(
								'status' => 'cancelled',
								'step' => 'cancelled',
								'driver' => null,
								'error' => __('Superseded by a new backup request.', 'snapshoter'),
							)
						)
					);
				}

				$lock_handle = $this->snapshoter_acquire_job_lock($job_id);
				$this->snapshoter_wait_for_exclusive_job_lock($lock_handle, self::SUPERSEDE_LOCK_WAIT_SECONDS);
				$this->snapshoter_run_cancel_cleanup($job_id, $current_state, $previous_status);
				$this->clear_job_passphrase($job_id);
				if (is_resource($lock_handle)) {
					$this->snapshoter_release_job_lock($lock_handle);
				}

				$this->store->delete($job_id);
				$killed[] = $job_id;
			} catch (\Throwable $e) {
				$this->debug_log(
					'supersede_open_exports failed',
					array(
						'job_id' => substr($job_id, 0, 24),
						'error' => $e->getMessage(),
					)
				);
				try {
					$this->store->delete($job_id);
					$killed[] = $job_id;
				} catch (\Throwable $delete_error) {
					$this->debug_log(
						'supersede_open_exports delete failed',
						array(
							'job_id' => substr($job_id, 0, 24),
							'error' => $delete_error->getMessage(),
						)
					);
				}
			}
		}

		ExportLock::force_clear();
		return $killed;
	}

	private function snapshoter_run_cancel_cleanup($job_id, array $state, $previous_status = '')
	{
		if (in_array($previous_status, array('completed', 'partial', 'failed', 'cancelled'), true)) {
			return;
		}

		$type = isset($state['type']) ? (string) $state['type'] : '';
		if (!in_array($type, array('export', 'import'), true)) {
			return;
		}

		try {
			if ($type === 'export') {
				$job = new ExportJob($this->store, $job_id);
			} else {
				$job = new ImportJob($this->store, $job_id);
			}
			$job->cleanup();
			if ($type === 'export') {
				ExportLock::release($job_id);
			}
		} catch (\Throwable $e) {
			$this->debug_log(
				'snapshoter_run_cancel_cleanup failed',
				array(
					'job_id' => substr((string) $job_id, 0, 20),
					'error' => $e->getMessage(),
				)
			);
		}
	}

	private function generate_job_passphrase()
	{
		try {
			return bin2hex(random_bytes(16));
		} catch (\Exception $e) {
			return wp_generate_password(32, false, false);
		}
	}

	/**
	 * @param string $passphrase Job passphrase.
	 * @return string
	 */
	private function hash_job_passphrase($passphrase)
	{
		return hash_hmac('sha256', (string) $passphrase, wp_salt('auth'));
	}

	/**
	 * @param string $job_id Job id.
	 * @return string
	 */
	private function job_secret_transient_key($job_id)
	{
		return 'snapshoter_job_secret_' . md5((string) $job_id);
	}

	/**
	 * @param string $job_id     Job id.
	 * @param string $passphrase Job passphrase.
	 * @return void
	 */
	private function remember_job_passphrase($job_id, $passphrase)
	{
		$job_id = (string) $job_id;
		$passphrase = (string) $passphrase;
		if ($job_id === '' || $passphrase === '') {
			return;
		}
		set_transient($this->job_secret_transient_key($job_id), $passphrase, WEEK_IN_SECONDS);
	}

	/**
	 * @param string $job_id Job id.
	 * @return string
	 */
	private function recall_job_passphrase($job_id)
	{
		$job_id = (string) $job_id;
		if ($job_id === '') {
			return '';
		}
		$cached = get_transient($this->job_secret_transient_key($job_id));
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}
		try {
			$state = $this->store->read($job_id);
		} catch (\Throwable $e) {
			return '';
		}
		if (!empty($state['job_passphrase']) && is_string($state['job_passphrase'])) {
			return (string) $state['job_passphrase'];
		}
		return '';
	}

	/**
	 * @param string     $job_id     Job id.
	 * @param string     $passphrase Submitted passphrase.
	 * @param array|null $state      Optional preloaded state.
	 * @return bool
	 */
	private function verify_job_passphrase($job_id, $passphrase, $state = null)
	{
		if (!is_string($passphrase) || $passphrase === '') {
			return false;
		}
		if (!is_array($state)) {
			try {
				$state = $this->store->read($job_id);
			} catch (\Throwable $e) {
				return false;
			}
		}
		if (empty($state) || !is_array($state)) {
			return false;
		}

		$hash = isset($state['job_passphrase_hash']) ? (string) $state['job_passphrase_hash'] : '';
		if ($hash !== '') {
			return hash_equals($hash, $this->hash_job_passphrase($passphrase));
		}

		$legacy = isset($state['job_passphrase']) ? (string) $state['job_passphrase'] : '';
		return $legacy !== '' && hash_equals($legacy, $passphrase);
	}

	private function clear_job_passphrase($job_id)
	{
		delete_transient($this->job_secret_transient_key($job_id));

		$state = $this->store->read($job_id);

		if (empty($state)) {
			return;
		}

		if (isset($state['job_passphrase'])) {
			unset($state['job_passphrase']);
			$this->store->write($job_id, $state);
		}
	}

	private function summarize_passphrase($passphrase)
	{
		if (empty($passphrase)) {
			return 'none';
		}

		return substr(hash('sha256', $passphrase), 0, 10);
	}

	private function is_json_output($output)
	{
		if (empty($output)) {
			return true;
		}
		$trimmed = trim($output);
		return ($trimmed[0] === '{' || $trimmed[0] === '[');
	}

	public function create_snapshot()
	{
		$this->guard();

		$this->supersede_open_exports();

		$job_id = uniqid('snapshot_', true);

		try {
			$this->store->ensure_job_dir($job_id);
		} catch (\Throwable $e) {
			$this->debug_log('create_snapshot() ensure_job_dir failed', array('error' => $e->getMessage()));
			$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
			return;
		}

		$passphrase = $this->generate_job_passphrase();
		$initial_state = array(
			'job_passphrase_hash' => $this->hash_job_passphrase($passphrase),
			'type' => 'export',
			'is_snapshot' => true,
		);
		$this->remember_job_passphrase($job_id, $passphrase);

		try {
			$this->store->write($job_id, $initial_state);
		} catch (\Throwable $e) {
			$this->debug_log('create_snapshot() write failed', array('error' => $e->getMessage()));
			$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
			return;
		}

		try {
			$job = $this->create_job('export', $job_id);
			$response = $job->init();
			ExportLock::register($job_id);
			$response['jobPassphrase'] = $passphrase;
			$response['jobId'] = $job_id;
			$this->snapshoter_send_json_success($response);
		} catch (\Throwable $e) {
			ExportLock::release($job_id);
			$this->debug_log('create_snapshot() failed', array('error' => $e->getMessage()));
			$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
		}
	}

	public function clear_cache()
	{
		$this->guard();
		wp_cache_flush();
		$this->snapshoter_send_json_success(array('message' => 'Cache cleared'));
	}

	public function list_snapshots()
	{
		$this->guard();
		clearstatcache(true);

		$local_only = false;

		if (isset($_POST['local_only']) && (string) $_POST['local_only'] !== '' && (string) $_POST['local_only'] !== '0') {
			$local_only = true;
		}

		$snapshots = array();

		try {
			$snapshots_dir = SNAPSHOTER_SNAPSHOTS_DIR;
			$local_files = array();

			$scan_backup_files = function ($dir) {
				$files = array();
				if (!is_dir($dir)) {
					return $files;
				}
				$found = glob($dir . DIRECTORY_SEPARATOR . '*.{smartin,zip,sql}', defined('GLOB_BRACE') ? GLOB_BRACE : 0);
				if (is_array($found) && !empty($found)) {
					return $found;
				}
				$dh = @opendir($dir);
				if ($dh) {
					while (($entry = @readdir($dh)) !== false) {
						if ($entry === '.' || $entry === '..') {
							continue;
						}
						$full = $dir . DIRECTORY_SEPARATOR . $entry;
						if (is_file($full)) {
							$ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
							if (in_array($ext, array('smartin', 'zip', 'sql'), true)) {
								$files[] = $full;
							}
						}
					}
					@closedir($dh);
				}
				return $files;
			};

			if (is_dir($snapshots_dir)) {
				$date_folders = glob($snapshots_dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
				if (empty($date_folders)) {
					$dh = @opendir($snapshots_dir);
					if ($dh) {
						while (($entry = @readdir($dh)) !== false) {
							if ($entry !== '.' && $entry !== '..' && is_dir($snapshots_dir . DIRECTORY_SEPARATOR . $entry)) {
								$date_folders[] = $snapshots_dir . DIRECTORY_SEPARATOR . $entry;
							}
						}
						@closedir($dh);
					}
				}
				if (is_array($date_folders)) {
					foreach ($date_folders as $date_folder) {
						$date = basename($date_folder);
						$found = $scan_backup_files($date_folder);
						if (is_array($found)) {
							foreach ($found as $f) {
								$local_files[] = array('path' => $f, 'date' => $date);
							}
						}
					}
				}

				$root_files = $scan_backup_files($snapshots_dir);
				if (is_array($root_files)) {
					foreach ($root_files as $f) {
						$local_files[] = array('path' => $f, 'date' => function_exists('wp_date') ? wp_date('Y-m-d', filemtime($f)) : gmdate('Y-m-d', filemtime($f)));
					}
				}
			}

			if (defined('SNAPSHOTER_ARCHIVE_DIR') && is_dir(SNAPSHOTER_ARCHIVE_DIR)) {
				$archive_files = $scan_backup_files(SNAPSHOTER_ARCHIVE_DIR);
				if (is_array($archive_files)) {
					foreach ($archive_files as $f) {
						$local_files[] = array('path' => $f, 'date' => function_exists('wp_date') ? wp_date('Y-m-d', filemtime($f)) : gmdate('Y-m-d', filemtime($f)));
					}
				}
			}

			$wp_date_format = get_option('date_format', 'F j, Y');
			$wp_time_format = get_option('time_format', 'g:i a');

			$seen_paths = array();
			foreach ($local_files as $item) {
				$file = $item['path'];
				if (isset($seen_paths[$file]) || !file_exists($file)) {
					continue;
				}
				$seen_paths[$file] = true;

				$filename = basename($file);
				$file_size = filesize($file);
				$file_time = filemtime($file);
				$date = $item['date'];
				$formatted_size = size_format($file_size);
				$formatted_date = function_exists('wp_date') ? wp_date("{$wp_date_format}, {$wp_time_format}", $file_time) : gmdate('F j, Y, g:i a', $file_time);

				$download_url = add_query_arg(array(
					'action' => 'SNAPSHOTER_download_file',
					'file' => $filename,
					'nonce' => wp_create_nonce('SNAPSHOTER_download'),
				), admin_url('admin-ajax.php'));

				$snapshots[] = array(
					'id' => 'local:' . $filename,
					'name' => $filename,
					'filename' => $filename,
					'date' => $date,
					'size' => $formatted_size,
					'sizeFormatted' => $formatted_size,
					'sizeBytes' => $file_size,
					'timestamp' => $file_time,
					'created' => $file_time,
					'createdAt' => function_exists('wp_date') ? wp_date('c', $file_time) : gmdate('c', $file_time),
					'createdFormatted' => $formatted_date,
					'path' => $file,
					'download_url' => $download_url,
					'downloadUrl' => $download_url,
					'url' => $download_url,
					'snapshot' => $filename,
					'location' => 'local',
					'location_label' => __('Local server', 'snapshoter'),
					'type' => 'full',
					'scope' => ((substr(strtolower($filename), -4) === '.sql')
							? 'Database'
							: ((substr(strtolower($filename), -4) === '.zip') ? 'Files' : 'Full Site')),
				);
			}

			$local_only = true;

			usort($snapshots, function ($a, $b) {
				return $b['timestamp'] - $a['timestamp'];
			});
		} catch (\Throwable $e) {
			$this->debug_log('list_snapshots failed', array('error' => $e->getMessage()));
		}

		$this->snapshoter_send_json_success(
			array(
				'snapshots'  => $snapshots,
				'local_only' => $local_only,
			)
		);
	}

	public function restore_snapshot()
	{
		$this->guard();

		$snapshot = '';
		if (!empty($_POST['snapshot'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['snapshot']));
		} elseif (!empty($_POST['filename'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['filename']));
		} elseif (!empty($_POST['name'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['name']));
		} elseif (!empty($_POST['file'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['file']));
		} elseif (!empty($_REQUEST['snapshot'])) {
			$snapshot = sanitize_file_name(wp_unslash($_REQUEST['snapshot']));
		} elseif (!empty($_REQUEST['filename'])) {
			$snapshot = sanitize_file_name(wp_unslash($_REQUEST['filename']));
		}

		if (empty($snapshot)) {
			$this->snapshoter_send_json_error(array('message' => __('Invalid snapshot selection.', 'snapshoter')), 400);
			return;
		}

		$ext = strtolower(pathinfo($snapshot, PATHINFO_EXTENSION));
		if ($ext !== 'smartin') {
			$this->snapshoter_send_json_error(array(
				'message' => __('Only .smartin archives can be restored.', 'snapshoter'),
			), 400);
			return;
		}

		$snapshot_path = $this->find_snapshot_file($snapshot);

		if (!$snapshot_path) {
			$this->snapshoter_send_json_error(array('message' => __('Snapshot file not found.', 'snapshoter')), 404);
			return;
		}

		try {
			$job_id = uniqid('snapshot_restore_', true);
			$passphrase = $this->generate_job_passphrase();

			try {
				$this->store->ensure_job_dir($job_id);
			} catch (\Throwable $e) {
				$this->debug_log('restore_snapshot() ensure_job_dir failed', array('error' => $e->getMessage()));
				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}

			try {
				$this->store->write(
					$job_id,
					array(
						'job_passphrase_hash' => $this->hash_job_passphrase($passphrase),
						'type' => 'import',
						'snapshot_restore' => true,
						'snapshot_filename' => $snapshot,
					)
				);
				$this->remember_job_passphrase($job_id, $passphrase);
			} catch (\Throwable $e) {
				$this->debug_log('restore_snapshot() initial write failed', array('error' => $e->getMessage()));
				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}

			$job = $this->create_job('import', $job_id);
			$job->init();

			$current_state = $this->store->read($job_id);

			$state_patch = array(
				'archive_path' => $snapshot_path,
				'snapshot_restore' => true,
				'snapshot_filename' => $snapshot,
				'step' => ImportJob::STEP_VALIDATE,
				'status' => 'validating',
			);

			try {
				$this->store->write(
					$job_id,
					array_merge(
						$current_state,
						$state_patch
					)
				);
			} catch (\Throwable $e) {
				$this->debug_log('restore_snapshot() state update write failed', array('error' => $e->getMessage()));
				$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
				return;
			}

			try {
				$this->store->log(
					$job_id,
					sprintf('Snapshot rollback queued for %s', $snapshot)
				);
			} catch (\Throwable $e) {

				$this->debug_log('restore_snapshot() log failed', array('error' => $e->getMessage()));
			}

			$this->snapshoter_start_background_driver($job_id);

			$this->snapshoter_send_json_success(
				array(
					'jobId' => $job_id,
					'jobPassphrase' => $passphrase,
					'message' => __('Snapshot rollback started.', 'snapshoter'),
					'engine' => '',
				)
			);
		} catch (\Throwable $e) {
			$this->debug_log('restore_snapshot() exception', array('error' => $e->getMessage()));
			$this->snapshoter_send_json_error(array('message' => esc_html($e->getMessage())), 500);
		}
	}

	public function delete_snapshot()
	{
		$this->guard();

		$snapshot = '';
		if (!empty($_POST['snapshot'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['snapshot']));
		} elseif (!empty($_POST['filename'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['filename']));
		} elseif (!empty($_POST['name'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['name']));
		} elseif (!empty($_POST['file'])) {
			$snapshot = sanitize_file_name(wp_unslash($_POST['file']));
		} elseif (!empty($_REQUEST['snapshot'])) {
			$snapshot = sanitize_file_name(wp_unslash($_REQUEST['snapshot']));
		} elseif (!empty($_REQUEST['filename'])) {
			$snapshot = sanitize_file_name(wp_unslash($_REQUEST['filename']));
		}

		if (empty($snapshot)) {
			$this->snapshoter_send_json_error(array('message' => __('Invalid snapshot selection.', 'snapshoter')), 400);
			return;
		}

		$deleted_local = false;
		$snapshot_path = $this->find_snapshot_file($snapshot);

		if ($snapshot_path) {

			$allowed_dirs = array();
			if (defined('SNAPSHOTER_SNAPSHOTS_DIR') && is_dir(SNAPSHOTER_SNAPSHOTS_DIR)) {
				$allowed_dirs[] = rtrim(wp_normalize_path(SNAPSHOTER_SNAPSHOTS_DIR), '/\\') . '/';
			}
			if (defined('SNAPSHOTER_RESTORES_DIR') && is_dir(SNAPSHOTER_RESTORES_DIR)) {
				$allowed_dirs[] = rtrim(wp_normalize_path(SNAPSHOTER_RESTORES_DIR), '/\\') . '/';
			}
			if (defined('SNAPSHOTER_ARCHIVE_DIR') && is_dir(SNAPSHOTER_ARCHIVE_DIR)) {
				$allowed_dirs[] = rtrim(wp_normalize_path(SNAPSHOTER_ARCHIVE_DIR), '/\\') . '/';
			}
			if (defined('SNAPSHOTER_STORAGE') && is_dir(SNAPSHOTER_STORAGE)) {
				$allowed_dirs[] = rtrim(wp_normalize_path(SNAPSHOTER_STORAGE), '/\\') . '/';
			}

			$resolved = realpath($snapshot_path);
			$check_against = $resolved !== false ? wp_normalize_path($resolved) : wp_normalize_path($snapshot_path);

			$is_allowed = false;
			foreach ($allowed_dirs as $allowed_dir) {
				if (strpos($check_against, $allowed_dir) === 0) {
					$is_allowed = true;
					break;
				}
			}

			if ($is_allowed) {
				$unlinked = wp_delete_file($snapshot_path);
				if ($unlinked) {
					$deleted_local = true;
					clearstatcache(true, $snapshot_path);
				}
				$parent = dirname($snapshot_path);
				$normalized_snap_dir = defined('SNAPSHOTER_SNAPSHOTS_DIR') ? wp_normalize_path(SNAPSHOTER_SNAPSHOTS_DIR) : '';
				if ($parent && $parent !== $normalized_snap_dir && is_dir($parent)) {
					$remaining = @scandir($parent);
					if (is_array($remaining)) {
						$material = array_values(array_filter(
							$remaining,
							function ($entry) {
								return $entry !== '.' && $entry !== '..' && $entry !== 'index.php';
							}
						));
						if (empty($material)) {
							$index_stub = $parent . DIRECTORY_SEPARATOR . 'index.php';
							if (file_exists($index_stub)) {
								wp_delete_file($index_stub);
							}
							@rmdir($parent);
						}
					}
				}
			}
		}

		$this->snapshoter_send_json_success(array(
			'message' => __('Snapshot deleted.', 'snapshoter'),
			'filename' => $snapshot,
			'deleted_local' => $deleted_local,
		));
	}

	public static function find_snapshot_file($filename)
	{
		if (empty($filename)) {
			return false;
		}

		$filename = basename((string) $filename);

		$search_dirs = array();
		if (defined('SNAPSHOTER_RESTORES_DIR') && is_dir(SNAPSHOTER_RESTORES_DIR)) {
			$search_dirs[] = wp_normalize_path(SNAPSHOTER_RESTORES_DIR);
		}
		if (defined('SNAPSHOTER_SNAPSHOTS_DIR') && is_dir(SNAPSHOTER_SNAPSHOTS_DIR)) {
			$search_dirs[] = wp_normalize_path(SNAPSHOTER_SNAPSHOTS_DIR);
		}
		if (defined('SNAPSHOTER_ARCHIVE_DIR') && is_dir(SNAPSHOTER_ARCHIVE_DIR)) {
			$search_dirs[] = wp_normalize_path(SNAPSHOTER_ARCHIVE_DIR);
		}
		if (defined('SNAPSHOTER_STORAGE') && is_dir(SNAPSHOTER_STORAGE)) {
			$search_dirs[] = wp_normalize_path(SNAPSHOTER_STORAGE);
		}

		if (empty($search_dirs)) {
			return false;
		}

		$candidates = array($filename);
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, array('smartin', 'zip', 'sql'), true)) {
			$candidates[] = $filename . '.smartin';
		}

		foreach ($search_dirs as $dir) {
			foreach ($candidates as $cand) {
				$direct = wp_normalize_path($dir . '/' . $cand);
				if (file_exists($direct) && is_readable($direct)) {
					return $direct;
				}

				$date_folders = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
				if (!empty($date_folders)) {
					foreach ($date_folders as $folder) {
						$potential = wp_normalize_path($folder . DIRECTORY_SEPARATOR . $cand);
						if (file_exists($potential) && is_readable($potential)) {
							return $potential;
						}
					}
				}
			}
		}

		return false;
	}

	public function get_job_log()
	{

		$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
		$job_passphrase = isset($_POST['job_passphrase']) ? sanitize_text_field(wp_unslash($_POST['job_passphrase'])) : '';
		$offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

		$this->guard($job_id, $job_passphrase);

		if (empty($job_id)) {
			$this->snapshoter_send_json_error(array('message' => __('Missing job ID.', 'snapshoter')), 400);
			return;
		}

		$store = new StateStore();
		$job_dir = $store->job_dir($job_id);
		$log_file = trailingslashit($job_dir) . 'activity.log';

		if (!file_exists($log_file)) {
			$this->snapshoter_send_json_success(array(
				'chunk' => '',
				'next_offset' => $offset,
				'completed' => true,
			));
			return;
		}

		$size = filesize($log_file);
		if ($size === false) {
			$this->snapshoter_send_json_success(array(
				'chunk' => '',
				'next_offset' => $offset,
				'completed' => true,
			));
			return;
		}

		if ($offset >= $size) {
			$this->snapshoter_send_json_success(array(
				'chunk' => '',
				'next_offset' => $size,
				'completed' => true,
			));
			return;
		}

		$handle = fopen($log_file, 'rb');
		if ($handle === false) {
			$this->snapshoter_send_json_error(array('message' => __('Unable to read job log.', 'snapshoter')), 500);
			return;
		}

		if ($offset > 0) {
			fseek($handle, $offset);
		}

		$chunk = fread($handle, 65536);
		fclose($handle);

		if ($chunk === false) {
			$chunk = '';
		}

		$next_offset = $offset + strlen($chunk);

		$this->snapshoter_send_json_success(array(
			'chunk' => $chunk,
			'next_offset' => $next_offset,
			'completed' => ($next_offset >= $size),
		));
	}

	private function is_backup_running()
	{
		$job_dir = SNAPSHOTER_JOB_DIR;
		if (!is_dir($job_dir)) {
			return false;
		}

		$dirs = glob($job_dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
		foreach ($dirs as $dir) {
			$job_id = basename($dir);
			$state = $this->store->read($job_id);

			if (!empty($state['type']) && $state['type'] === 'export') {
				if (!empty($state['status']) && $state['status'] === 'running') {
					if (empty($state['step']) || $state['step'] !== 'completed') {
						return true;
					}
				}
			}
		}

		return false;
	}

	private function validate_snapshot_path($raw_path)
	{
		if (empty($raw_path)) {
			return new \WP_Error('invalid_path', __('Snapshot path is missing.', 'snapshoter'));
		}

		$real_path = realpath($raw_path);

		$normalized_file = wp_normalize_path($real_path);
		$normalized_dir = wp_normalize_path(SNAPSHOTER_SNAPSHOTS_DIR);

		if (strpos($normalized_file, $normalized_dir) !== 0) {
			return new \WP_Error('invalid_path', __('Invalid snapshot path.', 'snapshoter'));
		}

		if (!file_exists($normalized_file) || !is_file($normalized_file)) {
			return new \WP_Error('invalid_path', __('Snapshot file could not be found.', 'snapshoter'));
		}

		return $normalized_file;
	}

}

