<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary = Splecheh_SpellCheckReport::get_dashboard_summary();
$url     = menu_page_url( 'splecheh', false );

// The Interpunction Check block only exists when the feature is switched on — with it
// off there is nothing to count, and the widget renders exactly as it did before.
$interpunction         = splecheh_interpunction_enabled();
$interpunction_summary = $interpunction ? Splecheh_InterpunctionReport::get_dashboard_summary() : [];
$interpunction_url     = $interpunction ? menu_page_url( 'splecheh-interpunction', false ) : '';
?>
<?php if ( $interpunction ) : ?>
	<h4 class="splecheh-dashboard-heading"><?php esc_html_e( 'Spell Check', 'splecheh' ); ?></h4>
<?php endif; ?>
<ul class="splecheh-dashboard-widget">
	<li>
		<strong><?php echo esc_html( number_format_i18n( $summary['unresolved_count'] ) ); ?></strong>
		<?php esc_html_e( 'spelling errors found', 'splecheh' ); ?>
	</li>
	<li>
		<strong><?php echo esc_html( number_format_i18n( $summary['posts_with_errors'] ) ); ?></strong>
		<?php esc_html_e( 'articles with spelling errors', 'splecheh' ); ?>
	</li>
	<li>
		<strong><?php echo esc_html( number_format_i18n( $summary['ignored_words'] ) ); ?></strong>
		<?php esc_html_e( 'ignored words', 'splecheh' ); ?>
	</li>
</ul>
<p><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Go to Spell Check', 'splecheh' ); ?></a></p>

<?php if ( $interpunction ) : ?>
	<h4 class="splecheh-dashboard-heading"><?php esc_html_e( 'Interpunction Check', 'splecheh' ); ?></h4>
	<ul class="splecheh-dashboard-widget">
		<li>
			<strong><?php echo esc_html( number_format_i18n( $interpunction_summary['unresolved_count'] ) ); ?></strong>
			<?php esc_html_e( 'interpunction issues found', 'splecheh' ); ?>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $interpunction_summary['posts_with_issues'] ) ); ?></strong>
			<?php esc_html_e( 'articles with interpunction issues', 'splecheh' ); ?>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $interpunction_summary['ignored_sentences'] ) ); ?></strong>
			<?php esc_html_e( 'ignored sentences', 'splecheh' ); ?>
		</li>
	</ul>
	<p><a href="<?php echo esc_url( $interpunction_url ); ?>"><?php esc_html_e( 'Go to Interpunction Check', 'splecheh' ); ?></a></p>
<?php endif; ?>
