<?php

namespace Snapshoter\Import;

use Snapshoter\Core\Job;
use Snapshoter\Core\StateStore;
use Snapshoter\Core\Environment;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable Squiz.PHP.DiscouragedFunctions
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// phpcs:disable PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared

// phpcs:disable WordPress.WP.AlternativeFunctions

class ImportJob extends Job
{

	const STEP_INIT = 'init';
	const STEP_UPLOAD = 'upload';
	const STEP_DOWNLOAD = 'downloading';
	const STEP_VALIDATE = 'validate';
	const STEP_EXTRACT = 'extract';
	const STEP_DISABLE_SITE = 'disable_site';
	const STEP_RESTORE_FILES = 'restore_files';
	const STEP_MIRROR_PRUNE = 'mirror_prune';
	const STEP_IMPORT_DB = 'import_database';
	const STEP_FINALIZE = 'finalize';
	const STEP_COMPLETED = 'completed';

	private $extract_path;

	private $db_checks_suppressed = false;

	private $pending_safe_mode_restore_mu = true;

	private $restore_drain_nested = false;

	private $restore_drain_iter = 0;

	private $restore_drain_deadline = 0.0;

	public function __construct(StateStore $store, $job_id)
	{
		parent::__construct($store, $job_id);

		$this->extract_path = $this->store->job_dir($job_id) . '/extract';
	}

	public function type()
	{
		return 'import';
	}

	public function init()
	{

		Environment::configure();

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		if (!class_exists('\ZipArchive')) {
			throw new \RuntimeException(esc_html__('ZipArchive extension is required for Snapshoter Backup imports.', 'snapshoter'));
		}

		$this->store->ensure_job_dir($this->job_id);

		$current_site_url = site_url();
		$current_home_url = home_url();
		$this->log(
			sprintf(
				'Captured target site URLs for import lock: siteurl=%s, home=%s',
				$current_site_url,
				$current_home_url
			)
		);

		$previous_status = $this->get('status');
		$previous_archive_path = $this->get('archive_path');

		if (is_dir($this->extract_path)) {
			try {
				$this->recursive_rmdir($this->extract_path);
				$this->log('Cleaned up extracted files from previous import attempt.');
			} catch (\Throwable $e) {

				$this->log('Warning: Could not fully clean up extract directory: ' . $e->getMessage());
			}
		}

		if (!empty($previous_archive_path) && file_exists($previous_archive_path) && $previous_status === 'failed') {
			try {

				wp_delete_file($previous_archive_path);
				$this->log('Removed archive file from previous failed import.');
			} catch (\Throwable $e) {

				$this->log('Warning: Could not remove old archive file: ' . $e->getMessage());
			}
		}

		if (!is_dir($this->extract_path)) {

			$result = @mkdir($this->extract_path, 0755, true);
			if (!$result && !is_dir($this->extract_path)) {

				// translators: %s: directory path.
				throw new \RuntimeException(esc_html(sprintf(esc_html__('Unable to create extract directory: %s', 'snapshoter'), esc_html($this->extract_path))));
			}
		}

		$this->update(
			array(
				'type' => $this->type(),
				'status' => 'awaiting-upload',
				'step' => self::STEP_UPLOAD,
				'archive_path' => null,
				'manifest' => array(),
				'extract_path' => $this->extract_path,
				'db_imported' => false,
				'files_restored' => false,
				'target_site_url' => $current_site_url,
				'target_home_url' => $current_home_url,
			)
		);

		$this->log('Import job initialized.');

		return $this->response();
	}

	public function tick()
	{

		if (!$this->restore_drain_nested && $this->should_run_restore_drain()) {
			return $this->run_restore_drain();
		}

		return $this->tick_once();
	}

	private function should_run_restore_drain()
	{
		if ((string) $this->get('engine') === 'direct_v1') {
			return in_array(
				(string) $this->get('step', ''),
				array(
					self::STEP_RESTORE_FILES,
					self::STEP_MIRROR_PRUNE,
					self::STEP_IMPORT_DB,
				),
				true
			);
		}

		return false;
	}

	private function run_restore_drain()
	{
		Environment::configure();
		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$budget = $this->restore_drain_budget_seconds();
		$memory = \Snapshoter\Core\TickCapacity::parse_ini_bytes((string) @ini_get('memory_limit'));

		if ($memory > 0 && $memory < 96 * 1024 * 1024) {
			$boost = 24 * 1024 * 1024;
		} else {
			$boost = \Snapshoter\Core\TickCapacity::RESTORE_BYTES_CEILING;
		}
		$boost = \Snapshoter\Core\TickCapacity::clamp_restore_bytes($boost);
		$this->update(
			array(
				'restore_bytes_budget' => $boost,
				'file_batch_size'      => \Snapshoter\Core\TickCapacity::RESTORE_BATCH_CEILING,
			)
		);

		$start = microtime(true);
		$this->restore_drain_deadline = $start + $budget;
		$this->restore_drain_nested = true;
		$last = null;
		$iters = 0;
		$max_iters = 400;

		try {
			for ($i = 0; $i < $max_iters; $i++) {
				$left = $this->restore_drain_deadline - microtime(true);
				if ($left < 0.35) {
					break;
				}
				$this->restore_drain_iter = $i;
				$last = $this->tick_once();
				$iters++;
				$this->flush_restore_ui_progress();

				if (!empty($last['completed']) || !empty($last['cancelled']) || !empty($last['error'])) {
					break;
				}
				$status = (string) $this->get('status', '');
				if (in_array($status, array('failed', 'cancelled', 'completed'), true)) {
					break;
				}
				if ((string) $this->get('step', '') === self::STEP_COMPLETED) {
					break;
				}
			}
		} finally {
			$this->restore_drain_nested = false;
			$this->restore_drain_iter = 0;
			$this->restore_drain_deadline = 0.0;
		}

		if (!is_array($last)) {
			$last = $this->response();
		}
		$last['drain_ticks'] = $iters;
		$last['drain_seconds'] = round(microtime(true) - $start, 2);
		$last['drain_budget'] = $budget;

		if ($iters > 1) {
			$this->log(
				sprintf(
					'Restore apply drain: %d inner tick(s) in %ss (budget %ss).',
					$iters,
					(string) $last['drain_seconds'],
					(string) $budget
				)
			);
		}

		return $last;
	}

	private function restore_drain_budget_seconds()
	{
		$limit = (int) @ini_get('max_execution_time');
		$can_extend = Environment::can_extend_time();

		if ((string) $this->get('driver', '') === 'server') {
			if ($can_extend || $limit <= 0) {
				return 5.0;
			}
			return (float) min(6.0, max(3.0, (float) $limit - 2.0));
		}

		if ($can_extend || $limit <= 0) {
			return 22.0;
		}

		$usable = max(3.0, (float) $limit - 2.0);
		return (float) min(28.0, $usable);
	}

	private function flush_restore_ui_progress()
	{
		$progress = $this->calculate_progress();
		$this->update(
			array(
				'progress'      => $progress,
				'percent'       => $progress,
				'current_step'  => (string) $this->get('step', ''),
				'ui_updated_at' => time(),
			)
		);
	}

	private function restore_soft_time_limit($strong = 25.0, $weak = 12.0)
	{
		if ($this->restore_drain_nested && $this->restore_drain_deadline > 0) {
			$left = $this->restore_drain_deadline - microtime(true);
			if ($left < 0.4) {
				return 0.25;
			}
			return max(0.4, $left - 0.2);
		}

		return Environment::can_extend_time() ? (float) $strong : (float) $weak;
	}

	private function tick_once()
	{

		$this->suppress_wordpress_db_checks();

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		if ($this->get('status') === 'cancelled') {

			$this->pending_safe_mode_restore_mu = false;
			$this->cleanup();
			return array('completed' => true, 'cancelled' => true);
		}

		if ($this->get('status') === 'failed') {
			$error_message = $this->get('message', __('Import failed.', 'snapshoter'));
			return $this->response(array('completed' => true, 'error' => $error_message));
		}

		$step_now = $this->get('step', self::STEP_UPLOAD);
		if (
			in_array(
				$step_now,
				array(
					self::STEP_DISABLE_SITE,
					self::STEP_RESTORE_FILES,
					self::STEP_MIRROR_PRUNE,
					self::STEP_IMPORT_DB,
				),
				true
			)
		) {

			if (!$this->restore_drain_nested || $this->restore_drain_iter === 0 || ($this->restore_drain_iter % 8) === 0) {
				$this->enforce_restore_freeze('tick:' . $step_now);
			}
		}

		try {
			$step = $this->get('step', self::STEP_UPLOAD);

			if (!isset($this->state['step']) || $this->state['step'] !== $step) {
				$this->state['step'] = $step;
			}

			switch ($step) {
				case self::STEP_UPLOAD:

					if ($this->get('archive_path')) {
						$this->update(array('step' => self::STEP_VALIDATE, 'status' => 'validating'));
					}
					break;

				case self::STEP_DOWNLOAD:
					$local = (string) $this->get('archive_path', '');
					if ($local !== '' && file_exists($local)) {
						$next = $this->step_after_archive_ready();
						$this->update(array(
							'step'         => $next['step'],
							'status'       => $next['status'],
							'engine'       => $next['engine'],
							'restore_mode' => $next['restore_mode'],
						));
						break;
					}
					throw new \RuntimeException(esc_html__('Upload a local .smartin archive to restore.', 'snapshoter'));

				case self::STEP_VALIDATE:
					$this->log('Validating archive...');
					$this->validate_archive();

					$this->update(array('step' => self::STEP_EXTRACT, 'status' => 'extracting'));
					break;

				case self::STEP_EXTRACT:

					if ($this->get('engine') === 'direct_v1') {
						$this->log('Engine v2 direct-apply: skipping full extract (stream zip → site paths).');
						$this->update(array(
							'step'           => self::STEP_DISABLE_SITE,
							'status'         => 'disabling-site',
							'extract_offset' => (int) $this->get('total_extract', 0),
							'extracted'      => (int) $this->get('total_extract', 0),
						));
						break;
					}
					$this->log('Extracting files...');
					if ($this->extract_entries_chunk()) {
						$this->log('Extraction complete. Moving to site disable step.');
						$this->update(array('step' => self::STEP_DISABLE_SITE, 'status' => 'disabling-site'));
					} else {
						$this->log('Extraction in progress...');

						$this->update(array('step' => self::STEP_EXTRACT));
					}
					break;

				case self::STEP_DISABLE_SITE:
					$this->log('Disabling site (plugin freeze + safe theme + safe-mode mu-plugin)...');
					$this->disable_site();
					$safe_theme = (string) $this->get('safe_theme', '');
					$plugin_basename = defined('SNAPSHOTER_FILE')
						? plugin_basename(SNAPSHOTER_FILE)
						: 'snapshoter/snapshoter.php';
					if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
						$safe_ok = \Snapshoter\Import\RestoreSafeMode::install(
							$this->job_id,
							$safe_theme,
							$plugin_basename
						);
						$this->log($safe_ok
							? 'Restore safe-mode mu-plugin installed (plugin/theme freeze + maintenance + tick fatal→JSON).'
							: 'Warning: could not install restore safe-mode mu-plugin (continuing).');
						$this->update(array('safe_mode' => $safe_ok ? 1 : 0));
					}
					$this->enforce_restore_freeze('post-disable');
					$this->log('Site preparation complete. Moving to file restoration step.');
					$total_entries = (int) $this->get('total_restore', 0);
					if ($total_entries <= 0) {
						$index_meta = $this->get('archive_index', array());
						$total_entries = is_array($index_meta) && isset($index_meta['total'])
							? (int) $index_meta['total']
							: 0;
					}
					$this->update(array('step' => self::STEP_RESTORE_FILES, 'status' => 'restoring-files'));
					$this->log(sprintf(
						'Step updated to restore_files. Streaming %d archive entries to disk.',
						$total_entries
					));
					if ((string) $this->get('engine') === 'direct_v1' && !$this->restore_drain_nested) {
						return $this->run_restore_drain();
					}
					break;

				case self::STEP_RESTORE_FILES:
					try {
						$files_done = ($this->get('engine') === 'direct_v1')
							? $this->restore_files_from_zip_chunk()
							: $this->restore_files_chunk();
					} catch (\Throwable $files_error) {
						$this->log_restore_debug(
							'files_exception',
							array(
								'error' => $files_error->getMessage(),
								'file'  => $files_error->getFile() . ':' . $files_error->getLine(),
								'last'  => (string) $this->get('last_restored_path', ''),
								'cursor'=> (int) $this->get('entry_cursor', (int) $this->get('restore_offset', 0)),
								'total' => (int) $this->get('total_restore', 0),
							)
						);
						$this->log('ERROR in restore_files: ' . $files_error->getMessage());
						throw $files_error;
					}
					if ($files_done) {
						$this->log_skip_summary();
						$exact_mirror = !empty($this->get('exact_mirror'));
						$this->log_restore_debug('files_complete', array('exact_mirror' => (bool) $exact_mirror));
						$this->log(
							$exact_mirror
								? 'File restoration complete. Moving to Exact Mirror orphan prune (plugins/themes/uploads).'
								: 'File restoration complete. Moving to Fast Restore prune (plugins/themes only).'
						);
						$this->update(array('step' => self::STEP_MIRROR_PRUNE, 'status' => 'pruning-orphans'));
					} else {

						$this->update(array('step' => self::STEP_RESTORE_FILES));
					}
					break;

				case self::STEP_MIRROR_PRUNE:
					$exact_mirror = !empty($this->get('exact_mirror'));
					$this->log(
						$exact_mirror
							? 'Exact Mirror prune: removing plugins/themes/uploads not in the incoming .smartin...'
							: 'Fast Restore prune: removing leftover plugins/themes only (uploads kept)...'
					);
					if ($this->mirror_prune_chunk()) {
						$this->log('Orphan prune complete. Moving to database import step.');
						$this->update(array('step' => self::STEP_IMPORT_DB, 'status' => 'importing-database'));
					} else {
						$this->update(array('step' => self::STEP_MIRROR_PRUNE));
					}
					break;

				case self::STEP_IMPORT_DB:
					$this->log('Starting database import...');
					if ($this->import_database_chunk()) {
						$this->log('Database import complete.');

						$this->reapply_preserved_site_credentials();

						$this->clean_permalink_structure_immediately();
						$this->update_site_urls_immediately();

						$this->log('Moving to finalization step.');
						$this->update(
							array(
								'step'    => self::STEP_FINALIZE,
								'status'  => 'finalizing',
								'message' => __('Finalizing restore... Applying WordPress core and finishing setup. The homepage may show "restore in progress" until this finishes.', 'snapshoter'),
							)
						);
					} else {
						$this->log('Database import in progress. More SQL to process.');

						$this->reapply_preserved_site_credentials();

						$this->update(array('step' => self::STEP_IMPORT_DB));
					}
					break;

				case self::STEP_FINALIZE:
					try {
						$this->reapply_preserved_site_credentials();
						if ($this->finalize_site_tick()) {
							$this->cleanup();
							$this->remember_restore_origin();
							$this->log('Finalization completed successfully.');
							$this->update(
								array(
									'step'     => self::STEP_COMPLETED,
									'status'   => 'completed',
									'progress' => 100,
									'percent'  => 100,
									'message'  => __('Restore completed successfully.', 'snapshoter'),
								)
							);
						} else {

							$this->update(array('step' => self::STEP_FINALIZE));
						}
					} catch (\Throwable $finalize_error) {
						$this->log('ERROR in finalize_site(): ' . $finalize_error->getMessage());
						$this->log('Finalize error trace: ' . $finalize_error->getTraceAsString());
						throw $finalize_error;
					}
					break;

				case self::STEP_COMPLETED:
					return $this->response(
						array(
							'completed' => true,
							'message' => __('Your site has been imported successfully.', 'snapshoter'),
							'notices' => $this->get('post_finalize_notices', array()),
						)
					);
			}
		} catch (\Throwable $e) {
			$this->log('Import error caught: ' . $e->getMessage());
			$this->log('Error occurred at step: ' . $this->get('step', 'unknown'));

			$this->log('Attempting to restore site to previous state...');
			try {
				$this->restore_site_on_error();
			} catch (\Throwable $restore_error) {
				$this->log('CRITICAL: Failed to restore site after error: ' . $restore_error->getMessage());
				$this->log('Restore error trace: ' . $restore_error->getTraceAsString());
			}

			$this->pending_safe_mode_restore_mu = false;

			$this->log('Leaving restore safe-mode active after failure (site stays bootable).');

			$this->update(
				array(
					'status' => 'failed',
					'message' => $e->getMessage(),
				)
			);

			$this->log('Import marked as failed. Error message: ' . $e->getMessage());

			return $this->response(array('error' => $e->getMessage()));
		}

		$completed = ($this->get('step') === self::STEP_COMPLETED);
		$payload = array('completed' => $completed);

		if ($completed) {
			$payload['notices'] = $this->get('post_finalize_notices', array());
		}

		return $this->response($payload);
	}

	private function validate_archive()
	{
		$archive = $this->get_archive();

		$manifest = $this->read_manifest($archive);

		$manifest_scope = isset($manifest['scope']) ? (string) $manifest['scope'] : 'full';
		if (!in_array($manifest_scope, array('full', 'database', 'files'), true)) {
			$manifest_scope = 'full';
		}
		$has_db = ($archive->locateName('database/database.sql') !== false ||
		           $archive->locateName('database.sql') !== false ||
		           $archive->locateName('files/database/database.sql') !== false ||
		           $archive->locateName('files/database.sql') !== false);
		$requires_database = ($manifest_scope !== 'files');

		if ($requires_database && !$has_db) {
			$this->log('Notice: No database dump found in archive. Falling back to files-only restoration.');
			$manifest_scope = 'files';
			$requires_database = false;
		}

		$this->verify_archive_integrity($archive, $manifest);

		$archive_path = (string) $this->get('archive_path', '');
		$archive_bytes = ($archive_path !== '' && file_exists($archive_path)) ? (int) filesize($archive_path) : 0;
		$use_direct = \Snapshoter\Core\EngineFlags::use_direct_apply($archive_bytes)
			|| !empty($this->get('prefer_direct_apply'));
		$gate = \Snapshoter\Core\ServerCapacityGate::evaluate_restore($archive_bytes, $use_direct);
		foreach ($gate['warnings'] as $warn) {
			$this->log('Capacity: ' . $warn);
		}
		if (!empty($gate['block'])) {
			throw new \RuntimeException(
				!empty($gate['warnings'][0])
					? esc_html($gate['warnings'][0])
					: esc_html__('Not enough free disk space to restore this archive.', 'snapshoter')
			);
		}

		$queue = $this->build_extraction_queue($archive);
		$total_entries = count($queue);

		$queue_path = $this->store->job_dir($this->job_id) . '/extraction_queue.json';
		file_put_contents($queue_path, json_encode($queue));

		$engine = 'legacy_extract';
		$index_meta = null;
		if ($use_direct) {

			$archive = $this->get_archive();
			$index = new \Snapshoter\Import\ArchiveIndex($this->store->job_dir($this->job_id));
			$index_meta = $index->build_from_zip($archive);
			$archive->close();
			$engine = 'direct_v1';
			$this->log(sprintf(
				'Engine v2 ArchiveIndex ready (%s): %d entries, ~%s payload (wp-content before core).',
				$index_meta['backend'],
				(int) $index_meta['total'],
				size_format((int) $index_meta['bytes'])
			));

			$this->discover_slugs_from_extraction_queue();
		}

		$this->update(
			array(
				'manifest' => $manifest,
				'total_extract' => $total_entries,
				'extract_offset' => 0,
				'status' => 'extracting',
				'archive_bytes' => $archive_bytes,
				'engine' => $engine,
				'capacity' => array(
					'free_bytes'   => $gate['free_bytes'],
					'memory_bytes' => $gate['memory_bytes'],
					'can_extend'   => !empty($gate['can_extend']),
				),
				'archive_index' => $index_meta,
				'entry_cursor' => 0,
				'entry_byte_offset' => 0,
				'bytes_restored' => 0,
			)
		);

		// Mirror policy (Exact vs Fast) once the manifest is known.
		$this->resolve_exact_mirror_policy();

		$this->log(sprintf(
			'Archive validated. %d entries. Engine=%s. Free disk=%s memory_limit=%s.',
			$total_entries,
			$engine,
			size_format((int) $gate['free_bytes']),
			size_format((int) $gate['memory_bytes'])
		));

		if (isset($manifest['skipped_files']) && is_array($manifest['skipped_files'])) {
			$export_skip = \Snapshoter\Core\SkipReporter::normalize($manifest['skipped_files']);
			if ($export_skip['count'] > 0) {
				$this->log(sprintf(
					'NOTE: source backup is missing %d file(s) the export couldn\'t capture (%s). These cannot be restored.',
					$export_skip['count'],
					$this->format_skip_reasons($export_skip['reasons'])
				));
				if (!empty($export_skip['samples'])) {
					$sample_paths = array_slice(
						array_map(function ($s) { return $s['path']; }, $export_skip['samples']),
						0,
						10
					);
					$this->log('Examples: ' . implode(', ', $sample_paths));
				}
			}
		}
	}

	private function extract_entries_chunk()
	{
		$queue_path = $this->store->job_dir($this->job_id) . '/extraction_queue.json';
		if (!file_exists($queue_path)) {
			$this->log('CRITICAL: extraction_queue.json missing.');
			return true;
		}

		$all_entries = json_decode(file_get_contents($queue_path), true);
		if (!is_array($all_entries)) {
			return true;
		}

		$total = count($all_entries);
		$offset = (int) $this->get('extract_offset', 0);

		if ($offset >= $total) {
			return true;
		}

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 25 : 12;
		$start_time = microtime(true);

		$batch_size = 500;

		$zip = $this->get_archive();
		$processed_count = 0;

		while ($offset < $total) {
			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}

			$batch = array_slice($all_entries, $offset, $batch_size);
			if (empty($batch)) {
				break;
			}

			$safe_batch = array();
			foreach ($batch as $entry) {
				$clean = \Snapshoter\Core\PathSafety::normalize_zip_entry((string) $entry);
				if ($clean === null) {
					continue;
				}
				if (\Snapshoter\Core\PathSafety::resolve_under_root($this->extract_path, $clean) === null) {
					$this->log(sprintf('Skipping unsafe extract entry: %s', esc_html((string) $entry)));
					continue;
				}
				$safe_batch[] = $clean;
			}
			if (empty($safe_batch)) {
				$offset += count($batch);
				$processed_count += count($batch);
				continue;
			}
			$batch = $safe_batch;

			foreach ($batch as $entry) {
				$entry_dir = dirname($this->extract_path . '/' . $entry);
				if (!is_dir($entry_dir)) {
					@mkdir($entry_dir, 0755, true);
				}
			}

			$res = $zip->extractTo($this->extract_path, $batch);
			if (!$res) {
				foreach ($batch as $entry) {
					$dest = $this->extract_path . '/' . $entry;
					wp_mkdir_p(dirname($dest), 0755, true);
					$fp = $zip->getStream($entry);
					if ($fp) {
						$out = @fopen($dest, 'wb');
						if ($out) {
							while (!feof($fp)) {
								fwrite($out, fread($fp, 65536));
							}
							fclose($out);
						}
						fclose($fp);
					}
				}
			}

			$count = count($batch);
			$offset += $count;
			$processed_count += $count;
		}

		$zip->close();

		$this->update(
			array(
				'extract_offset' => $offset,
				'extracted' => (int) $this->get('extracted', 0) + $processed_count,
			)
		);

		return ($offset >= $total);
	}

	private function suppress_wordpress_db_checks()
	{

		if ($this->db_checks_suppressed) {
			return;
		}

		if (!defined('WP_IMPORTING')) {
			define('WP_IMPORTING', true);
		}

		if (!has_filter('wp_check_database_version', '__return_true')) {
			add_filter('wp_check_database_version', '__return_true', 999);
		}

		if (!has_filter('pre_option_is_blog_installed', '__return_true')) {
			add_filter('pre_option_is_blog_installed', '__return_true', 999);
		}

		if (!has_filter('wp_db_version')) {
			add_filter('wp_db_version', function () {

				return 57155;
			}, 999);
		}

		$this->log('WordPress database integrity checks suppressed during import.');
		$this->db_checks_suppressed = true;
	}

	private function disable_site()
	{
		global $wpdb;

		$this->log('=== START: Preparing to disable site ===');

		$this->log('Suppressing WordPress database integrity checks...');
		$this->suppress_wordpress_db_checks();
		$this->log('WordPress database checks suppressed.');

		$this->log('Querying database for active_plugins option...');
		$active_plugins_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'active_plugins'
			)
		);

		$this->log(sprintf(
			'Active plugins query result: %s (length: %d)',
			$active_plugins_serialized ? 'found' : 'not found',
			$active_plugins_serialized ? strlen($active_plugins_serialized) : 0
		));

		if ($wpdb->last_error) {
			$this->log(sprintf('Database error while fetching active_plugins: %s', $wpdb->last_error));
		}

		$active = array();
		if ($active_plugins_serialized) {
			$this->log('Unserializing active plugins data...');
			$active = maybe_unserialize($active_plugins_serialized);
			if (!is_array($active)) {
				$this->log(sprintf('Warning: active_plugins is not an array. Type: %s', gettype($active)));
				$active = array();
			}
		}

		$this->log(sprintf('Found %d active plugins.', count($active)));
		if (!empty($active)) {
			$this->log(sprintf('Active plugins list: %s', implode(', ', array_slice($active, 0, 10)) . (count($active) > 10 ? '...' : '')));
		}

		$plugin_basename = plugin_basename(SNAPSHOTER_FILE);
		$this->log(sprintf('Snapshoter Backup plugin basename: %s', $plugin_basename));

		$this->log('Serializing plugins list for backup...');
		$active_serialized = maybe_serialize($active);
		$this->log(sprintf('Serialized plugins data length: %d bytes', strlen($active_serialized)));

		$this->log('Saving plugins backup to database (SNAPSHOTER_prev_plugins)...');
		$result = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			VALUES (%s, %s, 'no')
			ON DUPLICATE KEY UPDATE option_value = %s",
			'SNAPSHOTER_prev_plugins',
			$active_serialized,
			$active_serialized
		));

			$this->log(sprintf('Successfully saved %d plugins for restoration. Rows affected: %d', count($active), $result));

		$this->log('Deactivating all plugins except Snapshoter Backup using update_option()...');
		$new_plugins = array($plugin_basename);
		update_option('active_plugins', $new_plugins);
		$this->log('Successfully deactivated all plugins except Snapshoter Backup.');

		$this->log('Querying database for template option...');
		$template = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'template'
			)
		);

		if ($wpdb->last_error) {
			$this->log(sprintf('Database error while fetching template: %s', $wpdb->last_error));
		}

		$stylesheet = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'stylesheet'
			)
		);

		if ($wpdb->last_error) {
			$this->log(sprintf('Database error while fetching stylesheet: %s', $wpdb->last_error));
		}

		if ($template) {
			$result = $wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = %s",
				'SNAPSHOTER_prev_template',
				$template,
				$template
			));

		}

		if ($stylesheet) {
			$result = $wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = %s",
				'SNAPSHOTER_prev_stylesheet',
				$stylesheet,
				$stylesheet
			));

		}

		$this->log('Backing up widgets, theme_mods, and site settings for rollback...');

		if ($stylesheet) {
			$theme_mods_serialized = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'theme_mods_' . $stylesheet
				)
			);

			if ($theme_mods_serialized) {
				$result = $wpdb->query($wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					VALUES (%s, %s, 'no')
					ON DUPLICATE KEY UPDATE option_value = %s",
					'SNAPSHOTER_prev_theme_mods',
					$theme_mods_serialized,
					$theme_mods_serialized
				));

					$this->log('Backed up theme_mods for rollback.');

			}
		}

		$widget_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
				$wpdb->esc_like('widget_') . '%',
				'sidebars_widgets'
			)
		);

		if (!empty($widget_options)) {
			$widgets_backup = array();
			foreach ($widget_options as $option) {
				$widgets_backup[$option->option_name] = maybe_unserialize($option->option_value);
			}

			$widgets_serialized = maybe_serialize($widgets_backup);
			$result = $wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = %s",
				'SNAPSHOTER_prev_widgets',
				$widgets_serialized,
				$widgets_serialized
			));

				$this->log(sprintf('Backed up %d widget options for rollback.', count($widgets_backup)));

		}

		$site_settings_to_backup = array(
			'blogname',
			'blogdescription',
			'custom_logo',
			'site_icon',
		);

		$site_settings_backup = array();
		foreach ($site_settings_to_backup as $option_name) {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$option_name
				)
			);
			if ($value !== null) {
				$site_settings_backup[$option_name] = maybe_unserialize($value);
			}
		}

		if (!empty($site_settings_backup)) {
			$site_settings_serialized = maybe_serialize($site_settings_backup);
			$result = $wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = %s",
				'SNAPSHOTER_prev_site_settings',
				$site_settings_serialized,
				$site_settings_serialized
			));

				$this->log(sprintf('Backed up %d site settings for rollback.', count($site_settings_backup)));

		}

		$safe_theme = \Snapshoter\Import\RestoreSafeMode::pick_safe_theme();
		update_option('template', $safe_theme);
		update_option('stylesheet', $safe_theme);
		$this->update(array('safe_theme' => $safe_theme));
		$this->log(sprintf('Switched to safe theme "%s" for restore freeze.', $safe_theme));

		wp_cache_flush();

		$this->enforce_target_site_urls('safe-mode');
		$this->clean_permalink_structure_immediately('safe-mode', true);

		$this->log('=== END: Site put in safe mode. All plugins deactivated except Snapshoter Backup ===');
	}

	private function enforce_restore_freeze($context = '')
	{
		global $wpdb;

		$plugin_basename = defined('SNAPSHOTER_FILE')
			? plugin_basename(SNAPSHOTER_FILE)
			: 'snapshoter/snapshoter.php';

		update_option('active_plugins', array($plugin_basename));
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('active_plugins', 'options');
		}

		$safe_theme = (string) $this->get('safe_theme', '');
		if ($safe_theme === '' && class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			$safe_theme = \Snapshoter\Import\RestoreSafeMode::pick_safe_theme();
			if ($safe_theme !== '') {
				$this->update(array('safe_theme' => $safe_theme));
			}
		}
		if ($safe_theme !== '') {
			update_option('template', $safe_theme);
			update_option('stylesheet', $safe_theme);
			if (function_exists('wp_cache_delete')) {
				wp_cache_delete('template', 'options');
				wp_cache_delete('stylesheet', 'options');
			}
		}

		if (isset($wpdb) && $wpdb instanceof \wpdb) {
			$serialized = maybe_serialize(array($plugin_basename));
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					VALUES ('active_plugins', %s, 'yes')
					ON DUPLICATE KEY UPDATE option_value = %s",
					$serialized,
					$serialized
				)
			);
			if ($safe_theme !== '') {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name IN ('template','stylesheet')",
						$safe_theme
					)
				);
			}
		}

		if ($context !== '') {
			$this->log(sprintf('Restore freeze enforced (%s): plugins=[%s] theme=%s', $context, $plugin_basename, $safe_theme !== '' ? $safe_theme : '(none)'));
		}
	}

	private function should_skip_frozen_path($relative)
	{
		$safe_theme = (string) $this->get('safe_theme', '');
		return \Snapshoter\Import\RestoreSafeMode::should_skip_restore_path($relative, $safe_theme);
	}

	private function should_defer_core_path($relative)
	{
		return \Snapshoter\Import\ArchiveIndex::is_wordpress_core_path($relative);
	}

	private function note_deferred_core($count = 1)
	{
		$count = max(0, (int) $count);
		if ($count <= 0) {
			return;
		}
		$prev = (int) $this->get('deferred_core_count', 0);
		$update = array(
			'deferred_core_pending' => 1,
			'deferred_core_count'   => $prev + $count,
		);
		if (!$this->get('deferred_core_logged')) {
			$update['deferred_core_logged'] = 1;
			$this->log('Deferring WordPress core (wp-admin / wp-includes / bootstrap PHP) until finalize so the site stays bootable during restore.');
		}
		$this->update($update);
	}

	private function format_restore_mem()
	{
		$peak = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0;
		$now = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
		$limit = (string) @ini_get('memory_limit');
		return sprintf(
			'mem=%s peak=%s limit=%s',
			$now > 0 ? size_format($now) : '?',
			$peak > 0 ? size_format($peak) : '?',
			$limit !== '' ? $limit : '?'
		);
	}

	private function log_restore_debug($phase, array $fields = array())
	{
		$phase = (string) $phase;

		$overall = $this->calculate_progress();
		$engine = (string) $this->get('engine', 'legacy');
		$safe_theme = (string) $this->get('safe_theme', '');
		$base = array(
			'phase'       => $phase,
			'overall_pct' => $overall,
			'engine'      => $engine,
			'step'        => (string) $this->get('step', ''),
			'safe_theme'  => $safe_theme !== '' ? $safe_theme : '(none)',
			'mem'         => $this->format_restore_mem(),
		);
		$merged = array_merge($base, $fields);

		$bits = array();
		foreach ($merged as $k => $v) {
			if ($v === '' || $v === null) {
				continue;
			}
			if (is_bool($v)) {
				$v = $v ? 'yes' : 'no';
			}
			$bits[] = $k . '=' . (is_scalar($v) ? (string) $v : wp_json_encode($v));
		}

		$line = '[RESTORE-DEBUG] ' . implode(' | ', $bits);
		$this->log($line);
		$this->update(
			array(
				'last_restore_debug' => $merged,
				'progress'           => $overall,
			)
		);
	}

	private function import_database_chunk()
	{
		global $wpdb;

		$this->suppress_wordpress_db_checks();

		$this->snapshoter_ensure_db_connection();
		$this->enforce_target_site_urls('before-database-import');
		$this->clean_permalink_structure_immediately('pre-database-chunk', false);

		@$wpdb->query("SET SESSION sql_mode = ''");
		@$wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
		@$wpdb->query("SET UNIQUE_CHECKS = 0");
		@$wpdb->query("SET AUTOCOMMIT = 1");

		$rewrite_collations = $this->snapshoter_target_needs_collation_rewrite();

		$possible_paths = array(
			trailingslashit($this->extract_path) . 'database/database.sql',
			trailingslashit($this->extract_path) . 'database.sql',
			trailingslashit($this->extract_path) . 'files/database/database.sql',
			trailingslashit($this->extract_path) . 'files/database.sql',
		);

		$path = null;
		foreach ($possible_paths as $candidate) {
			if (file_exists($candidate) && filesize($candidate) > 0) {
				$path = $candidate;
				break;
			}
		}

		if (null === $path) {

			$target_dest = trailingslashit($this->extract_path) . 'database/database.sql';
			try {
				$archive = $this->get_archive();
				if ($archive) {
					wp_mkdir_p(dirname($target_dest), 0755, true);
					$db_names = array('database/database.sql', 'database.sql', 'files/database/database.sql', 'files/database.sql');
					foreach ($db_names as $db_name) {
						if ($archive->locateName($db_name) !== false) {
							$fp = $archive->getStream($db_name);
							if ($fp) {
								$out_fp = @fopen($target_dest, 'wb');
								if ($out_fp) {
									while (!feof($fp)) {
										$chunk = fread($fp, 65536);
										if ($chunk === false) {
											break;
										}
										fwrite($out_fp, $chunk);
									}
									fclose($out_fp);
									fclose($fp);
									if (file_exists($target_dest) && filesize($target_dest) > 0) {
										$path = $target_dest;
										$this->log('Extracted database dump directly from archive on demand.');
										break;
									}
								} else {
									fclose($fp);
								}
							}
						}
					}
					$archive->close();
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Direct database extraction fallback warning: %s', $e->getMessage()));
			}
		}

		if (null === $path || !file_exists($path)) {

			$manifest = $this->get('manifest', array());
			$scope = isset($manifest['scope']) ? (string) $manifest['scope'] : 'full';
			if ($scope === 'files') {
				$this->log('Files-only archive - skipping database import.');
				return true;
			}
			throw new \RuntimeException(esc_html__('Database dump missing in archive.', 'snapshoter'));
		}

		$manifest = $this->get('manifest', array());
		$source_prefix = isset($manifest['db_prefix']) ? $manifest['db_prefix'] : 'wp_';
		$target_prefix = $wpdb->prefix;

		$url_replacements = $this->build_snapshoter_url_replacements();

		if ($source_prefix !== $target_prefix) {
			$this->log(sprintf(
				'Table prefix mismatch detected. Source: %s, Target: %s. Replacing prefixes in SQL.',
				$source_prefix,
				$target_prefix
			));
		}

		$atomic_tables = array($wpdb->prefix . 'options');

		$handle = fopen($path, 'rb');

		$offset = (int) $this->get('sql_offset', 0);
		$buffer = $this->load_sql_buffer();

		$total_queries_executed = (int) $this->get('total_queries_executed', 0);
		$total_queries_failed = (int) $this->get('total_queries_failed', 0);

		$file_size = filesize($path);

		if ($offset > 0) {
			$progress_pct = $file_size > 0 ? (int) (($offset / $file_size) * 100) : 0;
			$this->log(sprintf(
				'Resuming database import. Progress: %d/%d bytes (%d%%), Total queries executed so far: %d',
				$offset,
				$file_size,
				$progress_pct,
				$total_queries_executed
			));
		} else {
			$this->log(sprintf(
				'Starting database import. File size: %d bytes',
				$file_size
			));
		}

		fseek($handle, $offset);

		$bytes = 0;
		$limit = \Snapshoter\Core\JobController::max_chunk_size();
		$queries_executed = 0;
		$queries_failed = 0;

		$time_limit = $this->restore_soft_time_limit(25, 12);
		$start_time = microtime(true);

		$limit = \Snapshoter\Core\JobController::max_chunk_size() * 2;

		$current_capacity = (int) $this->get('row_chunk', 800);
		if ($current_capacity < 500) {
			$limit = (int) ($limit * ($current_capacity / 500));
			$limit = max(500 * 1024, $limit);
			$this->log(sprintf('Throttling active: Reduced DB import chunk size to %d bytes', $limit));
		}

		while (!feof($handle) && $bytes < $limit) {

			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}

			$line = fgets($handle);

			$bytes += strlen($line);
			$trim = trim($line);

			if ('' === $trim || strpos($trim, '--') === 0 || strpos($trim, '/*') === 0) {
				continue;
			}

			$buffer .= $line;

			if (substr(rtrim($line), -1) === ';') {
				$statement = trim($buffer);
				if ($statement) {

					if ($source_prefix !== $target_prefix) {

						$statement = str_replace(
							'`' . $source_prefix,
							'`' . $target_prefix,
							$statement
						);

						$statement = preg_replace(
							'/(?<![`\w])' . preg_quote($source_prefix, '/') . '(?=\w)/',
							$target_prefix,
							$statement
						);
					}

					$statement = $this->revert_hash_placeholders($statement);

					$is_atomic = false;
					foreach ($atomic_tables as $atomic_table) {
						if (stripos($statement, $atomic_table) !== false) {
							$is_atomic = true;
							break;
						}
					}

					$suppress_errors = (
						stripos($statement, 'DROP TABLE') === 0 ||
						stripos($statement, 'CREATE TABLE') === 0 ||
						$is_atomic
					);

					if ($is_atomic && stripos($statement, 'INSERT INTO') === 0) {
						$statement = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $statement);
					}

					if ($suppress_errors) {
						$wpdb->suppress_errors(true);
					}

					$result = $wpdb->query($statement);

					if ($suppress_errors) {
 						$wpdb->suppress_errors(false);
					}

						$queries_executed++;

				}
				$buffer = '';
			}
		}

		$offset = ftell($handle);
		$done = feof($handle);

		$total_queries_executed += $queries_executed;
		$total_queries_failed += $queries_failed;

		fclose($handle);

		$progress_pct = $file_size > 0 ? (int) (($offset / $file_size) * 100) : 0;
		$this->log(sprintf(
			'Database import chunk complete. Progress: %d%% (%d/%d bytes). This chunk: %d executed, %d failed. Total: %d executed, %d failed',
			$progress_pct,
			$offset,
			$file_size,
			$queries_executed,
			$queries_failed,
			$total_queries_executed,
			$total_queries_failed
		));

		$this->update(
			array(
				'sql_offset' => $offset,
				'total_queries_executed' => $total_queries_executed,
				'total_queries_failed' => $total_queries_failed,
			)
		);
		$this->persist_sql_buffer($buffer);

		$this->enforce_target_site_urls('after-database-chunk');
		$this->clean_permalink_structure_immediately('post-database-chunk', true);

		$this->enforce_restore_freeze('post-database-chunk');

		if ($done && $offset >= $file_size) {
			$this->log(sprintf(
				'Database import complete. Total: %d queries executed, %d queries failed.',
				$total_queries_executed,
				$total_queries_failed
			));

			if (isset($manifest['wp_version'])) {
				$this->update_database_version($manifest['wp_version']);
			}

			$this->restore_blogname_immediately();

			$this->restore_blogname_immediately();

			$this->enforce_restore_freeze('database-import-complete');

			return true;
		}

		$this->adjust_capacity(microtime(true) - $start_time);

		return false;
	}

	private function restore_blogname_immediately()
	{
		$manifest = $this->get('manifest', array());
		if (!isset($manifest['site_settings']['blogname'])) {
			return;
		}

		$blogname = $manifest['site_settings']['blogname'];
		if (!is_string($blogname) || empty($blogname)) {
			return;
		}

		$blogname = str_replace("\0", '', $blogname);
		$blogname = mb_convert_encoding($blogname, 'UTF-8', 'UTF-8');
		$blogname = sanitize_text_field($blogname);

		if (!empty($blogname)) {
			update_option('blogname', $blogname);
			wp_cache_delete('blogname', 'options');
			$this->log(sprintf('CRITICAL: Restored blogname immediately after database import: %s', $blogname));
		}
	}

	private function update_database_version($wp_version)
	{
		global $wpdb;

		$db_version = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'db_version'");

		if (!$db_version) {

			$version_parts = explode('.', $wp_version);
			$major = (int) $version_parts[0];
			$minor = (int) (isset($version_parts[1]) ? $version_parts[1] : 0);

			$db_version = 57155;

			$wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES ('db_version', %s, 'yes')
				ON DUPLICATE KEY UPDATE option_value = %s",
				$db_version,
				$db_version
			));

			$this->log(sprintf('Database version set to %s for WordPress %s', $db_version, $wp_version));
		} else {
			$this->log(sprintf('Database version already set to %s for WordPress %s', $db_version, $wp_version));
		}
	}

	private function restore_files_chunk()
	{

		$this->apply_throttling();

		$queue_path = $this->store->job_dir($this->job_id) . '/restore_queue.json';

		if (!file_exists($queue_path)) {
			$this->log('Building restore queue...');

			$queue = $this->build_restore_queue_from_extraction_queue();
			if (empty($queue)) {
				$this->log('Extraction queue unavailable/empty - falling back to disk scan.');
				$queue = $this->build_restore_queue();
			}
			$queue = $this->prioritize_restore_queue($queue);
			file_put_contents($queue_path, json_encode($queue));
			$this->update(array('restore_offset' => 0, 'total_restore' => count($queue)));
			$this->log(sprintf('Restore queue saved. Found %d items (content-first order).', count($queue)));
			return false;
		}

		$all_files = json_decode(file_get_contents($queue_path), true);
		if (!is_array($all_files)) {
			return true;
		}

		$total = count($all_files);
		$offset = (int) $this->get('restore_offset', 0);

		if ($offset >= $total) {
			return true;
		}

		$files_pct = $total > 0 ? round(($offset / $total) * 100, 1) : 0;
		$this->log_restore_debug(
			'files_tick_start',
			array(
				'files_pct' => $files_pct,
				'cursor'    => $offset . '/' . $total,
				'last'      => (string) $this->get('last_restored_path', ''),
			)
		);

		$time_limit = $this->restore_soft_time_limit(25, 12);
		$max_bytes_per_chunk = $this->get_restore_bytes_budget();
		$current_bytes = 0;
		$start_time = microtime(true);
		$processed_count = 0;
		$hit_byte_cap = false;
		$hit_time_cap = false;

		$batch_size = (int) $this->get(
			'file_batch_size',
			\Snapshoter\Core\TickCapacity::initial_restore_batch(
				\Snapshoter\Core\Environment::can_extend_time()
			)
		);
		$batch_size = max(
			\Snapshoter\Core\TickCapacity::RESTORE_BATCH_FLOOR,
			min(\Snapshoter\Core\TickCapacity::RESTORE_BATCH_CEILING, $batch_size)
		);

		$batch = array_slice($all_files, $offset, $batch_size);
		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));
		$skipped_frozen = 0;
		$last_relative = (string) $this->get('last_restored_path', '');

		foreach ($batch as $index => $relative) {

			if ((microtime(true) - $start_time) > $time_limit) {
				$hit_time_cap = true;
				break;
			}

			if ($current_bytes > $max_bytes_per_chunk) {
				$hit_byte_cap = true;
				break;
			}

			if ($index % 100 === 0 && function_exists('set_time_limit')) {
				@set_time_limit(30);
			}

			if ($relative === 'wp-config.php' || $relative === '.htaccess') {
				$processed_count++;
				continue;
			}

			if ($this->should_skip_frozen_path($relative)) {
				$skipped_frozen++;
				$processed_count++;
				continue;
			}

			if ($this->should_defer_core_path($relative)) {
				$this->note_deferred_core(1);
				$processed_count++;
				continue;
			}

			if (in_array($relative, array('wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/db.php'), true)) {
				$processed_count++;
				continue;
			}

			if (
				$relative === 'database' ||
				strpos($relative, 'database/') === 0 ||
				$relative === 'database.sql' ||
				$relative === 'manifest.json' ||
				$relative === 'extraction_queue.json' ||
				$relative === 'restore_queue.json' ||
				strpos($relative, 'wp-content/plugins/snapshoter/') === 0
			) {
				$processed_count++;
				continue;
			}

			$source_files = trailingslashit($this->extract_path) . 'files/' . $relative;
			$source_root = trailingslashit($this->extract_path) . $relative;
			$source = file_exists($source_files) ? $source_files : $source_root;
			$target = $this->safe_restore_target($relative);
			if ($target === null) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_ZIP_REJECTED);
				$processed_count++;
				continue;
			}

			if (!file_exists($source)) {

				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_NOT_EXTRACTED);
				$processed_count++;
				continue;
			}

			if (is_dir($source)) {
				if (!is_dir($target)) {
					@mkdir($target, 0755, true);
				}
				$processed_count++;
				continue;
			}

			$target_dir = dirname($target);
			if (!is_dir($target_dir)) {
				@mkdir($target_dir, 0755, true);
			}

			$file_size = @filesize($source);
			if (@copy($source, $target)) {
				$current_bytes += $file_size;
				$last_relative = $relative;
				wp_delete_file($source);
			} else {

				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_COPY_FAILED);
			}
			$processed_count++;
		}

		$offset += $processed_count;

		$this->update(
			array(
				'restore_offset'     => $offset,
				'last_restored_path' => $last_relative,
			)
		);

		$elapsed = microtime(true) - $start_time;
		$this->adjust_capacity($elapsed);
		$this->adjust_restore_capacity($elapsed, $time_limit, $current_bytes, $hit_byte_cap, $hit_time_cap);

		$stop = 'batch';
		if ($hit_time_cap) {
			$stop = 'time_budget';
		} elseif ($hit_byte_cap) {
			$stop = 'byte_budget';
		} elseif ($offset >= $total) {
			$stop = 'done';
		}
		$files_pct_end = $total > 0 ? round(($offset / $total) * 100, 1) : 0;
		$this->log_restore_debug(
			'files_tick_end',
			array(
				'files_pct'      => $files_pct_end,
				'cursor'         => $offset . '/' . $total,
				'tick_bytes'     => size_format($current_bytes),
				'tick_sec'       => round($elapsed, 2),
				'stop'           => $stop,
				'last'           => $last_relative,
				'skipped_frozen' => $skipped_frozen,
				'budget'         => size_format($max_bytes_per_chunk) . '/' . $time_limit . 's',
			)
		);

		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::quarantine_foreign_mu_plugins();
		}

		return ($offset >= $total);
	}

	private function restore_files_from_zip_chunk()
	{
		$this->apply_throttling();

		$index = new \Snapshoter\Import\ArchiveIndex($this->store->job_dir($this->job_id));
		$total = $index->count();
		if ($total <= 0) {
			$this->log('ArchiveIndex empty - nothing to restore from zip.');
			return true;
		}

		$cursor = (int) $this->get('entry_cursor', 0);
		$byte_offset = (int) $this->get('entry_byte_offset', 0);
		$bytes_restored = (int) $this->get('bytes_restored', 0);

		if ($cursor >= $total) {
			return true;
		}

		$files_pct = $total > 0 ? round(($cursor / $total) * 100, 1) : 0;
		$this->log_restore_debug(
			'files_tick_start',
			array(
				'files_pct'   => $files_pct,
				'cursor'      => $cursor . '/' . $total,
				'byte_offset' => $byte_offset,
				'restored'    => size_format($bytes_restored),
				'last'        => (string) $this->get('last_restored_path', ''),
			)
		);

		$time_limit = $this->restore_soft_time_limit(25, 12);
		$max_bytes_per_chunk = $this->get_restore_bytes_budget();
		$current_bytes = 0;
		$start_time = microtime(true);
		$hit_byte_cap = false;
		$hit_time_cap = false;
		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));

		$zip = $this->get_archive();
		$batch_limit = $this->restore_drain_nested ? 8000 : 400;
		$processed = 0;
		$skipped_frozen = 0;
		$skipped_other = 0;
		$skipped_deferred_core = 0;
		$last_relative = (string) $this->get('last_restored_path', '');
		$largest_this_tick = 0;
		$largest_path = '';

		while ($cursor < $total) {
			if ((microtime(true) - $start_time) > $time_limit) {
				$hit_time_cap = true;
				break;
			}
			if ($current_bytes >= $max_bytes_per_chunk) {
				$hit_byte_cap = true;
				break;
			}

			$slice = $index->slice($cursor, 1);
			if (empty($slice)) {
				break;
			}
			$entry = $slice[0];
			$zip_name = (string) $entry['name'];
			$entry_size = (int) $entry['size'];
			if ($byte_offset === 0 && $entry_size > 0) {
				$this->update(array('entry_cursor_size' => $entry_size));
			}
			$relative = \Snapshoter\Import\ArchiveIndex::site_relative_from_entry($zip_name);

			if ($relative === null) {
				$skipped_other++;
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}

			if ($this->should_skip_frozen_path($relative)) {
				$skipped_frozen++;
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}

			if ($this->should_defer_core_path($relative)) {
				$skipped_deferred_core++;
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}

			if ($entry_size >= 8 * 1024 * 1024 && $byte_offset === 0) {
				$this->log(sprintf(
					'[RESTORE-DEBUG] large_file_start path=%s size=%s cursor=%d/%d',
					$relative,
					size_format($entry_size),
					$cursor,
					$total
				));
			}

			$target = $this->safe_restore_target($relative);
			if ($target === null) {
				$this->log(sprintf('Skipping unsafe restore path: %s', esc_html($relative)));
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}
			$target_dir = dirname($target);
			if (!is_dir($target_dir)) {
				@mkdir($target_dir, 0755, true);
			}

			$part_path = $target . '.snapshoter.part';
			$fp = $zip->getStream($zip_name);
			if (!$fp) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_NOT_EXTRACTED);
				$skipped_other++;
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}

			$skip = $byte_offset;
			while ($skip > 0) {
				$chunk = fread($fp, min(65536, $skip));

				$skip -= strlen($chunk);
			}

			$mode = ($byte_offset > 0 && file_exists($part_path)) ? 'ab' : 'wb';
			$out = @fopen($part_path, $mode);
			if (!$out) {
				fclose($fp);
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_COPY_FAILED);
				$skipped_other++;
				$cursor++;
				$byte_offset = 0;
				$processed++;
				continue;
			}

			$entry_done = false;
			while (!feof($fp)) {
				if ((microtime(true) - $start_time) > $time_limit) {
					$hit_time_cap = true;
					break;
				}
				if ($current_bytes >= $max_bytes_per_chunk) {
					$hit_byte_cap = true;
					break;
				}

				$buf = fread($fp, 65536);

				fwrite($out, $buf);
				$n = strlen($buf);
				$byte_offset += $n;
				$current_bytes += $n;
				$bytes_restored += $n;
			}

			if (feof($fp)) {
				$entry_done = true;
			}

			fclose($out);
			fclose($fp);

			$last_relative = $relative;
			if ($entry_size > $largest_this_tick) {
				$largest_this_tick = $entry_size;
				$largest_path = $relative;
			}

			if ($entry_done) {
				wp_delete_file($target);
				if (!@rename($part_path, $target)) {
					@copy($part_path, $target);
					wp_delete_file($part_path);
				}
				$cursor++;
				$byte_offset = 0;
				$processed++;
			} else {

				break;
			}

			if ($processed >= $batch_limit) {
				break;
			}
		}

		$zip->close();

		if ($skipped_deferred_core > 0) {
			$this->note_deferred_core($skipped_deferred_core);
		}

		$this->update(
			array(
				'entry_cursor'       => $cursor,
				'entry_byte_offset'  => $byte_offset,
				'bytes_restored'     => $bytes_restored,
				'restore_offset'     => $cursor,
				'total_restore'      => $total,
				'last_restored_path' => $last_relative,
				'message'            => sprintf(

					// translators: %1$s: percent complete; %2$d: files done; %3$d: total files.
					__('Restoring site files... %1$s%% (%2$d / %3$d)', 'snapshoter'),
					(string) round($this->restore_files_sub_progress(), 1),
					min($cursor, $total),
					$total
				),
			)
		);

		$elapsed = microtime(true) - $start_time;
		$this->adjust_capacity($elapsed);
		$this->adjust_restore_capacity($elapsed, $time_limit, $current_bytes, $hit_byte_cap, $hit_time_cap);

		$stop = 'batch';
		if ($hit_time_cap) {
			$stop = 'time_budget';
		} elseif ($hit_byte_cap) {
			$stop = 'byte_budget';
		} elseif ($cursor >= $total && $byte_offset === 0) {
			$stop = 'done';
		} elseif ($byte_offset > 0) {
			$stop = 'mid_file';
		}

		$files_pct_end = $total > 0 ? round(($cursor / $total) * 100, 1) : 0;
		$this->log_restore_debug(
			'files_tick_end',
			array(
				'files_pct'       => $files_pct_end,
				'cursor'          => $cursor . '/' . $total,
				'tick_bytes'      => size_format($current_bytes),
				'tick_sec'        => round($elapsed, 2),
				'restored'        => size_format($bytes_restored),
				'stop'            => $stop,
				'last'            => $last_relative,
				'mid_file_byte'   => $byte_offset,
				'skipped_frozen'  => $skipped_frozen,
				'skipped_other'   => $skipped_other,
				'skipped_core'    => $skipped_deferred_core,
				'largest'         => $largest_path !== '' ? ($largest_path . ' (' . size_format($largest_this_tick) . ')') : '',
				'budget'          => size_format($max_bytes_per_chunk) . '/' . $time_limit . 's',
			)
		);

		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			$q = \Snapshoter\Import\RestoreSafeMode::quarantine_foreign_mu_plugins();
			if ($q > 0) {
				$this->log(sprintf('[RESTORE-DEBUG] re-quarantined %d mu-plugin(s) after file tick', $q));
			}
		}

		return ($cursor >= $total && $byte_offset === 0);
	}

	private function discover_slugs_from_extraction_queue()
	{
		$files = $this->build_restore_queue_from_extraction_queue();
		if (empty($files)) {
			return;
		}

		unset($files);
	}

	private function clear_wp_core_maintenance()
	{
		$file = trailingslashit(ABSPATH) . '.maintenance';
		if (is_string($file) && $file !== '' && file_exists($file)) {
			wp_delete_file($file);
		}
	}

	private function pause_wp_auto_updates($minutes = 15)
	{
		if (function_exists('set_site_transient')) {
			set_site_transient('auto_updater.lock', time(), max(60, (int) $minutes * MINUTE_IN_SECONDS));
		}
	}

	private function clear_restore_safe_mode($restore_mu = null)
	{
		if ($restore_mu === null) {
			$restore_mu = (bool) $this->pending_safe_mode_restore_mu;
		}
		$this->clear_wp_core_maintenance();
		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::clear((bool) $restore_mu);
			$stats = \Snapshoter\Import\RestoreSafeMode::last_clear_mu_stats();
			$heal = \Snapshoter\Import\RestoreSafeMode::last_heal_result();
			$assert = \Snapshoter\Import\RestoreSafeMode::ensure_restored_theme_active();
			$this->update(array('safe_mode' => 0));
			if (is_array($heal) && !empty($heal['healed'])) {
				$this->log(sprintf(
					'Orphan theme heal: template=%s stylesheet=%s reason=%s',
					(string) ($heal['template'] ?? ''),
					(string) ($heal['stylesheet'] ?? ''),
					(string) ($heal['reason'] ?? '')
				));
			}
			if (is_array($assert) && !empty($assert['applied'])) {
				$this->log(sprintf(
					'Re-asserted archive theme after safe-mode clear: template=%s stylesheet=%s',
					(string) ($assert['template'] ?? ''),
					(string) ($assert['stylesheet'] ?? '')
				));
			}

			delete_option('SNAPSHOTER_prev_template');
			delete_option('SNAPSHOTER_prev_stylesheet');
			delete_option('SNAPSHOTER_restored_template');
			delete_option('SNAPSHOTER_restored_stylesheet');
			$this->log(
				sprintf(
					'Restore safe-mode cleared (restore_mu=%s, mu_restored=%d, mu_kept_quarantined=%d%s).',
					$restore_mu ? 'yes' : 'no',
					(int) $stats['restored'],
					(int) $stats['kept_quarantined'],
					!empty($stats['skipped']) ? ('; skipped=' . implode(',', $stats['skipped'])) : ''
				)
			);
		}
	}

	private function load_sql_buffer()
	{
		$spill = $this->store->job_dir($this->job_id) . '/sql_buffer.spill';
		if (!empty($this->get('sql_buffer_spill')) && file_exists($spill)) {
			$raw = @file_get_contents($spill);
			return is_string($raw) ? $raw : '';
		}
		return (string) $this->get('sql_buffer', '');
	}

	private function persist_sql_buffer($buffer)
	{
		$buffer = (string) $buffer;
		$spill = $this->store->job_dir($this->job_id) . '/sql_buffer.spill';
		$threshold = 2 * 1024 * 1024;

		if ($buffer === '') {
			if (file_exists($spill)) {
				wp_delete_file($spill);
			}
			$this->update(array('sql_buffer' => '', 'sql_buffer_spill' => 0));
			return;
		}

		if (strlen($buffer) >= $threshold) {
			@file_put_contents($spill, $buffer);
			$this->update(array('sql_buffer' => '', 'sql_buffer_spill' => 1));
			return;
		}

		if (file_exists($spill)) {
			wp_delete_file($spill);
		}
		$this->update(array('sql_buffer' => $buffer, 'sql_buffer_spill' => 0));
	}

	private function record_skip($relative, $reason)
	{
		$state = $this->get('restore_skipped_files', null);
		$updated = \Snapshoter\Core\SkipReporter::record($state, $relative, $reason);
		$this->update(array('restore_skipped_files' => $updated));
	}

	private function log_skip_summary()
	{
		$state = \Snapshoter\Core\SkipReporter::normalize($this->get('restore_skipped_files', null));
		if ($state['count'] === 0) {
			return;
		}
		$this->log(sprintf(
			'%d file(s) could not be restored (%s). See History → View log for the full list.',
			$state['count'],
			$this->format_skip_reasons($state['reasons'])
		));
		if (!empty($state['samples'])) {
			$sample_paths = array_slice(
				array_map(function ($s) { return $s['path']; }, $state['samples']),
				0,
				10
			);
			$this->log('Examples: ' . implode(', ', $sample_paths));
		}
	}

	private function format_skip_reasons(array $reasons)
	{
		if (empty($reasons)) {
			return 'no breakdown';
		}
		$parts = array();
		foreach ($reasons as $reason => $count) {
			$parts[] = $reason . '=' . (int) $count;
		}
		return implode(', ', $parts);
	}

	private function snapshoter_ensure_db_connection()
	{
		global $wpdb;
		if (!is_object($wpdb)) {
			return;
		}
		if (method_exists($wpdb, 'check_connection')) {

			@$wpdb->check_connection(true);
		}
	}

	private function snapshoter_target_needs_collation_rewrite()
	{
		static $cached;
		if ($cached !== null) {
			return $cached;
		}

		global $wpdb;
		if (!is_object($wpdb)) {
			$cached = false;
			return $cached;
		}

		$server_info = '';
		if (method_exists($wpdb, 'db_server_info')) {
			$server_info = (string) @$wpdb->db_server_info();
		}

		$version = '';
		if (method_exists($wpdb, 'db_version')) {
			$version = (string) @$wpdb->db_version();
		}
		if ($version === '' && $server_info !== '') {
			$version = $server_info;
		}

		if ($version !== '' && version_compare($version, '8.0', '<')) {
			$cached = true;
			$this->log(sprintf(
				'Target MySQL %s is older than 8.0 - will downgrade MySQL 8.0 collations during import.',
				$version
			));
			return $cached;
		}

		$cached = false;
		return $cached;
	}

	private function snapshoter_rewrite_mysql8_collations($statement)
	{
		if (!is_string($statement) || $statement === '') {
			return $statement;
		}


		$result = preg_replace(
			'/\butf8mb4_(?:[a-z0-9]+_)*uca1400_[a-z0-9_]+/i',
			'utf8mb4_unicode_520_ci',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}

		$result = preg_replace(
			'/\butf8mb3_(?:[a-z0-9]+_)*uca1400_[a-z0-9_]+/i',
			'utf8_unicode_ci',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}


		$result = preg_replace(
			'/\bCOLLATE[=\s]+[\'"]?utf8mb3_([a-z0-9_]+)[\'"]?/i',
			'COLLATE utf8_$1',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}

		$result = preg_replace(
			'/\bDEFAULT\s+CHARSET[=\s]+[\'"]?utf8mb3[\'"]?/i',
			'DEFAULT CHARSET=utf8',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}

		$result = preg_replace(
			'/\bCHARACTER\s+SET\s+[\'"]?utf8mb3[\'"]?/i',
			'CHARACTER SET utf8',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}


		$result = preg_replace(
			'/\butf8mb4_(?:[a-z0-9]+_)*0900_[a-z0-9_]+/i',
			'utf8mb4_unicode_520_ci',
			$statement
		);
		if ($result !== null) {
			$statement = $result;
		}


		$result = preg_replace('/\bDEFINER\s*=\s*`?[^`@\s]+`?@`?[^`@\s]+`?/i', '', $statement);
		if ($result !== null) {
			$statement = $result;
		}
		$result = preg_replace('/\bSQL\s+SECURITY\s+DEFINER\b/i', '', $statement);
		if ($result !== null) {
			$statement = $result;
		}


		$result = preg_replace('/\bROW_FORMAT\s*=\s*FIXED\b/i', 'ROW_FORMAT=DYNAMIC', $statement);
		if ($result !== null) {
			$statement = $result;
		}

		return $statement;
	}

	private function revert_hash_placeholders($string)
	{
		if (empty($string) || !is_string($string)) {
			return $string;
		}

		$result = preg_replace('/\{[a-f0-9]{32,}\}/i', '%', $string);

		if (null === $result) {
			$this->log('Warning: Regex failed in revert_hash_placeholders (likely string length). Returning original.');
			return $string;
		}

		return $result;
	}

	private function update_site_urls_immediately()
	{
		global $wpdb;

		$this->log('=== UPDATING SITE URLS IMMEDIATELY AFTER DATABASE IMPORT ===');

		list($target_site_url, $target_home_url) = $this->get_target_site_urls();

		update_option('siteurl', esc_url_raw($target_site_url));
		update_option('home', esc_url_raw($target_home_url));

		$this->log('Successfully updated siteurl and home options using update_option().');

		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('siteurl', 'site-options');
		wp_cache_delete('home', 'site-options');

		$this->remap_user_roles_and_capabilities_if_needed();

		if (class_exists('\\Snapshoter\\Import\\ThemeBuilderHealer')) {
			$flushed = ThemeBuilderHealer::purge_all_builder_caches();
			if (!empty($flushed)) {
				$this->log(sprintf('Purged builder and server caches: %s', implode(', ', $flushed)));
			}
		}

		$this->log('Cleared cached options to ensure new URLs are used.');
		$this->log('=== SITE URLS UPDATED SUCCESSFULLY ===');
	}

	private function remap_user_roles_and_capabilities_if_needed()
	{
		global $wpdb;
		$manifest = $this->get('manifest', array());
		$source_prefix = isset($manifest['db_prefix']) ? $manifest['db_prefix'] : 'wp_';
		$target_prefix = $wpdb->prefix;

		if ($source_prefix === $target_prefix) {
			return;
		}

		$this->log(sprintf('Remapping user roles and usermeta prefix from %s to %s...', $source_prefix, $target_prefix));


		$old_roles_opt = $source_prefix . 'user_roles';
		$new_roles_opt = $target_prefix . 'user_roles';
		$roles_val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $old_roles_opt));
		if ($roles_val !== null) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $new_roles_opt));
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", $new_roles_opt, $old_roles_opt));
			$this->log('Successfully remapped user_roles option.');
		}


		$usermeta_keys = array('capabilities', 'user_level', 'dashboard_quick_press_sort_order', 'user-settings', 'user-settings-time');
		foreach ($usermeta_keys as $key) {
			$old_key = $source_prefix . $key;
			$new_key = $target_prefix . $key;
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s", $new_key, $old_key));
		}
		$this->log('Successfully remapped usermeta capabilities keys.');
	}

	private function clean_permalink_structure_immediately($context = 'post-database-import', $allow_default_fallback = true)
	{
		global $wpdb;

		$this->log(sprintf('Cleaning permalink structure (%s)...', $context));

		$permalink_structure = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'permalink_structure'
			)
		);

		if (empty($permalink_structure)) {
			$this->log('No permalink structure found in database.');
			if ($allow_default_fallback) {
				$this->apply_default_permalink_structure($context);
			}
			return;
		}

		$has_hash = (
			preg_match('/[a-f0-9]{32,}/i', $permalink_structure) ||
			preg_match('/%7B[a-f0-9]{32,}%7D/i', $permalink_structure) ||
			preg_match('/\{[a-f0-9]{32,}\}/i', urldecode($permalink_structure))
		);

		if (!$has_hash) {
			$this->log(sprintf('Permalink structure appears clean: %s', $permalink_structure));
			return;
		}

		$this->log(sprintf('Detected corrupted permalink structure, cleaning: %s', $permalink_structure));

		$cleaned = urldecode($permalink_structure);

		$cleaned = preg_replace('/%7B/i', '', $cleaned);
		$cleaned = preg_replace('/%7D/i', '', $cleaned);

		$cleaned = $this->revert_hash_placeholders($cleaned);

		$cleaned = preg_replace('/(\/[^\/]+)\1+/', '$1', $cleaned);
		$cleaned = preg_replace('/\/+/', '/', $cleaned);
		$cleaned = trim($cleaned, '/');

			$manifest = $this->get('manifest', array());
			if (isset($manifest['permalink_structure']) && !empty($manifest['permalink_structure'])) {
				$manifest_structure = $manifest['permalink_structure'];

				$manifest_cleaned = preg_replace('/[a-f0-9]{32,}/i', '', urldecode($manifest_structure));
				$manifest_cleaned = preg_replace('/%7B[a-f0-9]{32,}%7D/i', '', $manifest_cleaned);
				$manifest_cleaned = preg_replace('/\{[a-f0-9]{32,}\}/i', '', $manifest_cleaned);
				$manifest_cleaned = preg_replace('/[{}%7B%7D]/', '', $manifest_cleaned);
				$manifest_cleaned = preg_replace('/\/+/', '/', $manifest_cleaned);
				$manifest_cleaned = trim($manifest_cleaned, '/');

			} else {
				$this->log('Warning: Could not clean permalink structure and no manifest fallback available.');
			}

		if ($allow_default_fallback) {
			$this->apply_default_permalink_structure($context);
		}
	}

	private function apply_default_permalink_structure($context)
	{
		global $wpdb;

		$default_structure = '/%postname%/';
		$result = $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'permalink_structure'",
			$default_structure
		));

			$this->log(sprintf('ERROR: Failed to apply default permalink structure (%s). DB error: %s', $context, $wpdb->last_error));

	}

	private function restore_site_on_error()
	{
		global $wpdb;

		$this->log('Starting site restoration after import error...');

		$this->suppress_wordpress_db_checks();

		$previous_plugins_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_plugins'
			)
		);

		if ($previous_plugins_serialized) {
			$previous_plugins = maybe_unserialize($previous_plugins_serialized);
			if (is_array($previous_plugins) && !empty($previous_plugins)) {
				$this->log(sprintf('Found %d plugins to restore.', count($previous_plugins)));

				update_option('active_plugins', $previous_plugins);
				$this->log('Plugins restored successfully using update_option().');

				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_plugins'
				));
				$this->log('Cleaned up plugin backup option.');
			}
		} else {
			$this->log('No previous plugins found to restore. Site may have been in safe mode before import started.');
		}

		$template = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_template'
			)
		);
		$stylesheet = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_stylesheet'
			)
		);

		if ($template && $stylesheet) {
			$this->log(sprintf('Restoring theme: template=%s, stylesheet=%s', $template, $stylesheet));

			update_option('template', $template);
			update_option('stylesheet', $stylesheet);
			$this->log('Theme restored successfully using update_option().');
		} else {
			$this->log('No previous theme found to restore.');
		}

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			'SNAPSHOTER_prev_template'
		));
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			'SNAPSHOTER_prev_stylesheet'
		));
		$this->log('Cleaned up theme backup options.');

		$previous_widgets_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_widgets'
			)
		);

		if ($previous_widgets_serialized) {
			$previous_widgets = maybe_unserialize($previous_widgets_serialized);
			if (is_array($previous_widgets) && !empty($previous_widgets)) {
				$this->log(sprintf('Found %d widget options to restore.', count($previous_widgets)));

				$restored_count = 0;
				foreach ($previous_widgets as $widget_option_name => $widget_value) {
					$result = update_option($widget_option_name, $widget_value);
					if ($result) {
						$restored_count++;
					}
				}

				if ($restored_count > 0) {
					$this->log(sprintf('Restored %d widget options.', $restored_count));
				}

				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_widgets'
				));
				$this->log('Cleaned up widget backup option.');
			}
		} else {
			$this->log('No previous widgets found to restore.');
		}

		$previous_theme_mods_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_theme_mods'
			)
		);

		if ($previous_theme_mods_serialized && $stylesheet) {
			$result = $wpdb->query($wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
				$previous_theme_mods_serialized,
				'theme_mods_' . $stylesheet
			));

				$this->log('Warning: Failed to restore theme_mods via direct database access.');

			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_theme_mods'
			));
			$this->log('Cleaned up theme_mods backup option.');
		} else {
			$this->log('No previous theme_mods found to restore.');
		}

		$previous_site_settings_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'SNAPSHOTER_prev_site_settings'
			)
		);

		if ($previous_site_settings_serialized) {
			$previous_site_settings = maybe_unserialize($previous_site_settings_serialized);
			if (is_array($previous_site_settings) && !empty($previous_site_settings)) {
				$this->log(sprintf('Found %d site settings to restore.', count($previous_site_settings)));

				$restored_count = 0;
				foreach ($previous_site_settings as $option_name => $option_value) {
					$result = update_option($option_name, $option_value);
					if ($result) {
						$restored_count++;
					}
				}

				if ($restored_count > 0) {
					$this->log(sprintf('Restored %d site settings (title, logo, etc.).', $restored_count));
				}

				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_site_settings'
				));
				$this->log('Cleaned up site settings backup option.');
			}
		} else {
			$this->log('No previous site settings found to restore.');
		}

		wp_cache_flush();
		$this->log('Cache flushed.');

		$current_plugins_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'active_plugins'
			)
		);
		$current_plugins = maybe_unserialize($current_plugins_serialized);
		$current_template = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'template'
			)
		);
		$current_stylesheet = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'stylesheet'
			)
		);

		$this->log(sprintf(
			'Restoration complete. Current state: %d active plugins, template=%s, stylesheet=%s',
			is_array($current_plugins) ? count($current_plugins) : 0,
			$current_template,
			$current_stylesheet
		));
	}

	private function apply_deferred_core_chunk()
	{
		if ($this->get('engine') === 'direct_v1') {
			return $this->apply_deferred_core_from_zip_chunk();
		}
		return $this->apply_deferred_core_from_extract_chunk();
	}

	private function apply_deferred_core_from_zip_chunk()
	{
		$this->apply_throttling();

		$index = new \Snapshoter\Import\ArchiveIndex($this->store->job_dir($this->job_id));
		$total = $index->count();
		if ($total <= 0) {
			return true;
		}

		$cursor = (int) $this->get('deferred_core_cursor', 0);
		$byte_offset = (int) $this->get('deferred_core_byte_offset', 0);
		if ($cursor >= $total) {
			return true;
		}

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 45 : 20;
		$max_bytes_per_chunk = max($this->get_restore_bytes_budget(), 80 * 1024 * 1024);
		$current_bytes = 0;
		$start_time = microtime(true);
		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));
		$zip = $this->get_archive();
		$applied = 0;
		$last_relative = '';

		while ($cursor < $total) {
			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}
			if ($current_bytes >= $max_bytes_per_chunk) {
				break;
			}

			$slice = $index->slice($cursor, 1);
			if (empty($slice)) {
				break;
			}
			$entry = $slice[0];
			$zip_name = (string) $entry['name'];
			$entry_size = (int) $entry['size'];
			$relative = \Snapshoter\Import\ArchiveIndex::site_relative_from_entry($zip_name);

			if ($relative === null || !$this->should_defer_core_path($relative)) {
				$cursor++;
				$byte_offset = 0;
				continue;
			}

			$target = $this->safe_restore_target($relative);
			if ($target === null) {
				$this->log(sprintf('Skipping unsafe deferred core path: %s', esc_html((string) $relative)));
				$cursor++;
				$byte_offset = 0;
				continue;
			}
			$target_dir = dirname($target);
			if (!is_dir($target_dir)) {
				@mkdir($target_dir, 0755, true);
			}

			$part_path = $target . '.snapshoter.part';
			$fp = $zip->getStream($zip_name);
			if (!$fp) {
				$cursor++;
				$byte_offset = 0;
				continue;
			}

			$skip = $byte_offset;
			while ($skip > 0) {
				$chunk = fread($fp, min(65536, $skip));

				$skip -= strlen($chunk);
			}

			$mode = ($byte_offset > 0 && file_exists($part_path)) ? 'ab' : 'wb';
			$out = @fopen($part_path, $mode);
			if (!$out) {
				fclose($fp);
				$cursor++;
				$byte_offset = 0;
				continue;
			}

			$entry_done = false;
			while (!feof($fp)) {
				if ((microtime(true) - $start_time) > $time_limit || $current_bytes >= $max_bytes_per_chunk) {
					break;
				}
				$buf = fread($fp, 65536);

				fwrite($out, $buf);
				$len = strlen($buf);
				$byte_offset += $len;
				$current_bytes += $len;
			}

			if (feof($fp)) {
				$entry_done = true;
			}

			fclose($out);
			fclose($fp);
			$last_relative = $relative;

			if ($entry_done) {
				wp_delete_file($target);
				if (!@rename($part_path, $target)) {
					@copy($part_path, $target);
					wp_delete_file($part_path);
				}
				$cursor++;
				$byte_offset = 0;
				$applied++;
			} else {
				break;
			}
		}

		$zip->close();

		$core_pct = $total > 0 ? round(min(100, max(0, ($cursor / $total) * 100)), 1) : 0.0;
		$this->state['deferred_core_cursor'] = $cursor;
		$this->state['deferred_core_byte_offset'] = $byte_offset;
		$progress = $this->calculate_progress();
		$this->update(
			array(
				'deferred_core_cursor'      => $cursor,
				'deferred_core_byte_offset' => $byte_offset,
				'last_restored_path'        => $last_relative !== '' ? $last_relative : (string) $this->get('last_restored_path', ''),
				'message'                   => sprintf(
					/* translators: 1: percent complete, 2: files processed, 3: total files */
					__('Applying WordPress core (wp-admin / wp-includes)... %1$s%% (%2$d / %3$d). Keep this tab open - the site stays in maintenance until this finishes.', 'snapshoter'),
					(string) $core_pct,
					min($cursor, $total),
					$total
				),
				'progress'                  => $progress,
				'percent'                   => $progress,
				'ui_updated_at'             => time(),
			)
		);

		$this->log_restore_debug(
			'deferred_core_tick',
			array(
				'cursor'  => $cursor . '/' . $total,
				'applied' => $applied,
				'bytes'   => size_format($current_bytes),
				'last'    => $last_relative,
			)
		);

		return ($cursor >= $total && $byte_offset === 0);
	}

	private function apply_deferred_core_from_extract_chunk()
	{
		$this->apply_throttling();

		$queue = $this->get('restore_queue');
		if (!is_array($queue) || empty($queue)) {
			$queue_path = $this->store->job_dir($this->job_id) . '/restore_queue.json';
			if (file_exists($queue_path)) {
				$decoded = json_decode((string) file_get_contents($queue_path), true);
				$queue = is_array($decoded) ? $decoded : array();
			}
		}
		if (!is_array($queue)) {
			$queue = array();
		}

		$core_paths = array();
		foreach ($queue as $relative) {
			$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
			if ($relative !== '' && $this->should_defer_core_path($relative)) {
				$core_paths[] = $relative;
			}
		}

		$total = count($core_paths);
		$offset = (int) $this->get('deferred_core_cursor', 0);
		if ($offset >= $total) {
			return true;
		}

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 45 : 20;
		$max_bytes = max($this->get_restore_bytes_budget(), 80 * 1024 * 1024);
		$current_bytes = 0;
		$start = microtime(true);
		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));
		$applied = 0;

		while ($offset < $total) {
			if ((microtime(true) - $start) > $time_limit || $current_bytes >= $max_bytes) {
				break;
			}
			$relative = $core_paths[$offset];
			$source_files = trailingslashit($this->extract_path) . 'files/' . $relative;
			$source_root = trailingslashit($this->extract_path) . $relative;
			$source = file_exists($source_files) ? $source_files : $source_root;
			$target = $this->safe_restore_target($relative);
			if ($target === null) {
				$offset++;
				continue;
			}

			if (!file_exists($source)) {
				$offset++;
				continue;
			}
			if (is_dir($source)) {
				if (!is_dir($target)) {
					@mkdir($target, 0755, true);
				}
				$offset++;
				continue;
			}

			$target_dir = dirname($target);
			if (!is_dir($target_dir)) {
				@mkdir($target_dir, 0755, true);
			}

			$size = (int) @filesize($source);
			if (@copy($source, $target)) {
				$current_bytes += max(0, $size);
				$applied++;
				wp_delete_file($source);
			}
			$offset++;
		}

		$core_pct = $total > 0 ? round(min(100, max(0, ($offset / $total) * 100)), 1) : 0.0;
		$this->state['deferred_core_cursor'] = $offset;
		$progress = $this->calculate_progress();
		$this->update(
			array(
				'deferred_core_cursor' => $offset,
				'message'              => sprintf(
					/* translators: 1: percent complete, 2: files processed, 3: total files */
					__('Applying WordPress core (wp-admin / wp-includes)... %1$s%% (%2$d / %3$d). Keep this tab open - the site stays in maintenance until this finishes.', 'snapshoter'),
					(string) $core_pct,
					min($offset, $total),
					$total
				),
				'progress'             => $progress,
				'percent'              => $progress,
				'ui_updated_at'        => time(),
			)
		);
		$this->log_restore_debug(
			'deferred_core_extract_tick',
			array(
				'cursor'  => $offset . '/' . $total,
				'applied' => $applied,
				'bytes'   => size_format($current_bytes),
			)
		);

		return $offset >= $total;
	}

	private function reapply_preserved_site_credentials()
	{
	}

	/**
	 * @param array $changes State changes including message/status/phase.
	 */
	private function update_finalize_ui(array $changes)
	{
		foreach ($changes as $key => $value) {
			$this->state[$key] = $value;
		}
		$progress = $this->calculate_progress();
		$changes['progress'] = $progress;
		$changes['percent'] = $progress;
		$changes['ui_updated_at'] = time();
		$this->update($changes);
	}

	private function finalize_site_tick()
	{
		$phase = (string) $this->get('finalize_phase', 'wp_core');

		if (class_exists('\\Snapshoter\\Import\\RestoreSafety') && RestoreSafety::is_enabled()) {
			if (!RestoreSafety::is_queue_paused()) {
				RestoreSafety::arm_queue_pause();
			}
		}

		if ($phase === '' || $phase === 'wp_core') {
			if (!empty($this->get('deferred_core_pending'))) {
				$this->log('Finalization: applying deferred WordPress core (wp-admin / wp-includes)...');
				$core_done = $this->apply_deferred_core_chunk();
				if (!$core_done) {
					$this->update(array('status' => 'finalizing'));
					return false;
				}
				$this->log('Deferred WordPress core applied successfully.');
			}
			$this->update_finalize_ui(
				array(
					'finalize_phase'        => 'core',
					'deferred_core_pending' => 0,
					'status'                => 'finalizing',
					'message'               => __('Updating theme & site settings...', 'snapshoter'),
				)
			);
			return false;
		}

		if ($phase === 'core') {
			$this->log('Starting finalization (core: theme & settings; plugins deferred)...');
			$this->finalize_site_core();
			$this->update_finalize_ui(
				array(
					'finalize_phase'              => 'urls',
					'url_replace_table_idx'       => 0,
					'url_replace_after_id'        => 0,
					'url_replace_widget_after_id' => 0,
					'status'                      => 'rewriting-urls',
					'message'                     => __('Rewriting URLs in the database... This can take a few minutes on large sites. Keep this tab open.', 'snapshoter'),
				)
			);
			return false;
		}

		if ($phase === 'urls') {
			$this->log('Finalization: serialization-safe URL rewrite (chunked)...');
			try {
				$done = $this->run_serialized_url_replacements_chunk();
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Error during URL replacement (may affect some plugin settings): %s', $e->getMessage()));
				$this->log('Continuing despite URL replacement error.');
				$done = true;
				$this->update(array('url_replacements_done' => true));
			}
			if (!$done) {
				$idx = (int) $this->get('url_replace_table_idx', 0);
				$this->update_finalize_ui(
					array(
						'status'  => 'rewriting-urls',
						'message' => sprintf(
							/* translators: %d: table batch index during URL rewrite */
							__('Rewriting URLs in the database... (batch %d). Keep this tab open.', 'snapshoter'),
							max(1, $idx + 1)
						),
					)
				);
				return false;
			}
			$this->update_finalize_ui(
				array(
					'finalize_phase' => 'finish',
					'status'         => 'finalizing',
					'message'        => __('Finishing restore (permalinks, caches, cleanup)...', 'snapshoter'),
				)
			);
			return false;
		}

		if ($phase === 'finish') {
			$this->log('Finalization: finishing (self-heal, permalinks, caches) while plugin set stays lean...');
			try {
				$this->finalize_site_finish();
			} catch (\Throwable $e) {
				$this->log('Warning during finalize finish (continuing): ' . $e->getMessage());
			}
			$this->update_finalize_ui(
				array(
					'finalize_phase' => 'plugins',
					'status'         => 'finalizing',
					'message'        => __('Activating plugins from the backup...', 'snapshoter'),
				)
			);
			return false;
		}

		$this->log('Finalization: activating plugins from manifest...');
		try {
			$this->release_plugin_freeze_for_activation();
			$this->finalize_activate_plugins();
		} catch (\Throwable $e) {
			$this->log('Warning: plugin activation phase error: ' . $e->getMessage());
		}
		return true;
	}

	private function release_plugin_freeze_for_activation()
	{

		remove_all_filters('pre_option_active_plugins');
		remove_all_filters('option_active_plugins');
		remove_all_filters('pre_site_option_active_sitewide_plugins');

		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::lift_plugin_freeze();
			$this->log('Lifted safe-mode plugin freeze before activation (fatal→JSON guard kept until cleanup).');
		}

		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('active_plugins', 'options');
		}
	}

	private function finalize_site_core()
	{
		global $wpdb;

		$this->log('=== FINALIZE_SITE() STARTED ===');

		$this->log('Getting current site URLs...');
		$current_site_url = site_url();
		$current_home_url = home_url();

		$this->log(sprintf(
			'Finalizing site URLs. Setting siteurl to: %s, home to: %s',
			$current_site_url,
			$current_home_url
		));

		update_option('siteurl', esc_url_raw($current_site_url));
		update_option('home', esc_url_raw($current_home_url));

		$this->log('Site URLs updated to current site URLs using update_option().');

		$this->log('Clearing Snapshoter job transients (not site-wide)...');
		$this->clear_snapshoter_transients_only();
		$this->log('Snapshoter transients cleared.');

		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'wp_redirect_%'));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_wp_%redirect%'));

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			'rewrite_rules'
		));

		$manifest = $this->get('manifest', array());
		if (!empty($manifest['site_url'])) {
			$this->log('Updating user URLs in wp_users table...');

			$old_domain = wp_parse_url($manifest['site_url'], PHP_URL_HOST);

			$new_domain = wp_parse_url($current_site_url, PHP_URL_HOST);

			if ($old_domain && $new_domain && $old_domain !== $new_domain) {
				$users = $wpdb->get_results("SELECT ID, user_url FROM {$wpdb->users}");
				foreach ($users as $user) {
					if (!empty($user->user_url) && strpos($user->user_url, $old_domain) !== false) {
						$new_user_url = str_replace($old_domain, $new_domain, $user->user_url);
						$wpdb->update(
							$wpdb->users,
							array('user_url' => $new_user_url),
							array('ID' => $user->ID)
						);
					}
				}
				$this->log(sprintf('Updated user URLs from %s to %s', $old_domain, $new_domain));
			}
		}

		$manifest = $this->get('manifest', array());

		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::lift_theme_freeze();
			$this->log('Lifted safe-mode theme freeze for finalize (plugins stay Snapshoter-only until plugins phase).');
		}

		$this->log('Activating theme from manifest...');
		$template = isset($manifest['template']) ? $manifest['template'] : null;
		$stylesheet = isset($manifest['stylesheet']) ? $manifest['stylesheet'] : null;

		if (!$template || !$stylesheet) {
			$this->log('No theme in manifest, falling back to pre-import backup...');
			$template = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_template'
				)
			);
			$stylesheet = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_stylesheet'
				)
			);
		}

		if ($template && $stylesheet) {
			update_option('template', $template);
			update_option('stylesheet', $stylesheet);

			update_option('SNAPSHOTER_restored_template', $template);
			update_option('SNAPSHOTER_restored_stylesheet', $stylesheet);
			$this->log(sprintf('Activated theme using update_option(): template=%s, stylesheet=%s', $template, $stylesheet));
		} else {
			$this->record_graceful_warning(__('The theme stored in the backup is missing on this site, so it was skipped. Install the theme files and reactivate it from Appearance ▸ Themes.', 'snapshoter'));
		}



		$manifest = $this->get('manifest', array());
		if (isset($manifest['site_settings']) && is_array($manifest['site_settings'])) {
			$this->log('Restoring site settings from manifest (before plugins)...');
			$site_settings = $manifest['site_settings'];

			$settings_map = array(
				'blogname' => 'blogname',
				'blogdescription' => 'blogdescription',

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

			$restored_count = 0;
			foreach ($settings_map as $manifest_key => $option_name) {
				if (isset($site_settings[$manifest_key])) {
					$value = $site_settings[$manifest_key];

					if (is_string($value)) {
						$value = $this->revert_hash_placeholders($value);
					}

					if (in_array($manifest_key, array('blogname', 'blogdescription'), true)) {

						if (is_string($value)) {

							$value = str_replace("\0", '', $value);
							$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

							$value = sanitize_text_field($value);
						} elseif (!is_scalar($value)) {

							$this->log(sprintf('Warning: Skipping %s - value is not a string: %s', $manifest_key, gettype($value)));
							continue;
						}

						if (empty($value)) {
							$this->log(sprintf('Warning: %s value is empty after sanitization. Skipping restore.', $manifest_key));
							continue;
						}

						if (strlen($value) > 100 && !preg_match('/[\s\p{L}]/u', $value) && preg_match('/^[a-zA-Z0-9]+$/', $value)) {
							$this->log(sprintf('Warning: %s value looks suspicious (very long alphanumeric string), but restoring from manifest anyway.', $manifest_key));
						}
					}

					update_option($option_name, $value);
					$restored_count++;
				}
			}

			if ($restored_count > 0) {
				$this->log(sprintf('Restored %d site settings from manifest (title, tagline, reading, media, etc.).', $restored_count));
			} else {
				$this->log('WARNING: No site settings were restored from manifest. Check if site_settings exists in manifest.');
			}

			if (isset($site_settings['blogname']) && !empty($site_settings['blogname'])) {
				$blogname = $site_settings['blogname'];
				if (is_string($blogname)) {
					$blogname = str_replace("\0", '', $blogname);
					$blogname = mb_convert_encoding($blogname, 'UTF-8', 'UTF-8');
					$blogname = sanitize_text_field($blogname);
					if (!empty($blogname)) {
						update_option('blogname', $blogname);
						wp_cache_delete('blogname', 'options');
						$this->log(sprintf('CRITICAL: Force-restored blogname from manifest: %s', $blogname));
					}
				}
			}
		}



		$manifest = $this->get('manifest', array());
		$site_settings = isset($manifest['site_settings']) && is_array($manifest['site_settings']) ? $manifest['site_settings'] : array();

		$manifest_stylesheet = isset($manifest['stylesheet']) ? $manifest['stylesheet'] : null;
		$current_stylesheet = get_option('stylesheet');
		$stylesheet = $manifest_stylesheet ? $manifest_stylesheet : $current_stylesheet;

		$db_existing_mods = !empty($stylesheet) ? $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'theme_mods_' . $stylesheet)) : null;
		$has_db_mods = !empty($db_existing_mods) && 'a:0:{}' !== $db_existing_mods && 'b:0;' !== $db_existing_mods;

		if (!$has_db_mods && isset($site_settings['theme_mods']) && is_array($site_settings['theme_mods'])) {
			if (!empty($stylesheet)) {
				try {
					$theme_mods = $site_settings['theme_mods'];

					if ($manifest_stylesheet && $manifest_stylesheet !== $current_stylesheet) {
						$this->log(sprintf(
							'Theme mismatch detected: backup theme=%s, current theme=%s. Restoring theme_mods to both themes.',
							$manifest_stylesheet,
							$current_stylesheet
						));
						update_option('theme_mods_' . $manifest_stylesheet, $theme_mods);
						update_option('theme_mods_' . $current_stylesheet, $theme_mods);
						$this->log('Restored theme_mods to both original and current theme.');
					} else {
						update_option('theme_mods_' . $stylesheet, $theme_mods);
						$this->log('Restored theme_mods (logo, CSS, theme options) from manifest.');
					}

					if (isset($theme_mods['custom_logo']) && !empty($theme_mods['custom_logo'])) {
						$original_logo_id = $theme_mods['custom_logo'];
						$remapped_logo_id = $this->remap_attachment_id($original_logo_id);

						if ($remapped_logo_id && $remapped_logo_id !== $original_logo_id) {
							$theme_mods['custom_logo'] = $remapped_logo_id;

							if ($manifest_stylesheet && $manifest_stylesheet !== $current_stylesheet) {
								update_option('theme_mods_' . $manifest_stylesheet, $theme_mods);
								update_option('theme_mods_' . $current_stylesheet, $theme_mods);
							} else {
								update_option('theme_mods_' . $stylesheet, $theme_mods);
							}

							update_option('custom_logo', $remapped_logo_id);
							$this->log(sprintf('Restored and remapped custom_logo: %d -> %d', $original_logo_id, $remapped_logo_id));
						} else {
							update_option('custom_logo', $original_logo_id);
							$this->log(sprintf('Restored custom_logo: %s', $original_logo_id));
						}
					}
				} catch (\Throwable $e) {
					$this->log(sprintf('Warning: Failed to restore theme_mods: %s', $e->getMessage()));
				}
			} else {
				$this->log('WARNING: Cannot restore theme_mods - stylesheet is empty.');
			}
		} elseif ($has_db_mods) {

			$this->log(sprintf(
				'theme_mods already present in database for stylesheet "%s" (from SQL import). Manifest overlay skipped.',
				(string) $stylesheet
			));
		} elseif (!isset($site_settings['theme_mods']) || !is_array($site_settings['theme_mods']) || empty($site_settings['theme_mods'])) {
			$this->log('WARNING: theme_mods data is missing or empty in site_settings and database. Logo and design may not be restored.');
		}

		if (isset($site_settings['widgets']) && is_array($site_settings['widgets'])) {
			try {
				$widget_count = 0;
				$widget_failed = 0;
				foreach ($site_settings['widgets'] as $widget_option_name => $widget_value) {

					$remapped_widget_value = $this->remap_attachment_ids_in_data($widget_value);
					$result = update_option($widget_option_name, $remapped_widget_value);
					if ($result) {
						$widget_count++;
					} else {
						$widget_failed++;
						$this->log(sprintf('Warning: Failed to update widget option: %s', $widget_option_name));
					}
				}
				if ($widget_count > 0) {
					$this->log(sprintf('Restored %d widget options from manifest (with attachment ID remapping).', $widget_count));
				}
				if ($widget_failed > 0) {
					$this->log(sprintf('Warning: Failed to restore %d widget options.', $widget_failed));
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Failed to restore widgets: %s', $e->getMessage()));
			}
		} else {
			$this->log('WARNING: widgets data is missing or empty in site_settings. Widgets will not be restored.');
		}

		if (isset($site_settings['sidebars_widgets']) && is_array($site_settings['sidebars_widgets'])) {
			try {
				update_option('sidebars_widgets', $site_settings['sidebars_widgets']);
				$this->log('Restored widget positions (sidebars_widgets) from manifest.');
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Failed to restore sidebars_widgets: %s', $e->getMessage()));
			}
		}

		if (isset($site_settings['custom_css']) && is_array($site_settings['custom_css'])) {
			try {
				$css_content = isset($site_settings['custom_css']['post_content']) ? $site_settings['custom_css']['post_content'] : '';
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
							'post_title' => isset($site_settings['custom_css']['post_title']) ? $site_settings['custom_css']['post_title'] : 'Custom CSS',
							'post_content' => $css_content,
							'post_type' => 'custom_css',
							'post_status' => 'publish',
						));
						if ($css_post_id) {
							update_option('custom_css_post_id', $css_post_id);
						}
					}
					$this->log('Restored custom CSS from manifest.');
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Failed to restore custom CSS: %s', $e->getMessage()));
			}
		}

		wp_cache_delete('alloptions', 'options');
		if (function_exists('wp_cache_delete_group')) {
			wp_cache_delete_group('options');
		}
		$this->log('Cleared widget and theme cache after restoration.');

		$this->ensure_SNAPSHOTER_active();
		$this->assert_site_installed('finalize-core');
		$this->log('Core finalize complete. Plugins stay deferred until URL rewrite finishes.');
	}

	private function finalize_activate_plugins()
	{
		global $wpdb;

		$manifest = $this->get('manifest', array());
		$this->log('Activating plugins from manifest...');
		if (isset($manifest['plugins']) && is_array($manifest['plugins']) && !empty($manifest['plugins'])) {
			$this->activate_plugins_from_manifest($manifest['plugins']);
			$this->log(sprintf('Activated %d plugins from manifest.', count($manifest['plugins'])));
		} else {
			$this->log('No plugins in manifest, falling back to pre-import backup...');
			$previous_plugins_serialized = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_plugins'
				)
			);

			if ($previous_plugins_serialized) {
				$previous_plugins = maybe_unserialize($previous_plugins_serialized);
				if (is_array($previous_plugins) && !empty($previous_plugins)) {
					$this->activate_plugins_from_manifest($previous_plugins);
					$this->log(sprintf('Activated %d plugins from pre-import backup.', count($previous_plugins)));
				}
				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					'SNAPSHOTER_prev_plugins'
				));
			}
		}

		$this->ensure_SNAPSHOTER_active();
		$this->assert_site_installed('finalize-plugins');

		$this->purge_host_caches_after_restore('post-plugins');
	}

	private function purge_host_caches_after_restore($phase = 'restore')
	{
		$phase = is_string($phase) && $phase !== '' ? $phase : 'restore';
		try {
			if (function_exists('wp_cache_flush')) {
				@wp_cache_flush();
			}
			if (function_exists('sg_cachepress_purge_everything')) {
				@sg_cachepress_purge_everything();
				$this->log('[Cache Purge][' . $phase . '] Flushed SiteGround dynamic cache.');
			}
			if (function_exists('rocket_clean_domain')) {
				@rocket_clean_domain();
				$this->log('[Cache Purge][' . $phase . '] Flushed WP Rocket domain cache.');
			}
			if (class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
				@\LiteSpeed\Purge::purge_all();
				$this->log('[Cache Purge][' . $phase . '] Flushed LiteSpeed server cache.');
			}

			do_action('litespeed_purge_all');
			if (function_exists('w3tc_flush_all')) {
				@w3tc_flush_all();
				$this->log('[Cache Purge][' . $phase . '] Flushed W3 Total Cache.');
			}
			if (function_exists('wp_cache_clear_cache')) {
				@wp_cache_clear_cache();
				$this->log('[Cache Purge][' . $phase . '] Flushed WP Super Cache.');
			}
			if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
				@autoptimizeCache::clearall();
				$this->log('[Cache Purge][' . $phase . '] Flushed Autoptimize cache.');
			}
			do_action('breeze_clear_all_cache');
			do_action('cache_enabler_clear_complete_cache');
			do_action('nitropack_integration_purge_all');
		} catch (\Throwable $e) {
			$this->log('[Cache Purge][' . $phase . '] Notice: ' . $e->getMessage());
		}
	}

	private function finalize_site_finish()
	{
		global $wpdb;

		$manifest = $this->get('manifest', array());
		$current_site_url = site_url();
		$current_home_url = home_url();

		$this->restore_blogname_immediately();



		try {
			$source_prefix = isset($manifest['db_prefix']) ? $manifest['db_prefix'] : '';
			$prefix_logs = \Snapshoter\Import\ThemeBuilderHealer::heal_table_prefixes($wpdb, $source_prefix);
			foreach ($prefix_logs as $plog) {
				$this->log('[Self-Healing] ' . $plog);
			}

			$theme_mod_logs = \Snapshoter\Import\ThemeBuilderHealer::heal_theme_mods($wpdb);
			foreach ($theme_mod_logs as $tmlog) {
				$this->log('[Self-Healing] ' . $tmlog);
			}

			if (function_exists('wp_cache_delete')) {
				$stylesheet = get_stylesheet();
				$template = get_template();
				wp_cache_delete('theme_mods_' . $stylesheet, 'options');
				if (!empty($template)) {
					wp_cache_delete('theme_mods_' . $template, 'options');
				}
				wp_cache_delete('nav_menu_locations', 'theme_mods');
			}
			if (function_exists('wp_cache_flush')) {
				wp_cache_flush();
			}

			$this->purge_host_caches_after_restore('finalize-finish');

			$content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			$builder_flushes = \Snapshoter\Import\ThemeBuilderHealer::flush_all_builder_caches($wpdb, $content_dir);
			foreach ($builder_flushes as $bflush) {
				$this->log('[Self-Healing] ' . $bflush);
			}
		} catch (\Throwable $e) {
			$this->log('[Self-Healing] Warning during theme/builder healing: ' . $e->getMessage());
		}


		$this->reapply_preserved_site_credentials();



		if (isset($manifest['server']['.htaccess']) && !empty($manifest['server']['.htaccess'])) {
			$htaccess_content = base64_decode($manifest['server']['.htaccess'], true);

		}

		if (isset($manifest['server']['web.config']) && !empty($manifest['server']['web.config'])) {
			$webconfig_content = base64_decode($manifest['server']['web.config'], true);

		}

		$this->clear_snapshoter_transients_only();

		$this->log('Flushing all caches...');
		$this->flush_all_caches();
		$this->log('Cache flush completed.');



		$permalink_structure = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'permalink_structure'
			)
		);

		$manifest = $this->get('manifest', array());
		$permalink_corrupted = false;

		if (!empty($permalink_structure)) {

			$decoded_structure = urldecode($permalink_structure);

			$has_hash_pattern = (
				preg_match('/[a-f0-9]{32,}/i', $permalink_structure) ||
				preg_match('/[a-f0-9]{32,}/i', $decoded_structure) ||
				preg_match('/%7B[a-f0-9]{32,}%7D/i', $permalink_structure) ||
				preg_match('/\{[a-f0-9]{32,}\}/i', $decoded_structure)
			);

			if ($has_hash_pattern || !preg_match('/%[a-z_]+%/', $permalink_structure)) {
				$permalink_corrupted = true;
				$this->log(sprintf('Detected corrupted permalink structure: %s', $permalink_structure));
			}
		}

		if (empty($permalink_structure) || $permalink_corrupted) {
			if (isset($manifest['permalink_structure']) && !empty($manifest['permalink_structure'])) {
				$permalink_structure = $manifest['permalink_structure'];
				$this->log(sprintf('Using permalink structure from manifest: %s', $permalink_structure));
			}
		}

		if ($permalink_structure) {

			$cleaned_structure = urldecode($permalink_structure);

			$cleaned_structure = preg_replace('/[a-f0-9]{32,}/i', '', $cleaned_structure);
			$cleaned_structure = preg_replace('/%7B[a-f0-9]{32,}%7D/i', '', $cleaned_structure);
			$cleaned_structure = preg_replace('/\{[a-f0-9]{32,}\}/i', '', $cleaned_structure);
			$cleaned_structure = preg_replace('/%7B/i', '', $cleaned_structure);
			$cleaned_structure = preg_replace('/%7D/i', '', $cleaned_structure);
			$cleaned_structure = preg_replace('/[{}]/', '', $cleaned_structure);

			$cleaned_structure = preg_replace('/(\/[^\/]+)\1+/', '$1', $cleaned_structure);

			$cleaned_structure = preg_replace('/\/+/', '/', $cleaned_structure);

			$cleaned_structure = trim($cleaned_structure, '/');
			if (!empty($cleaned_structure)) {
				$cleaned_structure = '/' . $cleaned_structure . '/';
			}

				if (isset($manifest['permalink_structure']) && !empty($manifest['permalink_structure'])) {
					$permalink_structure = $manifest['permalink_structure'];
					$this->log(sprintf('Using permalink structure from manifest after cleaning failed: %s', $permalink_structure));
				} else {
					$this->log(sprintf('Warning: Permalink structure does not appear valid: %s', $cleaned_structure));
				}

			$this->log(sprintf('Restoring permalink structure: %s', $permalink_structure));

			$this->log('Deleting cached rewrite rules before permalink update...');
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				'rewrite_rules'
			));

			$wpdb->query($wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'permalink_structure'",
				$permalink_structure
			));

			update_option('permalink_structure', $permalink_structure);
			$this->log('Permalink structure restored and WordPress notified.');

			wp_cache_delete('rewrite_rules', 'options');
			wp_cache_delete('alloptions', 'options');

			$this->log('Flushing rewrite rules to regenerate them...');
			try {
				global $wp_rewrite;
				if (isset($wp_rewrite)) {
					$wp_rewrite->init();
					$wp_rewrite->flush_rules(true);
					$this->log('Rewrite rules flushed and regenerated in database.');

					$this->log('Saving rewrite rules to .htaccess file...');
					if (function_exists('save_mod_rewrite_rules')) {
						if (!function_exists('insert_with_markers')) {
							require_once ABSPATH . 'wp-admin/includes/misc.php';
						}
						save_mod_rewrite_rules();
						$this->log('Rewrite rules saved to .htaccess successfully.');
					} else {
						$this->log('Warning: save_mod_rewrite_rules() function not available. User may need to save permalinks manually.');
					}
				} else {

					flush_rewrite_rules(true);
					$this->log('Rewrite rules flushed using fallback method.');
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Error flushing rewrite rules: %s', $e->getMessage()));
			}
		} else {
			$this->log('Warning: No permalink structure found in backup or manifest.');
		}



		$this->log('Clearing authentication and session data...');

		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", '_wp_session_%'));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'session_tokens'));

		wp_cache_flush();

		delete_option('rewrite_rules');
		wp_cache_delete('alloptions', 'options');

		wp_cache_delete('blogname', 'options');
		wp_cache_delete('blogdescription', 'options');
		wp_cache_delete('custom_logo', 'options');
		$manifest = $this->get('manifest', array());
		$stylesheet = isset($manifest['stylesheet']) ? $manifest['stylesheet'] : get_option('stylesheet');
		if (!empty($stylesheet)) {
			wp_cache_delete('theme_mods_' . $stylesheet, 'options');
		}

		$this->log('Authentication and session data cleared. Users will need to log in again with new domain.');

		try {
			if (class_exists('\\Snapshoter\\Import\\RestoreSafety') && RestoreSafety::is_enabled()) {
				$safety = RestoreSafety::after_restore(
					is_array($manifest) ? $manifest : array(),
					function ($message) {
						$this->log($message);
					}
				);
				if (!empty($safety['warnings']) && is_array($safety['warnings'])) {
					foreach ($safety['warnings'] as $warning) {
						$this->record_graceful_warning($warning);
					}
				}
			} elseif (class_exists('\\Snapshoter\\Import\\RestoreSafety')) {
				RestoreSafety::disarm_queue_pause();
			}
		} catch (\Throwable $e) {
			$this->log('[RestoreSafety] Warning: ' . $e->getMessage());
			if (class_exists('\\Snapshoter\\Import\\RestoreSafety')) {
				RestoreSafety::disarm_queue_pause();
			}
		}

		$manifest = $this->get('manifest', array());
		if (!empty($manifest)) {
			update_option('SNAPSHOTER_last_import_manifest', $manifest);
		}

		$notices = $this->generate_post_finalize_notices($permalink_structure);
		$this->update(
			array(
				'post_finalize_notices' => $notices,
			)
		);

		$this->assert_site_installed('finalize-finish');
	}

	public function cleanup()
	{
		$this->log('Cleaning up temporary files...');
		$this->clear_restore_safe_mode();
		$this->pause_wp_auto_updates(15);
		$this->clear_wp_core_maintenance();

		$archive_path = $this->get('archive_path');
		if ($archive_path && file_exists($archive_path) && !$this->is_protected_snapshot_archive($archive_path)) {

			wp_delete_file($archive_path);
			$this->log('Deleted archive file.');
		}

		$job_dir = $this->store->job_dir($this->job_id);
		$extract_path = $this->extract_path;

		try {

			if ($extract_path === $job_dir) {
				$items = @scandir($job_dir);

			} else {

				if (is_dir($extract_path)) {
					$this->recursive_rmdir($extract_path);
				}
			}
		} catch (\Throwable $e) {

			$this->log(sprintf('Warning: Error during cleanup (non-critical): %s', $e->getMessage()));
		}

		$this->log('Temporary files cleaned up.');

		if (class_exists('\\Snapshoter\\Core\\VaultPrune')) {
			try {
				\Snapshoter\Core\VaultPrune::after_restore();
			} catch (\Throwable $prune_error) {
				$this->log(sprintf('Vault prune after restore skipped: %s', $prune_error->getMessage()));
			}
		}
	}

	private function is_protected_snapshot_archive($path)
	{
		if (!$this->get('snapshot_restore')) {
			return false;
		}
		$path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
		if ($path === '' || !is_file($path)) {
			return false;
		}
		foreach (array('SNAPSHOTER_SNAPSHOTS_DIR', 'SNAPSHOTER_ARCHIVE_DIR') as $const) {
			if (!defined($const)) {
				continue;
			}
			$dir = rtrim((string) constant($const), '/\\');
			$dir = function_exists('wp_normalize_path') ? wp_normalize_path($dir) : str_replace('\\', '/', $dir);
			if ($dir !== '' && strpos($path, $dir . '/') === 0) {
				return true;
			}
		}
		return false;
	}

	private function recursive_rmdir($dir)
	{
		if (is_link($dir)) {
			wp_delete_file($dir);
			return;
		}

		if (!is_dir($dir)) {
			return;
		}

		try {

			$php_version_supports_catch_get_child = version_compare(PHP_VERSION, '7.1.0', '>=');

			if ($php_version_supports_catch_get_child) {

				try {
					$files = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
							$dir,
							\FilesystemIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CATCH_GET_CHILD
						),
						\RecursiveIteratorIterator::CHILD_FIRST,
						\RecursiveIteratorIterator::CATCH_GET_CHILD
					);
				} catch (\Throwable $e) {

					$files = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
							$dir,
							\FilesystemIterator::SKIP_DOTS
						),
						\RecursiveIteratorIterator::CHILD_FIRST
					);
				}
			} else {

				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator(
						$dir,
						\FilesystemIterator::SKIP_DOTS
					),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
			}

			foreach ($files as $fileinfo) {
				try {
					$path = $fileinfo->getRealPath();

					if (!file_exists($path) && !is_link($path)) {
						continue;
					}

					if ($fileinfo->isDir()) {

						@rmdir($path);
					} else {

						wp_delete_file($path);
					}
				} catch (\UnexpectedValueException $e) {

					$this->log(sprintf('Warning: Skipping inaccessible path during cleanup: %s', $e->getMessage()));
					continue;
				} catch (\Throwable $e) {

					$this->log(sprintf('Warning: Error during cleanup: %s', $e->getMessage()));
					continue;
				}
			}

			@rmdir($dir);
		} catch (\UnexpectedValueException $e) {

			$this->log(sprintf('Warning: Could not access directory for cleanup: %s. This is usually safe to ignore.', $e->getMessage()));
		} catch (\Throwable $e) {

			$this->log(sprintf('Warning: Error during recursive directory removal: %s', $e->getMessage()));
		}
	}

	private function purge_orphaned_plugins_and_themes()
	{

	}

	private function purge_foreign_orphaned_database_tables()
	{
		$this->log('[Clean-Slate] Post-import DB table DROP is disabled (stable 1.0 behavior). Prefix-remapped restores must not drop live tables.');
	}

	private function assert_site_installed($context = 'finalize')
	{
		global $wpdb;

		$site_url = site_url();
		$home_url = home_url();
		if (empty($site_url) || strpos($site_url, 'http') !== 0) {

			$site_url = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost');
			$home_url = $site_url;
		}

		update_option('siteurl', esc_url_raw($site_url));
		update_option('home', esc_url_raw($home_url));

		$has_siteurl = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'siteurl'
			)
		);
		if (empty($has_siteurl)) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					'siteurl',
					esc_url_raw($site_url)
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					'home',
					esc_url_raw($home_url)
				)
			);
			$this->log(sprintf('[%s] Re-inserted siteurl/home after missing options row.', $context));
		}

		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('siteurl', 'options');
		wp_cache_delete('home', 'options');
		wp_cache_delete('is_blog_installed', 'site-options');

		$this->log(
			sprintf(
				'[%s] Site installed guard OK (siteurl=%s). Next screen should be login, not installer.',
				$context,
				$site_url
			)
		);
	}

	private function extract_table_names_from_sql($sql_path)
	{
		$tables = array();
		if (!file_exists($sql_path) || !is_readable($sql_path)) {
			return $tables;
		}

		$handle = @fopen($sql_path, 'r');
		if (!$handle) {
			return $tables;
		}

		while (!feof($handle)) {
			$line = fgets($handle, 8192);

			if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_$]+)`?/i', $line, $matches)) {
				$tables[] = $matches[1];
			}
		}
		fclose($handle);

		return array_unique($tables);
	}

	private function build_snapshoter_url_replacements()
	{
		$manifest = $this->get('manifest', array());
		$old_replace_values = array();
		$new_replace_values = array();

		$source_site = isset($manifest['site_url']) ? $manifest['site_url'] : '';
		$source_home = isset($manifest['home_url']) ? $manifest['home_url'] : $source_site;
		$target_site = site_url();
		$target_home = home_url();

		foreach (array(
			array($source_site, $target_site),
			array($source_home, $target_home),
		) as $pair) {
			list($old_url, $new_url) = $pair;
			if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
				continue;
			}
			$this->append_url_variant_pairs($old_url, $new_url, $old_replace_values, $new_replace_values);
		}

		if (!empty($source_site)) {
			$old_domain = wp_parse_url($source_site, PHP_URL_HOST);
			$new_domain = wp_parse_url($target_site, PHP_URL_HOST);

			if ($old_domain && $new_domain && $old_domain !== $new_domain) {
				$old_replace_values[] = '@' . $old_domain;
				$new_replace_values[] = '@' . $new_domain;
				$old_replace_values[] = $old_domain;
				$new_replace_values[] = $new_domain;
			}
		}

		list($old_replace_values, $new_replace_values) = $this->dedupe_replacement_pairs(
			$old_replace_values,
			$new_replace_values
		);

		return array(
			'old' => $old_replace_values,
			'new' => $new_replace_values,
		);
	}

	private function append_url_variant_pairs($old_url, $new_url, array &$old_values, array &$new_values)
	{
		$old_url = (string) $old_url;
		$new_url = (string) $new_url;

		if ($old_url === '' || $new_url === '' || $old_url === $new_url) {
			return;
		}

		$old_no_slash = untrailingslashit($old_url);
		$new_no_slash = untrailingslashit($new_url);

		$old_variants = array($old_no_slash);
		if (preg_match('#^https?://#', $old_no_slash)) {
			$old_variants[] = preg_replace('#^https?://#', 'https://', $old_no_slash);
			$old_variants[] = preg_replace('#^https?://#', 'http://', $old_no_slash);
		}

		$twin_variants = array();
		foreach ($old_variants as $variant) {
			$host = wp_parse_url($variant, PHP_URL_HOST);
			if (!$host) {
				continue;
			}
			if (strpos($host, 'www.') === 0) {
				$twin_variants[] = str_replace('://' . $host, '://' . substr($host, 4), $variant);
			} else {
				$twin_variants[] = str_replace('://' . $host, '://www.' . $host, $variant);
			}
		}
		$old_variants = array_values(array_unique(array_merge($old_variants, $twin_variants)));

		foreach ($old_variants as $old_form) {
			$this->append_single_encoding_set($old_form, $new_no_slash, $old_values, $new_values);
		}
	}

	private function append_single_encoding_set($old_form, $new_form, array &$old_values, array &$new_values)
	{
		$old_form = (string) $old_form;
		$new_form = (string) $new_form;

		if ($old_form === '' || $new_form === '' || $old_form === $new_form) {
			return;
		}

		$old_values[] = $old_form;
		$new_values[] = $new_form;
		$old_values[] = trailingslashit($old_form);
		$new_values[] = trailingslashit($new_form);

		if (preg_match('#^https?:#', $old_form) && preg_match('#^https?:#', $new_form)) {
			$old_pr = preg_replace('#^https?:#', '', $old_form);
			$new_pr = preg_replace('#^https?:#', '', $new_form);
			if ($old_pr !== $new_pr) {
				$old_values[] = $old_pr;
				$new_values[] = $new_pr;
			}
		}

		$old_enc = rawurlencode($old_form);
		$new_enc = rawurlencode($new_form);
		if ($old_enc !== $new_enc) {
			$old_values[] = $old_enc;
			$new_values[] = $new_enc;
		}

		$old_addc = addcslashes($old_form, '/');
		$new_addc = addcslashes($new_form, '/');
		if ($old_addc !== $old_form && $old_addc !== $new_addc) {
			$old_values[] = $old_addc;
			$new_values[] = $new_addc;
		}

		$old_json = str_replace('/', '\\/', $old_form);
		$new_json = str_replace('/', '\\/', $new_form);
		if ($old_json !== $old_form && $old_json !== $new_json) {
			$old_values[] = $old_json;
			$new_values[] = $new_json;
		}
	}

	private function dedupe_replacement_pairs(array $olds, array $news)
	{
		$seen = array();
		$pairs = array();
		$count = min(count($olds), count($news));
		for ($i = 0; $i < $count; $i++) {
			$old = $olds[$i];
			$new = $news[$i];
			if ($old === '' || $new === '' || $old === $new) {
				continue;
			}
			$key = $old . '=>' . $new;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$pairs[] = array($old, $new);
		}

		usort($pairs, function ($a, $b) {
			$la = strlen($a[0]);
			$lb = strlen($b[0]);
			if ($la === $lb) {
				return 0;
			}
			return $lb - $la;
		});

		$final_old = array();
		$final_new = array();
		foreach ($pairs as $pair) {
			$final_old[] = $pair[0];
			$final_new[] = $pair[1];
		}

		return array($final_old, $final_new);
	}

	private function discover_source_subdomains()
	{
		global $wpdb;

		$manifest = $this->get('manifest', array());
		if (empty($manifest['site_url'])) {
			return array();
		}

		$old_host = wp_parse_url($manifest['site_url'], PHP_URL_HOST);
		$new_host = wp_parse_url(site_url(), PHP_URL_HOST);
		if (!$old_host || !$new_host || $old_host === $new_host) {
			return array();
		}

		$old_apex = preg_replace('#^www\.#i', '', $old_host);
		if (!$old_apex) {
			return array();
		}

		$like = '%' . $wpdb->esc_like('.' . $old_apex) . '%';

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 500",
				$like
			)
		);

		if (empty($rows) || !is_array($rows)) {
			return array();
		}

		$mapping = array();
		$pattern = '/([a-z0-9][a-z0-9-]{0,62})\.' . preg_quote($old_apex, '/') . '/i';

		foreach ($rows as $value) {
			if (!is_string($value) || strlen($value) > 524288) {
				continue;
			}
			if (!preg_match_all($pattern, $value, $matches)) {
				continue;
			}
			foreach ($matches[1] as $sub) {
				$sub = strtolower($sub);
				if ($sub === '' || $sub === 'www') {
					continue;
				}
				$old_sub_host = $sub . '.' . $old_apex;
				if ($old_sub_host === $old_host) {
					continue;
				}
				$new_sub_host = $sub . '.' . preg_replace('#^www\.#i', '', $new_host);
				if (!isset($mapping[$old_sub_host])) {
					$mapping[$old_sub_host] = $new_sub_host;
				}
			}
		}

		return $mapping;
	}

	private function run_serialized_url_replacements_if_needed()
	{
		while (!$this->run_serialized_url_replacements_chunk()) {

		}
	}

	private function run_serialized_url_replacements_chunk()
	{
		global $wpdb;

		if ($this->get('url_replacements_done')) {
			return true;
		}

		$manifest = $this->get('manifest', array());
		if (empty($manifest['site_url'])) {
			$this->update(array('url_replacements_done' => true));
			return true;
		}

		$old_url = untrailingslashit($manifest['site_url']);
		$new_url = untrailingslashit(site_url());

		if ($old_url === $new_url) {
			$this->update(array('url_replacements_done' => true));
			return true;
		}

		$old_host = strtolower((string) wp_parse_url($old_url, PHP_URL_HOST));
		$new_host = strtolower((string) wp_parse_url($new_url, PHP_URL_HOST));
		if ($old_host && $new_host && $old_host === $new_host) {
			$this->log(sprintf('Same-host restore (%s). Skipping full URL serialization sweep.', $old_host));
			$this->update(array('url_replacements_done' => true));
			return true;
		}

		if (!$this->get('url_replace_started')) {
			$this->log(sprintf('Running serialization-safe URL replacements from %s to %s...', $old_url, $new_url));
			$this->update(array('url_replace_started' => true));
		}

		$replacements = $this->get('url_replace_pairs', null);
		if (!is_array($replacements) || empty($replacements['old']) || empty($replacements['new'])) {
			$replacements = $this->build_snapshoter_url_replacements();

			$subdomain_map = $this->discover_source_subdomains();
			if (!empty($subdomain_map)) {
				foreach ($subdomain_map as $old_sub => $new_sub) {
					$replacements['old'][] = $old_sub;
					$replacements['new'][] = $new_sub;
				}
				list($replacements['old'], $replacements['new']) = $this->dedupe_replacement_pairs(
					$replacements['old'],
					$replacements['new']
				);
			}

			if (empty($replacements['old']) || empty($replacements['new'])) {
				$this->update(array('url_replacements_done' => true));
				return true;
			}
			$this->update(array('url_replace_pairs' => $replacements));
		}

		$targets = array(
			array($wpdb->options, 'option_id', 'option_value'),
			array($wpdb->posts, 'ID', 'post_content'),
			array($wpdb->postmeta, 'meta_id', 'meta_value'),
			array($wpdb->usermeta, 'umeta_id', 'meta_value'),
			array($wpdb->termmeta, 'meta_id', 'meta_value'),
			array($wpdb->commentmeta, 'meta_id', 'meta_value'),
		);

		$idx = (int) $this->get('url_replace_table_idx', 0);
		$after_id = (int) $this->get('url_replace_after_id', 0);
		$limit = 40;
		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 12 : 8;
		$start_time = microtime(true);
		$max_value_bytes = 256 * 1024;

		if ($idx >= count($targets)) {
			$this->update(array('url_replacements_done' => true));
			$this->log('Serialization-safe URL replacements completed.');

			try {
				$this->validate_post_restore_design();
			} catch (\Throwable $e) {
				$this->log(sprintf('Design sanity check skipped: %s', $e->getMessage()));
			}
			try {
				$this->regenerate_design_caches();
			} catch (\Throwable $e) {
				$this->log(sprintf('Design cache regeneration skipped: %s', $e->getMessage()));
			}
			return true;
		}

		list($table, $id_column, $value_column) = $targets[$idx];
		if (!$this->table_exists($table)) {
			$this->update(array('url_replace_table_idx' => $idx + 1, 'url_replace_after_id' => 0));
			return false;
		}

		if ($table === $wpdb->options && !$this->get('url_replace_widgets_done')) {
			$widgets_done = $this->replace_urls_in_table_widgets_chunk(
				$table,
				$id_column,
				$value_column,
				$replacements['old'],
				$replacements['new'],
				$time_limit,
				$max_value_bytes
			);
			if (!$widgets_done) {
				return false;
			}
			$this->update(array('url_replace_widgets_done' => true, 'url_replace_widget_after_id' => 0));
			return false;
		}

		$like = '%' . $wpdb->esc_like($old_url) . '%';
		if ($table === $wpdb->options) {

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_column}, option_name, {$value_column} FROM {$table} WHERE {$value_column} LIKE %s AND {$id_column} > %d ORDER BY {$id_column} ASC LIMIT %d",
					$like,
					$after_id,
					$limit
				),
				ARRAY_A
			);
		} else {

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_column}, {$value_column} FROM {$table} WHERE {$value_column} LIKE %s AND {$id_column} > %d ORDER BY {$id_column} ASC LIMIT %d",
					$like,
					$after_id,
					$limit
				),
				ARRAY_A
			);
		}

		$total_processed = 0;
		$last_id = $after_id;
		$critical_options = array('permalink_structure', 'blogname', 'blogdescription');
		if (!empty($rows)) {
			foreach ($rows as $row) {
				if ((microtime(true) - $start_time) > $time_limit) {
					break;
				}
				$row_id = isset($row[$id_column]) ? (int) $row[$id_column] : 0;
				if ($row_id > $last_id) {
					$last_id = $row_id;
				}
				if (isset($row['option_name']) && in_array($row['option_name'], $critical_options, true)) {
					continue;
				}
				$original = $row[$value_column];
				if (!is_string($original) || $original === '') {
					continue;
				}
				if (strlen($original) > $max_value_bytes) {
					$replaced = str_replace($replacements['old'], $replacements['new'], $original);
				} else {
					$replaced = $this->replace_serialized_value($original, $replacements['old'], $replacements['new']);
				}
				if ($replaced === $original) {
					continue;
				}
				$wpdb->update(
					$table,
					array($value_column => $replaced),
					array($id_column => $row[$id_column])
				);
				$total_processed++;
			}
		}

		$fetched = is_array($rows) ? count($rows) : 0;

		if ($last_id <= $after_id && !empty($rows) && isset($rows[0][$id_column])) {
			$last_id = (int) $rows[0][$id_column];
		}
		$this->log(
			sprintf(
				'URL rewrite chunk: %s after_id=%d fetched=%d updated=%d last_id=%d',
				$table,
				$after_id,
				$fetched,
				$total_processed,
				$last_id
			)
		);

		if ($fetched === 0) {
			$this->update(array('url_replace_table_idx' => $idx + 1, 'url_replace_after_id' => 0));
		} elseif ($fetched < $limit) {
			$this->update(array('url_replace_table_idx' => $idx + 1, 'url_replace_after_id' => 0));
		} else {
			$this->update(array('url_replace_after_id' => max($after_id, $last_id)));
		}

		return false;
	}

	private function validate_post_restore_design()
	{
		global $wpdb;

		$stylesheet = get_option('stylesheet');
		if (!$stylesheet) {
			return;
		}

		$theme_mods = get_option('theme_mods_' . $stylesheet);
		if (!is_array($theme_mods)) {
			$theme_mods = array();
		}

		$custom_logo_id = isset($theme_mods['custom_logo']) ? (int) $theme_mods['custom_logo'] : 0;
		if ($custom_logo_id <= 0) {
			$opt_logo = (int) get_option('site_logo', 0);
			if ($opt_logo > 0) {
				$custom_logo_id = $opt_logo;
			}
		}
		if ($custom_logo_id > 0 && !$this->attachment_file_exists($custom_logo_id)) {
			$this->record_graceful_warning(
				sprintf(

					// translators: %s: customizer URL.
					__('Your site logo image file is missing. Re-upload your logo in Appearance ▸ Customize ▸ Site Identity, or visit %s to set a new one.', 'snapshoter'),
					esc_url(admin_url('customize.php?autofocus[section]=title_tagline'))
				)
			);
		}

		$site_icon = (int) get_option('site_icon', 0);
		if ($site_icon > 0 && !$this->attachment_file_exists($site_icon)) {
			$this->record_graceful_warning(
				__('Your site icon (favicon) image file is missing. Re-upload it in Appearance ▸ Customize ▸ Site Identity.', 'snapshoter')
			);
		}

		if (!empty($theme_mods['header_image']) && is_string($theme_mods['header_image']) && filter_var($theme_mods['header_image'], FILTER_VALIDATE_URL)) {
			$header_path = $this->local_path_for_uploads_url($theme_mods['header_image']);
			if ($header_path && !file_exists($header_path)) {
				$this->record_graceful_warning(
					__('Your header image file is missing. Re-upload it in Appearance ▸ Customize ▸ Header Image.', 'snapshoter')
				);
			}
		}

		if (!empty($theme_mods['nav_menu_locations']) && is_array($theme_mods['nav_menu_locations'])) {
			$missing_locations = array();
			foreach ($theme_mods['nav_menu_locations'] as $location => $menu_id) {
				$menu_id = (int) $menu_id;
				if ($menu_id <= 0) {
					continue;
				}
				$exists = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu'",
					$menu_id
				));
				if (!$exists) {
					$missing_locations[] = $location;
				}
			}
			if (!empty($missing_locations)) {
				$this->record_graceful_warning(
					sprintf(

						// translators: %s: menu location names.
						__('One or more navigation menus are not assigned to their menu locations. Open Appearance ▸ Menus and pick a menu for each location: %s', 'snapshoter'),
						esc_url(admin_url('nav-menus.php?action=locations'))
					)
				);
			}
		} elseif (!empty($theme_mods) && function_exists('get_registered_nav_menus') && !empty(get_registered_nav_menus())) {
			$any_menu_exists = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'nav_menu'"
			);
			if ($any_menu_exists > 0) {
				$this->record_graceful_warning(
					sprintf(

						// translators: %s: menu locations URL or list.
						__('Your theme has navigation menu locations but no menu is assigned. Open Appearance ▸ Menus and assign one: %s', 'snapshoter'),
						esc_url(admin_url('nav-menus.php?action=locations'))
					)
				);
			}
		}

		$sidebars = get_option('sidebars_widgets');
		if (is_array($sidebars)) {
			$orphan_count = 0;
			foreach ($sidebars as $sidebar => $widget_ids) {
				if ($sidebar === 'wp_inactive_widgets' || $sidebar === 'array_version' || !is_array($widget_ids)) {
					continue;
				}
				foreach ($widget_ids as $widget_id) {
					if (!is_string($widget_id) || !preg_match('/^(.*)-(\d+)$/', $widget_id, $match)) {
						continue;
					}

				}
			}
			if ($orphan_count > 0) {
				$this->record_graceful_warning(
					sprintf(

						// translators: %s: widget details.
						__('Some sidebar widgets are missing their data. Open Appearance ▸ Widgets and re-add or remove the affected items: %s', 'snapshoter'),
						esc_url(admin_url('widgets.php'))
					)
				);
			}
		}

		if ('page' === get_option('show_on_front')) {
			$page_on_front = (int) get_option('page_on_front');
			$page_for_posts = (int) get_option('page_for_posts');
			$front_missing = $page_on_front > 0 && !get_post($page_on_front);
			$blog_missing = $page_for_posts > 0 && !get_post($page_for_posts);
			if ($front_missing || $blog_missing) {
				$this->record_graceful_warning(
					sprintf(

						// translators: %s: Settings Reading URL or detail.
						__('Your static front page or posts page is missing. Open Settings ▸ Reading and pick the right pages: %s', 'snapshoter'),
						esc_url(admin_url('options-reading.php'))
					)
				);
			}
		}
	}

	private function attachment_file_exists($attachment_id)
	{
		$attachment_id = (int) $attachment_id;
		if ($attachment_id <= 0 || !get_post($attachment_id)) {
			return false;
		}

		$path = get_attached_file($attachment_id);
		if ($path && file_exists($path)) {
			return true;
		}

		$src = wp_get_attachment_image_src($attachment_id, 'full');
		if (is_array($src) && !empty($src[0])) {
			$local = $this->local_path_for_uploads_url($src[0]);
			if ($local && file_exists($local)) {
				return true;
			}
		}

		return false;
	}

	private function local_path_for_uploads_url($url)
	{
		if (!is_string($url) || $url === '') {
			return null;
		}
		$uploads = wp_upload_dir();
		if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
			return null;
		}
		$baseurl = trailingslashit($uploads['baseurl']);
		if (strpos($url, $baseurl) !== 0) {
			$alt = strpos($baseurl, 'https://') === 0 ? 'http://' . substr($baseurl, 8) : 'https://' . substr($baseurl, 7);
			if (strpos($url, $alt) !== 0) {
				return null;
			}
			$relative = substr($url, strlen($alt));
		} else {
			$relative = substr($url, strlen($baseurl));
		}
		$relative = ltrim(preg_replace('/[?#].*$/', '', $relative), '/');

		return trailingslashit($uploads['basedir']) . $relative;
	}

	private function regenerate_design_caches()
	{
		if (function_exists('delete_expired_transients')) {
			delete_expired_transients(true);
		}
		if (function_exists('wp_cache_flush')) {
			@wp_cache_flush();
		}

		do_action('snapshoter_post_restore_regenerate_design');

		$candidates = array(
			array(
				'check' => function () { return class_exists('\\Elementor\\Plugin'); },
				'invoke' => function () {
					$plugin = call_user_func(array('\\Elementor\\Plugin', 'instance'));
					if ($plugin && isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
						$plugin->files_manager->clear_cache();
						return true;
					}
					return false;
				},
				'fallback_dir' => 'elementor/css',
			),
			array(
				'check' => function () { return class_exists('\\FLBuilderModel'); },
				'invoke' => function () {
					if (method_exists('\\FLBuilderModel', 'delete_asset_cache_for_all_posts')) {
						call_user_func(array('\\FLBuilderModel', 'delete_asset_cache_for_all_posts'));
						return true;
					}
					return false;
				},
				'fallback_dir' => 'bb-plugin',
			),
			array(
				'check' => function () { return defined('OXYGEN_VSB_PLUGIN_PATH') || function_exists('oxygen_vsb_load_plugin_textdomain'); },
				'invoke' => function () {
					if (function_exists('ct_recreate_all_components_css_files')) {
						call_user_func('ct_recreate_all_components_css_files');
						return true;
					}
					return false;
				},
				'fallback_dir' => 'oxygen/css',
			),
			array(
				'check' => function () { return defined('BREAKDANCE_PLUGIN_VERSION'); },
				'invoke' => function () { return false; },
				'fallback_dir' => 'breakdance/css',
			),
			array(
				'check' => function () { return defined('BRICKS_VERSION') || function_exists('bricks_is_builder'); },
				'invoke' => function () {
					if (function_exists('Bricks\\Helpers::delete_cached_css_files')) {
						call_user_func('Bricks\\Helpers::delete_cached_css_files');
						return true;
					}
					return false;
				},
				'fallback_dir' => 'bricks/css',
			),
			array(
				'check' => function () { return defined('GREENSHIFT_DIR_URL') || defined('GSPB_VERSION'); },
				'invoke' => function () { return false; },
				'fallback_dir' => 'greenshift',
			),
			array(
				'check' => function () { return class_exists('\\SiteGround_Optimizer\\Supercacher\\Supercacher'); },
				'invoke' => function () {
					$method = array('\\SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache');
					if (is_callable($method)) {
						call_user_func($method);
						return true;
					}
					return false;
				},
				'fallback_dir' => null,
			),
			array(
				'check' => function () { return function_exists('rocket_clean_domain'); },
				'invoke' => function () {
					rocket_clean_domain();
					return true;
				},
				'fallback_dir' => null,
			),
			array(
				'check' => function () { return class_exists('\\LiteSpeed\\Purge'); },
				'invoke' => function () {
					if (method_exists('\\LiteSpeed\\Purge', 'purge_all')) {
						call_user_func(array('\\LiteSpeed\\Purge', 'purge_all'));
						return true;
					}
					return false;
				},
				'fallback_dir' => null,
			),
		);

		foreach ($candidates as $entry) {
			try {
				if (!call_user_func($entry['check'])) {
					continue;
				}
				$invoked = false;
				if (isset($entry['invoke']) && is_callable($entry['invoke'])) {
					$invoked = (bool) call_user_func($entry['invoke']);
				}
				if (!$invoked && !empty($entry['fallback_dir'])) {
					$this->purge_uploads_subdir($entry['fallback_dir']);
				}
			} catch (\Throwable $e) {

			}
		}
	}

	private function purge_uploads_subdir($relative)
	{
		$relative = trim($relative, '/\\');

		$uploads = wp_upload_dir();
		if (empty($uploads['basedir'])) {
			return false;
		}
		$dir = trailingslashit($uploads['basedir']) . $relative;
		if (!is_dir($dir)) {
			return false;
		}

		$deleted = 0;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $file) {
				if (!$file->isFile()) {
					continue;
				}
				if (!in_array(strtolower($file->getExtension()), array('css', 'js', 'map', 'min'), true)) {
					continue;
				}

				wp_delete_file($file->getPathname());
				if (!file_exists($file->getPathname())) {
					$deleted++;
				}
			}
		} catch (\Throwable $e) {
			return false;
		}

		return $deleted > 0;
	}

	private function table_exists($table)
	{
		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	}

	private function replace_urls_in_table_widgets_only($table, $id_column, $value_column, $old_values, $new_values)
	{

		$guard = 0;
		while (
			!$this->replace_urls_in_table_widgets_chunk(
				$table,
				$id_column,
				$value_column,
				$old_values,
				$new_values,
				20,
				256 * 1024
			) && $guard < 500
		) {
			$guard++;
		}
	}

	private function replace_urls_in_table_widgets_chunk(
		$table,
		$id_column,
		$value_column,
		$old_values,
		$new_values,
		$time_limit,
		$max_value_bytes
	) {
		global $wpdb;

		$after_id = (int) $this->get('url_replace_widget_after_id', 0);
		$limit = 25;
		$start_time = microtime(true);

		if ($after_id === 0) {
			$this->log('Processing widget and theme options for URL replacements (chunked)...');
		}

		$widget_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$id_column}, option_name, {$value_column} FROM {$table}
				WHERE ({$id_column} > %d) AND (option_name = %s OR option_name LIKE %s OR option_name LIKE %s)
				ORDER BY {$id_column} ASC LIMIT %d",
				$after_id,
				'sidebars_widgets',
				'widget_%',
				'theme_mods_%',
				$limit
			),
			ARRAY_A
		);

		if (empty($widget_options)) {
			return true;
		}

		$total_processed = 0;
		$last_id = $after_id;
		foreach ($widget_options as $row) {
			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}
			$row_id = isset($row[$id_column]) ? (int) $row[$id_column] : 0;
			if ($row_id > $last_id) {
				$last_id = $row_id;
			}
			$original = $row[$value_column];
			if (!is_string($original) || $original === '') {
				continue;
			}
			if (strlen($original) > $max_value_bytes) {
				$replaced = str_replace($old_values, $new_values, $original);
			} else {
				$replaced = $this->replace_serialized_value($original, $old_values, $new_values);
			}
			if ($replaced !== $original) {
				$wpdb->update(
					$table,
					array($value_column => $replaced),
					array($id_column => $row[$id_column])
				);
				$total_processed++;
				$this->log(sprintf('Updated option: %s', $row['option_name']));
			}
		}

		$this->update(array('url_replace_widget_after_id' => max($after_id, $last_id)));
		$this->log(
			sprintf(
				'Widget/theme URL chunk: after_id=%d fetched=%d updated=%d',
				$after_id,
				count($widget_options),
				$total_processed
			)
		);

		return count($widget_options) < $limit;
	}

	private function replace_urls_in_table($table, $id_column, $value_column, $old_url, $old_values, $new_values)
	{
		global $wpdb;

		$total_processed = 0;

		if ($table === $wpdb->options) {
			$this->log('Processing widget and theme options for URL replacements...');

			$widget_options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_column}, option_name, {$value_column} FROM {$table} WHERE option_name = %s OR option_name LIKE %s OR option_name LIKE %s",
					'sidebars_widgets',
					'widget_%',
					'theme_mods_%'
				),
				ARRAY_A
			);

			foreach ($widget_options as $row) {
				$original = $row[$value_column];
				$replaced = $this->replace_serialized_value($original, $old_values, $new_values);

				if ($replaced !== $original) {
					$wpdb->update(
						$table,
						array($value_column => $replaced),
						array($id_column => $row[$id_column])
					);
					$total_processed++;
					$this->log(sprintf('Updated option: %s', $row['option_name']));
				}
			}

			if (!empty($widget_options)) {
				$this->log(sprintf('Processed %d widget/theme options for URL replacements.', count($widget_options)));
			}
		}

		$like = '%' . $wpdb->esc_like($old_url) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$id_column}, {$value_column} FROM {$table} WHERE {$value_column} LIKE %s",
				$like
			),
			ARRAY_A
		);

		if ($table === $wpdb->options) {

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_column}, option_name, {$value_column} FROM {$table} WHERE {$value_column} LIKE %s",
					$like
				),
				ARRAY_A
			);
		}

		if (!empty($rows)) {
			foreach ($rows as $row) {

				$critical_options = array('permalink_structure', 'blogname', 'blogdescription');
				if (isset($row['option_name']) && in_array($row['option_name'], $critical_options, true)) {
					continue;
				}

				$original = $row[$value_column];
				$replaced = $this->replace_serialized_value($original, $old_values, $new_values);

				if ($replaced === $original) {
					continue;
				}

				$wpdb->update(
					$table,
					array($value_column => $replaced),
					array($id_column => $row[$id_column])
				);
				$total_processed++;
			}
		}

		if ($total_processed > 0) {
			$this->log(sprintf('Processed %d total rows in %s for URL replacements.', $total_processed, $table));
		}
	}

	private function replace_serialized_value($value, $old_values, $new_values)
	{
		$url_map = array('old' => $old_values, 'new' => $new_values);

		$path_map = array('old' => array(), 'new' => array());
		$manifest = $this->get('manifest', array());
		if (!empty($manifest['server']['abspath'])) {
			$old_abspath = rtrim(wp_normalize_path($manifest['server']['abspath']), '/');
			$new_abspath = rtrim(wp_normalize_path(ABSPATH), '/');
			if ($old_abspath !== $new_abspath && !empty($old_abspath)) {
				$path_map['old'][] = $old_abspath;
				$path_map['new'][] = $new_abspath;
			}
		}

		return \Snapshoter\Import\ThemeBuilderHealer::deep_replace($value, $url_map, $path_map);
	}

	private function recursive_url_replace($data, $old_values, $new_values)
	{
		if (is_string($data)) {
			return str_replace($old_values, $new_values, $data);
		}

		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->recursive_url_replace($value, $old_values, $new_values);
			}
			return $data;
		}

		if (is_object($data)) {

			try {

				$class_name = @get_class($data);

				foreach ($data as $key => $value) {
					$data->$key = $this->recursive_url_replace($value, $old_values, $new_values);
				}
			} catch (\Throwable $e) {

				$class_name = @get_class($data);
				$this->log(sprintf('Warning: Skipping URL replacement for incomplete object (class: %s): %s', $class_name ?: 'unknown', $e->getMessage()));
				return $data;
			}
			return $data;
		}

		return $data;
	}

	private function apply_snapshoter_url_replacements($statement, $replacements)
	{
		if (empty($replacements['old']) || empty($replacements['new'])) {
			return $statement;
		}

		$statement = str_replace($replacements['old'], $replacements['new'], $statement);

		return $statement;
	}

	private function generate_post_finalize_notices($permalink_structure)
	{
		$notices = array();
		$manifest = $this->get('manifest', array());

		$stored_manifest = get_option('SNAPSHOTER_last_import_manifest', null);
		$has_stored_manifest = !empty($stored_manifest) && is_array($stored_manifest);

		if (empty($manifest) && $has_stored_manifest) {
			$manifest = $stored_manifest;
		}

		$has_site_settings = isset($manifest['site_settings']) && !empty($manifest['site_settings']);

		$permalink_corrupted = false;
		if (empty($permalink_structure)) {
			$permalink_corrupted = true;
		} else {

			if (
				preg_match('/[a-f0-9]{32,}/i', $permalink_structure) ||
				!preg_match('/%[a-z_]+%/', $permalink_structure)
			) {
				$permalink_corrupted = true;
			}
		}

		if (!empty($permalink_structure) && !$permalink_corrupted) {
			$permalink_url = admin_url('options-permalink.php#submit');
			$notices[] = array(
				'type' => 'permalink',
				'message' => sprintf(

					// translators: %s: permalinks admin URL.
					__('Permalink structure has been restored. If you experience 404 errors, try saving permalinks: <a href="%s" target="_blank" rel="noopener noreferrer">Open Permalink Settings</a>', 'snapshoter'),
					esc_url($permalink_url)
				),
			);
		} else if ($permalink_corrupted) {
			$notices[] = array(
				'type' => 'warning',
				'message' => __('Permalink structure may not have been restored correctly. Please try saving permalinks manually.', 'snapshoter'),
			);
		}

		if (empty($permalink_structure) || $permalink_corrupted) {
			$notices[] = array(
				'type' => 'info',
				'message' => __('No valid permalink structure was detected in the backup. WordPress will use the default until you save a new structure.', 'snapshoter'),
			);
		}

		foreach ($this->detect_plugin_post_restore_warnings() as $warning) {
			$notices[] = array(
				'type' => 'warning',
				'message' => $warning,
			);
		}

		$graceful_warnings = $this->get('graceful_warnings', array());
		if (is_array($graceful_warnings)) {
			foreach ($graceful_warnings as $warning) {
				if (empty($warning)) {
					continue;
				}
				$notices[] = array(
					'type' => 'warning',
					'message' => $warning,
				);
			}
		}

		return $notices;
	}

	private function detect_plugin_post_restore_warnings()
	{
		global $wpdb;

		$warnings = array();
		$active_plugins_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'active_plugins'
			)
		);

		$active_plugins = maybe_unserialize($active_plugins_serialized);
		if (!is_array($active_plugins)) {
			$active_plugins = array();
		}

		$plugin_labels = $this->get_plugin_labels($active_plugins);

		$security_slugs = array(
			'wordfence',
			'really-simple-ssl',
			'really-simple-security',
			'better-wp-security',
			'ithemes-security',
			'sucuri',
			'all-in-one-wp-security',
			'wp-cerber',
			'cerber',
			'defender-security',
			'wp-simple-firewall',
			'shield-security',
			'limit-login-attempts',
			'two-factor',
			'wps-hide-login',
			'rename-wp-login',
		);
		$ssl_slugs = array(
			'really-simple-ssl',
			'really-simple-security',
			'wordpress-https',
			'ssl-insecure-content-fixer',
			'wp-force-ssl',
			'force-https',
			'cloudflare-flexible-ssl',
		);
		$cache_slugs = array(
			'wp-rocket',
			'w3-total-cache',
			'litespeed-cache',
			'wp-super-cache',
			'autoptimize',
			'cache-enabler',
			'sg-cachepress',
			'breeze',
			'nitropack',
			'swift-performance',
			'wp-fastest-cache',
			'hummingbird-performance',
		);
		$maintenance_slugs = array(
			'maintenance',
			'coming-soon',
			'under-construction',
			'seedprod',
			'cmp-coming-soon-maintenance',
		);

		$security_hits = $this->find_plugins_by_slugs_or_words(
			$plugin_labels,
			$security_slugs,
			array('wordfence', 'sucuri', 'cerber', 'ithemes-security', 'all-in-one-wp-security', 'hide-login', 'limit-login', 'two-factor', '2fa')
		);
		$ssl_hits = $this->find_plugins_by_slugs_or_words(
			$plugin_labels,
			$ssl_slugs,
			array('really-simple-ssl', 'force-ssl', 'force-https', 'flexible-ssl', 'insecure-content')
		);
		$cache_hits = $this->find_plugins_by_slugs_or_words(
			$plugin_labels,
			$cache_slugs,
			array('wp-rocket', 'litespeed-cache', 'w3-total-cache', 'super-cache', 'autoptimize', 'nitropack', 'fastest-cache')
		);
		$maintenance_hits = $this->find_plugins_by_slugs_or_words(
			$plugin_labels,
			$maintenance_slugs,
			array('coming-soon', 'under-construction', 'maintenance-mode')
		);

		if (!empty($security_hits)) {
			$warnings[] = sprintf(

				// translators: %s: plugin names.
				__('Login or security plugins detected (%s). If you experience redirects or lockouts after the import, temporarily disable them and log in again.', 'snapshoter'),
				implode(', ', $security_hits)
			);
		}

		$ssl_only = array_values(array_diff($ssl_hits, $security_hits));
		if (!empty($ssl_only)) {
			$warnings[] = sprintf(

				// translators: %s: plugin names.
				__('SSL/redirect plugins detected (%s). Verify the restored site opens over the desired protocol before re-enabling strict redirects.', 'snapshoter'),
				implode(', ', $ssl_only)
			);
		} elseif (!empty($ssl_hits) && empty($security_hits)) {
			$warnings[] = sprintf(

				// translators: %s: plugin names.
				__('SSL/redirect plugins detected (%s). Verify the restored site opens over the desired protocol before re-enabling strict redirects.', 'snapshoter'),
				implode(', ', $ssl_hits)
			);
		}

		if (!empty($cache_hits)) {
			$warnings[] = sprintf(

				// translators: %s: plugin names.
				__('Caching or optimization plugins detected (%s). Clear their caches after the import so new URLs and assets propagate.', 'snapshoter'),
				implode(', ', $cache_hits)
			);
		}

		if (!empty($maintenance_hits)) {
			$warnings[] = sprintf(

				// translators: %s: plugin names.
				__('Maintenance/coming-soon plugins detected (%s). Disable them if you expect the site to be publicly accessible.', 'snapshoter'),
				implode(', ', $maintenance_hits)
			);
		}

		if (!is_ssl()) {
			$force_ssl = get_option('woocommerce_force_ssl_checkout');
			if ('yes' === $force_ssl) {
				$warnings[] = __('WooCommerce is configured to force HTTPS checkout, but this site currently loads over HTTP. Update WooCommerce ▸ Settings ▸ Advanced ▸ "Force secure checkout" after confirming HTTPS is available.', 'snapshoter');
			}
		}

		return $warnings;
	}

	private function record_graceful_warning($message)
	{
		if (empty($message)) {
			return;
		}

		if (function_exists('wp_strip_all_tags')) {
			$message = wp_strip_all_tags($message);
		}

		$warnings = $this->get('graceful_warnings', array());
		if (!is_array($warnings)) {
			$warnings = array();
		}

		if (in_array($message, $warnings, true)) {
			return;
		}

		$warnings[] = $message;
		$this->update(
			array(
				'graceful_warnings' => $warnings,
			)
		);
		$this->log(sprintf('Warning recorded for UI: %s', $message));
	}

	private function get_plugin_display_name($plugin_basename)
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		if (isset($plugins[$plugin_basename]['Name'])) {
			return $plugins[$plugin_basename]['Name'];
		}

		return $plugin_basename;
	}

	private function enforce_target_site_urls($context = 'general')
	{
		global $wpdb;

		list($target_site_url, $target_home_url) = $this->get_target_site_urls();

		$current_site_url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'siteurl'
			)
		);
		$current_home_url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'home'
			)
		);

		$updated = false;

		if (!$this->urls_match($current_site_url, $target_site_url)) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'siteurl'",
					esc_url_raw($target_site_url)
				)
			);
			$updated = true;
		}

		if (!$this->urls_match($current_home_url, $target_home_url)) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'home'",
					esc_url_raw($target_home_url)
				)
			);
			$updated = true;
		}

		if ($updated) {
			$this->log(
				sprintf(
					'Site URL lock enforced (%s). siteurl=%s, home=%s',
					$context,
					$target_site_url,
					$target_home_url
				)
			);

		}
	}

	private function get_target_site_urls()
	{
		$target_site_url = $this->get('target_site_url');
		$target_home_url = $this->get('target_home_url');

		if (empty($target_site_url) || empty($target_home_url)) {
			$target_site_url = site_url();
			$target_home_url = home_url();
			$this->update(
				array(
					'target_site_url' => $target_site_url,
					'target_home_url' => $target_home_url,
				)
			);
			$this->log(
				sprintf(
					'Target site URLs cached mid-import: siteurl=%s, home=%s',
					$target_site_url,
					$target_home_url
				)
			);
		}

		return array($target_site_url, $target_home_url);
	}

	private function urls_match($a, $b)
	{
		if (empty($a) && empty($b)) {
			return true;
		}

		if (empty($a) || empty($b)) {
			return false;
		}

		$normalized_a = strtolower(untrailingslashit(trim($a)));
		$normalized_b = strtolower(untrailingslashit(trim($b)));

		return $normalized_a === $normalized_b;
	}

	private function remap_attachment_id($old_attachment_id)
	{
		global $wpdb;

		if (!is_numeric($old_attachment_id) || $old_attachment_id <= 0) {
			return false;
		}

		$attachment = $wpdb->get_row($wpdb->prepare(
			"SELECT ID, guid, post_type FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'",
			$old_attachment_id
		));

		if ($attachment) {

			$file_path = get_attached_file($old_attachment_id);
			if ($file_path && file_exists($file_path)) {
				return (int) $old_attachment_id;
			}
		}

		$manifest = $this->get('manifest', array());
		$source_url = isset($manifest['site_url']) ? $manifest['site_url'] : '';

		if ($source_url) {

			$attachments = $wpdb->get_results(
				"SELECT ID, guid, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC"
			);

			foreach ($attachments as $attachment) {
				$file_path = get_attached_file($attachment->ID);
				if ($file_path && file_exists($file_path)) {

					if ($attachment->ID == $old_attachment_id) {
						return (int) $attachment->ID;
					}
				}
			}
		}

		return false;
	}

	private function remap_attachment_ids_in_data($data)
	{
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->remap_attachment_ids_in_data($value);
			}
		} elseif (is_object($data)) {
			foreach (get_object_vars($data) as $key => $value) {
				$data->$key = $this->remap_attachment_ids_in_data($value);
			}
		} elseif (is_numeric($data) && $data > 0 && $data < 1000000) {

			$remapped = $this->remap_attachment_id($data);
			if ($remapped && $remapped !== $data) {
				return $remapped;
			}
		}

		return $data;
	}

	private function get_plugin_labels($active_plugins)
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$labels = array();

		foreach ($active_plugins as $plugin_file) {
			if (isset($all_plugins[$plugin_file]['Name'])) {
				$labels[$plugin_file] = $all_plugins[$plugin_file]['Name'];
			} else {
				$labels[$plugin_file] = $plugin_file;
			}
		}

		return $labels;
	}

	private function find_plugins_by_slugs_or_words(array $plugin_labels, array $slugs, array $words = array())
	{
		$matches = array();
		$slugs = array_map('strtolower', $slugs);
		$words = array_map('strtolower', $words);

		foreach ($plugin_labels as $plugin_file => $label) {
			$file = strtolower((string) $plugin_file);
			$name = strtolower((string) $label);
			$folder = strtolower((string) dirname(str_replace('\\', '/', $file)));
			if ($folder === '.' || $folder === '') {
				$folder = preg_replace('/\.php$/', '', $file);
			}

			$hit = false;
			foreach ($slugs as $slug) {
				if ($slug === '') {
					continue;
				}
				if (
					$folder === $slug ||
					strpos($folder, $slug) === 0 ||
					strpos($file, $slug . '/') === 0 ||
					strpos($file, $slug . '-') === 0
				) {
					$hit = true;
					break;
				}
			}
			if (!$hit) {
				foreach ($words as $word) {
					if ($word === '') {
						continue;
					}
					$pattern = '/(^|[^a-z0-9])' . preg_quote($word, '/') . '([^a-z0-9]|$)/i';
					if (preg_match($pattern, $folder) || preg_match($pattern, $file) || preg_match($pattern, $name)) {
						$hit = true;
						break;
					}
				}
			}

			if ($hit) {
				$matches[] = $label;
			}
		}

		return array_values(array_unique($matches));
	}

	private function find_plugin_matches_by_keywords($plugin_labels, $keywords)
	{
		return $this->find_plugins_by_slugs_or_words($plugin_labels, array(), $keywords);
	}

	private function activate_plugins_from_manifest($plugins)
	{
		global $wpdb;

		if (empty($plugins) || !is_array($plugins)) {
			return;
		}

		if (!function_exists('validate_plugin')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$snapshoter_basename = defined('SNAPSHOTER_BASE_NAME')
			? SNAPSHOTER_BASE_NAME
			: (defined('SNAPSHOTER_FILE') ? plugin_basename(SNAPSHOTER_FILE) : 'snapshoter/snapshoter.php');
		$active_plugins = array($snapshoter_basename);
		update_option('active_plugins', $active_plugins);
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('active_plugins', 'options');
		}

		foreach ($plugins as $plugin) {
			try {
				$discovered = $this->discover_plugin_basename($plugin);
				if (!$discovered) {
					$discovered = $plugin;
				}

				if (in_array($discovered, $active_plugins, true)) {
					continue;
				}

				$display_name = $this->get_plugin_display_name($discovered);
				$validation = validate_plugin($discovered);

				if (is_wp_error($validation)) {
					$this->log(sprintf('Skipping invalid plugin: %s. Error: %s', $discovered, $validation->get_error_message()));
					$this->record_graceful_warning(
						sprintf(

							// translators: %1$s: plugin name.
							__('Skipped plugin %1$s because its files were not found on the target site.', 'snapshoter'),
							esc_html($display_name)
						)
					);
					continue;
				}

				$plugin_path = trailingslashit(WP_PLUGIN_DIR) . $discovered;

				$result = activate_plugin($discovered, '', false, true);
				if (is_wp_error($result)) {
					$this->log(sprintf('Skipping plugin %s: %s', $discovered, $result->get_error_message()));
					$this->record_graceful_warning(
						sprintf(

							// translators: %1$s: plugin name; %2$s: reason.
							__('Skipped plugin %1$s: %2$s', 'snapshoter'),
							esc_html($display_name),
							esc_html($result->get_error_message())
						)
					);
					continue;
				}

				$active_plugins[] = $discovered;
				$this->log(sprintf('Activated plugin: %s', $discovered));
			} catch (\Throwable $plugin_error) {
				if (!empty($discovered) && function_exists('deactivate_plugins')) {
					deactivate_plugins($discovered, true);
				}
				$this->record_graceful_warning(
					sprintf(

						// translators: %1$s: plugin name; %2$s: error message.
						__('Skipped plugin %1$s because it caused an error during activation: %2$s', 'snapshoter'),
						esc_html(isset($display_name) ? $display_name : (string) $plugin),
						esc_html($plugin_error->getMessage())
					)
				);
				$this->log(sprintf(
					'Warning: Failed to activate plugin %s due to error: %s',
					isset($discovered) ? $discovered : (string) $plugin,
					$plugin_error->getMessage()
				));
				continue;
			}
		}

		if (!in_array($snapshoter_basename, $active_plugins, true)) {
			$active_plugins[] = $snapshoter_basename;
		}
		$active_plugins = array_values(array_unique($active_plugins));
		sort($active_plugins);
		update_option('active_plugins', $active_plugins);
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('active_plugins', 'options');
			wp_cache_delete('plugins', 'plugins');
		}
		$this->log(sprintf('Saved %d active plugins matching backup manifest (after safe activation).', count($active_plugins)));
	}

	private function mirror_prune_chunk()
	{
		$manifest = $this->get('manifest', array());
		$manifest_scope = isset($manifest['scope']) ? (string) $manifest['scope'] : 'full';
		if ($manifest_scope === 'database') {
			$this->log('Database-only restore detected. Skipping exact-mirror file prune.');
			return true;
		}

		$queue_path = $this->store->job_dir($this->job_id) . '/mirror_prune_queue.json';

		if (!file_exists($queue_path)) {
			$this->log('Building exact-mirror deletion queue from restore whitelist...');
			$candidates = $this->build_mirror_prune_candidates();
			file_put_contents($queue_path, wp_json_encode($candidates));
			$this->update(
				array(
					'mirror_prune_offset' => 0,
					'mirror_prune_total'  => count($candidates),
				)
			);
			$this->log(sprintf('Exact-mirror queue ready: %d orphan path(s) to remove.', count($candidates)));
			return empty($candidates);
		}

		$candidates = json_decode((string) file_get_contents($queue_path), true);
		if (!is_array($candidates)) {
			$candidates = array();
		}

		$total = count($candidates);
		$offset = (int) $this->get('mirror_prune_offset', 0);
		if ($offset >= $total) {
			return true;
		}

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 20 : 10;
		$start_time = microtime(true);
		$batch_size = 250;
		$batch = array_slice($candidates, $offset, $batch_size);
		$processed = 0;
		$abspath = trailingslashit(wp_normalize_path(ABSPATH));

		foreach ($batch as $relative) {
			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}

			$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
			if ($relative === '' || $this->is_mirror_protected_relative($relative)) {
				$processed++;
				continue;
			}

			$target = $abspath . $relative;

			$real_target = realpath($target);
			$real_root = realpath(ABSPATH);
			if ($real_target && $real_root && strpos($real_target, $real_root) !== 0) {
				$processed++;
				continue;
			}

			if (is_dir($target)) {
				$this->recursive_rmdir($target);
				$this->log(sprintf('[Exact-Mirror] Removed orphan directory: %s', $relative));
			} elseif (is_file($target) || is_link($target)) {
				wp_delete_file($target);
				$this->log(sprintf('[Exact-Mirror] Removed orphan file: %s', $relative));
			}
			$processed++;
		}

		$offset += $processed;
		$this->update(array('mirror_prune_offset' => $offset));

		if ($offset >= $total) {
			$this->log(sprintf('Exact-mirror prune finished. Removed %d orphan path(s).', $total));
			return true;
		}

		$this->log(sprintf('Exact-mirror prune progress: %d / %d', $offset, $total));
		return false;
	}

	private function ensure_mirror_prune_whitelist()
	{
		$queue_path = $this->store->job_dir($this->job_id) . '/restore_queue.json';
		if (file_exists($queue_path)) {
			$decoded = json_decode((string) file_get_contents($queue_path), true);
			if (is_array($decoded) && !empty($decoded)) {
				return $decoded;
			}
		}

		$stored = $this->get('restore_queue', array());
		if (is_array($stored) && !empty($stored)) {
			return $stored;
		}

		$from_extract = $this->build_restore_queue_from_extraction_queue();
		if (!empty($from_extract)) {
			file_put_contents($queue_path, wp_json_encode($from_extract));
			$this->update(
				array(
					'restore_queue'  => $from_extract,
					'total_restore'  => count($from_extract),
				)
			);
			$this->log(
				sprintf(
					'Mirror prune: built restore whitelist from extraction list (%d path(s)).',
					count($from_extract)
				)
			);
			return $from_extract;
		}

		return array();
	}

	private function build_mirror_prune_candidates()
	{
		$restore_queue = $this->ensure_mirror_prune_whitelist();
		if (!is_array($restore_queue)) {
			$restore_queue = array();
		}

		if (empty($restore_queue)) {
			$this->log('[Exact-Mirror] Restore queue empty - refusing orphan wipe to avoid data loss.');
			return array();
		}

		$allowed_files = array();
		$allowed_dirs = array();
		$allowed_plugin_slugs = array('snapshoter' => true);
		$allowed_theme_slugs = array(
			'twentytwentyfive' => true,
			'twentytwentyfour' => true,
			'twentytwentythree' => true,
			\Snapshoter\Import\RestoreSafeMode::EMERGENCY_THEME => true,
		);

		foreach ($restore_queue as $rel) {
			$rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
			if ($rel === '') {
				continue;
			}

			if (strpos($rel, 'files/') === 0) {
				$rel = substr($rel, 6);
			}
			$allowed_files[$rel] = true;

			$parts = explode('/', $rel);
			$cursor = '';
			$count = count($parts);
			for ($i = 0; $i < $count - 1; $i++) {
				$cursor = ($cursor === '') ? $parts[$i] : ($cursor . '/' . $parts[$i]);
				$allowed_dirs[$cursor] = true;
			}

			if (preg_match('#^wp-content/plugins/([^/]+)#i', $rel, $m)) {
				$allowed_plugin_slugs[strtolower($m[1])] = true;
			} elseif (preg_match('#^plugins/([^/]+)#i', $rel, $m)) {
				$allowed_plugin_slugs[strtolower($m[1])] = true;
			}
			if (preg_match('#^wp-content/themes/([^/]+)#i', $rel, $m)) {
				$allowed_theme_slugs[strtolower($m[1])] = true;
			} elseif (preg_match('#^themes/([^/]+)#i', $rel, $m)) {
				$allowed_theme_slugs[strtolower($m[1])] = true;
			}
		}

		foreach ((array) $this->get('manifest_plugin_slugs', array()) as $slug) {
			$allowed_plugin_slugs[strtolower((string) $slug)] = true;
		}
		foreach ((array) $this->get('manifest_theme_slugs', array()) as $slug) {
			$allowed_theme_slugs[strtolower((string) $slug)] = true;
		}

		$manifest = $this->get('manifest', array());
		if (!empty($manifest['plugins']) && is_array($manifest['plugins'])) {
			foreach ($manifest['plugins'] as $p) {
				$parts = explode('/', str_replace('\\', '/', (string) $p));
				if (!empty($parts[0])) {
					$allowed_plugin_slugs[strtolower($parts[0])] = true;
				}
			}
		}
		if (!empty($manifest['template'])) {
			$allowed_theme_slugs[strtolower((string) $manifest['template'])] = true;
		}
		if (!empty($manifest['stylesheet'])) {
			$allowed_theme_slugs[strtolower((string) $manifest['stylesheet'])] = true;
		}

		$candidates = array();
		$abspath = trailingslashit(wp_normalize_path(ABSPATH));

		$plugins_dir = defined('WP_PLUGIN_DIR') ? wp_normalize_path(WP_PLUGIN_DIR) : ($abspath . 'wp-content/plugins');
		if (is_dir($plugins_dir)) {
			$entries = @scandir($plugins_dir);
			if (is_array($entries)) {
				foreach ($entries as $entry) {
					if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
						continue;
					}
					$slug = strtolower((string) $entry);
					if ($slug === 'snapshoter' || $slug === 'snapshoter-pro') {
						continue;
					}
					if (!isset($allowed_plugin_slugs[$slug])) {
						$rel = ltrim(str_replace($abspath, '', wp_normalize_path($plugins_dir . '/' . $entry)), '/');
						$candidates[] = $rel;
					}
				}
			}
		}

		$themes_dir = defined('WP_CONTENT_DIR')
			? wp_normalize_path(WP_CONTENT_DIR . '/themes')
			: ($abspath . 'wp-content/themes');
		if (is_dir($themes_dir)) {
			$entries = @scandir($themes_dir);
			if (is_array($entries)) {
				foreach ($entries as $entry) {
					if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
						continue;
					}
					if (!isset($allowed_theme_slugs[strtolower($entry)])) {
						$rel = ltrim(str_replace($abspath, '', wp_normalize_path($themes_dir . '/' . $entry)), '/');
						$candidates[] = $rel;
					}
				}
			}
		}

		$this->resolve_exact_mirror_policy();
		$exact_mirror = !empty($this->get('exact_mirror'));
		if (!$exact_mirror) {
			$this->log('[Fast Restore] Skipping uploads orphan wipe (enable Exact Mirror to remove leftover media).');
		} else {
		$uploads = wp_upload_dir(null, false);
		$uploads_basedir = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : ($abspath . 'wp-content/uploads');
		if (is_dir($uploads_basedir)) {
			try {
				$allowed_upload_prefixes = array();
				foreach (array_keys($allowed_files) as $f) {
					if (strpos($f, 'wp-content/uploads/') !== 0 && strpos($f, 'uploads/') !== 0) {
						continue;
					}
					$parts = explode('/', $f);
					$cursor = '';
					foreach ($parts as $part) {
						$cursor = ($cursor === '') ? $part : ($cursor . '/' . $part);
						$allowed_upload_prefixes[$cursor] = true;
					}
				}
				foreach (array_keys($allowed_dirs) as $d) {
					if (strpos($d, 'wp-content/uploads/') === 0 || strpos($d, 'uploads/') === 0) {
						$allowed_upload_prefixes[$d] = true;
					}
				}

				$stack = array($uploads_basedir);
				while (!empty($stack)) {
					$current = array_pop($stack);
					$entries = @scandir($current);
					if (!is_array($entries)) {
						continue;
					}
					foreach ($entries as $entry) {
						if ($entry === '.' || $entry === '..') {
							continue;
						}
						$full = wp_normalize_path($current . '/' . $entry);
						$rel = ltrim(str_replace($abspath, '', $full), '/');
						if ($rel === '' || $this->is_mirror_protected_relative($rel)) {
							continue;
						}

						if (is_dir($full)) {
							if (!isset($allowed_upload_prefixes[$rel])) {

								$candidates[] = $rel;
								continue;
							}
							$stack[] = $full;
							continue;
						}

						if (!isset($allowed_files[$rel])) {
							$candidates[] = $rel;
						}
					}
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('[Exact-Mirror] Uploads scan notice: %s', $e->getMessage()));
			}
		}
		}

		$candidates = array_values(array_unique($candidates));
		usort(
			$candidates,
			static function ($a, $b) {
				return substr_count($b, '/') - substr_count($a, '/');
			}
		);

		return $candidates;
	}

	private function is_mirror_protected_relative($rel)
	{
		$rel = strtolower(str_replace('\\', '/', (string) $rel));
		if ($rel === '' || $rel === '.' || $rel === '..') {
			return true;
		}
		if (strpos($rel, '..') !== false) {
			return true;
		}
		if (preg_match('#(^|/)wp-content/plugins/snapshoter(-pro)?(/|$)#', $rel)) {
			return true;
		}
		if (preg_match('#(^|/)wp-content/uploads/snapshoter(/|$)#', $rel)) {
			return true;
		}
		if (preg_match('#wp-content/uploads/.*/snapshoter(/|$)#', $rel)) {
			return true;
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Mirror prune policy (Exact Mirror vs Fast Restore)
	// -------------------------------------------------------------------------

	/**
	 * Exact Mirror removes uploads orphans; Fast Restore keeps uploads (same site).
	 *
	 * @return bool
	 */
	private function resolve_exact_mirror_policy()
	{
		if (!empty($this->get('exact_mirror'))) {
			return true;
		}

		if ($this->is_foreign_site_origin_restore()) {
			$this->update(
				array(
					'exact_mirror'        => 1,
					'exact_mirror_auto'   => 1,
					'exact_mirror_reason' => 'foreign_origin',
				)
			);
			$this->log('Foreign site origin - Exact Mirror (uploads orphan wipe).');
			return true;
		}

		if (!$this->is_cross_site_restore()) {
			return false;
		}

		$this->update(
			array(
				'exact_mirror'        => 1,
				'exact_mirror_auto'   => 1,
				'exact_mirror_reason' => 'cross_site',
			)
		);
		$this->log('Cross-site restore - Exact Mirror (uploads orphan wipe).');

		return true;
	}

	/**
	 * @param string $url Site or home URL.
	 * @return string Normalized host, or empty.
	 */
	private function normalize_site_host($url)
	{
		$host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
		if ($host !== '' && strpos($host, 'www.') === 0) {
			$host = substr($host, 4);
		}
		return $host;
	}

	/**
	 * @return string Host from archive manifest.
	 */
	private function get_archive_origin_host()
	{
		$manifest = $this->get('manifest', array());
		if (!is_array($manifest)) {
			$manifest = array();
		}
		$source = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
		if ($source === '' && isset($manifest['home_url'])) {
			$source = (string) $manifest['home_url'];
		}
		return $this->normalize_site_host($source);
	}

	/** Persist last successful restore origin for mirror policy. */
	private function remember_restore_origin()
	{
		$manifest = $this->get('manifest', array());
		if (!is_array($manifest)) {
			$manifest = array();
		}
		$site_url = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
		$home_url = isset($manifest['home_url']) ? (string) $manifest['home_url'] : $site_url;
		$host     = $this->normalize_site_host($site_url !== '' ? $site_url : $home_url);
		if ($host === '') {
			return;
		}

		update_option(
			'snapshoter_last_restore_origin',
			array(
				'host'       => $host,
				'site_url'   => $site_url,
				'home_url'   => $home_url,
				'restored_at'=> gmdate('c'),
			),
			false
		);
		$this->log(sprintf('Restore origin host: %s', $host));
	}

	/** @return bool */
	private function is_foreign_site_origin_restore()
	{
		$archive_host = $this->get_archive_origin_host();
		if ($archive_host === '') {
			return true;
		}

		$stored = get_option('snapshoter_last_restore_origin', null);
		if (!is_array($stored) || empty($stored['host'])) {
			return false;
		}

		$last_host = $this->normalize_site_host(
			isset($stored['site_url']) && (string) $stored['site_url'] !== ''
				? (string) $stored['site_url']
				: (string) $stored['host']
		);
		if ($last_host === '') {
			$last_host = strtolower((string) $stored['host']);
			if ($last_host !== '' && strpos($last_host, 'www.') === 0) {
				$last_host = substr($last_host, 4);
			}
		}

		if ($last_host === '') {
			return false;
		}

		return $archive_host !== $last_host;
	}

	/**
	 * @return bool True when archive host differs from target (or unknown).
	 */
	private function is_cross_site_restore()
	{
		$source_host = $this->get_archive_origin_host();

		list($target_site_url) = $this->get_target_site_urls();
		$target_host = $this->normalize_site_host((string) $target_site_url);

		if ($source_host === '' || $target_host === '') {
			return true;
		}

		return $source_host !== $target_host;
	}

	private function prune_orphan_plugins_and_themes($manifest)
	{
		unset($manifest);

		$this->mirror_prune_chunk();
	}

	private function discover_plugin_basename($basename)
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		if (empty($plugins)) {
			return false;
		}

		foreach ($plugins as $plugin => $info) {
			if (strpos(dirname($plugin), dirname($basename)) !== false) {
				if (basename($plugin) === basename($basename)) {
					return $plugin;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve a site-relative restore path under ABSPATH, or null if unsafe.
	 *
	 * @param string $relative Relative path from archive.
	 * @return string|null Absolute path.
	 */
	private function safe_restore_target($relative)
	{
		$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
		$root = rtrim(\Snapshoter\Core\PathSafety::normalize_fs_path(ABSPATH), '/');
		return \Snapshoter\Core\PathSafety::resolve_under_root($root, $relative);
	}

	private function ensure_SNAPSHOTER_active()
	{
		global $wpdb;

		$plugin_file = defined('SNAPSHOTER_PLUGIN_FILE') ? plugin_basename(SNAPSHOTER_PLUGIN_FILE) : 'snapshoter/snapshoter.php';

		$active_plugins_serialized = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'active_plugins'
			)
		);

		$active_plugins = maybe_unserialize($active_plugins_serialized);
		if (!is_array($active_plugins)) {
			$active_plugins = array();
		}

		$found = false;
		foreach ($active_plugins as $ap) {
			if ($ap === $plugin_file) {
				$found = true;
				break;
			}
		}

		if (!$found) {
			$active_plugins[] = $plugin_file;
			sort($active_plugins);
			update_option('active_plugins', $active_plugins);
			$this->log(sprintf('Ensured Snapshoter Backup plugin (%s) remains active using update_option().', $plugin_file));
		}
	}

	private function clear_snapshoter_transients_only()
	{
		global $wpdb;
		if (!isset($wpdb) || !is_object($wpdb)) {
			return;
		}

		$likes = array(
			$wpdb->esc_like('_transient_snapshoter_') . '%',
			$wpdb->esc_like('_transient_timeout_snapshoter_') . '%',
			$wpdb->esc_like('_transient_SNAPSHOTER_') . '%',
			$wpdb->esc_like('_transient_timeout_SNAPSHOTER_') . '%',
			$wpdb->esc_like('_site_transient_snapshoter_') . '%',
			$wpdb->esc_like('_site_transient_timeout_snapshoter_') . '%',
			$wpdb->esc_like('_site_transient_SNAPSHOTER_') . '%',
			$wpdb->esc_like('_site_transient_timeout_SNAPSHOTER_') . '%',
		);

		foreach ($likes as $like) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
		}

		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
	}

	private function flush_all_caches()
	{

		wp_cache_flush();
		$this->log('WordPress cache flushed.');

		if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$this->log('Elementor cache flushed.');
		}

		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
			$this->log('WP Super Cache flushed.');
		}

		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
			$this->log('W3 Total Cache flushed.');
		}
	}

	private function get_archive()
	{
		$path = $this->get('archive_path');

		if (empty($path) || !file_exists($path)) {
			throw new \RuntimeException(esc_html__('Archive not uploaded yet.', 'snapshoter'));
		}

		if (!is_readable($path)) {
			throw new \RuntimeException(esc_html__('Archive file is not readable. Check file permissions.', 'snapshoter'));
		}

		clearstatcache(true, $path);

		$file_size = filesize($path);
		if ($file_size === 0) {
			throw new \RuntimeException(esc_html__('Archive file is empty. Upload may have failed.', 'snapshoter'));
		}

		$zip_handle = @fopen($path, 'rb');
		if ($zip_handle) {

			$magic_bytes = fread($zip_handle, 4);

			fclose($zip_handle);

			if ($magic_bytes !== "PK\x03\x04") {
				$this->log(sprintf('Invalid ZIP magic number. Expected PK\x03\x04, got: %s', bin2hex($magic_bytes)));
				throw new \RuntimeException(esc_html__('File is not a valid ZIP archive. The uploaded file may be corrupted or incomplete.', 'snapshoter'));
			}
			if (!$this->get('zip_magic_ok')) {
				$this->log('ZIP magic number verified successfully.');
				$this->update(array('zip_magic_ok' => 1));
			}
		}

		$zip = new \ZipArchive();

		$result = $zip->open($path, \ZipArchive::RDONLY);
		if (true !== $result) {
			$result = $zip->open($path);
		}

		if (true !== $result) {

			$error_messages = array(
				\ZipArchive::ER_OK => 'No error',
				\ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
				\ZipArchive::ER_RENAME => 'Renaming temporary file failed',
				\ZipArchive::ER_CLOSE => 'Closing zip archive failed',
				\ZipArchive::ER_SEEK => 'Seek error',
				\ZipArchive::ER_READ => 'Read error',
				\ZipArchive::ER_WRITE => 'Write error',
				\ZipArchive::ER_CRC => 'CRC error (file may be corrupted)',
				\ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
				\ZipArchive::ER_NOENT => 'No such file',
				\ZipArchive::ER_EXISTS => 'File already exists',
				\ZipArchive::ER_OPEN => 'Can\'t open file',
				\ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
				\ZipArchive::ER_ZLIB => 'Zlib error',
				\ZipArchive::ER_MEMORY => 'Memory allocation failure',
				\ZipArchive::ER_CHANGED => 'Entry has been changed',
				\ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
				\ZipArchive::ER_EOF => 'Premature EOF',
				\ZipArchive::ER_INVAL => 'Invalid argument',
				\ZipArchive::ER_NOZIP => 'Not a zip archive',
				\ZipArchive::ER_INTERNAL => 'Internal error',
				\ZipArchive::ER_INCONS => 'Zip archive inconsistent',
				\ZipArchive::ER_REMOVE => 'Can\'t remove file',
				\ZipArchive::ER_DELETED => 'Entry has been deleted',
			);
			if (defined('ZipArchive::ER_ENCRNOTSUPP')) {
				$error_messages[\ZipArchive::ER_ENCRNOTSUPP] = 'Encryption not supported';
			}
			if (defined('ZipArchive::ER_RDONLY')) {
				$error_messages[\ZipArchive::ER_RDONLY] = 'Read-only archive';
			}
			if (defined('ZipArchive::ER_NOPASSWD')) {
				$error_messages[\ZipArchive::ER_NOPASSWD] = 'Password required';
			}
			if (defined('ZipArchive::ER_WRONGPASSWD')) {
				$error_messages[\ZipArchive::ER_WRONGPASSWD] = 'Wrong password';
			}
			if (defined('ZipArchive::ER_TRUNCATED_ZIP')) {
				$error_messages[\ZipArchive::ER_TRUNCATED_ZIP] = 'Truncated zip (incomplete archive)';
			}

			$has_eocd = $this->archive_has_zip_eocd($path);
			$is_truncated = !$has_eocd && (
				(defined('ZipArchive::ER_TRUNCATED_ZIP') && (int) $result === (int) \ZipArchive::ER_TRUNCATED_ZIP)
				|| (int) $result === (int) \ZipArchive::ER_EOF
			);

			$error_msg = isset($error_messages[$result])
				? $error_messages[$result]
				: sprintf('Unknown error (code: %d)', $result);

			$this->log(sprintf(
				'Failed to open archive. Error: %s (code: %d). File size: %d bytes. EOCD: %s',
				$error_msg,
				$result,
				$file_size,
				$has_eocd ? 'yes' : 'no'
			));

			if ($is_truncated) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: File size */
						esc_html__('This backup file is incomplete (cut off before it finished). Re-download the full .smartin from the source, or create a new backup on the original site and wait until it shows completed. File size: %s bytes.', 'snapshoter'),
						esc_html(number_format($file_size))
					)
				);
			}

			throw new \RuntimeException(
				sprintf(

					// translators: %1$s: ZipArchive error; %2$s: file size in bytes.
					esc_html__('Unable to open archive: %1$s. The file may be corrupted or incomplete. File size: %2$s bytes.', 'snapshoter'),
					esc_html($error_msg),
					esc_html(number_format($file_size))
				)
			);
		}

		return $zip;
	}

	/**
	 * @param string $path Absolute path to a .smartin / zip archive.
	 * @return bool
	 */
	private function archive_has_zip_eocd($path)
	{
		$path = (string) $path;
		if ($path === '' || !is_readable($path)) {
			return false;
		}
		$size = @filesize($path);
		if (!is_int($size) || $size < 22) {
			return false;
		}
		$window = (int) min($size, 65536 + 128);
		$fh = @fopen($path, 'rb');
		if (!$fh) {
			return false;
		}
		if (@fseek($fh, -$window, SEEK_END) !== 0) {
			fclose($fh);
			return false;
		}
		$tail = @fread($fh, $window);
		fclose($fh);
		if (!is_string($tail) || $tail === '') {
			return false;
		}
		return (strpos($tail, "PK\x05\x06") !== false || strpos($tail, "PK\x06\x07") !== false);
	}

	private function read_manifest(\ZipArchive $zip)
	{
		$manifest = $zip->getFromName('manifest.json');

		$data = json_decode($manifest, true);

		if (empty($data) || !is_array($data)) {
			throw new \RuntimeException(esc_html__('Invalid archive manifest.', 'snapshoter'));
		}

		if (!isset($data['format']) || !in_array($data['format'], array('snapshoter', 'smartin'), true)) {
			$data['format'] = 'snapshoter';
		}

		return $data;
	}

	private function verify_archive_integrity(\ZipArchive $zip, array $manifest)
	{

		$manifest_scope = isset($manifest['scope']) ? (string) $manifest['scope'] : 'full';
		if ($manifest_scope === 'files') {
			$this->log('Files-only archive - skipping database integrity check.');
			$this->update(array(
				'integrity_status' => 'skipped',
				'integrity_reason' => 'files_only',
			));
			return;
		}

		if (empty($manifest['integrity']) || !is_array($manifest['integrity'])) {
			$this->log('Archive has no integrity block (older format); skipping verification.');
			$this->update(array(
				'integrity_status' => 'skipped',
				'integrity_reason' => 'no_block',
			));
			return;
		}

		$integrity = $manifest['integrity'];
		$algorithm = isset($integrity['algorithm']) ? (string) $integrity['algorithm'] : '';

		if ($algorithm !== 'sha256') {
			$this->log(sprintf('Unknown integrity algorithm "%s"; skipping verification.', $algorithm));
			$this->update(array(
				'integrity_status' => 'skipped',
				'integrity_reason' => 'unknown_algorithm',
			));
			return;
		}

		$expected_hash = isset($integrity['database_sha256']) ? (string) $integrity['database_sha256'] : '';
		$expected_size = isset($integrity['database_size']) ? (int) $integrity['database_size'] : 0;

		if ($expected_hash === '') {
			$this->log('Manifest integrity block missing database_sha256; skipping verification.');
			$this->update(array(
				'integrity_status' => 'skipped',
				'integrity_reason' => 'missing_hash',
			));
			return;
		}

		$stream = $zip->getStream('database/database.sql');
		if (!is_resource($stream)) {
			$stream = $zip->getStream('database.sql');
		}
		if (!is_resource($stream)) {
			$stream = $zip->getStream('files/database/database.sql');
		}
		if (!is_resource($stream)) {
			$stream = $zip->getStream('files/database.sql');
		}

		if (!is_resource($stream)) {
			$this->log('Could not open database stream for integrity check; skipping verification.');
			$this->update(array(
				'integrity_status' => 'skipped',
				'integrity_reason' => 'missing_db_stream',
			));
			return;
		}

		$ctx = hash_init('sha256');
		$actual_size = 0;

		while (!feof($stream)) {
			$chunk = fread($stream, 1048576);

			$actual_size += strlen($chunk);
			hash_update($ctx, $chunk);
		}

		fclose($stream);

		$actual_hash = hash_final($ctx);

		if ($expected_size > 0 && $expected_size !== $actual_size) {
			$this->update(array(
				'integrity_status' => 'failed',
				'integrity_reason' => 'size_mismatch',
				'integrity_algorithm' => 'sha256',
			));
			throw new \RuntimeException(esc_html(sprintf(

				// translators: %1$d: Actual database bytes; %2$d: Expected database bytes from manifest
				__('Archive integrity check failed: database size %1$d does not match manifest size %2$d. The archive may be corrupted.', 'snapshoter'),
				(int) $actual_size,
				(int) $expected_size
			)));
		}

		if (!hash_equals(strtolower($expected_hash), strtolower($actual_hash))) {
			$this->update(array(
				'integrity_status' => 'failed',
				'integrity_reason' => 'hash_mismatch',
				'integrity_algorithm' => 'sha256',
			));
			throw new \RuntimeException(esc_html__('Archive integrity check failed: database SHA-256 mismatch. The archive may be corrupted or tampered with.', 'snapshoter'));
		}

		$this->log(sprintf(
			'Archive integrity verified: SHA-256 %s... OK (%d bytes).',
			substr($actual_hash, 0, 12),
			$actual_size
		));

		$this->update(array(
			'integrity_status' => 'verified',
			'integrity_algorithm' => 'sha256',
			'integrity_database_size' => $actual_size,
			'integrity_database_sha256_short' => substr($actual_hash, 0, 12),
		));
	}

	private function build_extraction_queue(\ZipArchive $zip)
	{
		$queue = array();

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!is_array($stat) || empty($stat['name'])) {
				continue;
			}
			$filename = (string) $stat['name'];

			if (substr($filename, -1) === '/') {
				continue;
			}

			$clean = \Snapshoter\Core\PathSafety::normalize_zip_entry($filename);
			if ($clean === null) {
				$this->log(sprintf('Skipping unsafe zip entry: %s', esc_html($filename)));
				continue;
			}

			$queue[] = $clean;
		}

		$zip->close();

		return $queue;
	}

	private function build_restore_queue_from_extraction_queue()
	{
		$queue_path = $this->store->job_dir($this->job_id) . '/extraction_queue.json';
		if (!file_exists($queue_path)) {
			return array();
		}

		$entries = json_decode((string) file_get_contents($queue_path), true);
		if (!is_array($entries) || empty($entries)) {
			return array();
		}

		$uses_files_prefix = false;
		foreach ($entries as $entry) {
			$entry = ltrim(str_replace('\\', '/', (string) $entry), '/');
			if (strpos($entry, 'files/') === 0) {
				$uses_files_prefix = true;
				break;
			}
		}

		$files = array();
		$discovered_plugin_slugs = array();
		$discovered_theme_slugs = array();

		foreach ($entries as $entry) {
			$relative = ltrim(str_replace('\\', '/', (string) $entry), '/');
			if ($relative === '') {
				continue;
			}

			if ($uses_files_prefix) {
				if (strpos($relative, 'files/') !== 0) {
					continue;
				}
				$relative = substr($relative, strlen('files/'));
			}

			if (
				$relative === 'database' ||
				strpos($relative, 'database/') === 0 ||
				$relative === 'database.sql' ||
				$relative === 'manifest.json' ||
				$relative === 'extraction_queue.json' ||
				$relative === 'restore_queue.json'
			) {
				continue;
			}

			if (
				strpos($relative, 'wp-content/plugins/snapshoter/') === 0 ||
				strpos($relative, 'plugins/snapshoter/') === 0
			) {
				continue;
			}

			$files[] = $relative;

			if (preg_match('~^(?:wp-content/)?plugins/([^/]+)~i', $relative, $p_match)) {
				$discovered_plugin_slugs[$p_match[1]] = true;
			}
			if (preg_match('~^(?:wp-content/)?themes/([^/]+)~i', $relative, $t_match)) {
				$discovered_theme_slugs[$t_match[1]] = true;
			}
		}

		$files = array_values(array_unique($files));

		$this->update(
			array(
				'manifest_plugin_slugs' => array_keys($discovered_plugin_slugs),
				'manifest_theme_slugs'  => array_keys($discovered_theme_slugs),
			)
		);

		$this->log(sprintf('Built restore queue from extraction list: %d path(s).', count($files)));
		return $files;
	}

	private function prioritize_restore_queue(array $files)
	{
		$buckets = array(
			1 => array(),
			2 => array(),
			3 => array(),
		);

		foreach ($files as $relative) {
			$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
			if ($relative === '') {
				continue;
			}

			$bucket = \Snapshoter\Import\ArchiveIndex::restore_path_bucket($relative);
			$buckets[$bucket][] = $relative;
		}

		return array_merge($buckets[1], $buckets[2], $buckets[3]);
	}

	private function build_restore_queue()
	{

		$files_path = trailingslashit($this->extract_path) . 'files';
		$root_path = $this->extract_path;

		$payload = is_dir($files_path) ? $files_path : $root_path;

		$this->log(sprintf('Building restore queue from: %s', $payload));

		if (!is_dir($payload)) {
			$this->log(sprintf('ERROR: Extracted files directory does not exist: %s (checked both %s and %s)', $payload, $files_path, $root_path));
			throw new \RuntimeException(esc_html__('Extracted files missing.', 'snapshoter'));
		}

		$this->log('Extracted files directory found. Scanning for files...');

		$root = wp_normalize_path($payload);
		$files = array();
		$discovered_plugin_slugs = array();
		$discovered_theme_slugs = array();

		try {

			$php_version_supports_catch_get_child = version_compare(PHP_VERSION, '7.1.0', '>=');

			if ($php_version_supports_catch_get_child) {

				try {
					$iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
							$root,
							\FilesystemIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CATCH_GET_CHILD
						),
						\RecursiveIteratorIterator::SELF_FIRST,
						\RecursiveIteratorIterator::CATCH_GET_CHILD
					);
				} catch (\Throwable $e) {

					$iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
							$root,
							\FilesystemIterator::SKIP_DOTS
						),
						\RecursiveIteratorIterator::SELF_FIRST
					);
				}
			} else {

				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator(
						$root,
						\FilesystemIterator::SKIP_DOTS
					),
					\RecursiveIteratorIterator::SELF_FIRST
				);
			}

			foreach ($iterator as $file) {
				try {

					$file_path = $file->getPathname();

					if (!file_exists($file_path) && !is_link($file_path)) {
						$this->log(sprintf('Warning: Skipping missing file/directory: %s', $file_path));
						continue;
					}

					$relative = ltrim(str_replace($root, '', wp_normalize_path($file_path)), '/');

					if ('' === $relative) {
						continue;
					}

					if (
						$relative === 'database' ||
						strpos($relative, 'database/') === 0 ||
						$relative === 'database.sql' ||
						$relative === 'manifest.json' ||
						$relative === 'extraction_queue.json' ||
						$relative === 'restore_queue.json'
					) {
						continue;
					}

					$files[] = $relative;

					if (preg_match('~^(?:wp-content/)?plugins/([^/]+)~i', $relative, $p_match)) {
						$discovered_plugin_slugs[$p_match[1]] = true;
					}
					if (preg_match('~^(?:wp-content/)?themes/([^/]+)~i', $relative, $t_match)) {
						$discovered_theme_slugs[$t_match[1]] = true;
					}
				} catch (\UnexpectedValueException $e) {

					$file_path = method_exists($file, 'getPathname') ? $file->getPathname() : 'unknown';
					$this->log(sprintf('Warning: Skipping inaccessible directory: %s. Error: %s', $file_path, $e->getMessage()));
					continue;
				} catch (\Throwable $e) {

					$file_path = method_exists($file, 'getPathname') ? $file->getPathname() : 'unknown';
					$this->log(sprintf('Warning: Skipping file/directory due to error: %s. Error: %s', $file_path, $e->getMessage()));
					continue;
				}
			}
		} catch (\UnexpectedValueException $e) {

			$this->log(sprintf('Warning: Error creating directory iterator: %s. Attempting fallback method...', $e->getMessage()));

			$files = $this->build_restore_queue_fallback($root);
		} catch (\Throwable $e) {

			$this->log(sprintf('Warning: Error during file scanning: %s. Attempting fallback method...', $e->getMessage()));

			$files = $this->build_restore_queue_fallback($root);
		}

		$this->log(sprintf('Restore queue scan complete. Found %d files/directories.', count($files)));

		$this->update(
			array(
				'restore_queue' => $files,
				'manifest_plugin_slugs' => array_keys($discovered_plugin_slugs),
				'manifest_theme_slugs'  => array_keys($discovered_theme_slugs),
			)
		);

		return $files;
	}

	private function build_restore_queue_fallback($root)
	{
		$files = array();
		$discovered_plugin_slugs = array();
		$discovered_theme_slugs = array();

		if (!is_dir($root) || !is_readable($root)) {
			$this->log(sprintf('Error: Root directory is not accessible: %s', $root));
			return $files;
		}

		$this->log('Using fallback directory scanning method...');

		$stack = array($root);

		while (!empty($stack)) {
			$current_dir = array_pop($stack);

			try {

				$entries = @scandir($current_dir);

				foreach ($entries as $entry) {
					if ($entry === '.' || $entry === '..') {
						continue;
					}

					$full_path = $current_dir . DIRECTORY_SEPARATOR . $entry;

					if (!file_exists($full_path) && !is_link($full_path)) {
						$this->log(sprintf('Warning: Skipping missing entry: %s', $full_path));
						continue;
					}

					$relative = ltrim(str_replace($root, '', wp_normalize_path($full_path)), '/');

					if ('' === $relative) {
						continue;
					}

					if (
						$relative === 'database' ||
						strpos($relative, 'database/') === 0 ||
						$relative === 'database.sql' ||
						$relative === 'manifest.json' ||
						$relative === 'extraction_queue.json' ||
						$relative === 'restore_queue.json'
					) {
						continue;
					}

					$files[] = $relative;

					if (preg_match('~^(?:wp-content/)?plugins/([^/]+)~i', $relative, $p_match)) {
						$discovered_plugin_slugs[$p_match[1]] = true;
					}
					if (preg_match('~^(?:wp-content/)?themes/([^/]+)~i', $relative, $t_match)) {
						$discovered_theme_slugs[$t_match[1]] = true;
					}

					if (is_dir($full_path) && is_readable($full_path)) {
						$stack[] = $full_path;
					}
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Error scanning directory %s: %s', $current_dir, $e->getMessage()));
				continue;
			}
		}

		$this->log(sprintf('Fallback scan complete. Found %d files/directories.', count($files)));
		return $files;
	}

	private function restore_files_sub_progress()
	{
		$total_files = (int) $this->get('total_restore', 0);
		if ($total_files <= 0) {
			$index_meta = $this->get('archive_index', array());
			$total_files = is_array($index_meta) && isset($index_meta['total'])
				? (int) $index_meta['total']
				: 0;
		}
		if ($total_files <= 0) {
			return 0.0;
		}

		$cursor = (int) $this->get('entry_cursor', (int) $this->get('restore_offset', 0));
		$byte_offset = (int) $this->get('entry_byte_offset', 0);
		$effective = (float) $cursor;

		if ($byte_offset > 0 && $cursor < $total_files) {
			$entry_size = (int) $this->get('entry_cursor_size', 0);
			if ($entry_size <= 0) {
				$index = new \Snapshoter\Import\ArchiveIndex($this->store->job_dir($this->job_id));
				$slice = $index->slice($cursor, 1);
				if (!empty($slice)) {
					$entry_size = (int) $slice[0]['size'];
				}
			}
			if ($entry_size > 0) {
				$effective += min(0.99, $byte_offset / $entry_size);
			}
		}

		return min(100.0, max(0.0, ($effective / $total_files) * 100.0));
	}

	protected function calculate_progress()
	{
		$step = $this->get('step', self::STEP_UPLOAD);

		$step_weights = array(
			self::STEP_UPLOAD => array('min' => 0, 'max' => 5),
			self::STEP_DOWNLOAD => array('min' => 5, 'max' => 20),
			self::STEP_VALIDATE => array('min' => 20, 'max' => 22),
			self::STEP_EXTRACT => array('min' => 22, 'max' => 30),
			self::STEP_DISABLE_SITE => array('min' => 30, 'max' => 35),
			self::STEP_RESTORE_FILES => array('min' => 35, 'max' => 55),
			self::STEP_MIRROR_PRUNE => array('min' => 55, 'max' => 62),
			self::STEP_IMPORT_DB => array('min' => 62, 'max' => 90),
			self::STEP_FINALIZE => array('min' => 90, 'max' => 100),
			self::STEP_COMPLETED => array('min' => 100, 'max' => 100),
		);

		$step_info = isset($step_weights[$step]) ? $step_weights[$step] : array('min' => 0, 'max' => 0);
		$base_progress = $step_info['min'];
		$step_range = $step_info['max'] - $step_info['min'];

		$sub_progress = 0;

		switch ($step) {
			case self::STEP_UPLOAD:
				$upload = $this->get('upload', array());
				if (isset($upload['received']) && isset($upload['total']) && $upload['total'] > 0) {
					$sub_progress = ($upload['received'] / $upload['total']) * 100;
				}
				break;

			case self::STEP_DOWNLOAD:
				$sub_progress = 0;
				break;

			case self::STEP_EXTRACT:
				$extracted = (int) $this->get('extracted', 0);
				$queue = $this->get('extraction_queue', array());
				$total = $extracted + count($queue);
				if ($total > 0) {
					$sub_progress = ($extracted / $total) * 100;
				}
				break;

			case self::STEP_RESTORE_FILES:
				if ($this->get('engine') === 'direct_v1') {
					$sub_progress = $this->restore_files_sub_progress();
					break;
				}

				$queue_path = $this->store->job_dir($this->job_id) . '/restore_queue.json';
				$total_files = 0;

				if (file_exists($queue_path)) {
					$queue_json = @file_get_contents($queue_path);

				}

				if ($total_files === 0) {
					$total_files = (int) $this->get('total_restore', 0);
				}

				$offset = (int) $this->get('restore_offset', 0);

				if ($total_files > 0) {
					$sub_progress = min(100, max(0, ($offset / $total_files) * 100));
				} else {

					$sub_progress = 0;
				}
				break;

			case self::STEP_MIRROR_PRUNE:
				$prune_total = (int) $this->get('mirror_prune_total', 0);
				$prune_offset = (int) $this->get('mirror_prune_offset', 0);
				if ($prune_total > 0) {
					$sub_progress = min(100, max(0, ($prune_offset / $prune_total) * 100));
				} else {
					$sub_progress = 0;
				}
				break;

			case self::STEP_IMPORT_DB:
				$sql_offset = (int) $this->get('sql_offset', 0);
				$db_path = trailingslashit($this->extract_path) . 'database/database.sql';
				if (file_exists($db_path)) {
					$file_size = filesize($db_path);
					if ($file_size > 0) {
						$sub_progress = min(100, ($sql_offset / $file_size) * 100);
					}
				}
				break;

			case self::STEP_FINALIZE:
				$phase = (string) $this->get('finalize_phase', '');
				$pending_core = !empty($this->get('deferred_core_pending'));
				if ($pending_core || $phase === '' || $phase === 'wp_core') {
					$core_total = (int) $this->get('total_restore', 0);
					if ($core_total <= 0) {
						$index_meta = $this->get('archive_index', array());
						if (is_array($index_meta) && isset($index_meta['total'])) {
							$core_total = (int) $index_meta['total'];
						}
					}
					$core_cursor = (int) $this->get('deferred_core_cursor', 0);
					if ($core_total > 0) {
						$sub_progress = min(42, max(2, ($core_cursor / $core_total) * 42));
					} else {
						$sub_progress = 8;
					}
				} elseif ($phase === 'core') {
					$sub_progress = 48;
				} elseif ($phase === 'urls') {
					$idx = (int) $this->get('url_replace_table_idx', 0);
					$sub_progress = min(88, max(55, 55 + ($idx * 8)));
				} elseif ($phase === 'finish') {
					$sub_progress = 92;
				} elseif ($phase === 'plugins') {
					$sub_progress = 97;
				} else {
					$sub_progress = 50;
				}
				break;
			case self::STEP_COMPLETED:
				$sub_progress = 100;
				break;
		}

		$progress = $base_progress + ($step_range * ($sub_progress / 100));
		$progress = min(100, max(0, round($progress, 2)));

		if ($step === self::STEP_FINALIZE && $progress >= 100) {
			$progress = 99;
		}

		return $progress;
	}

	protected function response(array $data = array())
	{

		$progress = $this->calculate_progress();
		$step = $this->get('step', self::STEP_UPLOAD);

		return array_merge(
			array(
				'jobId' => $this->job_id,
				'type' => $this->type(),
				'state' => array_merge($this->state, array('progress' => $progress, 'current_step' => $step)),
				'log' => $this->store->get_log($this->job_id),
				'progress' => $progress,
			),
			$data
		);
	}
}

