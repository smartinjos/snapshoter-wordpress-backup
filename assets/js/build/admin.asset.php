<?php
if (!defined('ABSPATH')) {
	exit;
}

$snapshoter_admin_js = __DIR__ . '/admin.js';
$snapshoter_admin_ver = file_exists($snapshoter_admin_js)
	? (string) filemtime($snapshoter_admin_js)
	: '1.0';

return array(
	'dependencies' => array('wp-element', 'wp-i18n'),
	'version' => $snapshoter_admin_ver,
);
