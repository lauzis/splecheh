<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
$post    = $post_id ? get_post( $post_id ) : null;

if ( ! $post ) {
	wp_die( esc_html__( 'Post not found.', 'splecheh' ) );
}

$report   = Splecheh_SpellCheckReport::get_report( $post_id );
$errors   = $report['errors'] ?? [];
$language = splecheh_get_language_code( $post_id );

$fix_everywhere_label = $language !== ''
	/* translators: %s: language code (e.g. "LV") */
	? sprintf( __( 'Fix everywhere in %s', 'splecheh' ), strtoupper( $language ) )
	: __( 'Fix everywhere', 'splecheh' );
?>
<div class="wrap">
	<h1>
		<?php
		printf(
			/* translators: %s: post title */
			esc_html__( 'Spell Check Details: %s', 'splecheh' ),
			esc_html( $post->post_title )
		);
		?>
	</h1>

	<p><a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit Post', 'splecheh' ); ?></a></p>

	<?php if ( ! $report ) : ?>
		<p><?php esc_html_e( 'No spell check report found for this post yet.', 'splecheh' ); ?></p>
	<?php else : ?>

		<div id="splecheh-details-message" style="display:none;" class="notice is-dismissible"><p></p></div>

		<p>
			<button type="button" class="button button-primary" id="splecheh-rerun-check"><?php esc_html_e( 'Re-run Spell Check', 'splecheh' ); ?></button>
			<span class="splecheh-spinner spinner" id="splecheh-rerun-spinner" style="display:none;float:none;margin:0 4px;vertical-align:middle;"></span>
			<button type="button" class="button" id="splecheh-mark-complete"><?php esc_html_e( 'Mark Complete', 'splecheh' ); ?></button>
			<span class="splecheh-spinner spinner" id="splecheh-mark-complete-spinner" style="display:none;float:none;margin:0 4px;vertical-align:middle;"></span>
		</p>
		<p class="description"><?php esc_html_e( '"Mark Complete" resolves all remaining issues below and marks this post as checked, without changing its content — use it once you\'ve reviewed the flagged words and decided none need fixing.', 'splecheh' ); ?></p>

		<div class="tablenav top">
			<div class="alignleft actions">
				<label for="splecheh-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'splecheh' ); ?></label>
				<select id="splecheh-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk actions', 'splecheh' ); ?></option>
					<option value="fix"><?php esc_html_e( 'Fix', 'splecheh' ); ?></option>
					<option value="fix_everywhere"><?php echo esc_html( $fix_everywhere_label ); ?></option>
					<option value="ignore_in_post"><?php esc_html_e( 'Ignore in post', 'splecheh' ); ?></option>
					<option value="ignore_always"><?php esc_html_e( 'Ignore always', 'splecheh' ); ?></option>
				</select>
				<button class="button" id="splecheh-bulk-apply"><?php esc_html_e( 'Apply', 'splecheh' ); ?></button>
			</div>
		</div>

		<table class="wp-list-table widefat striped" id="splecheh-details-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="splecheh-select-all">
					</td>
					<th><?php esc_html_e( 'Word', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Suggestion', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Sentence', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Replacement', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'splecheh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $errors ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No spelling issues found.', 'splecheh' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $errors as $index => $error ) :
					$resolved   = ! empty( $error['resolved'] );
					$suggestion = $error['suggestions'][0] ?? '';
				?>
				<tr data-index="<?php echo esc_attr( (string) $index ); ?>"<?php echo $resolved ? ' class="splecheh-resolved"' : ''; ?>>
					<th class="check-column">
						<input type="checkbox" class="splecheh-row-check" <?php disabled( $resolved ); ?>>
					</th>
					<td><?php echo esc_html( $error['word'] ); ?></td>
					<td><?php echo Splecheh_SpellCheckReport::render_suggestions( $error['suggestions'] ?? [], $error['word'] ); ?></td>
					<td><?php echo Splecheh_SpellCheckReport::highlight_word( $error['excerpt'], $error['word'] ); ?></td>
					<td>
						<input type="text" class="splecheh-replacement regular-text" value="<?php echo esc_attr( $suggestion ); ?>" <?php disabled( $resolved ); ?>>
					</td>
					<td>
						<?php if ( $resolved ) : ?>
							<span class="splecheh-badge splecheh-badge--current"><?php esc_html_e( 'Resolved', 'splecheh' ); ?></span>
						<?php else : ?>
							<button class="button button-primary button-small splecheh-fix"><?php esc_html_e( 'Fix', 'splecheh' ); ?></button>
							<button class="button button-small splecheh-fix-everywhere"><?php echo esc_html( $fix_everywhere_label ); ?></button>
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
