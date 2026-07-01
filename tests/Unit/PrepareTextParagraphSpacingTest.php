<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrepareTextParagraphSpacingTest extends TestCase {

	public function test_inserts_space_between_paragraphs(): void {
		$content = '<p>Iesaistot bērnus pasakā, mācās atveidot lomu, atcerēties sakāmo.</p>'
			. '<p>Var to ierakstīt vai nofilmēt, vai izspēlēt ar lellēm vai mīkstām mantiņām.</p>';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertStringNotContainsString( 'sakāmo.Var', $result );
		$this->assertStringContainsString( 'sakāmo. Var', $result );
	}

	public function test_inserts_space_for_br_tags(): void {
		$content = 'First line.<br>Second line.';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertStringNotContainsString( 'line.Second', $result );
		$this->assertStringContainsString( 'line. Second', $result );
	}

	public function test_inserts_space_for_self_closing_br_tags(): void {
		$content = 'First line.<br/>Second line.';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertStringNotContainsString( 'line.Second', $result );
		$this->assertStringContainsString( 'line. Second', $result );
	}

	public function test_inserts_space_between_list_items(): void {
		$content = '<ul><li>First item</li><li>Second item</li></ul>';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertStringNotContainsString( 'itemSecond', $result );
		$this->assertStringContainsString( 'item Second', $result );
	}

	public function test_collapses_whitespace_to_single_spaces(): void {
		$content = "<p>First paragraph.</p>\n\n<p>Second paragraph.</p>";

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertSame( 'First paragraph. Second paragraph.', $result );
	}
}
