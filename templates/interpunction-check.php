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

	<div id="splecheh-interpunction-check-message" style="display:none;" class="notice is-dismissible"><p></p></div>

	<form id="splecheh-interpunction-list-form" method="get">
		<?php $table->display(); ?>
	</form>
</div>
