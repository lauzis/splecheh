<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WhitespaceIssuesTest extends TestCase {

	/**
	 * @return string[] The flagged word pairs.
	 */
	private function words( string $content ): array {
		return array_map(
			static fn( array $issue ): string => $issue['word'],
			\Splecheh_SpellCheckReport::find_whitespace_issues( $content )
		);
	}

	public function test_finds_a_double_space_between_words(): void {
		$this->assertSame(
			[ 'Nepaguvāt  uz' ],
			$this->words( '<p>Nepaguvāt  uz Steam izpārdošanu.</p>' )
		);
	}

	public function test_reports_the_collapsed_pair_as_the_suggestion(): void {
		$issues = \Splecheh_SpellCheckReport::find_whitespace_issues( '<p>Nepaguvāt   uz Steam.</p>' );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'whitespace', $issues[0]['type'] );
		$this->assertSame( 'Nepaguvāt   uz', $issues[0]['word'] );
		$this->assertSame( [ 'Nepaguvāt uz' ], $issues[0]['suggestions'] );
	}

	public function test_finds_tabs_and_longer_runs(): void {
		$this->assertSame(
			[ "one\t\ttwo" ],
			$this->words( "<p>one\t\ttwo</p>" )
		);
	}

	public function test_ignores_single_spaces(): void {
		$this->assertSame( [], $this->words( '<p>A perfectly normal sentence here.</p>' ) );
	}

	/**
	 * Block markup is indented and newline-separated; none of that is prose.
	 */
	public function test_ignores_markup_indentation_and_line_breaks(): void {
		$content = "<!-- wp:list -->\n<ul>\n    <li>First item</li>\n    <li>Second item</li>\n</ul>\n<!-- /wp:list -->";

		$this->assertSame( [], $this->words( $content ) );
	}

	public function test_ignores_whitespace_inside_tags_and_attributes(): void {
		$this->assertSame( [], $this->words( '<p  class="a   b"   id="c">Clean text here.</p>' ) );
	}

	public function test_ignores_html_comments(): void {
		$this->assertSame( [], $this->words( '<!-- a comment  with  double  spaces --><p>Clean text.</p>' ) );
	}

	/**
	 * Spacing inside <pre>/<code> is deliberate — that's the one place a run of
	 * spaces survives rendering.
	 */
	public function test_ignores_pre_and_code_blocks(): void {
		$this->assertSame( [], $this->words( "<pre>if (a)  return b;</pre><code>x  =  1</code>" ) );
	}

	public function test_ignores_shortcodes(): void {
		$this->assertSame( [], $this->words( '<p>[gallery  ids="1,2"  columns="3"]</p>' ) );
	}

	public function test_still_flags_prose_around_ignored_regions(): void {
		$this->assertSame(
			[ 'after  the' ],
			$this->words( '<pre>code  here</pre><p>after  the block</p>' )
		);
	}

	public function test_deduplicates_identical_pairs(): void {
		$this->assertSame(
			[ 'same  pair' ],
			$this->words( '<p>same  pair once.</p><p>same  pair twice.</p>' )
		);
	}

	public function test_excerpt_keeps_the_run_and_drops_the_markup(): void {
		$issues = \Splecheh_SpellCheckReport::find_whitespace_issues(
			'<p>Some leading words before the offending  gap and some trailing words after it.</p>'
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'offending  gap', $issues[0]['excerpt'] );
		$this->assertStringNotContainsString( '<p>', $issues[0]['excerpt'] );
	}

	public function test_render_word_marks_the_run_visibly(): void {
		$html = \Splecheh_SpellCheckReport::render_word( [ 'type' => 'whitespace', 'word' => 'a  b' ] );

		$this->assertSame( 'a<span class="splecheh-whitespace-marker">␣␣</span>b', $html );
	}

	public function test_render_word_leaves_spelling_entries_escaped_and_plain(): void {
		$this->assertSame(
			'&lt;script&gt;',
			\Splecheh_SpellCheckReport::render_word( [ 'word' => '<script>' ] )
		);
	}

	/**
	 * Entries written before whitespace checking existed have no 'type' key.
	 */
	public function test_entries_without_a_type_are_treated_as_spelling(): void {
		$this->assertFalse( \Splecheh_SpellCheckReport::is_whitespace_error( [ 'word' => 'vasars' ] ) );
		$this->assertTrue( \Splecheh_SpellCheckReport::is_whitespace_error( [ 'type' => 'whitespace', 'word' => 'a  b' ] ) );
	}

	/**
	 * Identical pairs collapse into a single reported row, so one Fix has to clear all
	 * of them — otherwise the leftovers reappear on the next run with no row to act on.
	 */
	public function test_replace_whitespace_run_collapses_every_occurrence(): void {
		$this->assertSame(
			'<p>Nepaguvāt uz Steam.</p><p>Nepaguvāt uz Steam.</p>',
			\Splecheh_SpellCheckReport::replace_whitespace_run(
				'<p>Nepaguvāt  uz Steam.</p><p>Nepaguvāt  uz Steam.</p>',
				'Nepaguvāt  uz',
				'Nepaguvāt uz'
			)
		);
	}

	public function test_replace_whitespace_run_leaves_pre_blocks_alone(): void {
		$this->assertSame(
			'<pre>a  b</pre><p>a b</p>',
			\Splecheh_SpellCheckReport::replace_whitespace_run( '<pre>a  b</pre><p>a  b</p>', 'a  b', 'a b' )
		);
	}

	public function test_collapse_whitespace_runs_fixes_everything_at_once(): void {
		$result = \Splecheh_SpellCheckReport::collapse_whitespace_runs(
			"<p>one  two and   three</p>\n<p>four\t\tfive</p>"
		);

		$this->assertSame( "<p>one two and three</p>\n<p>four five</p>", $result['content'] );
		$this->assertSame( 3, $result['count'] );
	}

	public function test_collapse_whitespace_runs_leaves_markup_and_code_untouched(): void {
		$content = "<!-- wp:code -->\n<pre  class=\"a   b\">keep  this</pre>\n<!-- /wp:code -->\n<p>[sc  a=\"1\"] fix  this</p>";

		$result = \Splecheh_SpellCheckReport::collapse_whitespace_runs( $content );

		$this->assertSame( 1, $result['count'] );
		$this->assertStringContainsString( 'keep  this', $result['content'] );
		$this->assertStringContainsString( 'class="a   b"', $result['content'] );
		$this->assertStringContainsString( '[sc  a="1"]', $result['content'] );
		$this->assertStringContainsString( 'fix this', $result['content'] );
	}

	public function test_collapse_whitespace_runs_reports_nothing_to_do(): void {
		$result = \Splecheh_SpellCheckReport::collapse_whitespace_runs( '<p>Already clean text.</p>' );

		$this->assertSame( '<p>Already clean text.</p>', $result['content'] );
		$this->assertSame( 0, $result['count'] );
	}

	public function test_replace_whitespace_run_returns_null_when_the_run_is_gone(): void {
		$this->assertNull(
			\Splecheh_SpellCheckReport::replace_whitespace_run( '<p>Already fixed up.</p>', 'Already  fixed', 'Already fixed' )
		);
	}

	public function test_replace_occurrence_returns_null_when_the_word_is_gone(): void {
		$this->assertNull(
			\Splecheh_SpellCheckReport::replace_occurrence( '<p>Nothing to see.</p>', 'vasars', '', 'vasaras' )
		);
	}

	public function test_replace_occurrence_still_replaces_a_present_word(): void {
		$this->assertSame(
			'<p>pavasara vai vasaras izpārdošanu</p>',
			\Splecheh_SpellCheckReport::replace_occurrence(
				'<p>pavasara vai vasars izpārdošanu</p>',
				'vasars',
				'pavasara vai vasars izpārdošanu',
				'vasaras'
			)
		);
	}
}
