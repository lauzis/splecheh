<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Help', 'splecheh' ); ?></h1>

	<h2><?php esc_html_e( 'Setting Up a Real Cron Job', 'splecheh' ); ?></h2>

	<p>
		<?php esc_html_e( 'By default, WordPress uses a pseudo-cron ("WP-Cron") that only fires when someone visits your site. This means scheduled tasks are unreliable on low-traffic sites. A real system cron job is faster and guaranteed to run on time.', 'splecheh' ); ?>
	</p>

	<h3><?php esc_html_e( 'Step 1 — Disable WP-Cron', 'splecheh' ); ?></h3>

	<p><?php esc_html_e( 'Add this line to your wp-config.php (before the "That\'s all, stop editing!" comment):', 'splecheh' ); ?></p>

	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">define( 'DISABLE_WP_CRON', true );</pre>

	<h3><?php esc_html_e( 'Step 2 — Add a System Cron Entry', 'splecheh' ); ?></h3>

	<p><?php esc_html_e( 'Run crontab -e on your server and add one of the following entries. Replace the URL or path with your own site.', 'splecheh' ); ?></p>

	<p><strong><?php esc_html_e( 'Option A — wget (HTTP request):', 'splecheh' ); ?></strong></p>
	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron &>/dev/null</pre>

	<p><strong><?php esc_html_e( 'Option B — WP-CLI (recommended when available):', 'splecheh' ); ?></strong></p>
	<pre style="background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;font-size:13px;overflow:auto;">*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet</pre>

	<p>
		<?php esc_html_e( 'Both examples run every 5 minutes. Adjust the interval to match your needs.', 'splecheh' ); ?>
	</p>

	<h3><?php esc_html_e( 'Why Real Cron?', 'splecheh' ); ?></h3>

	<ul style="list-style:disc;padding-left:1.5em;">
		<li><?php esc_html_e( 'Reliability — tasks run even when no one is visiting the site.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Performance — WP-Cron adds overhead to every page request; a system cron does not.', 'splecheh' ); ?></li>
		<li><?php esc_html_e( 'Accuracy — tasks fire at the scheduled time, not whenever the next visitor happens to arrive.', 'splecheh' ); ?></li>
	</ul>
</div>
