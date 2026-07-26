<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionApplyFixTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_posts'] = [];
	}

	private function set_post_content( int $post_id, string $content ): void {
		$GLOBALS['__splecheh_test_posts'][ $post_id ] = new \WP_Post(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			]
		);
	}

	public function test_replaces_a_literal_match(): void {
		$content = '<p>Is this correct ,she asked. And then left.</p>';

		$this->assertSame(
			'<p>Is this correct, she asked. And then left.</p>',
			\Splecheh_InterpunctionReport::apply_fix( $content, 'Is this correct ,she asked.', 'Is this correct, she asked.' )
		);
	}

	/**
	 * The regression behind the silent no-op: report sentences come from the splitter
	 * with whitespace collapsed, so a double space in the source HTML made the literal
	 * search fail and the fix was reported as applied without changing anything.
	 */
	public function test_matches_across_a_double_space_in_the_source(): void {
		$content = '<p>Nepaguvāt  uz Steam izpārdošanu 2023, nekas.</p>';

		$this->assertSame(
			'<p>Nepaguvāt uz Steam izpārdošanu 2023? Nekas.</p>',
			\Splecheh_InterpunctionReport::apply_fix(
				$content,
				'Nepaguvāt uz Steam izpārdošanu 2023, nekas.',
				'Nepaguvāt uz Steam izpārdošanu 2023? Nekas.'
			)
		);
	}

	public function test_matches_across_a_newline_in_the_source(): void {
		$content = "<p>first sentence here,\nand more text.</p>";

		$this->assertSame(
			'<p>First sentence here, and more text.</p>',
			\Splecheh_InterpunctionReport::apply_fix(
				$content,
				'first sentence here, and more text.',
				'First sentence here, and more text.'
			)
		);
	}

	public function test_replaces_only_the_first_occurrence(): void {
		$content = '<p>same text. same text.</p>';

		$this->assertSame(
			'<p>Same text! same text.</p>',
			\Splecheh_InterpunctionReport::apply_fix( $content, 'same text.', 'Same text!' )
		);
	}

	public function test_returns_null_when_the_sentence_is_gone(): void {
		$content = '<p>Completely different content.</p>';

		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix( $content, 'A sentence that is not there.', 'Whatever.' )
		);
	}

	/**
	 * Inline markup inside the sentence is deliberately not matched — writing plain
	 * text back over it would destroy the formatting — so it must be reported as
	 * unapplied rather than silently skipped.
	 */
	public function test_returns_null_when_inline_markup_splits_the_sentence(): void {
		$content = '<p>This is <strong>very</strong> important ,he said.</p>';

		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix( $content, 'This is very important ,he said.', 'This is very important, he said.' )
		);
	}

	public function test_regex_metacharacters_in_the_sentence_are_literal(): void {
		$content = '<p>Cost (approx.)  is 5 EUR ,plus tax.</p>';

		$this->assertSame(
			'<p>Cost (approx.) is 5 EUR, plus tax.</p>',
			\Splecheh_InterpunctionReport::apply_fix( $content, 'Cost (approx.) is 5 EUR ,plus tax.', 'Cost (approx.) is 5 EUR, plus tax.' )
		);
	}

	public function test_empty_original_is_never_matched(): void {
		$this->assertNull( \Splecheh_InterpunctionReport::apply_fix( '<p>Text.</p>', '   ', 'Replacement.' ) );
	}

	public function test_find_unapplied_fixes_reports_text_missing_from_the_saved_post(): void {
		$this->set_post_content( 501, '<p>First fix landed here.</p><p>Untouched paragraph.</p>' );

		$missing = \Splecheh_SpellCheckReport::find_unapplied_fixes(
			501,
			[
				0 => 'First fix landed here.',
				3 => 'This one never made it into the post.',
			]
		);

		$this->assertSame( [ 3 ], $missing );
	}

	/**
	 * The saved content is compared via the splitter's plain text, so markup and
	 * entity encoding around the fix don't make it look lost.
	 */
	public function test_find_unapplied_fixes_ignores_markup_and_entities(): void {
		$this->set_post_content( 502, "<p>Tom &amp; Jerry, the classic.</p>\n<p>Second   paragraph, fixed.</p>" );

		$this->assertSame(
			[],
			\Splecheh_SpellCheckReport::find_unapplied_fixes(
				502,
				[
					0 => 'Tom & Jerry, the classic.',
					1 => 'Second paragraph, fixed.',
				]
			)
		);
	}

	public function test_find_unapplied_fixes_reports_everything_for_a_missing_post(): void {
		$this->assertSame(
			[ 0, 1 ],
			\Splecheh_SpellCheckReport::find_unapplied_fixes( 999, [ 0 => 'a.', 1 => 'b.' ] )
		);
	}

	public function test_find_unapplied_fixes_handles_an_empty_list(): void {
		$this->assertSame( [], \Splecheh_SpellCheckReport::find_unapplied_fixes( 999, [] ) );
	}
}
