<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HighlightWordTest extends TestCase {

	public function test_wraps_word_in_strong_tags(): void {
		$result = \Splecheh_SpellCheckReport::highlight_word( 'This has a bolld typo in it.', 'bolld' );

		$this->assertSame( 'This has a <strong>bolld</strong> typo in it.', $result );
	}

	public function test_matches_case_insensitively(): void {
		$result = \Splecheh_SpellCheckReport::highlight_word( 'Bolld at the start.', 'bolld' );

		$this->assertSame( '<strong>Bolld</strong> at the start.', $result );
	}

	public function test_only_matches_whole_word(): void {
		$result = \Splecheh_SpellCheckReport::highlight_word( 'unbolldable bolld', 'bolld' );

		$this->assertSame( 'unbolldable <strong>bolld</strong>', $result );
	}

	public function test_escapes_html_special_characters_in_excerpt(): void {
		$result = \Splecheh_SpellCheckReport::highlight_word( 'A <b>bolld</b> & tricky excerpt.', 'bolld' );

		$this->assertSame( 'A &lt;b&gt;<strong>bolld</strong>&lt;/b&gt; &amp; tricky excerpt.', $result );
	}

	public function test_returns_escaped_excerpt_unchanged_when_word_is_empty(): void {
		$result = \Splecheh_SpellCheckReport::highlight_word( 'Nothing to bold here <here>.', '' );

		$this->assertSame( 'Nothing to bold here &lt;here&gt;.', $result );
	}
}
