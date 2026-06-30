<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SPLECHEH_DIR . 'classes/SpellCheckListTable.php';

$table = new Splecheh_SpellCheckListTable();
$table->prepare_items();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Spell Check', 'splecheh' ); ?></h1>

	<?php if ( Splecheh_Cron::is_enabled() ) :
		$summary = Splecheh_Cron::get_summary();
		?>
	<div class="splecheh-status-bar notice notice-info inline" style="display:flex;align-items:center;gap:16px;padding:8px 12px;margin:12px 0;">
		<strong><?php esc_html_e( 'Background Task', 'splecheh' ); ?></strong>
		<span id="splecheh-status-last-run">
			<?php if ( $summary['last_run'] ) : ?>
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Last run: %s', 'splecheh' ),
					esc_html(
						get_date_from_gmt(
							gmdate( 'Y-m-d H:i:s', strtotime( $summary['last_run'] ) ),
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
						)
					)
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Not yet run', 'splecheh' ); ?>
			<?php endif; ?>
		</span>
		<span id="splecheh-status-issues">
			<?php
			printf(
				/* translators: %d: number of issues */
				esc_html__( 'Issues (last batch): %d', 'splecheh' ),
				(int) $summary['issues_found']
			);
			?>
		</span>
		<span id="splecheh-status-pending">
			<?php
			printf(
				/* translators: %d: number of posts */
				esc_html__( 'Pending: %d', 'splecheh' ),
				(int) $summary['posts_pending']
			);
			?>
		</span>
		<button class="button button-secondary" id="splecheh-run-now">
			<?php esc_html_e( 'Run Now', 'splecheh' ); ?>
		</button>
		<span id="splecheh-run-now-spinner" class="spinner" style="float:none;vertical-align:middle;display:none;margin:0;"></span>
	</div>
	<?php endif; ?>

	<div id="splecheh-check-message" style="display:none;" class="notice is-dismissible"><p></p></div>

	<form id="splecheh-list-form" method="get">
		<?php $table->display(); ?>
	</form>
</div>
