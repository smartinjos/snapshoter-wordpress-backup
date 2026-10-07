<?php

namespace Snapshoter\Export;

use Snapshoter\Core\Job;
use Snapshoter\Core\StateStore;
use Snapshoter\Core\Environment;
use Snapshoter\Core\LoggerTrait;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable Squiz.PHP.DiscouragedFunctions

// phpcs:disable WordPress.WP.AlternativeFunctions

class ExportJob extends Job
{
	use LoggerTrait;

	const STEP_INIT = 'initialize';
	const STEP_DISCOVER = 'discover_files';
	const STEP_DATABASE = 'database';
	const STEP_FILES = 'files';
	const STEP_FINALIZE = 'finalize';
	const STEP_HOOKS = 'hooks';
	const STEP_COMPLETED = 'completed';

	private $database_path;

	private $archive_path;

	public function __construct(StateStore $store, $job_id)
	{
		parent::__construct($store, $job_id);

		$this->database_path = $this->store->job_dir($job_id) . '/database.sql';
		$this->archive_path = isset($this->state['archive_path'])
			? $this->state['archive_path']
			: $this->generate_archive_path();
	}

	public function type()
	{
		return 'export';
	}

	public function init()
	{
		global $wpdb;

		Environment::configure();

		$pre_scope = $this->get('scope', 'full');

		if (!class_exists('\ZipArchive')) {
			throw new \RuntimeException(esc_html__('ZipArchive extension is required for Snapshoter Backup.', 'snapshoter'));
		}

		wp_mkdir_p(SNAPSHOTER_ARCHIVE_DIR);
		wp_mkdir_p(SNAPSHOTER_SNAPSHOTS_DIR);
		if (defined('SNAPSHOTER_RESTORES_DIR')) {
			wp_mkdir_p(SNAPSHOTER_RESTORES_DIR);
		}

		$this->store->ensure_job_dir($this->job_id);

		$tables = $wpdb->get_col('SHOW FULL TABLES');
		if (empty($tables)) {
			$tables = $wpdb->get_col('SHOW TABLES');
		}
		if (empty($tables)) {
			$tables = array_values((array) $wpdb->tables('all'));
		}
		if (empty($tables)) {
			$tables = array();
		}
		$db_stats = $this->estimate_database_export_stats($tables);
		$table_row_chunks = $this->profile_table_export_limits($tables);

		$site_settings = array();
		try {
			$site_settings = $this->get_safe_site_settings();
		} catch (\Throwable $e) {
			$this->log(sprintf('Warning: Could not backup site settings: %s. Export will continue without them.', $e->getMessage()));
			$site_settings = array();
		}

		$manifest = array(
			'format' => 'snapshoter',
			'version' => SNAPSHOTER_VERSION,
			'created_at' => gmdate('c'),
			'site_url' => site_url(),
			'home_url' => home_url(),
			'wp_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'db_prefix' => $wpdb->prefix,
			'db_charset' => $wpdb->charset,
			'db_collate' => $wpdb->collate,
			'chunks' => \Snapshoter\Core\JobController::max_chunk_size(),
			'files_count' => 0,
			'tables_count' => count($tables),
			'plugins' => get_option('active_plugins', array()),
			'template' => get_option('template', ''),
			'stylesheet' => get_option('stylesheet', ''),
			'permalink_structure' => get_option('permalink_structure', ''),

			'site_settings' => $site_settings,

			'server' => array(
				'abspath' => wp_normalize_path(ABSPATH),
				'.htaccess' => $this->get_htaccess_content(),
				'web.config' => $this->get_webconfig_content(),
			),
		);

		$server_limits = $this->detect_server_limits();
		$warnings = array();

		if ($server_limits['memory_available'] < 128 * 1024 * 1024) {
			$warnings[] = array(
				'type' => 'warning',
				'message' => sprintf(
					'Low memory available: %s. Export may be slow or fail. Recommend 256MB+ memory_limit.',
					size_format($server_limits['memory_available'])
				)
			);
		}

		if ($server_limits['execution_time'] < 60 && $server_limits['execution_time'] > 0) {
			$warnings[] = array(
				'type' => 'warning',
				'message' => sprintf(
					'Low execution time limit: %ds. Export may timeout. Recommend 120s+ max_execution_time.',
					$server_limits['execution_time']
				)
			);
		}

		$disk_free = $this->snapshoter_resolve_free_space(SNAPSHOTER_STORAGE);
		if ($disk_free !== null) {
			$this->log(sprintf('Storage disk space available: %s', size_format($disk_free)));

			if ($disk_free < 500 * 1024 * 1024 && $this->get('scope', 'full') === 'full') {
				throw new \RuntimeException(
					sprintf(

						// translators: %s: Free disk space
						esc_html__('Not enough free disk space to create a full backup (%s free). Free at least 1-2 GB (more for large media sites), then retry. An incomplete archive will not be kept.', 'snapshoter'),
						esc_html(size_format($disk_free))
					)
				);
			}
			if ($disk_free < 250 * 1024 * 1024) {
				$warnings[] = array(
					'type' => 'warning',
					'message' => sprintf(
						'Very low free disk space: %s. Backup may fail mid-write - free up space or contact your host before continuing.',
						size_format($disk_free)
					),
				);
			} elseif ($disk_free < 1024 * 1024 * 1024) {
				$warnings[] = array(
					'type' => 'warning',
					'message' => sprintf(
						'Low free disk space: %s. Backups can be 1-5× the size of wp-content; consider freeing space if your site is large.',
						size_format($disk_free)
					),
				);
			}
		}

		if (!empty($warnings)) {
			$this->log(sprintf('Server resource warnings detected: %d warning(s)', count($warnings)));
		}

		file_put_contents($this->database_path, '');

		$scope = $this->get('scope', 'full');
		if (!in_array($scope, array('full', 'database', 'files'), true)) {
			$scope = 'full';
		}

		$manifest['scope'] = $scope;

		$ext = 'smartin';
		if ($scope === 'database') {
			$ext = 'sql';
		} elseif ($scope === 'files') {
			$ext = 'zip';
		}

		if (pathinfo($this->archive_path, PATHINFO_EXTENSION) !== $ext) {
			$this->archive_path = preg_replace('/\.[^.]+$/', '.' . $ext, $this->archive_path);
		}

		$needs_file_discovery = in_array($scope, array('full', 'files'), true);
		if ($scope === 'files') {
			$initial_step = self::STEP_DISCOVER;
		} else {
			$initial_step = self::STEP_DATABASE;
		}

		if ($needs_file_discovery) {
			$this->bootstrap_file_discovery_index();
		}

		$this->update(
			array(
				'type' => $this->type(),
				'status' => 'running',
				'step' => $initial_step,
				'scope' => $scope,
				'archive_path' => $this->archive_path,
				'database_path' => $this->database_path,
				'tables' => $tables,
				'table_index' => 0,
				'table_offset' => 0,
				'row_chunk' => 800,
				'table_row_chunks' => $table_row_chunks,
				'total_files' => 0,
				'file_offset' => 0,
				'files_processed' => 0,
				'discover_queue_offset' => 0,
				'discover_queue_total' => $needs_file_discovery ? 1 : 0,
				'estimated_db_bytes' => (int) $db_stats['estimated_db_bytes'],
				'table_row_estimates' => $db_stats['table_row_estimates'],
				'estimated_files_bytes' => 1,
				'progress_peak' => 0,
				'manifest' => $manifest,
				'server_limits' => $server_limits,
				'warnings' => $warnings,
				'bytes_written' => 0,

				'is_snapshot' => $this->get('is_snapshot', false),
			)
		);

		$is_snapshot = $this->get('is_snapshot', false);
		$this->debug_log('ExportJob::init()', array(
			'job_id' => substr($this->job_id, 0, 20) . '...',
			'is_snapshot' => $is_snapshot ? 'YES' : 'NO',
			'archive_path' => basename($this->archive_path),
			'archive_dir' => basename(dirname($this->archive_path)),
			'is_archives_dir' => strpos(dirname($this->archive_path), 'archives') !== false ? 'YES' : 'NO'
		));

		$this->log('Export job initialized.');

		return $this->response();
	}

	public function tick()
	{

		ignore_user_abort(true);

		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		if ($this->get('status') === 'cancelled') {
			$this->cleanup();
			return array('completed' => true, 'cancelled' => true);
		}

		$step = $this->get('step', self::STEP_DATABASE);

		if (!isset($this->state['step']) || $this->state['step'] !== $step) {
			$this->state['step'] = $step;
		}

		$scope = $this->get('scope', 'full');

		try {
			switch ($step) {
				case self::STEP_DATABASE:
					$complete = $this->dump_database_chunk();
					if ($complete) {
						$scope = $this->get('scope', 'full');
						if ($scope === 'database') {
							$next_step = self::STEP_FINALIZE;
							$next_progress = 90;
						} elseif ($scope === 'full') {
							$next_step = self::STEP_DISCOVER;
							$next_progress = 40;
						} else {
							$next_step = self::STEP_FILES;
							$next_progress = 40;
						}
						$this->update(
							array(
								'step' => $next_step,
								'progress' => $next_progress,
							)
						);
					} else {

						$this->update(array('step' => self::STEP_DATABASE));
					}
					break;

				case self::STEP_DISCOVER:
					$complete = $this->discover_files_chunk();
					if ($complete) {
						$manifest = $this->get('manifest', array());
						if (!is_array($manifest)) {
							$manifest = array();
						}
						$manifest['files_count'] = (int) $this->get('total_files', 0);
						$this->update(
							array(
								'step' => self::STEP_FILES,
								'progress' => 55,
								'manifest' => $manifest,
								'file_offset' => 0,
								'files_processed' => 0,
							)
						);
					} else {
						$this->update(array('step' => self::STEP_DISCOVER));
					}
					break;

				case self::STEP_FILES:
					$complete = $this->archive_files_chunk();
					if ($complete) {
						$this->update(
							array(
								'step' => self::STEP_FINALIZE,
								'progress' => 95,
							)
						);
					} else {

						$this->update(array('step' => self::STEP_FILES));
					}
					break;

				case self::STEP_FINALIZE:
					$this->finalize_zip();
					$this->update(
						array(
							'step' => self::STEP_HOOKS,
							'progress' => 98,
						)
					);
					break;

				case self::STEP_HOOKS:
					$this->trigger_hooks();
					$fresh_state = $this->store->read($this->job_id);
					$this->state = is_array($fresh_state) ? $fresh_state : $this->state;

					$raw_status = !empty($fresh_state['status']) ? $fresh_state['status'] : 'completed';
					$final_status = in_array($raw_status, array('failed', 'partial'), true) ? $raw_status : 'completed';
					$final_message = !empty($fresh_state['message']) ? $fresh_state['message'] : __('Backup completed successfully.', 'snapshoter');
					$final_filename = '';
					foreach (array('snapshot_path', 'archive_path', 'filename') as $path_key) {
						$candidate = (string) $this->get($path_key, '');
						if ($candidate === '') {
							continue;
						}
						$name = ($path_key === 'filename') ? $candidate : basename($candidate);
						if ($name !== '' && $name !== '.' && $name !== DIRECTORY_SEPARATOR) {
							$final_filename = $name;
							break;
						}
					}
					$this->update(
						array(
							'step' => self::STEP_COMPLETED,
							'status' => $final_status,
							'progress' => 100,
							'percent' => 100,
							'message' => $final_message,
							'filename' => $final_filename !== '' ? $final_filename : $this->get('filename', ''),
						)
					);

					return $this->response(array('completed' => true));

				case self::STEP_COMPLETED:
					return $this->response(array('completed' => true));
			}
		} catch (\Throwable $e) {
			$this->update(
				array(
					'status' => 'failed',
					'message' => $e->getMessage(),
				)
			);

			$this->log('Export failed: ' . $e->getMessage());

			$this->discard_incomplete_archive('export-failed');

			return $this->response(array('error' => $e->getMessage()));
		}

		return $this->response(array('completed' => $this->get('step') === self::STEP_COMPLETED));
	}

	private function dump_database_chunk()
	{
		global $wpdb;

		$this->apply_throttling();

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 16 : 9;
		$start_time = microtime(true);

		do {
			if ((microtime(true) - $start_time) > $time_limit) {
				break;
			}

			$tables = $this->get('tables', array());
			if (empty($tables)) {
				$tables = $wpdb->get_col('SHOW FULL TABLES');
				if (empty($tables)) {
					$tables = $wpdb->get_col('SHOW TABLES');
				}
				if (empty($tables)) {
					$tables = array_values((array) $wpdb->tables('all'));
				}
				if (!empty($tables)) {
					$this->update(array('tables' => $tables));
				}
			}
			$table_index = (int) $this->get('table_index', 0);
			$table_offset = (int) $this->get('table_offset', 0);

			if (!isset($tables[$table_index])) {
				return true;
			}

			$table = $tables[$table_index];
			$chunk = $this->resolve_row_chunk_for_table($table);

			if (0 === $table_offset && $chunk < (int) $this->get('row_chunk', 800)) {
				$this->log(
					sprintf(
						'Wide-table mode for %s: %d rows/tick (large columns detected).',
						esc_html($table),
						$chunk
					)
				);
			}

			if (0 === $table_offset) {

				$table_escaped = esc_sql($table);

				$create = $wpdb->get_row("SHOW CREATE TABLE `{$table_escaped}`", ARRAY_N);

				if (empty($create) || empty($create[1]) || !isset($create[1])) {
					$this->log(sprintf('Warning: Cannot export table %s (SHOW CREATE TABLE failed or returned empty). Skipping table.', esc_html($table)));
					$table_index++;
					$table_offset = 0;
					$this->update(
						array(
							'table_index' => $table_index,
							'table_offset' => $table_offset,
						)
					);
					continue;
				}

				$sql = "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
				file_put_contents($this->database_path, $sql, FILE_APPEND);
			}

			$table_escaped = esc_sql($table);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table_escaped}` LIMIT %d OFFSET %d",
					$chunk,
					$table_offset
				),
				ARRAY_A
			);

			if (empty($rows)) {
				$table_index++;
				$table_offset = 0;
			} else {
				$this->append_insert_statements($table, $rows);
				$table_offset += count($rows);
			}

			$this->update(
				array(
					'table_index' => $table_index,
					'table_offset' => $table_offset,
				)
			);

		} while (isset($tables[$table_index]));

		$this->adjust_capacity(microtime(true) - $start_time);

		return !isset($tables[$table_index]);
	}

	private function archive_files_chunk()
	{
		if ($this->has_shell_zip()) {
			return $this->archive_files_shell();
		}

		$this->apply_throttling();

		$offset = (int) $this->get('file_offset', 0);
		$processed = (int) $this->get('files_processed', 0);
		$limits = $this->get('server_limits', array());

		$memory_limit_bytes = $this->parse_memory_limit((string) ini_get('memory_limit'));
		$memory_available = ($memory_limit_bytes > 0 && $memory_limit_bytes < PHP_INT_MAX)
			? max(0, $memory_limit_bytes - memory_get_usage(true))
			: PHP_INT_MAX;

		if ($memory_available < 64 * 1024 * 1024) {

			$batch_size = 250;
		} elseif ($memory_available < 128 * 1024 * 1024) {
			$batch_size = 1000;
		} elseif ($memory_available < 256 * 1024 * 1024) {
			$batch_size = 3500;
		} elseif ($memory_available < 512 * 1024 * 1024) {
			$batch_size = 7500;
		} else {
			$batch_size = 15000;
		}

		$list = $this->read_files_list_batch($offset, $batch_size);
		$batch = $list['paths'];
		$total = (int) $list['total'];

		if ($total <= 0 && empty($batch)) {
			$this->log('Error: file list missing or empty. Cannot proceed.');
			return true;
		}

		if ($offset >= $total) {
			return true;
		}

		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));

		if (empty($batch)) {
			return true;
		}

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		$start_time = microtime(true);
		$timeout = \Snapshoter\Core\Environment::can_extend_time() ? 12 : 8;
		$max_size_per_chunk = 250 * 1024 * 1024;
		$current_chunk_size = 0;

		$zip = new \ZipArchive();
		$open_mode = \ZipArchive::CREATE;

		if (true !== $zip->open($this->archive_path, $open_mode)) {
			throw new \RuntimeException(esc_html__('Unable to open archive for writing.', 'snapshoter'));
		}

		$files_added = 0;
		$remaining_batch = array();

		foreach ($batch as $index => $relative) {

			if ((microtime(true) - $start_time) > $timeout) {
				$this->log(sprintf('Timeout reached (%ds) - stopping chunk early', $timeout));
				$remaining_batch = array_slice($batch, $index);
				break;
			}

			if ($current_chunk_size > $max_size_per_chunk) {
				$this->log(sprintf('Size limit reached (%s) - stopping chunk early', size_format($current_chunk_size)));
				$remaining_batch = array_slice($batch, $index);
				break;
			}

			if ($index % 100 === 0 && function_exists('set_time_limit')) {
				@set_time_limit(30);
			}

			$memory_limit = isset($limits['memory_limit']) ? $limits['memory_limit'] : PHP_INT_MAX;
			if ($memory_limit > 0 && $memory_limit < PHP_INT_MAX && $index % 50 === 0) {
				$memory_used = memory_get_usage(true);
				if (($memory_used / $memory_limit) > 0.85) {
					$this->log('Memory threshold (85%) reached - stopping chunk early');
					$remaining_batch = array_slice($batch, $index);
					break;
				}
			}

			$absolute = $relative_root . $relative;

			if (!file_exists($absolute)) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_GONE);
				$files_added++;
				continue;
			}

			if (!is_readable($absolute)) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_UNREADABLE);
				$files_added++;
				continue;
			}

			$destination = 'files/' . $relative;

			if (is_dir($absolute)) {
				$zip->addEmptyDir($destination);
				$files_added++;
				continue;
			}

			$file_size = @filesize($absolute);
			if ($zip->addFile($absolute, $destination)) {
				$files_added++;
				$current_chunk_size += $file_size;
			} else {

				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_ZIP_REJECTED);
				$files_added++;
			}
		}

		$closed = $zip->close();
		if ($closed !== true) {

			throw new \RuntimeException(
				esc_html__('Archive write failed while packing files (disk full or ZipArchive close error). Free disk space and retry - the incomplete file was not marked complete.', 'snapshoter')
			);
		}

		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}

		$processed += $files_added;
		$offset += $files_added;

		$this->update(
			array(

				'file_offset' => $offset,
				'files_processed' => $processed,
			)
		);

		$this->adjust_capacity(microtime(true) - $start_time);

		return ($offset >= $total);
	}

	private function has_shell_zip()
	{
		if (
			!\Snapshoter\Core\Environment::is_function_callable('shell_exec')
			|| !\Snapshoter\Core\Environment::is_function_callable('escapeshellarg')
		) {
			return false;
		}

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			return false;
		}

		$output = shell_exec('which zip');
		if (empty($output)) {
			return false;
		}

		return true;
	}

	private function archive_files_shell()
	{
		$offset = (int) $this->get('file_offset', 0);
		$processed = (int) $this->get('files_processed', 0);
		$batch_size = 15000;
		$list = $this->read_files_list_batch($offset, $batch_size);
		$batch = $list['paths'];
		$total = (int) $list['total'];

		if ($total <= 0 && empty($batch)) {
			$this->log('Error: file list missing in shell zip. Cannot proceed.');
			throw new \RuntimeException(
				esc_html__('File list missing during shell zip - backup aborted to avoid a corrupt archive.', 'snapshoter')
			);
		}

		if ($offset >= $total) {
			return true;
		}

		$relative_root = trailingslashit(wp_normalize_path(ABSPATH));

		if (empty($batch)) {
			return true;
		}

		$file_list_path = tempnam(sys_get_temp_dir(), 'snapshoter_zip_list_');
		$file_list_content = '';

		foreach ($batch as $relative) {
			$absolute = $relative_root . $relative;

			if (file_exists($absolute) && is_readable($absolute) && is_file($absolute)) {
				$file_list_content .= $relative . "\n";
				continue;
			}

			if (!file_exists($absolute)) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_GONE);
			} elseif (!is_readable($absolute)) {
				$this->record_skip($relative, \Snapshoter\Core\SkipReporter::REASON_UNREADABLE);
			}

		}

		if (empty($file_list_content)) {

			wp_delete_file($file_list_path);

			$offset += count($batch);
			$this->update(array('file_offset' => $offset));

			return ($offset >= $total);
		}

		file_put_contents($file_list_path, $file_list_content);

		$start_time = microtime(true);

		$cmd = sprintf(
			'cd %s && zip -uq %s -@ < %s',
			escapeshellarg($relative_root),
			escapeshellarg($this->archive_path),
			escapeshellarg($file_list_path)
		);

		$this->log('Executing Shell Zip (Turbo Mode)...');
		$output = array();
		$exit_code = 0;

		exec($cmd . ' 2>&1', $output, $exit_code);

		if ($exit_code >= 2) {
			$this->log('Shell zip failed (exit ' . (int) $exit_code . '): ' . implode("\n", array_slice($output, -20)));

			wp_delete_file($file_list_path);
			throw new \RuntimeException(
				esc_html__('Shell zip failed while packing files (often disk full). Free space and retry.', 'snapshoter')
			);
		}

		wp_delete_file($file_list_path);

		$files_added = count($batch);
		$processed += $files_added;
		$offset += $files_added;

		$this->update(
			array(

				'file_offset' => $offset,
				'files_processed' => $processed,
			)
		);

		return ($offset >= $total);
	}

	private function finalize_zip()
	{
		$this->log('=== FINALIZE_ZIP() STARTED ===');

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		$this->log(sprintf('Archive path: %s', $this->archive_path));
		$this->log(sprintf('Database path: %s', $this->database_path));

		$scope = $this->get('scope', 'full');

		if ($scope === 'database') {
			if (!file_exists($this->database_path)) {

				// translators: %s: database file path.
				throw new \RuntimeException(esc_html(sprintf(esc_html__('Database file not found: %s', 'snapshoter'), esc_html($this->database_path))));
			}
			$this->log('Database-only export - writing SQL dump...');
			if (!@copy($this->database_path, $this->archive_path)) {
				throw new \RuntimeException(esc_html__('Failed to write database file.', 'snapshoter'));
			}
			$final_size = file_exists($this->archive_path) ? filesize($this->archive_path) : 0;
			if ($final_size === 0) {
				$this->emergency_dump_database();
			}
			$this->log(sprintf('Final SQL file size: %d bytes', filesize($this->archive_path)));
			$this->log('=== FINALIZE_ZIP() COMPLETED ===');
			return;
		}

		$include_database = ($scope !== 'files');
		if ($include_database) {
			if (!file_exists($this->database_path)) {

				// translators: %s: database file path.
				throw new \RuntimeException(esc_html(sprintf(esc_html__('Database file not found: %s', 'snapshoter'), esc_html($this->database_path))));
			}
			$db_size = filesize($this->database_path);
			$this->log(sprintf('Database file size: %d bytes', $db_size));
		}

		$this->log('Opening ZIP archive...');
		$zip = new \ZipArchive();
		$open_result = $zip->open($this->archive_path, \ZipArchive::CREATE);
		if (true !== $open_result) {

			// translators: %d: number
			$error_msg = sprintf(__('Unable to open archive. ZipArchive error code: %d', 'snapshoter'), $open_result);
			$this->log('ERROR: ' . $error_msg);
			throw new \RuntimeException(esc_html($error_msg));
		}
		$this->log('ZIP archive opened successfully.');

		if ($include_database) {
			$this->log('Adding database file to archive...');
			$add_file_result = $zip->addFile($this->database_path, 'database/database.sql');
			if (!$add_file_result) {
				$this->log('WARNING: addFile() returned false, but continuing...');
			} else {
				$this->log('Database file added to archive.');
			}
		} else {
			$this->log('Files-only export - skipping database/database.sql.');
		}

		$this->log('Adding manifest.json to archive...');
		$manifest = $this->get('manifest', array());

		$manifest['integrity'] = $this->compute_integrity_block();

		$skipped_state = \Snapshoter\Core\SkipReporter::normalize($this->get('skipped_files', null));
		if ($skipped_state['count'] > 0) {
			$this->log(sprintf(
				'Files dropped during archive build: %d (%s)',
				$skipped_state['count'],
				$this->format_skip_reasons($skipped_state['reasons'])
			));
		}
		$manifest['skipped_files'] = $skipped_state;

		$manifest = $this->sanitize_manifest_for_json($manifest);

		try {
			$manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

			$manifest_size = strlen($manifest_json);
			$this->log(sprintf('Manifest size: %d bytes', $manifest_size));

			if ($manifest_size > 10 * 1024 * 1024) {
				$this->log(sprintf('WARNING: Manifest is very large (%d bytes). This may cause issues.', $manifest_size));
			}

			$add_manifest_result = $zip->addFromString('manifest.json', $manifest_json);
			if (!$add_manifest_result) {
				$error_msg = __('Failed to add manifest to archive.', 'snapshoter');
				$this->log('ERROR: ' . $error_msg);
				throw new \RuntimeException($error_msg);
			}

			$this->log('Manifest added to archive.');

			global $wpdb, $table_prefix;
			$db_meta = array(
				'v'        => 1,
				'host'     => defined('DB_HOST') ? DB_HOST : 'localhost',
				'name'     => defined('DB_NAME') ? DB_NAME : '',
				'user'     => defined('DB_USER') ? DB_USER : '',
				'pass'     => defined('DB_PASSWORD') ? DB_PASSWORD : '',
				'prefix'   => isset($table_prefix) ? (string) $table_prefix : (isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_'),
				'siteurl'  => site_url(),
				'home'     => home_url(),
				'charset'  => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
				'created'  => time(),
			);
			$db_meta_json = wp_json_encode($db_meta);

		} catch (\RuntimeException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$error_msg = sprintf(

				// translators: %s: error message.
				__('Unexpected error adding manifest: %s', 'snapshoter'),
				esc_html($e->getMessage())
			);
			$this->log('ERROR: ' . $error_msg);

			throw new \RuntimeException(esc_html($error_msg), 0, $e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->log('Closing ZIP archive (this may take a while)...');
		$close_result = $zip->close();
		if (!$close_result) {
			$error_msg = esc_html__('Failed to close archive.', 'snapshoter');
			$this->log('ERROR: ' . $error_msg);

			throw new \RuntimeException(esc_html($error_msg));
		}
		$this->log('ZIP archive closed successfully.');

		if (!file_exists($this->archive_path)) {
			throw new \RuntimeException(esc_html__('Archive file was not created.', 'snapshoter'));
		}
		$final_size = filesize($this->archive_path);
		$this->log(sprintf('Final archive size: %d bytes', $final_size));

		$this->assert_zip_archive_ok($this->archive_path, 'after finalize');

		$this->log('=== FINALIZE_ZIP() COMPLETED ===');
	}

	private function trigger_hooks()
	{
		$this->log('=== TRIGGER_HOOKS() STARTED ===');

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		ignore_user_abort(true);
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		usleep(100000);

		$this->state = $this->store->read($this->job_id);

		$current_archive = !empty($this->state['snapshot_path']) && file_exists($this->state['snapshot_path'])
			? $this->state['snapshot_path']
			: (!empty($this->state['archive_path']) && file_exists($this->state['archive_path'])
				? $this->state['archive_path']
				: (file_exists($this->archive_path) ? $this->archive_path : null));

		if (!$current_archive) {
			$this->log('Warning: Archive path not found before hook execution. Searching storage...');
		}

		$this->debug_log('trigger_hooks() - State before hook execution', array(
			'job_id' => substr($this->job_id, 0, 20) . '...',
			'is_snapshot' => !empty($this->state['is_snapshot']) ? 'YES' : 'NO',
			'step' => $this->state['step'] ?? 'missing',
			'status' => $this->state['status'] ?? 'missing',
			'archive_path' => $current_archive ? basename($current_archive) : 'NOT SET',
		));

		try {
			ob_start();
			do_action('SNAPSHOTER_export_completed', $this->job_id, $this->state);
			$hook_output = ob_get_clean();
			if (!empty($hook_output) && defined('SNAPSHOTER_DEBUG') && SNAPSHOTER_DEBUG) {
				$this->log('Hook output: ' . substr($hook_output, 0, 500));
			}
			$this->debug_log('trigger_hooks() - Hook triggered successfully, BackupOrganizer handled it');
		} catch (\Throwable $hook_error) {
			while (ob_get_level() > 0) {
				ob_end_clean();
			}
			$this->log(sprintf('Warning: Hook error (export still succeeded): %s', $hook_error->getMessage()));
			if (defined('SNAPSHOTER_DEBUG') && SNAPSHOTER_DEBUG) {
				$this->log('Hook error trace: ' . $hook_error->getTraceAsString());
			}
		}

		$this->log('=== TRIGGER_HOOKS() COMPLETED ===');
	}

	private function emergency_dump_database()
	{
		global $wpdb;
		$this->log('Performing direct database dump fallback...');
		$tables = $wpdb->get_col('SHOW FULL TABLES');
		if (empty($tables)) {
			$tables = $wpdb->get_col('SHOW TABLES');
		}
		if (empty($tables)) {
			$tables = array_values((array) $wpdb->tables('all'));
		}
		if (empty($tables)) {
			return;
		}
		$sql_content = "-- Snapshoter Database Dump\n-- Generated: " . gmdate('c') . "\n\n";
		foreach ($tables as $table) {
			$table_escaped = esc_sql($table);
			$create = $wpdb->get_row("SHOW CREATE TABLE `{$table_escaped}`", ARRAY_N);
			if (empty($create) || empty($create[1])) {
				continue;
			}
			$sql_content .= "DROP TABLE IF EXISTS `{$table_escaped}`;\n" . $create[1] . ";\n\n";
			$rows = $wpdb->get_results("SELECT * FROM `{$table_escaped}`", ARRAY_A);
			if (!empty($rows)) {
				$sql_content .= $this->build_insert_statements($table, $rows);
			}
		}
		if (!empty($sql_content)) {
			file_put_contents($this->database_path, $sql_content);
			file_put_contents($this->archive_path, $sql_content);
			$this->log(sprintf('Emergency dump completed: %d bytes written.', strlen($sql_content)));
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

	private function discover_files_chunk()
	{
		$queue_path = $this->discover_queue_path();
		$list_path = $this->files_list_jsonl_path();
		if (!file_exists($queue_path)) {
			$this->bootstrap_file_discovery_index();
		}

		$time_limit = \Snapshoter\Core\Environment::can_extend_time() ? 14 : 8;
		$start = microtime(true);
		$root = trailingslashit(wp_normalize_path(ABSPATH));
		$storage_root = wp_normalize_path(SNAPSHOTER_STORAGE);

		$offset = (int) $this->get('discover_queue_offset', 0);
		$queue_total = (int) $this->get('discover_queue_total', 1);
		$total_files = (int) $this->get('total_files', 0);
		$estimated_bytes = (int) $this->get('estimated_files_bytes', 1);
		$dirs_processed = 0;
		$max_dirs_per_tick = 150;

		$file = new \SplFileObject($queue_path, 'r');
		if ($offset > 0) {
			$file->seek($offset);
		}

		while (!$file->eof() && $dirs_processed < $max_dirs_per_tick && (microtime(true) - $start) < $time_limit) {
			$line = trim((string) $file->current());
			$file->next();
			if ($line === '') {
				++$offset;
				continue;
			}

			$relative_dir = json_decode($line, true);
			if (!is_string($relative_dir)) {
				++$offset;
				continue;
			}

			$abs_dir = ($relative_dir === '') ? rtrim($root, '/') : $root . $relative_dir;
			$entries = @scandir($abs_dir);

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				$rel = ($relative_dir === '') ? $entry : $relative_dir . '/' . $entry;
				$abs = $root . $rel;
				$norm_abs = wp_normalize_path($abs);

				if (strpos($norm_abs, $storage_root) === 0) {
					continue;
				}

				if (is_dir($abs)) {
					if (!$this->should_skip_directory_branch($rel)) {
						file_put_contents(
							$queue_path,
							wp_json_encode($rel, JSON_UNESCAPED_SLASHES) . "\n",
							FILE_APPEND | LOCK_EX
						);
						++$queue_total;
					}
					continue;
				}

				if (!is_file($abs) || !$this->should_export_relative_file($rel)) {
					continue;
				}

				file_put_contents(
					$list_path,
					wp_json_encode($rel, JSON_UNESCAPED_SLASHES) . "\n",
					FILE_APPEND | LOCK_EX
				);
				++$total_files;
				$size = @filesize($abs);
				if (is_int($size) && $size > 0) {
					$estimated_bytes += $size;
				}
			}

			++$offset;
			++$dirs_processed;
		}

		$done = ($offset >= $queue_total);

		$this->update(
			array(
				'discover_queue_offset' => $offset,
				'discover_queue_total' => $queue_total,
				'total_files' => $total_files,
				'estimated_files_bytes' => max(1, $estimated_bytes),
			)
		);

		if ($done) {
			$this->log(
				sprintf(
					'File scan complete: %s files (%s).',
					number_format_i18n($total_files),
					size_format($estimated_bytes)
				)
			);
		} elseif ($total_files > 0 && $total_files % 10000 < $max_dirs_per_tick) {
			$this->log(sprintf('Scanning files... %s found so far.', number_format_i18n($total_files)));
		}

		return $done;
	}

	private function bootstrap_file_discovery_index()
	{
		$list_path = $this->files_list_jsonl_path();
		$queue_path = $this->discover_queue_path();
		if (!file_exists($list_path)) {
			touch($list_path);
		}
		if (!file_exists($queue_path)) {
			file_put_contents($queue_path, wp_json_encode('', JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
		}
	}

	private function files_list_jsonl_path()
	{
		return $this->store->job_dir($this->job_id) . '/files_list.jsonl';
	}

	private function discover_queue_path()
	{
		return $this->store->job_dir($this->job_id) . '/discover_queue.jsonl';
	}

	private function read_files_list_batch($offset, $limit)
	{
		$this->ensure_files_list_jsonl_from_legacy();

		$jsonl = $this->files_list_jsonl_path();
		if (!file_exists($jsonl)) {
			return array('paths' => array(), 'total' => 0);
		}

		$total = (int) $this->get('total_files', 0);
		$paths = array();
		$file = new \SplFileObject($jsonl, 'r');
		if ($offset > 0) {
			$file->seek($offset);
		}

		$read = 0;
		while (!$file->eof() && $read < $limit) {
			$line = trim((string) $file->current());
			$file->next();
			if ($line === '') {
				continue;
			}
			$decoded = json_decode($line, true);
			if (!is_string($decoded) || $decoded === '') {
				continue;
			}
			$paths[] = $decoded;
			++$read;
		}

		if ($total <= 0) {
			$total = $this->count_jsonl_lines($jsonl);
		}

		return array(
			'paths' => $paths,
			'total' => $total,
		);
	}

	private function ensure_files_list_jsonl_from_legacy()
	{
		$jsonl = $this->files_list_jsonl_path();
		if (file_exists($jsonl) && filesize($jsonl) > 0) {
			return;
		}

		$legacy = $this->store->job_dir($this->job_id) . '/files_list.json';
		if (!file_exists($legacy)) {
			return;
		}

		$paths = json_decode((string) file_get_contents($legacy), true);
		if (!is_array($paths)) {
			return;
		}

		$handle = @fopen($jsonl, 'wb');
		if (!$handle) {
			return;
		}

		foreach ($paths as $path) {
			if (!is_string($path) || $path === '') {
				continue;
			}
			fwrite($handle, wp_json_encode($path, JSON_UNESCAPED_SLASHES) . "\n");
		}
		fclose($handle);
	}

	private function count_jsonl_lines($path)
	{
		if (!is_readable($path)) {
			return 0;
		}

		$count = 0;
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			return 0;
		}

		while (!feof($handle)) {
			$line = fgets($handle);

		}
		fclose($handle);

		return $count;
	}

	private function profile_table_export_limits(array $tables)
	{
		global $wpdb;

		$profiles = array();
		if (empty($tables) || !defined('DB_NAME')) {
			return $profiles;
		}

		$columns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, DATA_TYPE FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND DATA_TYPE IN ('blob','mediumblob','longblob','text','mediumtext','longtext')",
				DB_NAME
			),
			ARRAY_A
		);

		if (is_array($columns)) {
			foreach ($columns as $row) {
				$name = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
				$type = isset($row['DATA_TYPE']) ? strtolower((string) $row['DATA_TYPE']) : '';
				if ($name === '' || !in_array($name, $tables, true)) {
					continue;
				}
				$current = isset($profiles[ $name ]) ? (int) $profiles[ $name ] : 800;
				if (in_array($type, array('longblob', 'longtext'), true)) {
					$profiles[ $name ] = min($current, 25);
				} elseif (in_array($type, array('mediumblob', 'mediumtext'), true)) {
					$profiles[ $name ] = min($current, 75);
				} else {
					$profiles[ $name ] = min($current, 150);
				}
			}
		}

		$table_stats = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				DB_NAME
			),
			ARRAY_A
		);

		if (is_array($table_stats)) {
			foreach ($table_stats as $row) {
				$name = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
				$avg = isset($row['AVG_ROW_LENGTH']) ? (int) $row['AVG_ROW_LENGTH'] : 0;
				if ($name === '' || !in_array($name, $tables, true) || $avg < 16384) {
					continue;
				}
				$current = isset($profiles[ $name ]) ? (int) $profiles[ $name ] : 800;
				if ($avg >= 65536) {
					$profiles[ $name ] = min($current, 25);
				} elseif ($avg >= 32768) {
					$profiles[ $name ] = min($current, 50);
				} elseif ($avg >= 16384) {
					$profiles[ $name ] = min($current, 100);
				}
			}
		}

		foreach ($tables as $table) {
			if (preg_match('/(postmeta|options|usermeta|commentmeta|woocommerce_order_itemmeta)$/i', (string) $table)) {
				$current = isset($profiles[ $table ]) ? (int) $profiles[ $table ] : 800;
				$profiles[ $table ] = min($current, 100);
			}
		}

		return $profiles;
	}

	private function resolve_row_chunk_for_table($table)
	{
		$adaptive = (int) $this->get('row_chunk', 800);
		$profiles = $this->get('table_row_chunks', array());
		if (!is_array($profiles)) {
			$profiles = array();
		}

		$table = (string) $table;
		$cap = isset($profiles[ $table ]) ? (int) $profiles[ $table ] : 800;
		if ($cap < 1) {
			$cap = 800;
		}

		return max(10, min($adaptive, $cap));
	}

	private function append_insert_statements($table, array $rows)
	{
		if (empty($rows)) {
			return;
		}

		$sub_batch = 40;
		$max_sql_bytes = 1024 * 1024;
		$offset = 0;
		$total = count($rows);

		while ($offset < $total) {
			$slice = array_slice($rows, $offset, $sub_batch);
			$sql = $this->build_insert_statements($table, $slice);

			while (strlen($sql) > $max_sql_bytes && count($slice) > 1) {
				$slice = array_slice($slice, 0, max(1, (int) floor(count($slice) / 2)));
				$sql = $this->build_insert_statements($table, $slice);
			}

			file_put_contents($this->database_path, $sql, FILE_APPEND);
			$offset += count($slice);
			unset($sql, $slice);
		}
	}

	private function export_cache_dir_patterns()
	{
		return array(
			'wp-content/cache/',
			'wp-content/w3tc-cache/',
			'wp-content/autoptimize/',
			'wp-content/litespeed/',
			'wp-content/breeze/',
			'wp-content/et_cache/',
			'wp-content/flying-press/',
			'wp-content/uploads/wp-rocket/',
			'wp-content/uploads/elementor/css/',
			'wp-content/updraft/',
			'wp-content/ai1wm-backups/',
			'wp-content/backup-migration/',
			'wp-content/backups/',
			'.git/',
			'.svn/',
			'node_modules/',
		);
	}

	private function should_skip_directory_branch($relative_dir)
	{
		$relative_dir = str_replace('\\', '/', (string) $relative_dir);
		$check = ($relative_dir === '') ? '' : trailingslashit($relative_dir);
		foreach ($this->export_cache_dir_patterns() as $pattern) {
			if ($check !== '' && strpos($check, $pattern) === 0) {
				return true;
			}
		}
		return false;
	}

	private function should_export_relative_file($file)
	{
		$file = str_replace('\\', '/', (string) $file);
		if ($file === '') {
			return false;
		}

		if (in_array(
			$file,
			array(
				'wp-content/object-cache.php',
				'wp-content/advanced-cache.php',
				'wp-content/db.php',
			),
			true
		)) {
			return false;
		}

		foreach ($this->export_cache_dir_patterns() as $cp) {
			if (strpos($file, $cp) !== false) {
				return false;
			}
		}

		if (basename($file) === 'error_log' || substr($file, -4) === '.log') {
			return false;
		}

		if (strpos(basename($file), 'hostinger-') === 0) {
			return false;
		}

		$lower = strtolower($file);
		if (substr($lower, -8) === '.smartin' || substr($lower, -7) === '.wpress') {
			return false;
		}
		if (preg_match('#^wp-content/(plugins|themes)/[^/]+\.(zip|tgz|tar\.gz)$#', $lower)) {
			return false;
		}

		return true;
	}

	private function generate_archive_path()
	{
		$scope = $this->get('scope', 'full');
		$ext = 'smartin';
		if ($scope === 'database') {
			$ext = 'sql';
		} elseif ($scope === 'files') {
			$ext = 'zip';
		}

		$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		if ($host === '') {
			$host = 'site';
		}

		$slug = sanitize_file_name(
			sprintf(
				'%s-%s.%s',
				$host,
				gmdate('Y-m-d-H.i.s'),
				$ext
			)
		);

		$path = trailingslashit(SNAPSHOTER_ARCHIVE_DIR) . $slug;

		if (file_exists($path)) {
			$path = trailingslashit(SNAPSHOTER_ARCHIVE_DIR) . sanitize_file_name(
				sprintf('%s-%s-%s.%s', $host, gmdate('Y-m-d-H.i.s'), substr((string) time(), -4), $ext)
			);
		}

		return $path;
	}

	private function discard_incomplete_archive($reason = 'failed')
	{
		$paths = array(
			(string) $this->get('archive_path', ''),
			(string) $this->get('snapshot_path', ''),
		);
		$removed = array();
		foreach ($paths as $path) {
			if ($path === '' || !file_exists($path) || isset($removed[$path])) {
				continue;
			}

			$ok_dir = false;
			foreach (array(SNAPSHOTER_ARCHIVE_DIR, SNAPSHOTER_SNAPSHOTS_DIR) as $root) {
				if ($root && strpos($path, trailingslashit($root)) === 0) {
					$ok_dir = true;
					break;
				}
			}
			if (!$ok_dir) {
				continue;
			}
			wp_delete_file($path); if (!file_exists($path)) {
				$removed[$path] = true;
				$this->log(sprintf('Removed incomplete archive after %s: %s', $reason, basename($path)));
			}
		}
		if (!empty($removed)) {
			$this->update(array(
				'archive_path' => '',
				'snapshot_path' => '',
			));
		}
	}

	private function get_htaccess_content()
	{
		$htaccess_path = ABSPATH . '.htaccess';

		if (file_exists($htaccess_path) && is_readable($htaccess_path)) {
			$content = file_get_contents($htaccess_path);

		}

		return '';
	}

	private function get_webconfig_content()
	{
		$webconfig_path = ABSPATH . 'web.config';

		if (file_exists($webconfig_path) && is_readable($webconfig_path)) {
			$content = file_get_contents($webconfig_path);

		}

		return '';
	}

	protected function calculate_progress()
	{
		$step = $this->get('step', self::STEP_DATABASE);

		$scope = $this->get('scope', 'full');
		$discover_weight = ($scope === 'files')
			? array('min' => 5, 'max' => 40)
			: array('min' => 40, 'max' => 55);
		$files_weight = ($scope === 'files')
			? array('min' => 40, 'max' => 95)
			: array('min' => 55, 'max' => 95);

		$step_weights = array(
			self::STEP_INIT => array('min' => 0, 'max' => 5),
			self::STEP_DATABASE => array('min' => 5, 'max' => 40),
			self::STEP_DISCOVER => $discover_weight,
			self::STEP_FILES => $files_weight,
			self::STEP_FINALIZE => array('min' => 95, 'max' => 98),
			self::STEP_HOOKS => array('min' => 98, 'max' => 99),
			self::STEP_COMPLETED => array('min' => 100, 'max' => 100),
		);

		$step_info = isset($step_weights[$step]) ? $step_weights[$step] : array('min' => 0, 'max' => 0);
		$base_progress = $step_info['min'];
		$step_range = $step_info['max'] - $step_info['min'];

		$sub_progress = 0;

		switch ($step) {
			case self::STEP_DATABASE:
				$tables = $this->get('tables', array());
				$table_index = (int) $this->get('table_index', 0);
				$table_count = count($tables);
				$by_tables = 0;
				if ($table_count > 0) {
					$table_offset = (int) $this->get('table_offset', 0);
					$row_estimates = $this->get('table_row_estimates', array());
					$table_fraction = (float) $table_index;
					if (isset($tables[ $table_index ])) {
						$current_table = (string) $tables[ $table_index ];
						$est_rows = isset($row_estimates[ $current_table ])
							? max(1, (int) $row_estimates[ $current_table ])
							: 0;
						if ($est_rows > 0 && $table_offset > 0) {
							$table_fraction += min(0.99, $table_offset / $est_rows);
						}
					}
					$by_tables = min(100, ($table_fraction / $table_count) * 100);
				}
				$db_path = (string) $this->get('database_path', '');
				$db_bytes = ($db_path !== '' && file_exists($db_path)) ? (int) filesize($db_path) : 0;
				$est_db_bytes = (int) $this->get('estimated_db_bytes', 0);
				$by_db_bytes = ($est_db_bytes > 0 && $db_bytes > 0)
					? min(100, ($db_bytes / $est_db_bytes) * 100)
					: 0;
				$sub_progress = max($by_tables, $by_db_bytes);
				break;

			case self::STEP_DISCOVER:
				$offset = (int) $this->get('discover_queue_offset', 0);
				$total_dirs = max(1, (int) $this->get('discover_queue_total', 1));
				$sub_progress = min(100, ($offset / $total_dirs) * 100);
				break;

			case self::STEP_FILES:
				$processed = (int) $this->get('files_processed', 0);
				$total = (int) $this->get('total_files', 1);
				$by_count = ($total > 0) ? min(100, ($processed / $total) * 100) : 0;
				$arch_path = (string) $this->get('archive_path', '');
				$arch_bytes = ($arch_path !== '' && file_exists($arch_path)) ? (int) filesize($arch_path) : 0;
				$est_files_bytes = (int) $this->get('estimated_files_bytes', 0);
				$by_file_bytes = ($est_files_bytes > 0 && $arch_bytes > 0)
					? min(100, ($arch_bytes / $est_files_bytes) * 100)
					: 0;
				$sub_progress = max($by_count, $by_file_bytes);
				break;

			case self::STEP_FINALIZE:
			case self::STEP_COMPLETED:
				$sub_progress = 100;
				break;

			case self::STEP_HOOKS:
				$sub_progress = 100;
				break;
		}

		$progress = $base_progress + ($step_range * ($sub_progress / 100));

		return $this->finalize_export_progress($progress);
	}

	protected function finalize_export_progress($calculated)
	{
		$peak = (float) $this->get('progress_peak', 0);
		$progress = max($peak, (float) $calculated);
		if ($progress > $peak) {
			$this->update(array('progress_peak' => $progress));
		}

		return min(100, max(0, round($progress, 2)));
	}

	protected function backup_progress_detail()
	{
		$step = $this->get('step', self::STEP_DATABASE);

		if ($step === self::STEP_DATABASE) {
			$tables = $this->get('tables', array());
			$table_index = (int) $this->get('table_index', 0);
			$table_count = count($tables);
			$current_table = ($table_count > 0 && isset($tables[ $table_index ]))
				? (string) $tables[ $table_index ]
				: '';
			$db_path = (string) $this->get('database_path', '');
			$db_bytes = ($db_path !== '' && file_exists($db_path)) ? (int) filesize($db_path) : 0;
			$shown_table = min($table_count, $table_index + 1);

			$detail = sprintf(

				// translators: %1$d: number 1; %2$d: number 2
				__('Database table %1$d/%2$d', 'snapshoter'),
				$table_count > 0 ? $shown_table : 0,
				max(1, $table_count)
			);
			if ($current_table !== '') {
				$detail .= ' · ' . $current_table;
			}
			if ($db_bytes > 0) {
				$detail .= ' · ' . sprintf(

					// translators: %s: SQL dump size.
					__('SQL %s', 'snapshoter'),
					size_format($db_bytes)
				);
			}

			return $detail;
		}

		if ($step === self::STEP_DISCOVER) {
			$found = (int) $this->get('total_files', 0);
			$dirs_done = (int) $this->get('discover_queue_offset', 0);
			$dirs_total = max(1, (int) $this->get('discover_queue_total', 1));
			$detail = sprintf(

				// translators: %s: number of files found.
				__('Scanning files - %s found', 'snapshoter'),
				number_format_i18n($found)
			);
			$detail .= sprintf(

				// translators: %1$d: number 1; %2$d: number 2
				__(' · dirs %1$d / %2$d', 'snapshoter'),
				$dirs_done,
				$dirs_total
			);
			$est_bytes = (int) $this->get('estimated_files_bytes', 0);
			if ($est_bytes > 1) {
				$detail .= ' · ' . size_format($est_bytes);
			}
			return $detail;
		}

		if ($step === self::STEP_FILES) {
			$processed = (int) $this->get('files_processed', 0);
			$total = (int) $this->get('total_files', 0);
			$arch_path = (string) $this->get('archive_path', '');
			$arch_bytes = ($arch_path !== '' && file_exists($arch_path)) ? (int) filesize($arch_path) : 0;

			$detail = sprintf(

				// translators: %1$s: files processed; %2$s: total files.
				__('Files %1$s / %2$s', 'snapshoter'),
				number_format_i18n($processed),
				number_format_i18n(max(1, $total))
			);
			if ($arch_bytes > 0) {
				$detail .= ' · ' . sprintf(

					// translators: %s: archive size.
					__('Archive %s', 'snapshoter'),
					size_format($arch_bytes)
				);
			}

			return $detail;
		}

		return '';
	}

	private function estimate_database_export_stats(array $tables)
	{
		global $wpdb;

		$empty = array(
			'estimated_db_bytes' => 0,
			'table_row_estimates' => array(),
		);

		if (empty($tables) || !defined('DB_NAME')) {
			return $empty;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				DB_NAME
			),
			ARRAY_A
		);

		if (empty($rows)) {
			return $empty;
		}

		$wanted = array_flip($tables);
		$estimates = array();
		$data_bytes = 0;

		foreach ($rows as $row) {
			$name = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
			if ($name === '' || !isset($wanted[ $name ])) {
				continue;
			}
			$estimates[ $name ] = max(0, (int) ($row['TABLE_ROWS'] ?? 0));
			$data_bytes += (int) ($row['DATA_LENGTH'] ?? 0) + (int) ($row['INDEX_LENGTH'] ?? 0);
		}

		$estimated_db_bytes = (int) round($data_bytes * 1.25);
		if ($estimated_db_bytes < 1) {
			$estimated_db_bytes = max(1, count($tables) * 1024);
		}

		return array(
			'estimated_db_bytes' => $estimated_db_bytes,
			'table_row_estimates' => $estimates,
		);
	}

	protected function response(array $data = array())
	{
		$progress = $this->calculate_progress();
		$step = $this->get('step', self::STEP_DATABASE);

		$display_step = ($step === self::STEP_HOOKS) ? self::STEP_FINALIZE : $step;

		$out = array_merge(
			array(
				'jobId' => $this->job_id,
				'type' => $this->type(),
				'state' => array_merge($this->state, array('progress' => $progress, 'percent' => $progress, 'current_step' => $display_step)),
				'log' => $this->store->get_log($this->job_id),
				'progress' => $progress,
				'percent' => $progress,
				'detail' => $this->backup_progress_detail(),
			),
			$data
		);

		$path = '';
		$snap = (string) $this->get('snapshot_path', '');
		$arch = (string) $this->get('archive_path', '');
		$stored_name = (string) $this->get('filename', '');
		if ($snap !== '' && file_exists($snap)) {
			$path = $snap;
		} elseif ($arch !== '' && file_exists($arch)) {
			$path = $arch;
		}

		$filename = '';
		if ($path !== '') {
			$filename = basename($path);
		} elseif ($stored_name !== '' && preg_match('/\.(smartin|sql|zip)$/i', $stored_name)) {
			$filename = basename($stored_name);
		} elseif ($snap !== '') {
			$filename = basename($snap);
		} elseif ($arch !== '') {
			$filename = basename($arch);
		}

		if ($filename !== '' && preg_match('/\.(smartin|sql|zip)$/i', $filename)) {
			$url = add_query_arg(
				array(
					'action' => 'SNAPSHOTER_download_file',
					'file'   => $filename,
					'nonce'  => wp_create_nonce('SNAPSHOTER_download'),
				),
				admin_url('admin-ajax.php')
			);
			$out['filename'] = $filename;
			$out['download_url'] = $url;
			$out['downloadUrl'] = $url;
		}

		return $out;
	}

	private function detect_server_limits()
	{
		$memory_limit_str = ini_get('memory_limit');
		$memory_limit = $this->parse_memory_limit($memory_limit_str);
		$memory_used = memory_get_usage(true);
		$memory_available = $memory_limit > 0 ? ($memory_limit - $memory_used) : PHP_INT_MAX;

		$exec_time = (int) ini_get('max_execution_time');

		$this->log(sprintf(
			'Server limits: memory_limit=%s (%d bytes), available=%s, execution_time=%ds',
			$memory_limit_str,
			$memory_limit,
			size_format($memory_available),
			$exec_time
		));

		return array(
			'memory_limit' => $memory_limit,
			'memory_available' => $memory_available,
			'execution_time' => $exec_time
		);
	}

	private function record_skip($relative, $reason)
	{
		$state = $this->get('skipped_files', null);
		$updated = \Snapshoter\Core\SkipReporter::record($state, $relative, $reason);
		$this->update(array('skipped_files' => $updated));
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

	private function snapshoter_resolve_free_space($path)
	{
		if (!is_string($path) || $path === '') {
			return null;
		}

		$probe = $path;
		while (!is_dir($probe)) {
			$parent = dirname($probe);
			if ($parent === $probe || $parent === '' || $parent === '.') {
				return null;
			}
			$probe = $parent;
		}
		if (!\Snapshoter\Core\Environment::is_function_callable('disk_free_space')) {
			return null;
		}
		$free = @disk_free_space($probe);

		return (int) $free;
	}

	private function parse_memory_limit($limit)
	{
		if ($limit === '-1') {
			return PHP_INT_MAX;
		}

		$limit = trim($limit);
		$last = strtolower($limit[strlen($limit) - 1]);
		$value = (int) $limit;

		switch ($last) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}
	public function cleanup()
	{
		$this->log('Cleaning up temporary files...');

		if ($this->get('status') === 'cancelled') {
			$this->discard_incomplete_archive('cancelled');
		} else {
			$archive_path = $this->get('archive_path');
			if ($archive_path && file_exists($archive_path) && empty($this->state['is_snapshot'])) {

				wp_delete_file($archive_path);
			}
		}

		foreach (array('db_dump_file', 'database_path') as $db_key) {
			$db_dump = $this->get($db_key);
			if ($db_dump && file_exists($db_dump)) {

				wp_delete_file($db_dump);
			}
		}
		if (file_exists($this->database_path)) {

			wp_delete_file($this->database_path);
		}

		foreach (array('files_list.jsonl', 'discover_queue.jsonl', 'files_list.json') as $index_name) {
			$index_path = $this->store->job_dir($this->job_id) . '/' . $index_name;
			if ($index_path && file_exists($index_path)) {

				wp_delete_file($index_path);
			}
		}

		$this->store->delete($this->job_id);

		$this->log('Cleanup completed.');
	}

	private function get_safe_site_settings()
	{
		$settings = array();
		$settings_to_backup = array(
			'blogname',
			'blogdescription',
			'custom_logo',
			'site_icon',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'default_ping_status',
			'default_comment_status',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
		);

		foreach ($settings_to_backup as $option_name) {
			try {
				$value = get_option($option_name);

				if (is_scalar($value)) {
					$settings[$option_name] = $value;
				} elseif (is_null($value)) {
					$settings[$option_name] = null;
				} else {
					$defaults = array(
						'custom_logo' => 0,
						'site_icon' => 0,
						'page_on_front' => 0,
						'page_for_posts' => 0,
						'thumbnail_size_w' => 150,
						'thumbnail_size_h' => 150,
						'medium_size_w' => 300,
						'medium_size_h' => 300,
						'large_size_w' => 1024,
						'large_size_h' => 1024,
					);
					$settings[$option_name] = isset($defaults[$option_name]) ? $defaults[$option_name] : null;
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Could not backup option %s: %s', $option_name, $e->getMessage()));
				$settings[$option_name] = null;
			}
		}

		$stylesheet = get_option('stylesheet', '');
		if (!empty($stylesheet)) {
			try {
				$theme_mods = get_option('theme_mods_' . $stylesheet, array());
				if (!empty($theme_mods) && is_array($theme_mods)) {
					$settings['theme_mods'] = $this->sanitize_array_for_json($theme_mods);
					$this->log('Backed up theme_mods for ' . $stylesheet);
				}
			} catch (\Throwable $e) {
				$this->log(sprintf('Warning: Could not backup theme_mods: %s', $e->getMessage()));
			}
		}

		global $wpdb;
		try {
			$widget_options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					'widget_%'
				),
				ARRAY_A
			);

			$settings['widgets'] = array();
			foreach ($widget_options as $widget_option) {
				$option_name = $widget_option['option_name'];
				$option_value = maybe_unserialize($widget_option['option_value']);

				if (is_array($option_value) || is_scalar($option_value)) {
					$settings['widgets'][$option_name] = $this->sanitize_array_for_json($option_value);
				} elseif (is_object($option_value)) {

					$settings['widgets'][$option_name] = $this->sanitize_array_for_json($option_value);
				}
			}

			if (!empty($settings['widgets'])) {
				$this->log(sprintf('Backed up %d widget options', count($settings['widgets'])));
			}
		} catch (\Throwable $e) {
			$this->log(sprintf('Warning: Could not backup widgets: %s', $e->getMessage()));
		}

		try {
			$sidebars_widgets = get_option('sidebars_widgets', array());
			if (!empty($sidebars_widgets) && is_array($sidebars_widgets)) {
				$settings['sidebars_widgets'] = $this->sanitize_array_for_json($sidebars_widgets);
				$this->log('Backed up sidebars_widgets');
			}
		} catch (\Throwable $e) {
			$this->log(sprintf('Warning: Could not backup sidebars_widgets: %s', $e->getMessage()));
		}

		try {
			$custom_css = get_option('custom_css_post_id');
			if ($custom_css) {
				$css_post = get_post($custom_css);
				if ($css_post && $css_post->post_type === 'custom_css') {
					$settings['custom_css'] = array(
						'post_content' => $css_post->post_content,
						'post_title' => $css_post->post_title,
					);
					$this->log('Backed up custom CSS');
				}
			}
		} catch (\Throwable $e) {
			$this->log(sprintf('Warning: Could not backup custom CSS: %s', $e->getMessage()));
		}

		return $settings;
	}

	private function assert_zip_archive_ok($path, $when = '')
	{
		$path = (string) $path;
		$label = $when !== '' ? $when : 'integrity check';

		if ($path === '' || !file_exists($path)) {
			throw new \RuntimeException(
				esc_html__('Archive missing during integrity check.', 'snapshoter')
			);
		}

		$size = (int) filesize($path);
		if ($size < 64) {
			$this->log(sprintf('ERROR: Archive too small (%d bytes) %s', $size, $label));
			throw new \RuntimeException(
				esc_html__('Archive is empty or truncated - backup aborted.', 'snapshoter')
			);
		}

		$zip = new \ZipArchive();
		$flags = defined('ZipArchive::CHECKCONS') ? \ZipArchive::CHECKCONS : 0;
		$open = $zip->open($path, $flags);
		if ($open !== true) {
			$this->log(sprintf('ERROR: ZipArchive open failed (%s) code=%s size=%d', $label, (string) $open, $size));
			throw new \RuntimeException(
				esc_html__('Archive failed integrity check (corrupt zip). Free disk space and run the backup again.', 'snapshoter')
			);
		}

		$entries = (int) $zip->numFiles;
		$zip->close();
		$this->log(sprintf('Archive OK %s (%s, %d entries)', $label, size_format($size), $entries));

		if ($entries < 1) {
			throw new \RuntimeException(
				esc_html__('Archive has no entries - backup aborted.', 'snapshoter')
			);
		}
	}

	private function compute_integrity_block()
	{
		$db_size = 0;
		$db_sha256 = '';

		if (file_exists($this->database_path)) {
			$db_size = (int) filesize($this->database_path);
			$hash = @hash_file('sha256', $this->database_path);
			if (is_string($hash) && $hash !== '') {
				$db_sha256 = $hash;
			}
		}

		$files_count = (int) $this->get('total_files', 0);

		$integrity = array(
			'algorithm' => 'sha256',
			'version' => 1,
			'database_sha256' => $db_sha256,
			'database_size' => $db_size,
			'files_count' => $files_count,
		);

		$this->log(sprintf(
			'Computed integrity: db_sha256=%s..., db_size=%d, files=%d',
			$db_sha256 !== '' ? substr($db_sha256, 0, 12) : 'unavailable',
			$db_size,
			$files_count
		));

		return $integrity;
	}

	private function sanitize_manifest_for_json($manifest)
	{
		if (!is_array($manifest)) {

			if (is_object($manifest)) {
				$manifest = (array) $manifest;
			} else {
				return array();
			}
		}

		$sanitized = array();

		foreach ($manifest as $key => $value) {
			if (is_scalar($value) || is_null($value)) {
				$sanitized[$key] = $value;
			} elseif (is_array($value)) {
				$sanitized[$key] = $this->sanitize_array_for_json($value);
			} elseif (is_object($value)) {

				$sanitized[$key] = $this->sanitize_array_for_json((array) $value);
			}
		}

		return $sanitized;
	}

	private function sanitize_array_for_json($array, $depth = 0)
	{

		if ($depth > 50) {
			return array();
		}

		if (!is_array($array)) {

			if (is_object($array)) {
				$array = (array) $array;
			} else {
				return array();
			}
		}

		$sanitized = array();

		foreach ($array as $key => $value) {
			if (is_scalar($value) || is_null($value)) {
				$sanitized[$key] = $value;
			} elseif (is_array($value)) {
				$sanitized[$key] = $this->sanitize_array_for_json($value, $depth + 1);
			} elseif (is_object($value)) {

				$sanitized[$key] = $this->sanitize_array_for_json((array) $value, $depth + 1);
			}
		}

		return $sanitized;
	}
}

