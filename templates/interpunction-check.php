<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SPLECHEH_DIR . 'classes/InterpunctionListTable.php';

$table = new Splecheh_InterpunctionListTable();
$table->prepare_items();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Interpunction Check', 'splecheh' ); ?></h1>

	<p><?php esc_html_e( 'Uses an LLM (configured in Settings) to check punctuation and capitalization, sentence by sentence.', 'splecheh' ); ?></p>

	<?php if ( Splecheh_InterpunctionCron::is_enabled() ) :
		$interpunction_summary = Splecheh_InterpunctionCron::get_summary();
		?>
	<div class="splecheh-status-bar notice notice-info inline" style="display:flex;align-items:center;gap:16px;padding:8px 12px;margin:12px 0;">
		<strong><?php esc_html_e( 'Background Task', 'splecheh' ); ?></strong>
		<span id="splecheh-interpunction-status-last-run">
			<?php if ( $interpunction_summary['last_run'] ) : ?>
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Last run: %s', 'splecheh' ),
					esc_html(
						get_date_from_gmt(
							gmdate( 'Y-m-d H:i:s', strtotime( $interpunction_summary['last_run'] ) ),
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
						)
					)
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Not yet run', 'splecheh' ); ?>
			<?php endif; ?>
		</span>
		<span id="splecheh-interpunction-status-issues">
			<?php
			printf(
				/* translators: %d: number of issues */
				esc_html__( 'Issues (last batch): %d', 'splecheh' ),
				(int) $interpunction_summary['issues_found']
			);
			?>
		</span>
		<span id="splecheh-interpunction-status-pending">
			<?php
			printf(
				/* translators: %d: number of posts */
				esc_html__( 'Pending: %d', 'splecheh' ),
				(int) $interpunction_summary['posts_pending']
			);
			?>
		</span>
		<button class="button button-secondary" id="splecheh-interpunction-run-now">
			<?php esc_html_e( 'Run Now', 'splecheh' ); ?>
		</button>
		<span id="splecheh-interpunction-run-now-spinner" class="spinner" style="float:none;vertical-align:middle;display:none;margin:0;"></span>
	</div>
	<?php endif; ?>

	<div id="splecheh-interpunction-check-message" style="display:none;" class="notice is-dismissible"><p></p></div>

	<form id="splecheh-interpunction-list-form" method="get">
		<?php $table->display(); ?>
	</form>
</div>
