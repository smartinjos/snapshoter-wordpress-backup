<?php

namespace Snapshoter\Core;

if (!defined('ABSPATH')) {
	exit;
}

class AdminPage
{
	public function render()
	{
		if (!current_user_can(SNAPSHOTER_CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'snapshoter'));
		}

		wp_nonce_field(SNAPSHOTER_NONCE_ACTION, SNAPSHOTER_NONCE_NAME);

		?>
		<div class="snapshoter" id="snapshoter-root">
			<noscript>
				<p>
					<?php esc_html_e('Snapshoter requires JavaScript to be enabled.', 'snapshoter'); ?>
				</p>
			</noscript>
			<p class="snapshoter-boot">
				<?php esc_html_e('Loading Snapshoter...', 'snapshoter'); ?>
			</p>
		</div>
		<?php
	}
}
