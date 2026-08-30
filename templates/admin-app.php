<?php
/**
 * Mount point for the Flexa SMTP admin React app. Enqueue.php enqueues the
 * built bundle and localizes the `flexaSmtp` global before this renders.
 *
 * @package Flexa\Smtp
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<div id="flexa-smtp-admin-root" class="flexa-smtp-wrap flexa-smtp-themed">
		<noscript><?php esc_html_e( 'Flexa SMTP requires JavaScript to be enabled.', 'flexa-smtp' ); ?></noscript>
	</div>
</div>
