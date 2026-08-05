<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle clear logs.
if ( isset( $_POST['splecheh_clear_logs'] ) && check_admin_referer( 'splecheh_clear_logs' ) ) {
	Splecheh_Logs::clearLogs();
	wp_redirect( add_query_arg( 'cleared', '1', menu_page_url( 'splecheh-logs', false ) ) );
	exit;
}

$cleared  = isset( $_GET['cleared'] );
$files    = Splecheh_Logs::getLogFiles();
$selected = isset( $_GET['log_date'] ) ? sanitize_file_name( wp_unslash( $_GET['log_date'] ) ) : ( $files[0] ?? '' );

$log_content = '';
if ( $selected && file_exists( SPLECHEH_LOG_PATH . '/' . $selected ) ) {
	$raw = file( SPLECHEH_LOG_PATH . '/' . $selected, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( $raw ) {
		$log_content = implode( "\n", array_reverse( $raw ) );
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Logs', 'splecheh' ); ?></h1>

	<?php if ( $cleared ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'All log files deleted.', 'splecheh' ); ?></p>
		</div>
	<?php endif; ?>

	<div style="margin-bottom:1em;">
		<form method="post" style="display:inline-block;">
			<?php wp_nonce_field( 'splecheh_clear_logs' ); ?>
			<button type="submit" name="splecheh_clear_logs" value="1" class="button button-secondary"
				onclick="return confirm('<?php esc_attr_e( 'Delete all log files? This cannot be undone.', 'splecheh' ); ?>');">
				<?php esc_html_e( 'Clear All', 'splecheh' ); ?>
			</button>
		</form>

		<?php if ( ! empty( $files ) ) : ?>
		<form method="get" style="display:inline-block;margin-left:1em;">
			<input type="hidden" name="page" value="splecheh-logs">
			<label for="splecheh-log-date"><?php esc_html_e( 'Date:', 'splecheh' ); ?></label>
			<select id="splecheh-log-date" name="log_date" onchange="this.form.submit()">
				<?php foreach ( $files as $file ) :
					$date = preg_replace( '/^splecheh-(.+)\.log$/', '$1', $file );
				?>
				<option value="<?php echo esc_attr( $file ); ?>" <?php selected( $file, $selected ); ?>>
					<?php echo esc_html( $date ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php endif; ?>
	</div>

	<div style="background:#1e1e1e;padding:1em;border-radius:4px;">
		<pre style="color:#d4d4d4;font-size:12px;margin:0;overflow:auto;max-height:600px;white-space:pre-wrap;"><?php
			if ( $log_content !== '' ) {
				echo esc_html( $log_content );
			} else {
				echo esc_html__( 'No log entries found.', 'splecheh' );
			}
		?></pre>
	</div>
</div>
