<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InterpunctionDiffHighlightTest extends TestCase {

	#[DataProvider( 'pairProvider' )]
	public function test_diff_highlight( string $original, string $fixed, string $expected ): void {
		$this->assertSame( $expected, \Splecheh_InterpunctionReport::diff_highlight( $original, $fixed ) );
	}

	public static function pairProvider(): array {
		return [
			'capitalization only'   => [
				'the cat sat',
				'The cat sat',
				'<strong>The</strong> cat sat',
			],
			'punctuation added'     => [
				'the cat sat',
				'The cat sat.',
				'<strong>The</strong> cat <strong>sat.</strong>',
			],
			'word replaced'         => [
				// ",she" (no space) vs "she" are different tokens, so both bold — a
				// word-level diff, not character-level, is the intended granularity.
				'is this correct  ,she asked',
				'Is this correct, she asked?',
				'<strong>Is</strong> this <strong>correct,</strong> <strong>she</strong> <strong>asked?</strong>',
			],
			'no changes'            => [
				'already fine.',
				'already fine.',
				'already fine.',
			],
			'html is escaped'       => [
				'a <b> tag',
				'A <b> tag',
				'<strong>A</strong> &lt;b&gt; tag',
			],
		];
	}

	public function test_diff_highlight_word_removed_from_middle(): void {
		$result = \Splecheh_InterpunctionReport::diff_highlight( 'we visited paris london berlin', 'We visited Paris, London, and Berlin.' );

		// Unchanged words stay plain; every altered/added word is wrapped.
		$this->assertStringContainsString( 'visited', $result );
		$this->assertStringNotContainsString( '<strong>visited</strong>', $result );
		$this->assertStringContainsString( '<strong>We</strong>', $result );
		$this->assertStringContainsString( '<strong>Berlin.</strong>', $result );
	}
}
