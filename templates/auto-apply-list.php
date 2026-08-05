<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $_POST['splecheh_add_auto_apply'] ) && check_admin_referer( 'splecheh_add_auto_apply' ) ) {
	$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$word        = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
	$replacement = isset( $_POST['replacement'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement'] ) ) : '';
	if ( $language !== '' && $word !== '' && $replacement !== '' ) {
		Splecheh_AutoApplyList::add_pair( $language, $word, $replacement );
	}
	wp_redirect( menu_page_url( 'splecheh-auto-apply-list', false ) );
	exit;
}

if ( isset( $_POST['splecheh_remove_auto_apply'] ) && check_admin_referer( 'splecheh_remove_auto_apply' ) ) {
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$word     = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
	if ( $language !== '' && $word !== '' ) {
		Splecheh_AutoApplyList::remove_word( $language, $word );
	}
	wp_redirect( menu_page_url( 'splecheh-auto-apply-list', false ) );
	exit;
}

$auto_apply_list = Splecheh_AutoApplyList::get_all();
ksort( $auto_apply_list );

$default_language = function_exists( 'splecheh_get_language_code' ) ? splecheh_get_language_code( 0 ) : Splecheh_SpellCheckReport::get_language();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Auto-Apply List', 'splecheh' ); ?></h1>
	<p><?php esc_html_e( 'Word→replacement pairs added via "Fix everywhere in {language}" on the Spell Check Details page, plus any added manually below. When a spell check finds one of these words in a post of the matching language, the replacement is applied automatically on the next run and the issue is not flagged. Lists are scoped per language.', 'splecheh' ); ?></p>

	<h2><?php esc_html_e( 'Add an entry', 'splecheh' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'splecheh_add_auto_apply' ); ?>
		<label>
			<?php esc_html_e( 'Language code', 'splecheh' ); ?>
			<input type="text" name="language" value="<?php echo esc_attr( $default_language ); ?>" size="6" required>
		</label>
		<label>
			<?php esc_html_e( 'Word', 'splecheh' ); ?>
			<input type="text" name="word" value="" size="30" placeholder="teh" required>
		</label>
		<label>
			<?php esc_html_e( 'Replacement', 'splecheh' ); ?>
			<input type="text" name="replacement" value="" size="30" placeholder="the" required>
		</label>
		<button type="submit" name="splecheh_add_auto_apply" value="1" class="button button-primary">
			<?php esc_html_e( 'Add Entry', 'splecheh' ); ?>
		</button>
	</form>

	<?php if ( empty( $auto_apply_list ) ) : ?>
		<p><?php esc_html_e( 'No words have been added to the auto-apply list yet.', 'splecheh' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $auto_apply_list as $language => $pairs ) :
		if ( empty( $pairs ) || ! is_array( $pairs ) ) {
			continue;
		}
		ksort( $pairs );
		?>
		<h2><?php echo esc_html( strtoupper( (string) $language ) ); ?></h2>
		<table class="wp-list-table widefat striped" style="max-width:600px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Word', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Replacement', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'splecheh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pairs as $word => $replacement ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $word ); ?></td>
					<td><?php echo esc_html( (string) $replacement ); ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'splecheh_remove_auto_apply' ); ?>
							<input type="hidden" name="language" value="<?php echo esc_attr( (string) $language ); ?>">
							<input type="hidden" name="word" value="<?php echo esc_attr( (string) $word ); ?>">
							<button type="submit" name="splecheh_remove_auto_apply" value="1" class="button button-small">
								<?php esc_html_e( 'Remove', 'splecheh' ); ?>
							</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
</div>
