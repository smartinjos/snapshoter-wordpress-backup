<?php

namespace Snapshoter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	public static function register( $base_path ) {
		spl_autoload_register(
			static function ( $class ) use ( $base_path ) {
				if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
					return;
				}

				$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
				$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
				$file     = rtrim( $base_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative . '.php';

				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}

