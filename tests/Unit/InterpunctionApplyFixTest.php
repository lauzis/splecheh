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
	 * Inline markup inside the sentence is edited around, not written over: the sentence
	 * exists in the rendered text but never as one string in the source.
	 */
	public function test_applies_around_inline_markup(): void {
		$content = '<p>This is <strong>very</strong> important ,he said.</p>';

		$this->assertSame(
			'<p>This is <strong>very</strong> important, he said.</p>',
			\Splecheh_InterpunctionReport::apply_fix( $content, 'This is very important ,he said.', 'This is very important, he said.' )
		);
	}

	/**
	 * A tag can split a word in half — "old</a>," — which word-level matching cannot
	 * handle, so the sentence is located character by character.
	 */
	public function test_applies_when_a_tag_splits_a_word(): void {
		$content = '<p>suitable for <a href="/tag/six-years-old/">six years old</a>, kids</p>';

		$this->assertSame(
			'<p>Suitable for <a href="/tag/six-years-old/">six years old</a>, kids.</p>',
			\Splecheh_InterpunctionReport::apply_fix( $content, 'suitable for six years old, kids', 'Suitable for six years old, kids.' )
		);
	}

	/**
	 * The word "six" appears in the link target before it appears in the visible text —
	 * a naive search-and-replace would rewrite the URL.
	 */
	public function test_never_edits_inside_a_tag(): void {
		$content = '<p>for <a href="/tag/six-years-old/" title="six years old">six years old</a> kids</p>';

		$result = \Splecheh_InterpunctionReport::apply_fix( $content, 'for six years old kids', 'For six years old kids.' );

		$this->assertStringContainsString( 'href="/tag/six-years-old/" title="six years old"', $result );
		$this->assertStringContainsString( '<p>For ', $result );
		$this->assertStringContainsString( 'kids.</p>', $result );
	}

	public function test_gives_up_rather_than_guess_when_the_sentence_is_absent(): void {
		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix(
				'<p>Something <em>entirely</em> different here.</p>',
				'This is very important ,he said.',
				'This is very important, he said.'
			)
		);
	}

	public function test_applies_across_several_tags_in_one_sentence(): void {
		$this->assertSame(
			'<p>A <b>b,</b> c <i>d</i> e f.</p>',
			\Splecheh_InterpunctionReport::apply_fix( '<p>a <b>b</b> c <i>d</i> e f</p>', 'a b c d e f', 'A b, c d e f.' )
		);
	}

	public function test_applies_across_nested_tags(): void {
		$this->assertSame(
			'<p>See <strong>the <em>big</em> one</strong> here.</p>',
			\Splecheh_InterpunctionReport::apply_fix(
				'<p>see <strong>the <em>big</em> one</strong> here</p>',
				'see the big one here',
				'See the big one here.'
			)
		);
	}

	public function test_applies_when_the_model_adds_a_word(): void {
		$this->assertSame(
			'<p>This is a <em>very</em> good stuff.</p>',
			\Splecheh_InterpunctionReport::apply_fix(
				'<p>this is <em>very</em> good stuff</p>',
				'this is very good stuff',
				'This is a very good stuff.'
			)
		);
	}

	/**
	 * Deleting the whole content of a tag would leave <em></em> behind and throw away
	 * whatever the emphasis was for, so the fix is refused rather than applied.
	 */
	public function test_refuses_to_hollow_out_a_tag(): void {
		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix(
				'<p>this is <em>very</em> good stuff</p>',
				'this is very good stuff',
				'This is good stuff.'
			)
		);
	}

	public function test_only_the_first_occurrence_is_rewritten_across_markup(): void {
		$this->assertSame(
			'<p>A <b>b</b> c.</p><p>a <b>b</b> c</p>',
			\Splecheh_InterpunctionReport::apply_fix( '<p>a <b>b</b> c</p><p>a <b>b</b> c</p>', 'a b c', 'A b c.' )
		);
	}

	/**
	 * The sentence carries the decoded text ("Tom & Jerry") while the source stores the
	 * entity, so there is no safe mapping — reported as unapplied rather than guessed at.
	 */
	public function test_entities_in_the_sentence_are_refused(): void {
		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix( '<p>Tom &amp; Jerry are here</p>', 'Tom & Jerry are here', 'Tom & Jerry are here.' )
		);
	}

	/**
	 * The character-level alignment is O(n×m); a malformed report must not turn a page
	 * load into a minute of dynamic programming.
	 */
	public function test_absurdly_long_sentences_are_refused(): void {
		$sentence = trim( str_repeat( 'word x ', 300 ) );

		$this->assertNull(
			\Splecheh_InterpunctionReport::apply_fix(
				'<p>' . str_repeat( 'word <b>x</b> ', 300 ) . '</p>',
				$sentence,
				trim( str_repeat( 'Word x ', 300 ) )
			)
		);
	}

	/**
	 * Known cosmetic quirk, pinned so it doesn't change unnoticed: punctuation the model
	 * appends to a sentence ending inside a tag lands inside that tag — a bold full stop.
	 */
	public function test_trailing_punctuation_lands_inside_a_closing_tag(): void {
		$this->assertSame(
			'<p>And <strong>parents.</strong></p>',
			\Splecheh_InterpunctionReport::apply_fix( '<p>and <strong>parents</strong></p>', 'and parents', 'And parents.' )
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
