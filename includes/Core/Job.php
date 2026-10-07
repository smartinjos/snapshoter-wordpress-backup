<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

abstract class Job
{

	protected $store;

	protected $job_id;

	protected $state = array();

	public function __construct(StateStore $store, $job_id)
	{
		$this->store = $store;
		$this->job_id = $job_id;
		$this->state = $store->read($job_id);
	}

	abstract public function type();

	abstract public function init();

	abstract public function tick();

	abstract public function cleanup();

	protected function get($key, $default = null)
	{
		return isset($this->state[$key]) ? $this->state[$key] : $default;
	}

	protected function update(array $changes)
	{
		$disk = $this->store->read($this->job_id);
		$merged = array_merge($this->state, is_array($disk) ? $disk : array(), $changes);
		$this->state = $merged;
		$this->store->write($this->job_id, $this->state);
	}

	protected function log($message)
	{
		$this->store->log($this->job_id, $message);
	}

	protected function response(array $data = array())
	{
		$state = $this->state;

		if (isset($state['job_passphrase'])) {
			unset($state['job_passphrase']);
		}
		if (isset($state['job_passphrase_hash'])) {
			unset($state['job_passphrase_hash']);
		}

		return array_merge(
			array(
				'jobId' => $this->job_id,
				'type' => $this->type(),
				'state' => $state,
				'log' => $this->store->get_log($this->job_id),
			),
			$data
		);
	}

	protected function apply_throttling()
	{

		$throttle = '';
		if (isset($_SERVER['HTTP_X_SNAPSHOTER_THROTTLE'])) {
			$throttle = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SNAPSHOTER_THROTTLE']));
		}
		if ($throttle === 'true') {
			$this->log('Received throttling signal from client. Reducing batch sizes by 50%.');

			$current_row_chunk = (int) $this->get('row_chunk', 500);
			$current_file_batch = (int) $this->get('file_batch_size', 200);
			$current_restore_bytes = (int) $this->get(
				'restore_bytes_budget',
				\Snapshoter\Core\TickCapacity::RESTORE_BYTES_DEFAULT
			);
			$current_upload_seconds = (float) $this->get(
				'upload_time_budget',
				\Snapshoter\Core\TickCapacity::UPLOAD_SECONDS_DEFAULT
			);

			$new_row_chunk = max(50, (int) ($current_row_chunk * 0.5));
			$new_file_batch = max(10, (int) ($current_file_batch * 0.5));
			$new_restore_bytes = \Snapshoter\Core\TickCapacity::throttle_restore_bytes($current_restore_bytes);
			$new_upload_seconds = \Snapshoter\Core\TickCapacity::throttle_upload_seconds($current_upload_seconds);

			$this->update(
				array(
					'row_chunk' => $new_row_chunk,
					'file_batch_size' => $new_file_batch,
					'restore_bytes_budget' => $new_restore_bytes,
					'restore_fast_streak' => 0,
					'upload_time_budget' => $new_upload_seconds,
					'upload_fast_streak' => 0,
				)
			);

			$this->log(
				sprintf(
					'Throttle: restore byte budget %s → %s; upload tick %.1fs → %.1fs',
					size_format($current_restore_bytes),
					size_format($new_restore_bytes),
					$current_upload_seconds,
					$new_upload_seconds
				)
			);

			return true;
		}

		return false;
	}

	protected function adjust_capacity($execution_time)
	{

		$target_time = 20.0;

		$current_row_chunk = (int) $this->get('row_chunk', 500);
		$current_file_batch = (int) $this->get('file_batch_size', 200);

		$changes = array();

		if ($execution_time > ($target_time * 0.7)) {
			$factor = 0.6;

			$new_row_chunk = max(50, (int) ($current_row_chunk * $factor));
			$new_file_batch = max(10, (int) ($current_file_batch * $factor));

			$changes['row_chunk'] = $new_row_chunk;
			$changes['file_batch_size'] = $new_file_batch;

			$this->log(
				sprintf(
					'Performance Warning: Last chunk took %.2fs. Throttling down (Rows: %d->%d, Files: %d->%d)',
					$execution_time,
					$current_row_chunk,
					$new_row_chunk,
					$current_file_batch,
					$new_file_batch
				)
			);
		} elseif ($execution_time < ($target_time * 0.25)) {

			$factor = 1.1;

			$new_row_chunk = min(2000, (int) ($current_row_chunk * $factor));
			$new_file_batch = min(2000, (int) ($current_file_batch * $factor));

			if ($new_row_chunk > $current_row_chunk || $new_file_batch > $current_file_batch) {
				$changes['row_chunk'] = $new_row_chunk;
				$changes['file_batch_size'] = $new_file_batch;

				if ($new_row_chunk % 100 === 0) {
					$this->log(
						sprintf(
							'Performance Good: Last chunk took %.2fs. Increasing speed (Rows: %d->%d)',
							$execution_time,
							$current_row_chunk,
							$new_row_chunk
						)
					);
				}
			}
		}

		if (!empty($changes)) {
			$this->update($changes);
		}
	}

	protected function get_restore_bytes_budget()
	{
		$budget = (int) $this->get('restore_bytes_budget', 0);
		if ($budget > 0) {
			return \Snapshoter\Core\TickCapacity::clamp_restore_bytes($budget);
		}

		$memory = \Snapshoter\Core\TickCapacity::parse_ini_bytes((string) @ini_get('memory_limit'));
		$budget = \Snapshoter\Core\TickCapacity::initial_restore_bytes(
			\Snapshoter\Core\Environment::can_extend_time(),
			$memory
		);
		$this->update(array('restore_bytes_budget' => $budget, 'restore_fast_streak' => 0));
		$this->log(
			sprintf(
				'Host-aware restore budget: %s/tick (time_extend=%s, memory_limit=%s)',
				size_format($budget),
				\Snapshoter\Core\Environment::can_extend_time() ? 'yes' : 'no',
				$memory > 0 ? size_format($memory) : 'unknown'
			)
		);
		return $budget;
	}

	protected function adjust_restore_capacity($elapsed, $time_budget, $bytes_copied, $hit_byte_cap, $hit_time_cap)
	{
		$current = $this->get_restore_bytes_budget();
		$streak = (int) $this->get('restore_fast_streak', 0);
		$result = \Snapshoter\Core\TickCapacity::next_restore_bytes(
			$current,
			$elapsed,
			$time_budget,
			$bytes_copied,
			$hit_byte_cap,
			$hit_time_cap,
			$streak
		);

		$updates = array(
			'restore_bytes_budget' => $result['budget'],
			'restore_fast_streak' => $result['fast_streak'],
		);

		if ($result['action'] === 'bump' && $result['budget'] > $current) {
			$this->log(
				sprintf(
					'Restore capacity bump: %s → %s (last tick %.2fs / %.0fs budget)',
					size_format($current),
					size_format($result['budget']),
					$elapsed,
					$time_budget
				)
			);
		} elseif ($result['action'] === 'backoff' && $result['budget'] < $current) {
			$this->log(
				sprintf(
					'Restore capacity backoff: %s → %s (last tick %.2fs, time_cap=%s)',
					size_format($current),
					size_format($result['budget']),
					$elapsed,
					$hit_time_cap ? 'yes' : 'no'
				)
			);
		}

		$this->update($updates);
	}
}

