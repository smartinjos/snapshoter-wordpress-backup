<?php

namespace Snapshoter\Import;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable Squiz.PHP.DiscouragedFunctions
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Post-restore theme_mods, URL/path, and builder cache fixes.
 */
final class ThemeBuilderHealer
{
	/**
	 * Fix broken PHP serialized string lengths.
	 *
	 * @param string $data
	 * @return string
	 */
	public static function heal_serialized_string($data)
	{
		if (!is_string($data) || '' === trim($data)) {
			return $data;
		}

		$test = @unserialize($data, array('allowed_classes' => false));
		if ($test !== false || 'b:0;' === $data || 'a:0:{}' === $data || 'N;' === $data) {
			return $data;
		}

		$repaired = preg_replace_callback(
			'/s:(\d+):"(.*?)";(?=[a-z]:|\}|N;)/s',
			static function ($matches) {
				$string_val = $matches[2];
				$real_length = strlen($string_val);
				return 's:' . $real_length . ':"' . $string_val . '";';
			},
			$data
		);

		if (@unserialize($repaired, array('allowed_classes' => false)) !== false) {
			return $repaired;
		}

		$repaired_utf8 = preg_replace_callback(
			'/s:(\d+):\"(.*?)\";(?=[a-z]:|\}|N;)/s',
			static function ($matches) {
				$string_val = $matches[2];
				$real_length = strlen($string_val);
				return 's:' . $real_length . ':"' . $string_val . '";';
			},
			$data
		);

		if (@unserialize($repaired_utf8, array('allowed_classes' => false)) !== false) {
			return $repaired_utf8;
		}

		return $data;
	}

	/**
	 * Build multi-format URL replacement pairs.
	 *
	 * @param string $old_url
	 * @param string $new_url
	 * @return array
	 */
	public static function build_multiformat_url_map($old_url, $new_url)
	{
		$old_url = rtrim($old_url, '/');
		$new_url = rtrim($new_url, '/');

		if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
			return array('old' => array(), 'new' => array());
		}

		$old_schemes = array('https://', 'http://');
		$old_host = str_replace($old_schemes, '', $old_url);
		$new_host = str_replace($old_schemes, '', $new_url);

		$pairs = array();

		$pairs['https://' . $old_host] = 'https://' . $new_host;
		$pairs['http://' . $old_host]  = 'http://' . $new_host;
		$pairs[$old_url]               = $new_url;

		$pairs[str_replace('/', '\/', 'https://' . $old_host)] = str_replace('/', '\/', 'https://' . $new_host);
		$pairs[str_replace('/', '\/', 'http://' . $old_host)]  = str_replace('/', '\/', 'http://' . $new_host);
		$pairs[str_replace('/', '\/', $old_url)]               = str_replace('/', '\/', $new_url);

		$pairs[str_replace('/', '\\\/', 'https://' . $old_host)] = str_replace('/', '\\\/', 'https://' . $new_host);
		$pairs[str_replace('/', '\\\/', 'http://' . $old_host)]  = str_replace('/', '\\\/', 'http://' . $new_host);

		$pairs[rawurlencode('https://' . $old_host)] = rawurlencode('https://' . $new_host);
		$pairs[rawurlencode('http://' . $old_host)]  = rawurlencode('http://' . $new_host);
		$pairs[urlencode($old_url)]                  = urlencode($new_url);

		$pairs['//' . $old_host] = '//' . $new_host;

		return array(
			'old' => array_keys($pairs),
			'new' => array_values($pairs),
		);
	}

	/**
	 * Recursively replace URLs/paths in scalars, arrays, objects, serialized, and JSON.
	 *
	 * @param mixed $data
	 * @param array $url_map
	 * @param array $path_map
	 * @return mixed
	 */
	public static function deep_replace($data, array $url_map, array $path_map = array())
	{
		if (is_string($data)) {
			$unserialized = @unserialize($data, array('allowed_classes' => false));
			if ($unserialized === false && 'b:0;' !== $data) {
				$healed = self::heal_serialized_string($data);
				$unserialized = @unserialize($healed, array('allowed_classes' => false));
			}

			if ($unserialized !== false || 'b:0;' === $data) {
				$processed = self::deep_replace($unserialized, $url_map, $path_map);
				return serialize($processed);
			}

			$trimmed = trim($data);
			if (
				(strpos($trimmed, '{') === 0 && substr($trimmed, -1) === '}') ||
				(strpos($trimmed, '[') === 0 && substr($trimmed, -1) === ']')
			) {
				$json = json_decode($data, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					$processed_json = self::deep_replace($json, $url_map, $path_map);
					return function_exists('wp_json_encode') 
						? wp_json_encode($processed_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) 
						: json_encode($processed_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				}
			}

			$result = $data;
			if (!empty($url_map['old']) && !empty($url_map['new'])) {
				$result = str_replace($url_map['old'], $url_map['new'], $result);
			}
			if (!empty($path_map['old']) && !empty($path_map['new'])) {
				$result = str_replace($path_map['old'], $path_map['new'], $result);
			}
			return $result;
		}

		if (is_array($data)) {
			$result = array();
			foreach ($data as $key => $value) {
				$new_key = self::deep_replace($key, $url_map, $path_map);
				$result[$new_key] = self::deep_replace($value, $url_map, $path_map);
			}
			return $result;
		}

		if (is_object($data)) {
			try {
				$class_name = @get_class($data);
				if ('__PHP_Incomplete_Class' === $class_name || false === $class_name) {
					return $data;
				}
				foreach ($data as $key => $value) {
					$data->$key = self::deep_replace($value, $url_map, $path_map);
				}
			} catch (\Throwable $e) {
				return $data;
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Repair theme_mods, aliases, and nav menu locations for the active theme.
	 *
	 * @param \wpdb $wpdb
	 * @return array
	 */
	public static function heal_theme_mods($wpdb)
	{
		$logs = array();

		$template   = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'template'));
		$stylesheet = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'stylesheet'));

		if (empty($stylesheet)) {
			return $logs;
		}

		$current_mod_key = 'theme_mods_' . $stylesheet;
		$current_mod = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $current_mod_key));

		$all_mods = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%'");
		foreach ($all_mods as $m) {
			if (!empty($m->option_value)) {
				$healed_val = self::heal_serialized_string($m->option_value);
				if ($healed_val !== $m->option_value) {
					$wpdb->update($wpdb->options, array('option_value' => $healed_val), array('option_name' => $m->option_name));
					$logs[] = sprintf('Healed serialized string encoding for "%s".', $m->option_name);
				}
			}
		}

		$current_mod = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $current_mod_key));

		$is_mod_empty = empty($current_mod) || 'a:0:{}' === $current_mod || 'b:0;' === $current_mod;

		if ($is_mod_empty) {
			$candidates = array();
			if (!empty($template) && $template !== $stylesheet) {
				$candidates[] = 'theme_mods_' . $template;
			}
			$candidates[] = 'theme_mods_' . str_replace('-', ' ', $stylesheet);
			$candidates[] = 'theme_mods_' . str_replace(' ', '-', $stylesheet);
			$candidates[] = 'theme_mods_' . str_replace('_', '-', $stylesheet);
			$candidates[] = 'theme_mods_' . str_replace('-', '_', $stylesheet);
			$candidates[] = 'theme_mods_' . preg_replace('/-child$/i', '', $stylesheet);
			$candidates[] = 'theme_mods_' . preg_replace('/ child$/i', '', $stylesheet);

			foreach ($candidates as $cand_key) {
				if ($cand_key === $current_mod_key) {
					continue;
				}
				$cand_val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $cand_key));
				if (!empty($cand_val) && 'a:0:{}' !== $cand_val && 'b:0;' !== $cand_val) {
					$wpdb->query($wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = %s",
						$current_mod_key,
						$cand_val,
						$cand_val
					));
					$current_mod = $cand_val;
					$is_mod_empty = false;
					$logs[] = sprintf('Restored theme customization from alias "%s" to active stylesheet "%s".', $cand_key, $current_mod_key);
					break;
				}
			}

			if ($is_mod_empty) {
				$output_type = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
				$any_mod = $wpdb->get_row("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%' AND LENGTH(option_value) > 20 ORDER BY LENGTH(option_value) DESC LIMIT 1", $output_type);
				if (!empty($any_mod) && !empty($any_mod['option_value'])) {
					$wpdb->query($wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = %s",
						$current_mod_key,
						$any_mod['option_value'],
						$any_mod['option_value']
					));
					$current_mod = $any_mod['option_value'];
					$is_mod_empty = false;
					$logs[] = sprintf('Restored richest theme customization from "%s" to active stylesheet "%s".', $any_mod['option_name'], $current_mod_key);
				}
			}
		}

		if (!empty($template) && $template !== $stylesheet) {
			$parent_mod_key = 'theme_mods_' . $template;
			$parent_mod_val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $parent_mod_key));
			if (!empty($parent_mod_val)) {
				$parent_array = @unserialize($parent_mod_val, array('allowed_classes' => false));
				$child_array = !empty($current_mod) ? @unserialize($current_mod, array('allowed_classes' => false)) : array();
				if (is_array($parent_array) && is_array($child_array)) {
					$merged_child = array_merge($parent_array, array_filter($child_array));
					$merged_serialized = serialize($merged_child);
					$wpdb->query($wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = %s",
						$current_mod_key,
						$merged_serialized,
						$merged_serialized
					));
					$current_mod = $merged_serialized;
					$logs[] = sprintf('Synchronized parent theme "%s" customizer and header settings into child theme "%s".', $template, $stylesheet);
				}
			}
		}

		if (!empty($current_mod)) {
			$mods_array = @unserialize($current_mod, array('allowed_classes' => false));
			if (is_array($mods_array)) {
				$needs_menu_update = false;
				$nav_locations = isset($mods_array['nav_menu_locations']) && is_array($mods_array['nav_menu_locations']) ? $mods_array['nav_menu_locations'] : array();

				$valid_menus = $wpdb->get_results(
					"SELECT t.term_id, t.name, t.slug, tt.count 
					FROM {$wpdb->terms} t 
					INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
					WHERE tt.taxonomy = 'nav_menu' 
					ORDER BY tt.count DESC"
				);

				$valid_menu_ids = array();
				$primary_menu_id = 0;
				if (!empty($valid_menus)) {
					foreach ($valid_menus as $vm) {
						$valid_menu_ids[(int) $vm->term_id] = $vm;
					}
					$primary_menu_id = (int) $valid_menus[0]->term_id;
				}

				$has_active_valid_menu = false;
				foreach ($nav_locations as $loc => $term_id) {
					$term_id_int = (int) $term_id;
					if ($term_id_int > 0 && isset($valid_menu_ids[$term_id_int])) {
						$has_active_valid_menu = true;
					} else {
						if ($primary_menu_id > 0) {
							$nav_locations[$loc] = $primary_menu_id;
							$needs_menu_update = true;
							$has_active_valid_menu = true;
							$logs[] = sprintf('Repaired broken menu link for location "%s" (remapped from ID %d to valid menu ID %d).', $loc, $term_id_int, $primary_menu_id);
						}
					}
				}

				if (!$has_active_valid_menu && $primary_menu_id > 0) {
					$standard_locations = array('primary', 'primary-menu', 'main', 'main-menu', 'header-menu', 'top', 'menu-1', 'rehub_header_menu');
					foreach ($standard_locations as $sloc) {
						$nav_locations[$sloc] = $primary_menu_id;
					}
					$needs_menu_update = true;
					$logs[] = sprintf('Auto-assigned primary navigation menu (term ID %d) to all theme header locations.', $primary_menu_id);
				}

				if ($needs_menu_update) {
					$mods_array['nav_menu_locations'] = $nav_locations;
					$updated_serialized = serialize($mods_array);
					$wpdb->update($wpdb->options, array('option_value' => $updated_serialized), array('option_name' => $current_mod_key));
					if (!empty($template) && $template !== $stylesheet) {
						$wpdb->update($wpdb->options, array('option_value' => $updated_serialized), array('option_name' => 'theme_mods_' . $template));
					}
				}
			}
		}

		if (stripos($template, 'rehub') !== false || stripos($stylesheet, 'rehub') !== false) {
			$rehub_rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'rehub_%'");
			foreach ($rehub_rows as $rr) {
				if (!empty($rr->option_value)) {
					$healed_rehub = self::heal_serialized_string($rr->option_value);
					if ($healed_rehub !== $rr->option_value) {
						$wpdb->update($wpdb->options, array('option_value' => $healed_rehub), array('option_name' => $rr->option_name));
						$logs[] = sprintf('Healed Rehub option "%s" serialization.', $rr->option_name);
					}
				}
			}
		}

		if (stripos($template, 'flatsome') !== false || stripos($stylesheet, 'flatsome') !== false) {
			$flatsome_opts = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'flatsome_options' LIMIT 1");
			if (!empty($flatsome_opts)) {
				$healed_flatsome = self::heal_serialized_string($flatsome_opts);
				if ($healed_flatsome !== $flatsome_opts) {
					$wpdb->update($wpdb->options, array('option_value' => $healed_flatsome), array('option_name' => 'flatsome_options'));
					$logs[] = 'Healed Flatsome UX Builder theme options serialization.';
				}
			}
		}

		if (stripos($template, 'astra') !== false || stripos($stylesheet, 'astra') !== false) {
			$astra_opts = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'astra-settings' LIMIT 1");
			if (!empty($astra_opts)) {
				$healed_astra = self::heal_serialized_string($astra_opts);
				if ($healed_astra !== $astra_opts) {
					$wpdb->update($wpdb->options, array('option_value' => $healed_astra), array('option_name' => 'astra-settings'));
					$logs[] = 'Healed Astra theme settings serialization.';
				}
			}
		}

		return $logs;
	}

	/**
	 * Remap prefix-dependent option/usermeta keys to the target prefix.
	 *
	 * @param \wpdb $wpdb
	 * @param string $source_prefix
	 * @return array
	 */
	public static function heal_table_prefixes($wpdb, $source_prefix = '')
	{
		$logs = array();
		$target_prefix = $wpdb->prefix;

		if (empty($source_prefix) || $source_prefix === $target_prefix) {
			return $logs;
		}

		$old_roles_key = $source_prefix . 'user_roles';
		$new_roles_key = $target_prefix . 'user_roles';

		$roles_val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $old_roles_key));
		if (!empty($roles_val)) {
			$wpdb->query($wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = %s",
				$new_roles_key,
				$roles_val,
				$roles_val
			));
			$logs[] = sprintf('Normalized user roles key: "%s" → "%s".', $old_roles_key, $new_roles_key);
		}

		$meta_keys = array('capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user_roles');
		foreach ($meta_keys as $mk) {
			$old_mk = $source_prefix . $mk;
			$new_mk = $target_prefix . $mk;

			$count = $wpdb->query($wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
				$new_mk,
				$old_mk
			));

			if ($count > 0) {
				$logs[] = sprintf('Normalized %d usermeta records from "%s" to "%s".', $count, $old_mk, $new_mk);
			}
		}

		return $logs;
	}

	/**
	 * @param \wpdb|null $wpdb
	 * @param string $content_dir
	 * @return array
	 */
	public static function purge_all_builder_caches($wpdb = null, $content_dir = '')
	{
		if (null === $wpdb) {
			global $wpdb;
		}
		return self::flush_all_builder_caches($wpdb, $content_dir);
	}

	/**
	 * Clear builder CSS caches and common page-cache plugins.
	 *
	 * @param \wpdb $wpdb
	 * @param string $content_dir
	 * @return array
	 */
	public static function flush_all_builder_caches($wpdb, $content_dir = '')
	{
		$flushed = array();

		if (empty($content_dir)) {
			$content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		}
		$uploads_dir = $content_dir . '/uploads';

		$elementor_css_dir = $uploads_dir . '/elementor/css';
		if (is_dir($elementor_css_dir)) {
			self::empty_directory($elementor_css_dir);
			$flushed[] = 'Elementor compiled CSS cache purged';
		}
		$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_css'");
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_elementor_%' OR option_name LIKE 'elementor_global_css%'");

		$et_cache_dir = $uploads_dir . '/et_cache';
		if (is_dir($et_cache_dir)) {
			self::empty_directory($et_cache_dir);
			$flushed[] = 'Divi / Elegant Themes static CSS cache purged';
		}
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'et_pb_cache_%' OR option_name LIKE 'et_cache_%'");

		$fusion_css_dir = $uploads_dir . '/fusion-styles';
		if (is_dir($fusion_css_dir)) {
			self::empty_directory($fusion_css_dir);
			$flushed[] = 'Avada / Fusion Builder compiled CSS cache purged';
		}
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fusion_dynamic_css_%'");

		$astra_css_dir = $uploads_dir . '/astra-addon';
		if (is_dir($astra_css_dir)) {
			self::empty_directory($astra_css_dir);
			$flushed[] = 'Astra Pro dynamic style cache purged';
		}
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_astra_%' OR option_name LIKE 'astra_customizer_cache%'");

		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rehub_custom_css_%' OR option_name LIKE '_transient_rehub_%'");
		$flushed[] = 'Rehub dynamic theme and visual table style cache purged';

		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'flatsome_custom_css%' OR option_name LIKE '_transient_flatsome_%'");
		$flushed[] = 'Flatsome UX Builder CSS cache purged';

		$oxygen_css_dir = $uploads_dir . '/oxygen/css';
		if (is_dir($oxygen_css_dir)) {
			self::empty_directory($oxygen_css_dir);
			$flushed[] = 'Oxygen Builder universal CSS cache purged';
		}

		$bricks_css_dir = $uploads_dir . '/bricks/css';
		if (is_dir($bricks_css_dir)) {
			self::empty_directory($bricks_css_dir);
			$flushed[] = 'Bricks Builder compiled stylesheet cache purged';
		}

		$bb_css_dir = $uploads_dir . '/bb-plugin/cache';
		if (is_dir($bb_css_dir)) {
			self::empty_directory($bb_css_dir);
			$flushed[] = 'Beaver Builder page cache purged';
		}

		$woodmart_css_dir = $uploads_dir . '/woodmart-css';
		if (is_dir($woodmart_css_dir)) {
			self::empty_directory($woodmart_css_dir);
			$flushed[] = 'WoodMart dynamic stylesheet cache purged';
		}

		$avia_css_dir = $uploads_dir . '/dynamic_avia';
		if (is_dir($avia_css_dir)) {
			self::empty_directory($avia_css_dir);
			$flushed[] = 'Enfold Avia Layout Builder CSS cache purged';
		}

		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_core_block_css_%' OR option_name LIKE '_transient_global_styles_%'");
		$flushed[] = 'Gutenberg & Full Site Editing (FSE) block cache purged';

		$greenshift_dir = $uploads_dir . '/greenshift';
		if (is_dir($greenshift_dir)) {
			self::empty_directory($greenshift_dir);
			$flushed[] = 'GreenShift dynamic CSS cache purged';
		}
		$gspb_dir = $uploads_dir . '/gspb';
		if (is_dir($gspb_dir)) {
			self::empty_directory($gspb_dir);
			$flushed[] = 'GreenShift page builder cache purged';
		}
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gspb_%' OR option_name LIKE '_transient_greenshift_%'");

		$rev_css_dir = $uploads_dir . '/revslider';
		if (is_dir($rev_css_dir)) {
			self::empty_directory($rev_css_dir);
			$flushed[] = 'Slider Revolution asset cache purged';
		}

		try {
			if (class_exists('\\LiteSpeed\\Purge')) {
				\LiteSpeed\Purge::purge_all();
				$flushed[] = 'LiteSpeed Cache purged';
			}
			do_action('litespeed_purge_all');
		} catch (\Throwable $e) {}

		try {
			if (function_exists('rocket_clean_domain')) {
				rocket_clean_domain();
				$flushed[] = 'WP Rocket domain cache cleared';
			}
			if (function_exists('rocket_clean_minify')) {
				rocket_clean_minify();
			}
			if (function_exists('rocket_clean_dynamic_cookies')) {
				rocket_clean_dynamic_cookies();
			}
		} catch (\Throwable $e) {}

		try {
			if (function_exists('w3tc_flush_all')) {
				w3tc_flush_all();
				$flushed[] = 'W3 Total Cache flushed';
			}
			if (function_exists('w3tc_pgcache_flush')) {
				w3tc_pgcache_flush();
			}
			if (function_exists('w3tc_objectcache_flush')) {
				w3tc_objectcache_flush();
			}
		} catch (\Throwable $e) {}

		try {
			if (function_exists('wp_cache_clear_cache')) {
				wp_cache_clear_cache();
				$flushed[] = 'WP Super Cache cleared';
			}
			if (function_exists('prune_super_cache') && function_exists('get_supercache_dir')) {
				prune_super_cache(get_supercache_dir(), true);
			}
		} catch (\Throwable $e) {}

		try {
			if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
				$GLOBALS['wp_fastest_cache']->deleteCache(true);
				$flushed[] = 'WP Fastest Cache purged';
			}
		} catch (\Throwable $e) {}

		try {
			if (function_exists('sg_cachepress_purge_cache')) {
				sg_cachepress_purge_cache();
				$flushed[] = 'SiteGround Speed Optimizer cache purged';
			}
			if (function_exists('sg_cachepress_purge_everything')) {
				sg_cachepress_purge_everything();
			}
		} catch (\Throwable $e) {}

		try {
			do_action('breeze_clear_all_cache');
			if (class_exists('\\Breeze_PurgeCache') && method_exists('\\Breeze_PurgeCache', 'breeze_cache_flush')) {
				\Breeze_PurgeCache::breeze_cache_flush();
				$flushed[] = 'Cloudways Breeze cache purged';
			}
		} catch (\Throwable $e) {}

		try {
			if (class_exists('\\autoptimizeCache') && method_exists('\\autoptimizeCache', 'clearall')) {
				\autoptimizeCache::clearall();
				$flushed[] = 'Autoptimize minification cache cleared';
			}
		} catch (\Throwable $e) {}

		try {
			do_action('wphb_clear_page_cache');
		} catch (\Throwable $e) {}

		try {
			if (class_exists('\\Cache_Enabler') && method_exists('\\Cache_Enabler', 'clear_total_cache')) {
				\Cache_Enabler::clear_total_cache();
				$flushed[] = 'Cache Enabler cache cleared';
			}
		} catch (\Throwable $e) {}

		try {
			if (class_exists('\\comet_cache') && method_exists('\\comet_cache', 'clear')) {
				\comet_cache::clear();
				$flushed[] = 'Comet Cache purged';
			}
		} catch (\Throwable $e) {}

		try {
			if (class_exists('\\Swift_Performance_Cache') && method_exists('\\Swift_Performance_Cache', 'clear_all_cache')) {
				\Swift_Performance_Cache::clear_all_cache();
				$flushed[] = 'Swift Performance cache cleared';
			}
		} catch (\Throwable $e) {}

		try {
			if (class_exists('\\FlyingPress\\Purge') && method_exists('\\FlyingPress\\Purge', 'purge_everything')) {
				\FlyingPress\Purge::purge_everything();
				$flushed[] = 'FlyingPress cache purged';
			}
		} catch (\Throwable $e) {}

		try {
			if (function_exists('nitro_purge_all')) {
				nitro_purge_all();
				$flushed[] = 'NitroPack cache purged';
			}
		} catch (\Throwable $e) {}

		try {
			do_action('kinsta_cache_purge');
			do_action('wpe_purge_all');
			do_action('pantheon_cache_purge');
			do_action('cloudflare_purge_cache');
			do_action('rt_nginx_helper_purge_all');
		} catch (\Throwable $e) {}

		$cache_dirs = array(
			$content_dir . '/cache',
			$content_dir . '/w3tc-cache',
			$content_dir . '/autoptimize',
			$content_dir . '/litespeed',
			$content_dir . '/breeze',
			$content_dir . '/flying-press',
			$content_dir . '/et_cache',
			$uploads_dir . '/wp-rocket',
		);
		foreach ($cache_dirs as $cd) {
			if (is_dir($cd)) {
				self::empty_directory($cd);
			}
		}
		$flushed[] = 'Static file cache directories purged';

		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		return $flushed;
	}

	/**
	 * @param string $dir
	 * @return void
	 */
	private static function empty_directory($dir)
	{
		if (!is_dir($dir)) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($iterator as $item) {
				if ($item->isDir()) {
					@rmdir($item->getPathname());
				} else {
					@unlink($item->getPathname());
				}
			}
		} catch (\Throwable $e) {
		}
	}
}
