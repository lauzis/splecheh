<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $_POST['splecheh_add_ignored'] ) && check_admin_referer( 'splecheh_add_ignored' ) ) {
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$word     = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
	if ( $language !== '' && $word !== '' ) {
		Splecheh_IgnoreList::add_word( $language, $word );
	}
	wp_redirect( menu_page_url( 'splecheh-ignore-list', false ) );
	exit;
}

if ( isset( $_POST['splecheh_remove_ignored'] ) && check_admin_referer( 'splecheh_remove_ignored' ) ) {
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$word     = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
	if ( $language !== '' && $word !== '' ) {
		Splecheh_IgnoreList::remove_word( $language, $word );
	}
	wp_redirect( menu_page_url( 'splecheh-ignore-list', false ) );
	exit;
}

$ignore_list = Splecheh_IgnoreList::get_all();
ksort( $ignore_list );

$default_language = function_exists( 'splecheh_get_language_code' ) ? splecheh_get_language_code( 0 ) : Splecheh_SpellCheckReport::get_language();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Ignore List', 'splecheh' ); ?></h1>
	<p><?php esc_html_e( 'Words added via "Ignore always" on the Spell Check Details page, plus any added manually below. Lists are scoped per language.', 'splecheh' ); ?></p>

	<h2><?php esc_html_e( 'Add a word', 'splecheh' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'splecheh_add_ignored' ); ?>
		<label>
			<?php esc_html_e( 'Language code', 'splecheh' ); ?>
			<input type="text" name="language" value="<?php echo esc_attr( $default_language ); ?>" size="6" required>
		</label>
		<label>
			<?php esc_html_e( 'Word', 'splecheh' ); ?>
			<input type="text" name="word" value="" size="40" placeholder="teh" required>
		</label>
		<button type="submit" name="splecheh_add_ignored" value="1" class="button button-primary">
			<?php esc_html_e( 'Add Word', 'splecheh' ); ?>
		</button>
	</form>

	<?php if ( empty( $ignore_list ) ) : ?>
		<p><?php esc_html_e( 'No words have been globally ignored yet.', 'splecheh' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $ignore_list as $language => $words ) :
		if ( empty( $words ) ) {
			continue;
		}
		sort( $words );
		?>
		<h2><?php echo esc_html( strtoupper( $language ) ); ?></h2>
		<table class="wp-list-table widefat striped" style="max-width:500px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Word', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'splecheh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $words as $word ) : ?>
				<tr>
					<td><?php echo esc_html( $word ); ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'splecheh_remove_ignored' ); ?>
							<input type="hidden" name="language" value="<?php echo esc_attr( $language ); ?>">
							<input type="hidden" name="word" value="<?php echo esc_attr( $word ); ?>">
							<button type="submit" name="splecheh_remove_ignored" value="1" class="button button-small">
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
