<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary = Splecheh_SpellCheckReport::get_dashboard_summary();
$url     = menu_page_url( 'splecheh', false );
?>
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
