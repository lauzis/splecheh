<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
$post    = $post_id ? get_post( $post_id ) : null;

if ( ! $post ) {
	wp_die( esc_html__( 'Post not found.', 'splecheh' ) );
}

$report = Splecheh_InterpunctionReport::get_report( $post_id );
$issues = $report['issues'] ?? [];
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %s: post title */
			esc_html__( 'Interpunction Check Details: %s', 'splecheh' ),
			esc_html( $post->post_title )
		);
		?>
	</h1>

	<p><a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit Post', 'splecheh' ); ?></a></p>

	<?php if ( ! $report ) : ?>
		<p><?php esc_html_e( 'No interpunction check report found for this post yet.', 'splecheh' ); ?></p>
	<?php else : ?>

		<?php if ( ! empty( $report['model'] ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: model/command label, e.g. "claude-3-5-haiku-latest" or "qwen2.5:7b" */
				esc_html__( 'Checked with: %s', 'splecheh' ),
				'<code>' . esc_html( $report['model'] ) . '</code>'
			);
			?>
		</p>
		<?php endif; ?>

		<div id="splecheh-interpunction-details-message" style="display:none;" class="notice is-dismissible"><p></p></div>

		<p>
			<button type="button" class="button button-primary" id="splecheh-interpunction-rerun-check"><?php esc_html_e( 'Re-run Interpunction Check', 'splecheh' ); ?></button>
			<span class="splecheh-spinner spinner" id="splecheh-interpunction-rerun-spinner" style="display:none;float:none;margin:0 4px;vertical-align:middle;"></span>
		</p>

		<div class="tablenav top">
			<div class="alignleft actions">
				<label for="splecheh-interpunction-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'splecheh' ); ?></label>
				<select id="splecheh-interpunction-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk actions', 'splecheh' ); ?></option>
					<option value="fix"><?php esc_html_e( 'Fix', 'splecheh' ); ?></option>
					<option value="ignore_in_post"><?php esc_html_e( 'Ignore in post', 'splecheh' ); ?></option>
					<option value="ignore_always"><?php esc_html_e( 'Ignore always', 'splecheh' ); ?></option>
				</select>
				<button class="button" id="splecheh-interpunction-bulk-apply"><?php esc_html_e( 'Apply', 'splecheh' ); ?></button>
			</div>
		</div>

		<table class="wp-list-table widefat striped" id="splecheh-interpunction-details-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="splecheh-interpunction-select-all">
					</td>
					<th><?php esc_html_e( 'Original', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Fixed', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Explanation', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'splecheh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $issues ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No interpunction issues found.', 'splecheh' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $issues as $index => $issue ) :
					$resolved = ! empty( $issue['resolved'] );
				?>
				<tr data-index="<?php echo esc_attr( (string) $index ); ?>"<?php echo $resolved ? ' class="splecheh-resolved"' : ''; ?>>
					<th class="check-column">
						<input type="checkbox" class="splecheh-row-check" <?php disabled( $resolved ); ?>>
					</th>
					<td><?php echo esc_html( $issue['original'] ); ?></td>
					<td>
						<textarea class="splecheh-interpunction-fixed regular-text" rows="2" <?php disabled( $resolved ); ?>><?php echo esc_textarea( $issue['fixed'] ); ?></textarea>
					</td>
					<td><?php echo esc_html( $issue['explanation'] ); ?></td>
					<td>
						<?php if ( $resolved ) : ?>
							<span class="splecheh-badge splecheh-badge--current"><?php esc_html_e( 'Resolved', 'splecheh' ); ?></span>
						<?php else : ?>
							<button class="button button-primary button-small splecheh-fix"><?php esc_html_e( 'Fix', 'splecheh' ); ?></button>
							<button class="button button-small splecheh-ignore-post"><?php esc_html_e( 'Ignore in post', 'splecheh' ); ?></button>
							<button class="button button-small splecheh-ignore-always"><?php esc_html_e( 'Ignore always', 'splecheh' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
