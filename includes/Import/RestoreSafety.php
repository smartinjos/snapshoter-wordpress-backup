<?php

namespace Snapshoter\Import;

if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

final class RestoreSafety
{
	const PAUSE_OPTION = 'snapshoter_as_pause_restore';
	const NOTICE_OPTION = 'snapshoter_restore_safety_notices';

	/**
	 * @return bool
	 */
	public static function is_enabled()
	{
		if (defined('SNAPSHOTER_RESTORE_SAFETY') && !SNAPSHOTER_RESTORE_SAFETY) {
			return false;
		}

		/**
		 * Toggle restore safety (AS / webhooks / payment checklist). Default true.
		 *
		 * @param bool $enabled Whether restore safety runs after finalize.
		 */
		return (bool) apply_filters('snapshoter_restore_safety', true);
	}

	/**
	 * Prevent Action Scheduler from executing while finalize is in flight.
	 */
	public static function arm_queue_pause()
	{
		if (!self::is_enabled()) {
			return;
		}
		update_option(self::PAUSE_OPTION, (string) time(), false);
		self::install_runtime_hooks();
	}

	/**
	 * Clear finalize pause flag (reconcile already neutralized overdue work).
	 */
	public static function disarm_queue_pause()
	{
		delete_option(self::PAUSE_OPTION);
	}

	/**
	 * @return bool
	 */
	public static function is_queue_paused()
	{
		return (bool) get_option(self::PAUSE_OPTION, '');
	}

	public static function install_runtime_hooks()
	{
		static $installed = false;
		if ($installed) {
			return;
		}
		$installed = true;

		add_filter('action_scheduler_queue_runner_batch_size', array(__CLASS__, 'filter_as_batch_size'), 100);
	}

	/**
	 * @param int $size Batch size.
	 * @return int
	 */
	public static function filter_as_batch_size($size)
	{
		if (self::is_queue_paused()) {
			return 0;
		}
		return (int) $size;
	}

	/**
	 * @param array    $manifest Import manifest.
	 * @param callable $log      function(string $message): void
	 * @return array{logs: string[], warnings: string[], touched: bool}
	 */
	public static function after_restore(array $manifest, $log = null)
	{
		$result = array(
			'logs' => array(),
			'warnings' => array(),
			'touched' => false,
		);

		if (!self::is_enabled()) {
			self::emit($log, $result, 'Restore safety skipped (disabled).');
			return $result;
		}

		$old_url = self::manifest_site_url($manifest);
		$new_url = untrailingslashit(site_url());
		$domain_changed = ($old_url !== '' && $new_url !== '' && self::normalize_url($old_url) !== self::normalize_url($new_url));

		$as = self::reconcile_action_scheduler($log);
		$result['logs'] = array_merge($result['logs'], $as['logs']);
		$result['warnings'] = array_merge($result['warnings'], $as['warnings']);
		if ($as['touched']) {
			$result['touched'] = true;
		}

		$hooks = self::reconcile_woocommerce_webhooks($old_url, $new_url, $domain_changed, $log);
		$result['logs'] = array_merge($result['logs'], $hooks['logs']);
		$result['warnings'] = array_merge($result['warnings'], $hooks['warnings']);
		if ($hooks['touched']) {
			$result['touched'] = true;
		}

		$pay = self::reconcile_payment_gateway_options($old_url, $new_url, $domain_changed, $log);
		$result['logs'] = array_merge($result['logs'], $pay['logs']);
		$result['warnings'] = array_merge($result['warnings'], $pay['warnings']);
		if ($pay['touched']) {
			$result['touched'] = true;
		}

		$https = self::https_mismatch_warnings();
		$result['warnings'] = array_merge($result['warnings'], $https);

		if (!empty($result['warnings'])) {
			update_option(self::NOTICE_OPTION, array_values(array_unique($result['warnings'])), false);
		} else {
			delete_option(self::NOTICE_OPTION);
		}

		if (!$result['touched'] && empty($result['warnings'])) {
			self::emit($log, $result, 'Restore safety: no Action Scheduler / WooCommerce payment work needed.');
		}

		self::disarm_queue_pause();

		return $result;
	}

	/**
	 * @param callable|null $log Logger.
	 * @return array{logs: string[], warnings: string[], touched: bool}
	 */
	private static function reconcile_action_scheduler($log)
	{
		global $wpdb;

		$out = array('logs' => array(), 'warnings' => array(), 'touched' => false);
		$table = $wpdb->prefix . 'actionscheduler_actions';

		if (!self::table_exists($table)) {
			return $out;
		}

		$table = esc_sql($table);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table = esc_sql( $wpdb->prefix . known_suffix ).
		$overdue = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}`
			 WHERE status IN ('pending','in-progress')
			   AND scheduled_date_gmt IS NOT NULL
			   AND scheduled_date_gmt <= UTC_TIMESTAMP()"
		);

		if ($overdue < 1) {
			self::emit($log, $out, 'Action Scheduler: no overdue pending/in-progress actions.');
			return $out;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table = esc_sql( $wpdb->prefix . known_suffix ).
		$canceled = (int) $wpdb->query(
			"UPDATE `{$table}`
			 SET status = 'canceled', last_attempt_gmt = UTC_TIMESTAMP()
			 WHERE status = 'pending'
			   AND scheduled_date_gmt IS NOT NULL
			   AND scheduled_date_gmt <= UTC_TIMESTAMP()"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table = esc_sql( $wpdb->prefix . known_suffix ).
		$completed = (int) $wpdb->query(
			"UPDATE `{$table}`
			 SET status = 'complete', last_attempt_gmt = UTC_TIMESTAMP()
			 WHERE status = 'in-progress'
			   AND scheduled_date_gmt IS NOT NULL
			   AND scheduled_date_gmt <= UTC_TIMESTAMP()"
		);

		if ($canceled < 0) {
			$canceled = 0;
		}
		if ($completed < 0) {
			$completed = 0;
		}

		$out['touched'] = ($canceled + $completed) > 0;
		$msg = sprintf(
			'Action Scheduler: neutralized %d overdue action(s) (%d canceled, %d marked complete) to prevent post-restore side effects (emails, webhooks, renewals).',
			$canceled + $completed,
			$canceled,
			$completed
		);
		self::emit($log, $out, $msg);

		if ($out['touched']) {
			$out['warnings'][] = __(
				'Overdue Action Scheduler jobs from the restored backup were canceled so they would not re-run (Woo emails, webhooks, subscription renewals, payment retries). Review WooCommerce → Status → Scheduled Actions if you expected any of those jobs to fire.',
				'snapshoter'
			);
		}

		return $out;
	}

	/**
	 * @param string $old_url Old site URL.
	 * @param string $new_url New site URL.
	 * @param bool   $domain_changed Whether URL changed.
	 * @param callable|null $log Logger.
	 * @return array{logs: string[], warnings: string[], touched: bool}
	 */
	private static function reconcile_woocommerce_webhooks($old_url, $new_url, $domain_changed, $log)
	{
		global $wpdb;

		$out = array('logs' => array(), 'warnings' => array(), 'touched' => false);
		$table = $wpdb->prefix . 'wc_webhooks';

		if (!self::table_exists($table)) {
			return $out;
		}

		$table = esc_sql($table);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table = esc_sql( $wpdb->prefix . known_suffix ).
		$rows = $wpdb->get_results("SELECT webhook_id, name, status, delivery_url FROM `{$table}`", ARRAY_A);
		if (empty($rows) || !is_array($rows)) {
			return $out;
		}

		$rewritten = 0;
		$disabled = 0;
		$old_host = $old_url !== '' ? (string) wp_parse_url($old_url, PHP_URL_HOST) : '';

		foreach ($rows as $row) {
			$id = isset($row['webhook_id']) ? (int) $row['webhook_id'] : 0;
			$url = isset($row['delivery_url']) ? (string) $row['delivery_url'] : '';
			if ($id < 1 || $url === '') {
				continue;
			}

			$needs = false;
			if ($domain_changed && $old_url !== '' && self::string_contains_url($url, $old_url)) {
				$needs = true;
			} elseif ($old_host !== '' && stripos($url, $old_host) !== false && $domain_changed) {
				$needs = true;
			}

			if (!$needs) {
				continue;
			}

			$new_delivery = self::soft_replace_url($url, $old_url, $new_url);
			if ($new_delivery !== $url && $new_delivery !== '') {
				$wpdb->update(
					$table,
					array(
						'delivery_url' => $new_delivery,
					),
					array('webhook_id' => $id),
					array('%s'),
					array('%d')
				);
				$rewritten++;
				$out['touched'] = true;
				continue;
			}

			$wpdb->update(
				$table,
				array('status' => 'disabled'),
				array('webhook_id' => $id),
				array('%s'),
				array('%d')
			);
			$disabled++;
			$out['touched'] = true;
		}

		if ($rewritten > 0 || $disabled > 0) {
			self::emit(
				$log,
				$out,
				sprintf(
					'WooCommerce webhooks: rewritten %d delivery URL(s), disabled %d that could not be rewritten safely.',
					$rewritten,
					$disabled
				)
			);
			$out['warnings'][] = __(
				'WooCommerce webhook delivery URLs were updated or disabled after restore. Confirm webhooks under WooCommerce → Settings → Advanced → Webhooks (and in each payment/gateway dashboard).',
				'snapshoter'
			);
		}

		return $out;
	}

	/**
	 * @param string $old_url Old site URL.
	 * @param string $new_url New site URL.
	 * @param bool   $domain_changed Whether URL changed.
	 * @param callable|null $log Logger.
	 * @return array{logs: string[], warnings: string[], touched: bool}
	 */
	private static function reconcile_payment_gateway_options($old_url, $new_url, $domain_changed, $log)
	{
		global $wpdb;

		$out = array('logs' => array(), 'warnings' => array(), 'touched' => false);

		$active = self::active_plugin_basenames();
		$detected = self::detect_gateway_plugins($active);

		if (empty($detected) && !self::woocommerce_active($active)) {
			return $out;
		}

		$rewrites = 0;
		if ($domain_changed && $old_url !== '' && $new_url !== '') {
			$like_woo = $wpdb->esc_like('woocommerce_') . '%';
			$like_url = '%' . $wpdb->esc_like($old_url) . '%';
			$like_host = '';
			$host = (string) wp_parse_url($old_url, PHP_URL_HOST);
			if ($host !== '') {
				$like_host = '%' . $wpdb->esc_like($host) . '%';
			}

			if ($like_host !== '') {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_id, option_name, option_value FROM {$wpdb->options}
						WHERE option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)",
						$like_woo,
						$like_url,
						$like_host
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_id, option_name, option_value FROM {$wpdb->options}
						WHERE option_name LIKE %s AND option_value LIKE %s",
						$like_woo,
						$like_url
					),
					ARRAY_A
				);
			}
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$name = isset($row['option_name']) ? (string) $row['option_name'] : '';
					$value = isset($row['option_value']) ? $row['option_value'] : '';
					if ($name === '' || $value === '') {
						continue;
					}

					$decoded = maybe_unserialize($value);
					$changed = false;
					$new_value = self::rewrite_non_secret_urls($decoded, $old_url, $new_url, $changed);
					if (!$changed) {
						continue;
					}

					$stored = maybe_serialize($new_value);
					$wpdb->update(
						$wpdb->options,
						array('option_value' => $stored),
						array('option_id' => (int) $row['option_id']),
						array('%s'),
						array('%d')
					);
					$rewrites++;
					$out['touched'] = true;
					self::emit($log, $out, 'Payment option URL soft-rewrite: ' . $name);
				}
			}
		}

		if ($rewrites > 0) {
			self::emit($log, $out, sprintf('Payment gateways: soft-rewrote URL fields in %d option row(s). Secrets were not modified.', $rewrites));
		}

		if ($domain_changed || $rewrites > 0) {
			if (!empty($detected)) {
				$labels = array_values($detected);
				$out['warnings'][] = sprintf(
					/* translators: %s: comma-separated payment gateway names */
					__('Payment gateways detected (%s). After restore/migration, update webhook/callback URLs in each gateway dashboard (Razorpay, Stripe, PayPal, Cashfree, Mollie, Afterpay, etc.). Snapshoter never changes API keys or secrets.', 'snapshoter'),
					implode(', ', $labels)
				);
			} elseif (self::woocommerce_active($active)) {
				$out['warnings'][] = __(
					'WooCommerce is active and the site URL changed. Verify payment gateway webhooks and return URLs in each provider dashboard. API keys were not modified.',
					'snapshoter'
				);
			}
		}

		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function https_mismatch_warnings()
	{
		$warnings = array();
		if (is_ssl()) {
			return $warnings;
		}

		$site = (string) get_option('siteurl');
		if (stripos($site, 'https://') === 0) {
			$warnings[] = __(
				'Site URL is stored as HTTPS but this request is HTTP. Fix SSL / reverse-proxy headers before taking live payments.',
				'snapshoter'
			);
		}

		return $warnings;
	}

	/**
	 * @param mixed  $value   Option value.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @param bool   $changed Set true when mutated.
	 * @param string $key     Array key when walking.
	 * @return mixed
	 */
	private static function rewrite_non_secret_urls($value, $old_url, $new_url, &$changed, $key = '')
	{
		if (is_array($value)) {
			foreach ($value as $k => $v) {
				$value[$k] = self::rewrite_non_secret_urls($v, $old_url, $new_url, $changed, (string) $k);
			}
			return $value;
		}

		if (!is_string($value) || $value === '') {
			return $value;
		}

		if (self::is_secret_field_key($key) || self::looks_like_secret_value($value)) {
			return $value;
		}

		if (!self::string_contains_url($value, $old_url) && !self::string_contains_host($value, $old_url)) {
			return $value;
		}

		if (!self::looks_like_urlish($value, $key)) {
			return $value;
		}

		$replaced = self::soft_replace_url($value, $old_url, $new_url);
		if ($replaced !== $value) {
			$changed = true;
			return $replaced;
		}

		return $value;
	}

	/**
	 * @param string $key Option / array key.
	 * @return bool
	 */
	private static function is_secret_field_key($key)
	{
		$key = strtolower((string) $key);
		if ($key === '') {
			return false;
		}

		$needles = array(
			'secret',
			'password',
			'passwd',
			'private',
			'api_key',
			'apikey',
			'access_token',
			'refresh_token',
			'client_secret',
			'webhook_secret',
			'signing',
			'signature',
			'salt',
			'merchant_key',
			'encryption',
			'auth_token',
			'publishable_key', // keep Stripe pk untouched too - dashboard ownership
			'secret_key',
			'test_key',
			'live_key',
			'key_id',
			'key_secret',
		);

		foreach ($needles as $needle) {
			if (strpos($key, $needle) !== false) {
				return true;
			}
		}

		if (preg_match('/(^|_)(key|token|pwd)(_|$)/', $key) && strpos($key, 'url') === false) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $value Candidate value.
	 * @return bool
	 */
	private static function looks_like_secret_value($value)
	{
		$value = trim($value);
		if ($value === '') {
			return false;
		}
		if (preg_match('/^(sk|pk|rk)_(live|test)_/i', $value)) {
			return true;
		}
		if (preg_match('/^whsec_/i', $value)) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $value Value.
	 * @param string $key   Key hint.
	 * @return bool
	 */
	private static function looks_like_urlish($value, $key)
	{
		$key = strtolower((string) $key);
		if ($key !== '' && preg_match('/url|endpoint|callback|notify|return|webhook|ipn|redirect|success|cancel|fail/i', $key)) {
			return true;
		}
		if (preg_match('#https?://#i', $value)) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $haystack Haystack.
	 * @param string $old_url  Old URL.
	 * @return bool
	 */
	private static function string_contains_url($haystack, $old_url)
	{
		if ($old_url === '' || $haystack === '') {
			return false;
		}
		$variants = array(
			$old_url,
			untrailingslashit($old_url),
			trailingslashit($old_url),
		);
		foreach ($variants as $v) {
			if ($v !== '' && stripos($haystack, $v) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $haystack Haystack.
	 * @param string $old_url  Old URL.
	 * @return bool
	 */
	private static function string_contains_host($haystack, $old_url)
	{
		$host = (string) wp_parse_url($old_url, PHP_URL_HOST);
		if ($host === '') {
			return false;
		}
		return stripos($haystack, $host) !== false;
	}

	/**
	 * @param string $value   Value.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return string
	 */
	private static function soft_replace_url($value, $old_url, $new_url)
	{
		if ($old_url === '' || $new_url === '' || $value === '') {
			return $value;
		}

		$pairs = array(
			$old_url => $new_url,
			untrailingslashit($old_url) => untrailingslashit($new_url),
			trailingslashit($old_url) => trailingslashit($new_url),
		);

		$old_host = (string) wp_parse_url($old_url, PHP_URL_HOST);
		$new_host = (string) wp_parse_url($new_url, PHP_URL_HOST);
		if ($old_host !== '' && $new_host !== '' && $old_host !== $new_host) {
			$pairs['://' . $old_host] = '://' . $new_host;
			$pairs['//' . $old_host] = '//' . $new_host;
		}

		$out = $value;
		foreach ($pairs as $from => $to) {
			if ($from === '' || $from === $to) {
				continue;
			}
			$out = str_ireplace($from, $to, $out);
		}

		return $out;
	}

	/**
	 * @param array $active Active plugin basenames.
	 * @return array<string, string> slug => label
	 */
	private static function detect_gateway_plugins(array $active)
	{
		$map = array(
			'woocommerce-gateway-stripe' => 'Stripe',
			'woocommerce-paypal-payments' => 'PayPal',
			'woocommerce-paypal' => 'PayPal',
			'razorpay-payments' => 'Razorpay',
			'woo-razorpay' => 'Razorpay',
			'razorpay' => 'Razorpay',
			'cashfree' => 'Cashfree',
			'woocommerce-cashfree' => 'Cashfree',
			'payu' => 'PayU',
			'payubiz' => 'PayU',
			'phonepe' => 'PhonePe',
			'paytm' => 'Paytm',
			'ccavenue' => 'CCAvenue',
			'instamojo' => 'Instamojo',
			'mollie' => 'Mollie',
			'klarna' => 'Klarna',
			'afterpay' => 'Afterpay',
			'zipmoney' => 'Zip',
			'zip-payment' => 'Zip',
			'square' => 'Square',
			'adyen' => 'Adyen',
			'authorize-net' => 'Authorize.net',
			'affirm' => 'Affirm',
		);

		$found = array();
		foreach ($active as $basename) {
			$basename = (string) $basename;
			$slug = strtolower(dirname($basename));
			if ($slug === '.' || $slug === '') {
				$slug = strtolower(basename($basename, '.php'));
			}
			foreach ($map as $needle => $label) {
				if (strpos($slug, $needle) !== false || strpos(strtolower($basename), $needle) !== false) {
					$found[$needle] = $label;
				}
			}
		}

		return $found;
	}

	/**
	 * @param array $active Active plugins.
	 * @return bool
	 */
	private static function woocommerce_active(array $active)
	{
		foreach ($active as $basename) {
			if (strpos((string) $basename, 'woocommerce/') === 0 || (string) $basename === 'woocommerce/woocommerce.php') {
				return true;
			}
		}
		return class_exists('\\WooCommerce') || class_exists('\\WC_Payment_Gateways');
	}

	/**
	 * @return string[]
	 */
	private static function active_plugin_basenames()
	{
		$raw = get_option('active_plugins', array());
		if (!is_array($raw)) {
			$raw = maybe_unserialize($raw);
		}
		return is_array($raw) ? $raw : array();
	}

	/**
	 * @param array $manifest Manifest.
	 * @return string
	 */
	private static function manifest_site_url(array $manifest)
	{
		if (!empty($manifest['site_url'])) {
			return untrailingslashit((string) $manifest['site_url']);
		}
		if (!empty($manifest['home_url'])) {
			return untrailingslashit((string) $manifest['home_url']);
		}
		return '';
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url($url)
	{
		return strtolower(untrailingslashit((string) $url));
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists($table)
	{
		global $wpdb;
		if ($table === '') {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		return $found === $table;
	}

	/**
	 * @param callable|null $log    Logger.
	 * @param array         $result Result bag.
	 * @param string        $message Message.
	 */
	private static function emit($log, array &$result, $message)
	{
		$message = (string) $message;
		if ($message === '') {
			return;
		}
		$result['logs'][] = $message;
		if (is_callable($log)) {
			call_user_func($log, '[RestoreSafety] ' . $message);
		}
	}
}
