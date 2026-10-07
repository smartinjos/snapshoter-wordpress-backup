<?php

namespace Snapshoter\Core;

use Snapshoter\Core\AdminPage;
use Snapshoter\Core\JobController;
use Snapshoter\Core\InstructionsPage;
use Snapshoter\Core\BackupOrganizer;
use Snapshoter\Core\LoggerTrait;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

final class Plugin
{
	use LoggerTrait;

	private static $instance;

	private static $boot_errors = array();

	private $jobs;

	private $admin;

	private $instructions;

	private $backup_organizer;

	private function __construct()
	{

		$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
		if (in_array($action, self::guarded_ajax_actions(), true)) {
			if (ob_get_level() === 0) {
				ob_start();
			}
			add_action('shutdown', array($this, 'catch_html_errors'), 999);
		}

		$this->jobs = new JobController();
		$this->admin = new AdminPage();
		$this->instructions = new InstructionsPage();
		$this->backup_organizer = new BackupOrganizer();

		register_activation_hook(SNAPSHOTER_FILE, array(__CLASS__, 'activate'));
		register_deactivation_hook(SNAPSHOTER_FILE, array(__CLASS__, 'deactivate'));

		if (function_exists('add_action')) {
			$this->check_duplicate_installations();
		}

		add_action('plugins_loaded', array($this, 'check_requirements'));
		add_action('plugins_loaded', array($this, 'maybe_resume_restore_safety_hooks'), 5);
		add_action('admin_menu', array($this, 'register_admin_menu'), 9);
		add_action('admin_head', array($this, 'render_menu_icon_styles'), 99);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		if (defined('SNAPSHOTER_FILE')) {
			add_filter('plugin_action_links_' . plugin_basename(SNAPSHOTER_FILE), array($this, 'add_plugin_action_links'));
		}

		add_action('plugins_loaded', array($this, 'maybe_suppress_db_checks'), 1);
		add_action('init', array($this, 'maybe_suppress_db_checks'), 1);
		add_action('admin_init', array($this, 'maybe_suppress_db_checks'), 1);

		add_action('wp_ajax_SNAPSHOTER_start_job', array($this->jobs, 'start_job'));
		add_action('wp_ajax_SNAPSHOTER_run_job', array($this->jobs, 'run_job'));
		add_action('wp_ajax_SNAPSHOTER_cancel_job', array($this->jobs, 'cancel_job'));
		add_action('wp_ajax_SNAPSHOTER_get_status', array($this->jobs, 'get_status'));
		add_action('wp_ajax_SNAPSHOTER_upload_chunk', array($this->jobs, 'upload_chunk'));
		add_action('wp_ajax_SNAPSHOTER_download_archive', array($this->jobs, 'download_archive'));
		add_action('wp_ajax_SNAPSHOTER_get_upload_limits', array($this->jobs, 'get_upload_limits'));
		add_action('wp_ajax_SNAPSHOTER_restore_site_settings', array($this->jobs, 'restore_site_settings'));
		add_action('wp_ajax_SNAPSHOTER_export_database_only', array($this->jobs, 'export_database_only'));
		add_action('wp_ajax_SNAPSHOTER_export_files_only', array($this->jobs, 'export_files_only'));
		add_action('wp_ajax_SNAPSHOTER_download_file', array($this->jobs, 'download_file'));
		add_action('wp_ajax_SNAPSHOTER_create_snapshot', array($this->jobs, 'create_snapshot'));
		add_action('wp_ajax_SNAPSHOTER_get_job_log', array($this->jobs, 'get_job_log'));
		add_action('wp_ajax_SNAPSHOTER_clear_cache', array($this->jobs, 'clear_cache'));
		add_action('wp_ajax_SNAPSHOTER_list_snapshots', array($this->jobs, 'list_snapshots'));
		add_action('wp_ajax_SNAPSHOTER_restore_snapshot', array($this->jobs, 'restore_snapshot'));
		add_action('wp_ajax_SNAPSHOTER_delete_snapshot', array($this->jobs, 'delete_snapshot'));

		add_action('wp_ajax_SNAPSHOTER_refresh_nonce', array($this->jobs, 'refresh_nonce'));

		add_action('wp_ajax_nopriv_SNAPSHOTER_run_job', array($this->jobs, 'run_job'));
		add_action('wp_ajax_nopriv_SNAPSHOTER_get_status', array($this->jobs, 'get_status'));

		add_action(JobController::BACKGROUND_TICK_HOOK, array($this->jobs, 'handle_background_tick'), 10, 1);

		add_action('admin_init', array('\\Snapshoter\\Core\\RestoreSessionGuard', 'register'));

		add_action('SNAPSHOTER_export_completed', array($this->backup_organizer, 'handle_export_completion'), 10, 2);
		add_action('SNAPSHOTER_export_completed', array('\\Snapshoter\\Core\\VaultPrune', 'after_backup'), 20, 2);
	}

	private static function guarded_ajax_actions()
	{
		return array('SNAPSHOTER_run_job', 'SNAPSHOTER_upload_chunk', 'SNAPSHOTER_get_status');
	}

	/**
	 * If a restore crashed mid-finalize, keep AS paused briefly; expire after 2h.
	 */
	public function maybe_resume_restore_safety_hooks()
	{
		if (!class_exists('\\Snapshoter\\Import\\RestoreSafety')) {
			return;
		}
		if (!\Snapshoter\Import\RestoreSafety::is_enabled()) {
			\Snapshoter\Import\RestoreSafety::disarm_queue_pause();
			return;
		}
		$armed = get_option(\Snapshoter\Import\RestoreSafety::PAUSE_OPTION, '');
		if ($armed === '' || $armed === false) {
			return;
		}
		$ts = (int) $armed;
		if ($ts > 0 && (time() - $ts) > 2 * HOUR_IN_SECONDS) {
			\Snapshoter\Import\RestoreSafety::disarm_queue_pause();
			return;
		}
		\Snapshoter\Import\RestoreSafety::install_runtime_hooks();
	}

	public function maybe_suppress_db_checks()
	{
		if (!wp_doing_ajax()) {
			return;
		}

		$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';

		if (in_array($action, self::guarded_ajax_actions(), true)) {
			if (!defined('DIEONDBERROR')) {

				define('DIEONDBERROR', false);
			}

			add_filter('wp_die_handler', array($this, 'ajax_wp_die_handler'), 999);

			$this->snapshoter_suppress_db_checks();

			$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

			if (!empty($job_id)) {
				$store = new \Snapshoter\Core\StateStore();
				$state = $store->read($job_id);
			}
		}
	}

	public function catch_html_errors()
	{
		if (!wp_doing_ajax()) {
			return;
		}

		$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';

		if (!in_array($action, self::guarded_ajax_actions(), true)) {
			return;
		}

		$output = '';
		while (ob_get_level() > 0) {
			$output = ob_get_contents() . $output;
			ob_end_clean();
		}

		if (!empty($output) && !$this->is_json($output)) {
			$this->debug_log('CRITICAL - HTML output detected at shutdown', array('length' => strlen($output)));

			if (stripos($output, 'Error establishing a database connection') !== false) {
				$this->debug_log('Database connection error HTML detected at shutdown');
				$this->send_json_error_response('Database connection error occurred during import. Please check your database settings.');
				exit;
			}

			$error_message = $this->extract_error_message($output);
			if ($error_message) {
				$this->debug_log('Converting HTML error to JSON', array('error' => substr($error_message, 0, 100)));
				$this->send_json_error_response($error_message);
				exit;
			}
		}
	}

	private function is_json($string)
	{
		if (empty($string)) {
			return false;
		}
		$trimmed = trim($string);
		return ($trimmed[0] === '{' || $trimmed[0] === '[');
	}

	private function extract_error_message($html)
	{
		if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
			return wp_strip_all_tags($matches[1]);
		}
		if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
			return wp_strip_all_tags($matches[1]);
		}
		return 'An error occurred during the operation.';
	}

	private function send_json_error_response($message)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			http_response_code(500);

			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Cache-Control: post-check=0, pre-check=0', false);
			header('Pragma: no-cache');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
		}

		$response = array(
			'success' => false,
			'data' => array(
				'message' => $message,
			),
		);

		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public function ajax_wp_die_handler($handler)
	{
		if (!wp_doing_ajax()) {
			return $handler;
		}

		return function ($message, $title = '', $args = array ()) {
			while (ob_get_level() > 0) {
				ob_end_clean();
			}

			if (!headers_sent()) {
				header('Content-Type: application/json; charset=utf-8');
				http_response_code(500);

				header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				header('Cache-Control: post-check=0, pre-check=0', false);
				header('Pragma: no-cache');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
			}

			$error_message = is_string($message) ? $message : __('Database connection error occurred.', 'snapshoter');

			$error_message = wp_strip_all_tags($error_message);

			$response = array(
				'success' => false,
				'data' => array(
					'message' => $error_message,
				),
			);

			echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			exit;
		};
	}

	private function snapshoter_suppress_db_checks()
	{
		add_filter('wp_check_database_version', '__return_true', 999);

		add_filter('pre_option_is_blog_installed', '__return_true', 999);

		add_filter('wp_db_version', function () {
			return 57155;
		}, 999);
	}

	public static function boot()
	{
		if (null === self::$instance) {
			try {
				self::$instance = new self();
			} catch (\Throwable $e) {
				self::notify_boot_failure($e);
			}
		}
	}

	public static function notify_boot_failure($e)
	{
		$message = $e instanceof \Throwable ? $e->getMessage() : (string) $e;
		if (defined('SNAPSHOTER_DEBUG') && SNAPSHOTER_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('Snapshoter boot failure: ' . $message . ($e instanceof \Throwable ? "\n" . $e->getTraceAsString() : ''));
		}
		self::$boot_errors[] = $message;

		if (!function_exists('add_action')) {
			return;
		}

		static $hooked = false;
		if ($hooked) {
			return;
		}
		$hooked = true;
		add_action('admin_notices', array(__CLASS__, 'render_boot_notices'));
		add_action('network_admin_notices', array(__CLASS__, 'render_boot_notices'));
	}

	public static function render_boot_notices()
	{
		if (!current_user_can('activate_plugins') || empty(self::$boot_errors)) {
			return;
		}

		foreach (array_unique(self::$boot_errors) as $message) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
				esc_html__('Snapshoter failed to load a required component.', 'snapshoter'),
				esc_html($message)
			);
		}
	}

	public static function activate()
	{
		try {
			if (function_exists('is_multisite') && is_multisite()) {
				if (!function_exists('deactivate_plugins')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				deactivate_plugins(plugin_basename(SNAPSHOTER_FILE), true);
				return;
			}

			$active_plugins = get_option('active_plugins', array());
			$plugin_basename = plugin_basename(SNAPSHOTER_FILE);
			$duplicates = array();

			if (is_array($active_plugins)) {
				foreach ($active_plugins as $plugin) {
					if ($plugin !== $plugin_basename && strpos($plugin, 'snapshoter') !== false) {
						$duplicates[] = $plugin;
					}
				}
			}

			if (!empty($duplicates)) {
				if (!function_exists('deactivate_plugins')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if (function_exists('deactivate_plugins')) {
					deactivate_plugins($duplicates);
				}
			}

			self::ensure_storage();
			self::clear_legacy_prune_cron();
		} catch (\Throwable $e) {

		}
	}

	public static function deactivate()
	{
		self::clear_legacy_prune_cron();
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook(JobController::BACKGROUND_TICK_HOOK);
		}
		if (class_exists('\\Snapshoter\\Import\\RestoreSafeMode')) {
			\Snapshoter\Import\RestoreSafeMode::purge_install_artifacts();
		}
	}

	private static function clear_legacy_prune_cron()
	{
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook(VaultPrune::CRON_HOOK);
		}
	}

	public static function uninstall()
	{
	}

	public function check_requirements()
	{
		global $wp_version;

		if (is_admin() && !wp_doing_ajax()) {
			self::ensure_storage();
			self::clear_legacy_prune_cron();
		}

		if (version_compare($wp_version, SNAPSHOTER_MIN_WP, '<')) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(sprintf(

							// translators: %s: Minimum WordPress version required
							__('Snapshoter requires WordPress %s or newer.', 'snapshoter'),
							SNAPSHOTER_MIN_WP
						))
					);
				}
			);
		}
	}

	private function check_duplicate_installations()
	{
		if (!is_admin()) {
			return;
		}

		$current_plugin_dir = dirname(SNAPSHOTER_FILE);
		$plugins_dir = WP_PLUGIN_DIR;

		$all_plugins = glob($plugins_dir . '/*/snapshoter.php');

		$all_plugins = array_filter($all_plugins, function ($plugin_file) {
			$dir = dirname($plugin_file);
			$basename = basename($dir);

			return !preg_match('/^snapshoter-\d+\.\d+\.\d+$/', $basename);
		});

		if ($all_plugins && count($all_plugins) > 1) {

			add_action('admin_notices', function () use ($all_plugins, $current_plugin_dir) {
				if (!current_user_can('manage_options')) {
					return;
				}

				$other_instances = array();
				foreach ($all_plugins as $plugin_file) {
					if ($plugin_file !== SNAPSHOTER_FILE) {
						$other_instances[] = dirname($plugin_file);
					}
				}

				if (!empty($other_instances)) {
					printf(
						'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p><ul style="margin-left: 20px;">%s</ul><p>%s</p></div>',
						esc_html__('Multiple Snapshoter installations detected:', 'snapshoter'),
						esc_html__('The plugin is installed in multiple directories:', 'snapshoter'),
						'<li><strong>' . esc_html($current_plugin_dir) . '</strong> <em>(' . esc_html__('Active', 'snapshoter') . ')</em></li>' .
						'<li>' . implode('</li><li>', array_map('esc_html', $other_instances)) . ' <em>(' . esc_html__('Inactive', 'snapshoter') . ')</em></li></ul>',
						esc_html__('Please remove the inactive installation(s) to prevent conflicts.', 'snapshoter')
					);
				}
			});
		}
	}

	public function register_admin_menu()
	{

		$icon = $this->get_admin_menu_icon();

		add_menu_page(
			__('Snapshoter: Free Unlimited Backup and Restore', 'snapshoter'),
			__('Snapshoter', 'snapshoter'),
			SNAPSHOTER_CAPABILITY,
			'snapshoter',
			array($this->admin, 'render'),
			$icon,
			59
		);

		add_submenu_page(
			'snapshoter',
			__('Snapshoter Dashboard', 'snapshoter'),
			__('Dashboard', 'snapshoter'),
			SNAPSHOTER_CAPABILITY,
			'snapshoter',
			array($this->admin, 'render')
		);

		add_submenu_page(
			'snapshoter',
			__('Help & Instructions', 'snapshoter'),
			__('Help', 'snapshoter'),
			SNAPSHOTER_CAPABILITY,
			'snapshoter-help',
			array($this->instructions, 'render')
		);

		add_management_page(
			__('Snapshoter Backup & Restore', 'snapshoter'),
			__('Snapshoter Backup', 'snapshoter'),
			SNAPSHOTER_CAPABILITY,
			'snapshoter-backup',
			array($this->admin, 'render')
		);
	}

	public function add_plugin_action_links($links)
	{
		$settings_link = sprintf(
			'<a href="%s" style="font-weight:600;color:#9333ea;">%s</a>',
			esc_url(admin_url('admin.php?page=snapshoter')),
			esc_html__('Dashboard & Backups', 'snapshoter')
		);
		array_unshift($links, $settings_link);
		return $links;
	}

	private function get_admin_menu_icon()
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20"><path fill="#a7aaad" d="M16 5h-2.38l-.8-1.6A1 1 0 0 0 11.93 3H8.07a1 1 0 0 0-.89.4L6.38 5H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm-6 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	public function render_menu_icon_styles()
	{
		echo '<style>
			#adminmenu #toplevel_page_snapshoter .wp-menu-image.svg {
				background-repeat: no-repeat !important;
				background-position: center !important;
				background-size: 20px auto !important;
			}
			#adminmenu #toplevel_page_snapshoter:hover .wp-menu-image.svg,
			#adminmenu #toplevel_page_snapshoter.wp-has-current-submenu .wp-menu-image.svg,
			#adminmenu #toplevel_page_snapshoter.current .wp-menu-image.svg {
				filter: brightness(0) invert(1);
			}
		</style>';
	}

	public function enqueue_assets($hook)
	{
		wp_enqueue_style('dashicons');

		$page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
		$is_snapshoter_page = ($hook === 'toplevel_page_snapshoter' || $hook === 'snapshoter_page_snapshoter-help' || $page === 'snapshoter' || $page === 'snapshoter-help');
		if (!$is_snapshoter_page) {
			return;
		}

		$admin_asset = $this->load_admin_asset_manifest();

		$css_version = file_exists(SNAPSHOTER_PATH . 'assets/css/admin.css') ? (string) filemtime(SNAPSHOTER_PATH . 'assets/css/admin.css') : (string) time();
		$admin_asset['version'] = file_exists(SNAPSHOTER_PATH . $admin_asset['relative_path']) ? (string) filemtime(SNAPSHOTER_PATH . $admin_asset['relative_path']) : (string) time();

		wp_enqueue_style(
			'snapshoter-admin',
			SNAPSHOTER_URL . 'assets/css/admin.css',
			array(),
			$css_version
		);

		$restore_session_relative = 'assets/js/restore-session.js';
		$has_restore_session = file_exists(SNAPSHOTER_PATH . $restore_session_relative);

		if ($has_restore_session) {
			wp_enqueue_script(
				'snapshoter-restore-session',
				SNAPSHOTER_URL . $restore_session_relative,
				array('jquery'),
				$this->asset_version($restore_session_relative),
				true
			);
		}

		$admin_dependencies = $admin_asset['dependencies'];
		if ($has_restore_session) {
			$admin_dependencies = array_merge($admin_dependencies, array('snapshoter-restore-session'));
		}

		wp_enqueue_script(
			'snapshoter-admin',
			SNAPSHOTER_URL . $admin_asset['relative_path'],
			$admin_dependencies,
			$admin_asset['version'],
			true
		);

		wp_localize_script(
			'snapshoter-admin',
			'snapshoter',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'adminUrl' => admin_url(),
				'loginUrl' => wp_login_url(),
				'pluginAdminUrl' => admin_url('admin.php?page=snapshoter'),
				'nonce' => wp_create_nonce(SNAPSHOTER_NONCE_ACTION),
				'nonceName' => SNAPSHOTER_NONCE_NAME,
				'chunkBytes' => JobController::max_chunk_size(),
				'uploadConcurrency' => JobController::recommended_upload_concurrency(),
				'pluginUrl' => SNAPSHOTER_URL,
				'version' => SNAPSHOTER_VERSION,
				'wpVersion' => isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '',
				'phpVersion' => PHP_VERSION,
				'serverSoftware' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
				'memoryLimit' => (string) ini_get('memory_limit'),
				'maxExecutionTime' => (string) ini_get('max_execution_time'),
				'uploadMaxFilesize' => (string) ini_get('upload_max_filesize'),
				'postMaxSize' => (string) ini_get('post_max_size'),
				'diskFree' => function_exists('disk_free_space') && @disk_free_space(ABSPATH) !== false ? size_format(@disk_free_space(ABSPATH)) : 'Available',
				'diskTotal' => function_exists('disk_total_space') && @disk_total_space(ABSPATH) !== false ? size_format(@disk_total_space(ABSPATH)) : '',
				'phpArch' => (PHP_INT_SIZE === 8 ? '64-bit' : '32-bit'),
				'dbVersion' => isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'db_version') ? (string) $GLOBALS['wpdb']->db_version() : 'MySQL',
				'zipEnabled' => extension_loaded('zip'),
				'curlVersion' => function_exists('curl_version') && is_array(curl_version()) && !empty(curl_version()['version']) ? (string) curl_version()['version'] : 'Active',
				'opcacheEnabled' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) && !empty(@opcache_get_status(false)['opcache_enabled']),
				'uploadsWritable' => function_exists('wp_upload_dir') && function_exists('wp_is_writable') ? wp_is_writable(wp_upload_dir()['basedir']) : true,
				'activeTheme' => function_exists('wp_get_theme') ? wp_get_theme()->get('Name') . ' v' . wp_get_theme()->get('Version') : '',
				'activePluginsCount' => is_array(get_option('active_plugins')) ? count(get_option('active_plugins')) : 0,

				'gracefulRestore' => (bool) get_option('snapshoter_graceful_restore', true),
				'siteUrl' => site_url(),
				'i18n' => array(
					'starting' => __('Starting job...', 'snapshoter'),
					'processing' => __('Processing...', 'snapshoter'),
				),
				'timezone' => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC',
				'authorUrl' => 'https://www.smartin.in',
				'learnMoreUrl' => 'https://www.smartin.in/wordpress-backup-and-migration/',
			)
		);

		$inline_js = 'window.snapshoterDebug = ' . (defined('SNAPSHOTER_DEBUG') && SNAPSHOTER_DEBUG ? 'true' : 'false') . ';';
		$inline_js .= '(function(){if(typeof window!=="undefined"&&!window.ReactJSXRuntime&&window.wp&&window.wp.element){var el=window.wp.element;function createJsx(type,props,key){var clone=Object.assign({},props);if(key!==undefined){clone.key=key;}var children=clone.children;delete clone.children;if(Array.isArray(children)){return el.createElement.apply(el,[type,clone].concat(children));}else if(children!==undefined){return el.createElement(type,clone,children);}return el.createElement(type,clone);}window.ReactJSXRuntime={jsx:createJsx,jsxs:createJsx,Fragment:el.Fragment||(window.React&&window.React.Fragment)};}})();';

		wp_add_inline_script(
			'snapshoter-admin',
			$inline_js,
			'before'
		);

		if ($hook === 'snapshoter_page_snapshoter-help') {
			wp_localize_script(
				'snapshoter-admin',
				'snapshoterHelp',
				InstructionsPage::build_payload()
			);
		}
	}

	private function asset_version($relative_path)
	{
		$relative_path = ltrim($relative_path, '/\\');
		$full_path = SNAPSHOTER_PATH . $relative_path;

		if (file_exists($full_path)) {
			return SNAPSHOTER_VERSION . '-' . filemtime($full_path);
		}

		return SNAPSHOTER_VERSION . '-' . time();
	}

	private function load_admin_asset_manifest()
	{
		$force_new = defined('SNAPSHOTER_USE_NEW_UI') && SNAPSHOTER_USE_NEW_UI;
		$force_legacy = defined('SNAPSHOTER_USE_LEGACY_UI') && SNAPSHOTER_USE_LEGACY_UI;

		$prefer_new = $force_new || !$force_legacy;

		if ($prefer_new) {
			$manifest_path = SNAPSHOTER_PATH . 'assets/js/build/admin.asset.php';
			$bundle_path = 'assets/js/build/admin.js';

			if (file_exists($manifest_path) && file_exists(SNAPSHOTER_PATH . $bundle_path)) {
				$manifest = include $manifest_path;
				if (
					is_array($manifest)
					&& isset($manifest['dependencies'])
					&& is_array($manifest['dependencies'])
					&& isset($manifest['version'])
				) {
					$dependencies = array();
					foreach ($manifest['dependencies'] as $dep) {
						if ($dep === 'react-jsx-runtime') {
							if (function_exists('wp_script_is') && wp_script_is('react-jsx-runtime', 'registered')) {
								$dependencies[] = $dep;
							}
						} else {
							$dependencies[] = $dep;
						}
					}
					if (!in_array('wp-element', $dependencies, true)) {
						$dependencies[] = 'wp-element';
					}

					return array(
						'relative_path' => $bundle_path,
						'dependencies' => $dependencies,
						'version' => (string) $manifest['version'] . '.' . filemtime(SNAPSHOTER_PATH . $bundle_path),
					);
				}
			}
		}

		return array(
			'relative_path' => 'assets/js/admin.js',
			'dependencies' => array('wp-element', 'wp-i18n'),
			'version' => $this->asset_version('assets/js/admin.js'),
		);
	}

	private static function ensure_storage()
	{
		$paths = array(
			SNAPSHOTER_STORAGE,
			SNAPSHOTER_ARCHIVE_DIR,
			SNAPSHOTER_JOB_DIR,
			SNAPSHOTER_SNAPSHOTS_DIR,
			SNAPSHOTER_RESTORES_DIR,
		);

		foreach ($paths as $path) {
			if (!is_dir($path)) {
				@wp_mkdir_p($path);
			}

			$index = trailingslashit($path) . 'index.php';
			$index_body = "<?php\nhttp_response_code(403);\nexit;\n";
			if (!file_exists($index) || @file_get_contents($index) !== $index_body) {
				@file_put_contents($index, $index_body);
			}
		}

		self::write_storage_web_denial();

		try {
			$mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/mu-plugins' : ABSPATH . 'wp-content/mu-plugins');
			$legacy_guard = trailingslashit($mu_dir) . 'snapshoter-guard.php';
			if (file_exists($legacy_guard)) {
				wp_delete_file($legacy_guard);
			}
		} catch (\Throwable $e) {}
	}

	private static function write_storage_web_denial()
	{
		$root = trailingslashit(SNAPSHOTER_STORAGE);
		$marker = '# snapshoter-protect-v2';

		$htaccess = $root . '.htaccess';
		$ht_rules = $marker . "\n" .
			"Options -Indexes\n" .
			"<IfModule mod_authz_core.c>\n" .
			"    Require all denied\n" .
			"</IfModule>\n" .
			"<IfModule !mod_authz_core.c>\n" .
			"    Order Deny,Allow\n" .
			"    Deny from all\n" .
			"</IfModule>\n" .
			"<IfModule mod_rewrite.c>\n" .
			"    RewriteEngine On\n" .
			"    RewriteRule .* - [F,L]\n" .
			"</IfModule>\n" .
			"<FilesMatch \"(?i)\\.(smartin|sql|zip|log|json|php|phtml)$\">\n" .
			"    <IfModule mod_authz_core.c>\n" .
			"        Require all denied\n" .
			"    </IfModule>\n" .
			"    <IfModule !mod_authz_core.c>\n" .
			"        Order Deny,Allow\n" .
			"        Deny from all\n" .
			"    </IfModule>\n" .
			"</FilesMatch>\n";
		$existing_ht = file_exists($htaccess) ? (string) @file_get_contents($htaccess) : '';
		if (strpos($existing_ht, $marker) === false) {
			@file_put_contents($htaccess, $ht_rules);
		}

		$webconfig = $root . 'web.config';
		$web_rules = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<configuration>' . "\n" .
			'  <system.webServer>' . "\n" .
			'    <security>' . "\n" .
			'      <requestFiltering>' . "\n" .
			'        <hiddenSegments>' . "\n" .
			'          <add segment="snapshoter" />' . "\n" .
			'          <add segment="snapshoters" />' . "\n" .
			'        </hiddenSegments>' . "\n" .
			'        <fileExtensions allowUnlisted="true">' . "\n" .
			'          <add fileExtension=".smartin" allowed="false" />' . "\n" .
			'          <add fileExtension=".sql" allowed="false" />' . "\n" .
			'          <add fileExtension=".zip" allowed="false" />' . "\n" .
			'          <add fileExtension=".log" allowed="false" />' . "\n" .
			'          <add fileExtension=".json" allowed="false" />' . "\n" .
			'        </fileExtensions>' . "\n" .
			'      </requestFiltering>' . "\n" .
			'    </security>' . "\n" .
			'  </system.webServer>' . "\n" .
			'</configuration>' . "\n";
		$existing_web = file_exists($webconfig) ? (string) @file_get_contents($webconfig) : '';
		if (strpos($existing_web, 'fileExtension=".smartin"') === false) {
			@file_put_contents($webconfig, $web_rules);
		}
	}

}

