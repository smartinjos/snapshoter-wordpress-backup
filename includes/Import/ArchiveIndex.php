<?php

namespace Snapshoter\Import;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO

final class ArchiveIndex
{

	private $db_path;

	private $pdo;

	private $json_path;

	public function __construct($job_dir)
	{
		$job_dir = rtrim((string) $job_dir, '/\\');
		$this->db_path = $job_dir . '/archive_index.sqlite';
		$this->json_path = $job_dir . '/archive_index.json';
	}

	public static function sqlite_available()
	{
		return class_exists('\\PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true);
	}

	public function build_from_zip(\ZipArchive $zip)
	{
		$entries = array();
		$total_bytes = 0;
		$file_count = 0;

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!is_array($stat) || empty($stat['name'])) {
				continue;
			}
			$name = str_replace(array('\\', "\0"), array('/', ''), (string) $stat['name']);
			if ($name === '' || substr($name, -1) === '/') {
				continue;
			}
			if (!$this->is_safe_zip_path($name)) {
				continue;
			}

			$size = isset($stat['size']) ? (int) $stat['size'] : 0;
			$entries[] = array(
				'idx'  => (int) $i,
				'name' => $name,
				'size' => $size,
			);
			$total_bytes += max(0, $size);
			$file_count++;
		}

		$entries = self::sort_entries_for_restore($entries);

		if (self::sqlite_available()) {
			$this->write_sqlite($entries);
			return array(
				'total'   => count($entries),
				'files'   => $file_count,
				'bytes'   => $total_bytes,
				'backend' => 'sqlite',
				'path'    => $this->db_path,
			);
		}

		@file_put_contents($this->json_path, wp_json_encode($entries));
		return array(
			'total'   => count($entries),
			'files'   => $file_count,
			'bytes'   => $total_bytes,
			'backend' => 'json',
			'path'    => $this->json_path,
		);
	}

	public function slice($cursor, $limit = 200)
	{
		$cursor = max(0, (int) $cursor);
		$limit = max(1, (int) $limit);

		if (self::sqlite_available() && file_exists($this->db_path)) {
			$pdo = $this->pdo();
			$stmt = $pdo->prepare('SELECT zip_idx AS idx, name, size FROM entries WHERE id > ? ORDER BY id ASC LIMIT ?');

			$stmt->execute(array($cursor, $limit));
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			$out = array();
			foreach ($rows as $row) {
				$out[] = array(
					'idx'  => (int) $row['idx'],
					'name' => (string) $row['name'],
					'size' => (int) $row['size'],
				);
			}
			return $out;
		}

		if (!file_exists($this->json_path)) {
			return array();
		}
		$all = json_decode((string) file_get_contents($this->json_path), true);
		if (!is_array($all)) {
			return array();
		}
		return array_values(array_slice($all, $cursor, $limit));
	}

	public function count()
	{
		if (self::sqlite_available() && file_exists($this->db_path)) {
			$pdo = $this->pdo();
			return (int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();
		}
		if (!file_exists($this->json_path)) {
			return 0;
		}
		$all = json_decode((string) file_get_contents($this->json_path), true);
		return is_array($all) ? count($all) : 0;
	}

	public static function restore_path_bucket($relative)
	{
		$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
		if ($relative === '') {
			return 0;
		}

		if ($relative === 'wp-content' || strpos($relative, 'wp-content/') === 0) {
			return 1;
		}

		if (self::is_wordpress_core_path($relative)) {
			return 3;
		}

		return 2;
	}

	public static function is_wordpress_core_path($relative)
	{
		$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
		if ($relative === '') {
			return false;
		}

		$is_core_tree = (
			$relative === 'wp-admin' ||
			$relative === 'wp-includes' ||
			strpos($relative, 'wp-admin/') === 0 ||
			strpos($relative, 'wp-includes/') === 0
		);
		$is_bootstrap_php = (bool) preg_match(
			'/^(wp-.*\.php|xmlrpc\.php|index\.php)$/i',
			$relative
		);

		return $is_core_tree || $is_bootstrap_php;
	}

	public static function sort_entries_for_restore(array $entries)
	{
		usort(
			$entries,
			static function ($a, $b) {
				$name_a = (string) $a['name'];
				$name_b = (string) $b['name'];
				$rel_a = self::site_relative_from_entry($name_a);
				$rel_b = self::site_relative_from_entry($name_b);
				$bucket_a = $rel_a === null ? 0 : self::restore_path_bucket($rel_a);
				$bucket_b = $rel_b === null ? 0 : self::restore_path_bucket($rel_b);
				if ($bucket_a !== $bucket_b) {
					return $bucket_a <=> $bucket_b;
				}
				return strcmp($name_a, $name_b);
			}
		);

		return $entries;
	}

	public static function site_relative_from_entry($name)
	{
		$relative = \Snapshoter\Core\PathSafety::site_relative_from_zip_entry($name);
		if ($relative === null || $relative === '') {
			return null;
		}

		if (
			$relative === 'database' ||
			strpos($relative, 'database/') === 0 ||
			$relative === 'database.sql' ||
			$relative === 'manifest.json' ||
			$relative === 'extraction_queue.json' ||
			$relative === 'restore_queue.json' ||
			$relative === 'archive_index.json' ||
			$relative === 'archive_index.sqlite'
		) {
			return null;
		}

		if (
			strpos($relative, 'wp-content/plugins/snapshoter/') === 0 ||
			strpos($relative, 'plugins/snapshoter/') === 0
		) {
			return null;
		}

		if ($relative === 'wp-config.php' || $relative === '.htaccess') {
			return null;
		}

		if (in_array($relative, array('wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/db.php'), true)) {
			return null;
		}

		return $relative;
	}

	private function is_safe_zip_path($name)
	{
		return \Snapshoter\Core\PathSafety::is_safe_zip_entry($name);
	}

	private function write_sqlite(array $entries)
	{
		if (file_exists($this->db_path)) {
			wp_delete_file($this->db_path);
		}
		$pdo = $this->pdo();
		$pdo->exec('CREATE TABLE entries (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			zip_idx INTEGER NOT NULL,
			name TEXT NOT NULL,
			size INTEGER NOT NULL DEFAULT 0
		)');
		$pdo->beginTransaction();
		$stmt = $pdo->prepare('INSERT INTO entries (zip_idx, name, size) VALUES (?, ?, ?)');
		foreach ($entries as $e) {
			$stmt->execute(array((int) $e['idx'], (string) $e['name'], (int) $e['size']));
		}
		$pdo->commit();
	}

	private function pdo()
	{
		if ($this->pdo instanceof \PDO) {
			return $this->pdo;
		}
		$this->pdo = new \PDO('sqlite:' . $this->db_path);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		return $this->pdo;
	}
}
