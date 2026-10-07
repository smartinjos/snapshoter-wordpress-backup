<?php

namespace Snapshoter\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstructionsPage {

	public function render() {
		if ( ! current_user_can( SNAPSHOTER_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'snapshoter' ) );
		}

		wp_nonce_field( SNAPSHOTER_NONCE_ACTION, SNAPSHOTER_NONCE_NAME );

		?>
		<div class="snapshoter" id="snapshoter-help-root">
			<noscript>
				<p><?php esc_html_e( 'Snapshoter Help requires JavaScript. Please enable it for /wp-admin and reload this page.', 'snapshoter' ); ?></p>
			</noscript>
		</div>
		<?php
	}

	public static function build_payload() {
		$server_info        = JobController::get_upload_limits_info();
		$max_execution_time = (int) ini_get( 'max_execution_time' );

		$memory_bytes        = isset( $server_info['limits']['memory_limit']['bytes'] )
			? (int) $server_info['limits']['memory_limit']['bytes']
			: 0;
		$upload_bytes        = isset( $server_info['limits']['upload_max_filesize']['bytes'] )
			? (int) $server_info['limits']['upload_max_filesize']['bytes']
			: 0;
		$post_bytes          = isset( $server_info['limits']['post_max_size']['bytes'] )
			? (int) $server_info['limits']['post_max_size']['bytes']
			: 0;

		$limits = array(
			array(
				'key'              => 'memory_limit',
				'label'            => __( 'PHP memory limit', 'snapshoter' ),
				'currentFormatted' => isset( $server_info['limits']['memory_limit']['formatted'] )
					? $server_info['limits']['memory_limit']['formatted']
					: __( 'Unknown', 'snapshoter' ),
				'recommended'      => '256 MB+',
				'status'           => self::classify_bytes( $memory_bytes, 256 * 1024 * 1024, 128 * 1024 * 1024 ),
			),
			array(
				'key'              => 'max_execution_time',
				'label'            => __( 'Max execution time', 'snapshoter' ),
				'currentFormatted' => $max_execution_time > 0 ? $max_execution_time . 's' : __( 'Unlimited', 'snapshoter' ),
				'recommended'      => '120s+',
				'status'           => self::classify_seconds( $max_execution_time, 120, 60 ),
			),
			array(
				'key'              => 'upload_max_filesize',
				'label'            => __( 'Upload max filesize', 'snapshoter' ),
				'currentFormatted' => isset( $server_info['limits']['upload_max_filesize']['formatted'] )
					? $server_info['limits']['upload_max_filesize']['formatted']
					: __( 'Unknown', 'snapshoter' ),
				'recommended'      => '64 MB+',
				'status'           => self::classify_bytes( $upload_bytes, 64 * 1024 * 1024, 10 * 1024 * 1024 ),
			),
			array(
				'key'              => 'post_max_size',
				'label'            => __( 'Post max size', 'snapshoter' ),
				'currentFormatted' => isset( $server_info['limits']['post_max_size']['formatted'] )
					? $server_info['limits']['post_max_size']['formatted']
					: __( 'Unknown', 'snapshoter' ),
				'recommended'      => '64 MB+',
				'status'           => self::classify_bytes( $post_bytes, 64 * 1024 * 1024, 10 * 1024 * 1024 ),
			),
		);

		return array(
			'version'  => SNAPSHOTER_VERSION,
			'limits'   => $limits,
			'warnings' => isset( $server_info['warnings'] ) && is_array( $server_info['warnings'] )
				? array_values( array_map( 'strval', $server_info['warnings'] ) )
				: array(),
		);
	}

	private static function classify_bytes( $current, $recommended, $critical ) {
		if ( $current <= 0 ) {
			return 'warning';
		}
		if ( $current >= $recommended ) {
			return 'ok';
		}
		if ( $current >= $critical ) {
			return 'warning';
		}
		return 'critical';
	}

	private static function classify_seconds( $current, $recommended, $critical ) {
		if ( $current === 0 ) {
			return 'ok';
		}
		if ( $current >= $recommended ) {
			return 'ok';
		}
		if ( $current >= $critical ) {
			return 'warning';
		}
		return 'critical';
	}
}
