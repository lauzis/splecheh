<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShortcodeIgnoringTest extends TestCase {

	public function test_strips_simple_shortcode_with_no_attributes(): void {
		$text = '[gdlnks_title_whats_in_it] Jūs saņemsiet 10 darba lapas ar vārdiem.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertStringNotContainsString( 'gdlnks_title_whats_in_it', $result );
		$this->assertStringContainsString( 'Jūs saņemsiet 10 darba lapas ar vārdiem.', $result );
	}

	public function test_strips_shortcode_with_attributes(): void {
		$text = 'Before [gallery ids="1,2,3" size="thumbnail"] after.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertStringNotContainsString( 'gallery', $result );
		$this->assertStringNotContainsString( 'thumbnail', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'after.', $result );
	}

	public function test_strips_self_closing_shortcode(): void {
		$text = 'Start [button label="Click"/] end.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertStringNotContainsString( 'button', $result );
		$this->assertStringContainsString( 'Start', $result );
		$this->assertStringContainsString( 'end.', $result );
	}

	public function test_strips_enclosing_shortcode_and_its_content(): void {
		$text = 'Before [caption id="1"]Some caption text[/caption] after.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertStringNotContainsString( 'caption', $result );
		$this->assertStringNotContainsString( 'Some caption text', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'after.', $result );
	}

	public function test_preserves_escaped_shortcode_as_literal_text(): void {
		$text = 'This is [[not_a_shortcode]] literally.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertSame( 'This is [not_a_shortcode] literally.', $result );
	}

	public function test_leaves_text_without_shortcodes_unchanged(): void {
		$text = 'Plain text with no brackets at all.';

		$result = \Splecheh_SpellCheckReport::strip_shortcodes( $text );

		$this->assertSame( $text, $result );
	}

	public function test_prepare_text_excludes_shortcode_when_ignoring_enabled(): void {
		$content = '[gdlnks_title_whats_in_it] Jūs saņemsiet 10 darba lapas.';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, true );

		$this->assertStringNotContainsString( 'gdlnks_title_whats_in_it', $result );
		$this->assertStringContainsString( 'Jūs saņemsiet 10 darba lapas.', $result );
	}

	public function test_prepare_text_still_includes_shortcode_when_ignoring_disabled(): void {
		$content = '[gdlnks_title_whats_in_it] Jūs saņemsiet 10 darba lapas.';

		$result = \Splecheh_SpellCheckReport::prepare_text( $content, false );

		$this->assertStringContainsString( 'gdlnks_title_whats_in_it', $result );
	}
}
