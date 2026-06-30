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

	<div id="splecheh-check-message" style="display:none;" class="notice is-dismissible"><p></p></div>

	<form id="splecheh-list-form" method="get">
		<?php $table->display(); ?>
	</form>
</div>
