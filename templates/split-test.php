<?php
/**
 * Split Test admin page: a sandbox for issue #62's tree-based splitter. Paste (or
 * pick a post's) HTML content, run the split, and see the block-level chunks the
 * Spell Check / Interpunction Check now work on — nothing is checked, fixed, or
 * saved. Ships a few hardcoded example snippets that exercise headers-next-to-
 * paragraphs, lists, tables, and inline formatting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$examples = [
	'headers'   => "<h2>A heading with no ending punctuation</h2>\n<p>A following paragraph. It has two sentences.</p>",
	'list'      => "<p>Shopping list:</p>\n<ul>\n\t<li>Buy milk</li>\n\t<li>Buy bread and butter</li>\n</ul>",
	'inline'    => "<p>This has <strong>bold</strong>, <em>italic</em> and a <a href=\"https://example.com\">link</a> inside one paragraph.</p>",
	'nested'    => "<div class=\"wrapper\">\n\t<h3>Section title</h3>\n\t<div>\n\t\t<p>Deeply nested paragraph one.</p>\n\t\t<p>Deeply nested paragraph two.</p>\n\t</div>\n</div>",
	'table'     => "<table>\n\t<tr><td>Row one, cell one.</td><td>Row one, cell two.</td></tr>\n\t<tr><td>Row two, cell one.</td><td>Row two, cell two.</td></tr>\n</table>",
	'multipara' => "<blockquote>A quoted sentence. And a second one.</blockquote>\n<p>First paragraph.</p>\n<p>Second paragraph with a <code>code span</code>.</p>",
];

$default_example = $examples['headers'];
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Split Test', 'splecheh' ); ?></h1>
	<p>
		<?php esc_html_e( 'Preview how post content is split into block-level chunks before Spell Check / Interpunction Check run. Each block (heading, paragraph, list item, table cell, …) becomes its own chunk so text is never merged across block boundaries, and inline formatting (bold, links, …) is kept per chunk. This page only previews the split — it never checks, fixes, or saves anything.', 'splecheh' ); ?>
	</p>

	<h2><?php esc_html_e( 'Source content', 'splecheh' ); ?></h2>

	<p>
		<label for="splecheh-split-test-example"><?php esc_html_e( 'Load an example', 'splecheh' ); ?></label><br>
		<select id="splecheh-split-test-example">
			<option value="headers"><?php esc_html_e( 'Heading next to paragraph', 'splecheh' ); ?></option>
			<option value="list"><?php esc_html_e( 'Bulleted list', 'splecheh' ); ?></option>
			<option value="inline"><?php esc_html_e( 'Inline formatting (bold / italic / link)', 'splecheh' ); ?></option>
			<option value="nested"><?php esc_html_e( 'Nested block containers', 'splecheh' ); ?></option>
			<option value="table"><?php esc_html_e( 'Table cells', 'splecheh' ); ?></option>
			<option value="multipara"><?php esc_html_e( 'Blockquote + multiple paragraphs', 'splecheh' ); ?></option>
		</select>
		<script type="application/json" id="splecheh-split-test-examples"><?php echo wp_json_encode( $examples ); ?></script>
	</p>

	<p>
		<label for="splecheh-split-test-post"><?php esc_html_e( 'Or test against a post/page (optional)', 'splecheh' ); ?></label><br>
		<input
			type="text"
			id="splecheh-split-test-post"
			class="regular-text"
			autocomplete="off"
			placeholder="<?php esc_attr_e( 'Search by title… selecting a post loads its content below', 'splecheh' ); ?>"
		>
		<input type="hidden" id="splecheh-split-test-post-id" value="">
		<button type="button" class="button" id="splecheh-split-test-clear-post"><?php esc_html_e( 'Clear post', 'splecheh' ); ?></button>
	</p>

	<p>
		<textarea id="splecheh-split-test-content" rows="12" class="large-text code" style="font-family: monospace;"><?php echo esc_textarea( $default_example ); ?></textarea>
	</p>

	<p>
		<label>
			<input type="checkbox" id="splecheh-split-test-ignore-shortcodes" checked>
			<?php esc_html_e( 'Ignore shortcodes (strip them before splitting, like a real check)', 'splecheh' ); ?>
		</label>
	</p>

	<p>
		<button type="button" id="splecheh-split-test-button" class="button button-primary">
			<?php esc_html_e( 'Split', 'splecheh' ); ?>
		</button>
		<span class="spinner" style="float: none; display: none; vertical-align: middle;"></span>
	</p>

	<div id="splecheh-split-test-message" class="notice" style="display: none; margin-top: 10px;"><p></p></div>

	<h2><?php esc_html_e( 'Chunks', 'splecheh' ); ?></h2>
	<div id="splecheh-split-test-results"></div>
</div>
