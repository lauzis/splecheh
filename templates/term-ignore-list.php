<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $_POST['splecheh_add_term'] ) && check_admin_referer( 'splecheh_add_term' ) ) {
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	if ( $language !== '' && $term !== '' ) {
		Splecheh_TermIgnoreList::add_term( $language, $term );
	}
	wp_redirect( menu_page_url( 'splecheh-term-ignore-list', false ) );
	exit;
}

if ( isset( $_POST['splecheh_remove_term'] ) && check_admin_referer( 'splecheh_remove_term' ) ) {
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
	$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	if ( $language !== '' && $term !== '' ) {
		Splecheh_TermIgnoreList::remove_term( $language, $term );
	}
	wp_redirect( menu_page_url( 'splecheh-term-ignore-list', false ) );
	exit;
}

$term_list = Splecheh_TermIgnoreList::get_all();
ksort( $term_list );

$default_language = function_exists( 'splecheh_get_language_code' ) ? splecheh_get_language_code( 0 ) : Splecheh_SpellCheckReport::get_language();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Term Ignore List', 'splecheh' ); ?></h1>
	<p><?php esc_html_e( 'Multi-word terms (e.g. "Steam Deck", "Lego Batman") that spell check should not flag. Aspell checks each word separately, so it may flag "Steam" and "Deck" individually; when a flagged word belongs to a listed term and its sentence contains the whole term, the issue is dropped. Lists are scoped per language.', 'splecheh' ); ?></p>

	<h2><?php esc_html_e( 'Add a term', 'splecheh' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'splecheh_add_term' ); ?>
		<label>
			<?php esc_html_e( 'Language code', 'splecheh' ); ?>
			<input type="text" name="language" value="<?php echo esc_attr( $default_language ); ?>" size="6" required>
		</label>
		<label>
			<?php esc_html_e( 'Term', 'splecheh' ); ?>
			<input type="text" name="term" value="" size="40" placeholder="Steam Deck" required>
		</label>
		<button type="submit" name="splecheh_add_term" value="1" class="button button-primary">
			<?php esc_html_e( 'Add Term', 'splecheh' ); ?>
		</button>
	</form>

	<?php if ( empty( $term_list ) ) : ?>
		<p><?php esc_html_e( 'No terms have been added yet.', 'splecheh' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $term_list as $language => $terms ) :
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			continue;
		}
		sort( $terms );
		?>
		<h2><?php echo esc_html( strtoupper( (string) $language ) ); ?></h2>
		<table class="wp-list-table widefat striped" style="max-width:600px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Term', 'splecheh' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'splecheh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $terms as $term ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $term ); ?></td>
					<td>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'splecheh_remove_term' ); ?>
							<input type="hidden" name="language" value="<?php echo esc_attr( (string) $language ); ?>">
							<input type="hidden" name="term" value="<?php echo esc_attr( (string) $term ); ?>">
							<button type="submit" name="splecheh_remove_term" value="1" class="button button-small">
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
