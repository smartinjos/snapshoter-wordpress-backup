<?php

namespace Snapshoter\Import;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions

final class RestoreSafeMode
{
	const MU_FILENAME = 'snapshoter-restore-safe.php';
	const FLAG_OPTION = 'snapshoter_restore_safe_active';
	const MU_QUARANTINE_SUFFIX = '.snapshoter-off';

	const EMERGENCY_THEME = 'snapshoter-safe';

	public static function should_skip_restore_path($relative, $safe_theme = '')
	{
		$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
		if ($relative === '') {
			return true;
		}

		if (
			$relative === 'wp-content/mu-plugins/' . self::MU_FILENAME
			|| $relative === 'mu-plugins/' . self::MU_FILENAME
		) {
			return true;
		}

		if ($safe_theme === '') {
			$safe_theme = self::EMERGENCY_THEME;
		}
		$safe_theme = sanitize_file_name((string) $safe_theme);
		if ($safe_theme === '') {
			return false;
		}

		$prefix = 'wp-content/themes/' . $safe_theme . '/';
		$prefix2 = 'themes/' . $safe_theme . '/';
		if (strpos($relative, $prefix) === 0 || strpos($relative, $prefix2) === 0) {
			return true;
		}
		if ($relative === 'wp-content/themes/' . $safe_theme || $relative === 'themes/' . $safe_theme) {
			return true;
		}

		return false;
	}

	public static function mu_path()
	{
		$mu_dir = defined('WPMU_PLUGIN_DIR')
			? WPMU_PLUGIN_DIR
			: (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/mu-plugins' : ABSPATH . 'wp-content/mu-plugins');
		return trailingslashit($mu_dir) . self::MU_FILENAME;
	}

	public static function theme_root()
	{
		if (function_exists('get_theme_root')) {
			return get_theme_root();
		}
		return (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (ABSPATH . 'wp-content')) . '/themes';
	}

	public static function ensure_emergency_theme()
	{
		$root = self::theme_root();
		$dir = trailingslashit($root) . self::EMERGENCY_THEME;
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$style = $dir . '/style.css';
		if (!file_exists($style)) {
			@file_put_contents(
				$style,
				"/*\nTheme Name: Snapshoter Safe\nDescription: Temporary theme used only while Snapshoter restores. Auto-removed when restore finishes.\nVersion: 1.0\n*/\nbody{font-family:system-ui,sans-serif;padding:3rem;text-align:center}\n"
			);
		}
		$index = $dir . '/index.php';
		if (!file_exists($index)) {
			@file_put_contents(
				$index,
				"<?php\nstatus_header(503);\nheader('Retry-After: 120');\necho '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Restore in progress</title></head><body><h1>Site restore in progress</h1><p>Please try again shortly.</p></body></html>';\n"
			);
		}

		return self::EMERGENCY_THEME;
	}

	public static function remove_emergency_theme($force = false)
	{
		if (!$force) {
			$flag = get_option(self::FLAG_OPTION, array());
			$created = is_array($flag) && !empty($flag['emergency_theme']);
			if (!$created) {
				return;
			}
		}
		$dir = trailingslashit(self::theme_root()) . self::EMERGENCY_THEME;
		if (!is_dir($dir)) {
			return;
		}
		$files = array('style.css', 'index.php', 'functions.php');
		foreach ($files as $f) {
			$p = $dir . '/' . $f;
			if (file_exists($p)) {
				wp_delete_file($p);
			}
		}
		@rmdir($dir);
	}

	public static function purge_install_artifacts()
	{
		self::restore_quarantined_mu_plugins();
		$path = self::mu_path();
		if (file_exists($path)) {
			wp_delete_file($path);
		}
		self::remove_emergency_theme(true);
		if (function_exists('delete_option')) {
			delete_option(self::FLAG_OPTION);
		}
	}

	public static function pick_safe_theme()
	{
		return self::ensure_emergency_theme();
	}

	public static function install($job_id = '', $safe_theme = '', $snapshoter_basename = '')
	{
		$path = self::mu_path();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$job_id = sanitize_text_field((string) $job_id);
		$using_emergency = false;
		if ($safe_theme === '') {
			$safe_theme = self::pick_safe_theme();
			$using_emergency = ($safe_theme === self::EMERGENCY_THEME);
		} elseif ($safe_theme === self::EMERGENCY_THEME) {
			self::ensure_emergency_theme();
			$using_emergency = true;
		}
		$safe_theme = sanitize_file_name($safe_theme);
		if ($snapshoter_basename === '') {
			$snapshoter_basename = defined('SNAPSHOTER_FILE')
				? plugin_basename(SNAPSHOTER_FILE)
				: 'snapshoter/snapshoter.php';
		}
		$snapshoter_basename = str_replace(array('..', "\0"), '', (string) $snapshoter_basename);

		self::quarantine_foreign_mu_plugins();

		$contents = self::plugin_source($job_id, $safe_theme, $snapshoter_basename);
		$ok = (false !== @file_put_contents($path, $contents));
		if ($ok) {
			$prev = get_option(self::FLAG_OPTION, array());
			update_option(
				self::FLAG_OPTION,
				array(
					'active'              => 1,
					'job_id'              => $job_id,
					'safe_theme'          => $safe_theme,
					'snapshoter_basename' => $snapshoter_basename,
					'emergency_theme'     => $using_emergency || (!empty($prev['emergency_theme'])),
					'at'                  => time(),
				),
				false
			);
		}
		return $ok;
	}

	public static function lift_theme_freeze()
	{
		$flag = get_option(self::FLAG_OPTION, array());
		if (!is_array($flag) || empty($flag['active'])) {
			return false;
		}
		$job_id = isset($flag['job_id']) ? (string) $flag['job_id'] : '';
		$basename = isset($flag['snapshoter_basename']) ? (string) $flag['snapshoter_basename'] : '';
		$path = self::mu_path();
		if (!file_exists($path)) {
			return false;
		}
		$contents = self::plugin_source($job_id, '', $basename, empty($flag['plugin_freeze_lifted']));
		$ok = (false !== @file_put_contents($path, $contents));
		if ($ok) {
			$flag['safe_theme'] = '';
			$flag['theme_lifted'] = 1;
			update_option(self::FLAG_OPTION, $flag, false);
		}
		return $ok;
	}

	public static function lift_plugin_freeze()
	{
		$flag = get_option(self::FLAG_OPTION, array());
		if (!is_array($flag) || empty($flag['active'])) {
			return false;
		}
		$job_id = isset($flag['job_id']) ? (string) $flag['job_id'] : '';
		$basename = isset($flag['snapshoter_basename']) ? (string) $flag['snapshoter_basename'] : '';
		$safe_theme = isset($flag['safe_theme']) ? (string) $flag['safe_theme'] : '';

		if (!empty($flag['theme_lifted'])) {
			$safe_theme = '';
		}
		$path = self::mu_path();
		if (!file_exists($path)) {
			return false;
		}
		$contents = self::plugin_source($job_id, $safe_theme, $basename, false);
		$ok = (false !== @file_put_contents($path, $contents));
		if ($ok) {
			$flag['plugin_freeze_lifted'] = 1;
			update_option(self::FLAG_OPTION, $flag, false);
		}
		return $ok;
	}

	public static function quarantine_foreign_mu_plugins()
	{
		$dir = dirname(self::mu_path());
		if (!is_dir($dir)) {
			return 0;
		}
		$n = 0;
		$items = @scandir($dir);
		if (!is_array($items)) {
			return 0;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			if ($item === self::MU_FILENAME) {
				continue;
			}
			if (substr($item, -strlen(self::MU_QUARANTINE_SUFFIX)) === self::MU_QUARANTINE_SUFFIX) {
				continue;
			}
			$full = $dir . '/' . $item;

			if (is_file($full) && substr($item, -4) === '.php') {
				@rename($full, $full . self::MU_QUARANTINE_SUFFIX);
				$n++;
			}
		}
		return $n;
	}

	public static function restore_quarantined_mu_plugins()
	{
		$dir = dirname(self::mu_path());
		$out = array(
			'restored'         => 0,
			'kept_quarantined' => 0,
			'skipped'          => array(),
		);
		if (!is_dir($dir)) {
			return $out;
		}
		$items = @scandir($dir);
		if (!is_array($items)) {
			return $out;
		}
		$suffix = self::MU_QUARANTINE_SUFFIX;
		$len = strlen($suffix);
		foreach ($items as $item) {
			if (substr($item, -$len) !== $suffix) {
				continue;
			}
			$full = $dir . '/' . $item;
			$orig = substr($full, 0, -$len);
			if (file_exists($orig)) {
				continue;
			}
			if (self::php_file_looks_boot_fatal($full)) {
				$out['kept_quarantined']++;
				$out['skipped'][] = basename($orig);
				continue;
			}
			if (@rename($full, $orig)) {
				$out['restored']++;
			}
		}
		return $out;
	}

	public static function php_file_looks_boot_fatal($path)
	{
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
			return false;
		}
		$src = @file_get_contents($path, false, null, 0, 65536);
		if (!is_string($src) || $src === '') {
			return false;
		}

		if (function_exists('token_get_all')) {
			$tokens = @token_get_all($src);
			if (!is_array($tokens)) {
				return false;
			}
			$depth = 0;
			foreach ($tokens as $t) {
				if (is_array($t)) {
					$id = $t[0];
					if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
						$depth++;
					}
					if ($depth === 0 && ($id === T_THROW || $id === T_EXIT)) {
						return true;
					}
					continue;
				}
				if ($t === '{') {
					$depth++;
				} elseif ($t === '}') {
					$depth = max(0, $depth - 1);
				}
			}
			return false;
		}

		if (preg_match('/\b(function|class|trait|interface)\b/i', $src, $m, PREG_OFFSET_CAPTURE)) {
			$src = substr($src, 0, (int) $m[0][1]);
		}
		return (bool) preg_match('/\bthrow\s+new\b|(^|\n)\s*(exit|die)\s*(\(|;)/i', $src);
	}

	public static function job_looks_terminal($job_id, $stale_seconds = 7200)
	{
		$job_id = sanitize_text_field((string) $job_id);
		if ($job_id === '') {
			return true;
		}
		if (!defined('SNAPSHOTER_JOB_DIR')) {
			return false;
		}

		$state_file = trailingslashit(SNAPSHOTER_JOB_DIR) . $job_id . '/state.json';
		if (!file_exists($state_file)) {
			return true;
		}

		$raw = @file_get_contents($state_file);
		$state = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($state)) {
			return true;
		}

		$status = isset($state['status']) ? (string) $state['status'] : '';
		$step = isset($state['step']) ? (string) $state['step'] : '';
		if (in_array($status, array('completed', 'failed', 'cancelled'), true)) {
			return true;
		}
		if (in_array($step, array('completed', 'failed', 'cancelled'), true)) {
			return true;
		}

		$tick_at = isset($state['tick_at']) ? (int) $state['tick_at'] : 0;
		$updated = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
		$last = max($tick_at, $updated);
		if ($last > 0 && (time() - $last) >= max(300, (int) $stale_seconds)) {
			return true;
		}

		return false;
	}

	public static function maybe_clear_stale($force = false)
	{
		$flag = get_option(self::FLAG_OPTION, array());
		if (!is_array($flag) || empty($flag['active'])) {
			return array('cleared' => false, 'reason' => 'inactive');
		}

		$job_id = isset($flag['job_id']) ? sanitize_text_field((string) $flag['job_id']) : '';
		if (!$force && !self::job_looks_terminal($job_id)) {
			return array('cleared' => false, 'reason' => 'running', 'job_id' => $job_id);
		}

		self::clear(true);

		return array('cleared' => true, 'job_id' => $job_id);
	}

	public static function heal_orphaned_theme_options()
	{
		$template = (string) get_option('template', '');
		$stylesheet = (string) get_option('stylesheet', '');
		$root = self::theme_root();
		$tpl_missing = ($template === '' || !is_dir(trailingslashit($root) . $template));
		$style_missing = ($stylesheet === '' || !is_dir(trailingslashit($root) . $stylesheet));
		$is_emergency = ($template === self::EMERGENCY_THEME || $stylesheet === self::EMERGENCY_THEME);

		if (!$is_emergency && !$tpl_missing && !$style_missing) {
			return array('healed' => false, 'reason' => 'ok', 'template' => $template, 'stylesheet' => $stylesheet);
		}

		$restored_template = (string) get_option('SNAPSHOTER_restored_template', '');
		$restored_stylesheet = (string) get_option('SNAPSHOTER_restored_stylesheet', '');
		$prev_template = (string) get_option('SNAPSHOTER_prev_template', '');
		$prev_stylesheet = (string) get_option('SNAPSHOTER_prev_stylesheet', '');
		$candidates = array();
		if ($restored_stylesheet !== '') {
			$candidates[] = array(
				$restored_template !== '' ? $restored_template : $restored_stylesheet,
				$restored_stylesheet,
			);
		}
		if ($prev_stylesheet !== '') {
			$candidates[] = array($prev_template !== '' ? $prev_template : $prev_stylesheet, $prev_stylesheet);
		}
		if ($prev_template !== '' && $prev_template !== $prev_stylesheet) {
			$candidates[] = array($prev_template, $prev_template);
		}

		$stock_slugs = array(
			'screenr',
			'twentytwentyfive',
			'twentytwentyfour',
			'twentytwentythree',
			'twentytwentytwo',
			'twentytwentyone',
			'twentytwenty',
			'twentyseventeen',
		);
		$stock_lookup = array_fill_keys($stock_slugs, true);

		if (function_exists('wp_get_themes')) {
			foreach (wp_get_themes() as $slug => $theme) {
				$slug = sanitize_file_name((string) $slug);
				if ($slug === '' || $slug === self::EMERGENCY_THEME || isset($stock_lookup[$slug])) {
					continue;
				}
				$parent = method_exists($theme, 'get_template') ? sanitize_file_name((string) $theme->get_template()) : $slug;
				if ($parent === '') {
					$parent = $slug;
				}
				$candidates[] = array($parent, $slug);
			}
		}

		foreach ($stock_slugs as $slug) {
			$candidates[] = array($slug, $slug);
		}

		$picked = null;
		$seen = array();
		foreach ($candidates as $pair) {
			list($t, $s) = $pair;
			$t = sanitize_file_name((string) $t);
			$s = sanitize_file_name((string) $s);
			$key = $t . '|' . $s;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			if ($t === '' || $s === '' || $t === self::EMERGENCY_THEME || $s === self::EMERGENCY_THEME) {
				continue;
			}
			if (is_dir(trailingslashit($root) . $s) && is_dir(trailingslashit($root) . $t)) {
				$picked = array($t, $s);
				break;
			}
			if (is_dir(trailingslashit($root) . $s)) {
				$picked = array($s, $s);
				break;
			}
		}

		if ($picked === null) {
			return array('healed' => false, 'reason' => 'no_theme_available', 'template' => $template, 'stylesheet' => $stylesheet);
		}

		update_option('template', $picked[0]);
		update_option('stylesheet', $picked[1]);
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('template', 'options');
			wp_cache_delete('stylesheet', 'options');
			wp_cache_delete('alloptions', 'options');
		}

		return array(
			'healed'     => true,
			'template'   => $picked[0],
			'stylesheet' => $picked[1],
			'reason'     => $is_emergency ? 'was_emergency' : 'missing_dir',
		);
	}

	public static function clear($restore_mu = true)
	{
		$mu_stats = array('restored' => 0, 'kept_quarantined' => 0, 'skipped' => array());
		if ($restore_mu) {
			$mu_stats = self::restore_quarantined_mu_plugins();
		}
		self::remove_emergency_theme();
		$path = self::mu_path();
		if (file_exists($path)) {
			wp_delete_file($path);
		}
		delete_option(self::FLAG_OPTION);

		self::$last_heal_result = self::heal_orphaned_theme_options();

		self::ensure_restored_theme_active();

		self::$last_clear_mu_stats = $mu_stats;
		return !file_exists($path);
	}

	private static $last_heal_result = null;

	public static function last_heal_result()
	{
		return self::$last_heal_result;
	}

	public static function ensure_restored_theme_active()
	{
		$restored_template = (string) get_option('SNAPSHOTER_restored_template', '');
		$restored_stylesheet = (string) get_option('SNAPSHOTER_restored_stylesheet', '');
		if ($restored_stylesheet === '') {
			return array('applied' => false, 'reason' => 'no_restored_marker');
		}
		$template = $restored_template !== '' ? $restored_template : $restored_stylesheet;
		$stylesheet = $restored_stylesheet;
		$root = self::theme_root();
		if (!is_dir(trailingslashit($root) . $stylesheet)) {
			return array('applied' => false, 'reason' => 'stylesheet_missing', 'template' => $template, 'stylesheet' => $stylesheet);
		}
		if (!is_dir(trailingslashit($root) . $template)) {
			$template = $stylesheet;
		}

		$cur_t = (string) get_option('template', '');
		$cur_s = (string) get_option('stylesheet', '');
		if ($cur_t === $template && $cur_s === $stylesheet) {
			return array('applied' => false, 'reason' => 'already_active', 'template' => $template, 'stylesheet' => $stylesheet);
		}

		update_option('template', $template);
		update_option('stylesheet', $stylesheet);
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('template', 'options');
			wp_cache_delete('stylesheet', 'options');
			wp_cache_delete('alloptions', 'options');
		}

		return array(
			'applied'    => true,
			'template'   => $template,
			'stylesheet' => $stylesheet,
			'reason'     => 'reasserted',
		);
	}

	private static $last_clear_mu_stats = null;

	public static function last_clear_mu_stats()
	{
		return is_array(self::$last_clear_mu_stats)
			? self::$last_clear_mu_stats
			: array('restored' => 0, 'kept_quarantined' => 0, 'skipped' => array());
	}

	private static function plugin_source($job_id, $safe_theme, $snapshoter_basename, $freeze_plugins = true)
	{
		$job_id = addcslashes($job_id, "\\'");
		$safe_theme = addcslashes($safe_theme, "\\'");
		$snapshoter_basename = addcslashes($snapshoter_basename, "\\'");
		$freeze_plugins_php = $freeze_plugins ? 'true' : 'false';

		// phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed
		return <<<PHP
<?php
/**
 * Plugin Name: Snapshoter Restore Safe Mode
 * Description: Freeze plugins/theme + maintenance while Snapshoter restores. Auto-removed when restore finishes.
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('SNAPSHOTER_RESTORE_SAFE_JOB')) {
	define('SNAPSHOTER_RESTORE_SAFE_JOB', '{$job_id}');
}
if (!defined('SNAPSHOTER_RESTORE_SAFE_THEME')) {
	define('SNAPSHOTER_RESTORE_SAFE_THEME', '{$safe_theme}');
}
if (!defined('SNAPSHOTER_RESTORE_SAFE_PLUGIN')) {
	define('SNAPSHOTER_RESTORE_SAFE_PLUGIN', '{$snapshoter_basename}');
}
if (!defined('SNAPSHOTER_RESTORE_FREEZE_PLUGINS')) {
	define('SNAPSHOTER_RESTORE_FREEZE_PLUGINS', {$freeze_plugins_php});
}

/**
 * True for Snapshoter tick / bridge / cron requests.
 */
\$snapshoter_is_tick_request = static function () {
	if (defined('REST_REQUEST') && REST_REQUEST) {
		return true;
	}
	if (isset(\$_SERVER['REQUEST_URI']) && is_string(\$_SERVER['REQUEST_URI'])) {
		\$uri = \$_SERVER['REQUEST_URI'];

	}

	\$action = isset(\$_REQUEST['action']) ? (string) \$_REQUEST['action'] : '';
	if (\$action !== '' && (strpos(\$action, 'SNAPSHOTER_') === 0 || strpos(\$action, 'snapshoter_') === 0)) {
		return true;
	}
	if (defined('DOING_CRON') && DOING_CRON) {
		return true;
	}
	return false;
};

\$is_tick = \$snapshoter_is_tick_request();

// Fatal handler: JSON for tick requests (not WP HTML critical-error page).
if (\$is_tick && !defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
	define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
}
if (\$is_tick) {
	add_filter('wp_fatal_error_handler_enabled', '__return_false', 0);
}

// -------------------------------------------------------------------------
// Plugin freeze
// -------------------------------------------------------------------------
if (SNAPSHOTER_RESTORE_FREEZE_PLUGINS) {
	\$snapshoter_freeze_plugins = static function () {
		\$only = array(SNAPSHOTER_RESTORE_SAFE_PLUGIN);
		return \$only;
	};
	add_filter('pre_option_active_plugins', \$snapshoter_freeze_plugins, 0);
	add_filter('option_active_plugins', \$snapshoter_freeze_plugins, 0);
	add_filter('pre_site_option_active_sitewide_plugins', static function () {
		return array();
	}, 0);
}

// -------------------------------------------------------------------------
// Theme freeze
// -------------------------------------------------------------------------
if (SNAPSHOTER_RESTORE_SAFE_THEME !== '') {
	\$freeze_theme = static function () {
		return SNAPSHOTER_RESTORE_SAFE_THEME;
	};
	add_filter('pre_option_template', \$freeze_theme, 0);
	add_filter('pre_option_stylesheet', \$freeze_theme, 0);
	add_filter('template', \$freeze_theme, 0);
	add_filter('stylesheet', \$freeze_theme, 0);
}

// -------------------------------------------------------------------------
// Tick fatals → JSON
// -------------------------------------------------------------------------
if (\$is_tick) {
	register_shutdown_function(static function () {
		\$err = error_get_last();
		if (!\$err || !in_array(\$err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
			return;
		}
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
		if (!headers_sent()) {
			status_header(500);
			header('Content-Type: application/json; charset=utf-8');
		}
		echo wp_json_encode(array(
			'ok'        => false,
			'completed' => false,
			'fatal'     => true,
			'message'   => isset(\$err['message']) ? substr((string) \$err['message'], 0, 500) : 'fatal',
			'status'    => 'throttled',
		));
	});
	return; // no maintenance page for ticks
}

// -------------------------------------------------------------------------
// Front-end maintenance
// -------------------------------------------------------------------------
add_action('template_redirect', static function () {
	if (is_admin()) {
		return;
	}
	if (defined('REST_REQUEST') && REST_REQUEST) {
		return;
	}
	if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('current_user_can') && current_user_can('manage_options')) {
		return;
	}

	status_header(503);
	header('Retry-After: 120');
	if (function_exists('nocache_headers')) {
		nocache_headers();
	}
	\$title = 'Site restore in progress';
	\$msg = 'Snapshoter is restoring this site. Please try again in a few minutes.';
	if (function_exists('esc_html')) {
		\$title = esc_html(\$title);
		\$msg = esc_html(\$msg);
	}
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . \$title . '</title></head><body style="font-family:system-ui,sans-serif;padding:3rem;text-align:center">';
	echo '<h1>' . \$title . '</h1><p>' . \$msg . '</p>';
	echo '</body></html>';
	exit;
}, 0);

// -------------------------------------------------------------------------
// Auto-remove drop-in when restore ends or stalls
// -------------------------------------------------------------------------
add_action('init', static function () {
	\$job = defined('SNAPSHOTER_RESTORE_SAFE_JOB') ? SNAPSHOTER_RESTORE_SAFE_JOB : '';
	if (\$job === '' || !defined('SNAPSHOTER_JOB_DIR')) {
		return;
	}
	\$state_file = rtrim(SNAPSHOTER_JOB_DIR, '/\\\\') . '/' . \$job . '/state.json';
	if (!file_exists(\$state_file)) {
		wp_delete_file(__FILE__);
		delete_option('snapshoter_restore_safe_active');
		return;
	}
	\$raw = @file_get_contents(\$state_file);
	\$state = is_string(\$raw) ? json_decode(\$raw, true) : null;
	if (!is_array(\$state)) {
		return;
	}
	\$status = isset(\$state['status']) ? (string) \$state['status'] : '';
	\$step = isset(\$state['step']) ? (string) \$state['step'] : '';
	\$terminal = in_array(\$status, array('completed', 'failed', 'cancelled'), true)
		|| in_array(\$step, array('completed', 'failed', 'cancelled'), true);
	\$tick_at = isset(\$state['tick_at']) ? (int) \$state['tick_at'] : 0;
	\$updated = isset(\$state['updated_at']) ? (int) \$state['updated_at'] : 0;
	\$last = max(\$tick_at, \$updated);
	\$stale = \$last > 0 && (time() - \$last) >= 7200;
	if (\$terminal || \$stale) {
		wp_delete_file(__FILE__);
		delete_option('snapshoter_restore_safe_active');
	}
}, 0);

// -------------------------------------------------------------------------
// Session / heartbeat during restore
// -------------------------------------------------------------------------
add_action('init', static function () {
	if (!is_admin()) {
		return;
	}
	add_filter('wp_auth_check_load', '__return_false', 99);

	add_filter('heartbeat_settings', static function (\$settings) {
		if (is_array(\$settings)) {
			\$settings['interval'] = 60;
			\$settings['minimalInterval'] = 60;
		}
		return \$settings;
	}, 99);
}, 0);

PHP;
	}
}
