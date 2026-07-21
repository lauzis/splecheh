<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionBlockSentenceSplitTest extends TestCase {

	public function test_sentences_never_span_block_boundaries(): void {
		// The heading has no trailing punctuation; if the whole post were flattened
		// first, "A heading" would merge with the paragraph's first sentence. Splitting
		// per block keeps them separate.
		$content   = '<h2>A heading</h2><p>First sentence. Second sentence.</p>';
		$sentences = \Splecheh_InterpunctionReport::split_content_into_sentences( $content, false );

		$this->assertSame(
			[ 'A heading', 'First sentence.', 'Second sentence.' ],
			$sentences
		);
	}

	public function test_list_items_stay_separate_sentences(): void {
		$content   = '<ul><li>Buy milk</li><li>Buy bread</li></ul>';
		$sentences = \Splecheh_InterpunctionReport::split_content_into_sentences( $content, false );

		$this->assertSame( [ 'Buy milk', 'Buy bread' ], $sentences );
	}

	public function test_multi_sentence_block_is_split_within_the_block(): void {
		$content   = '<p>One. Two. Three.</p>';
		$sentences = \Splecheh_InterpunctionReport::split_content_into_sentences( $content, false );

		$this->assertSame( [ 'One.', 'Two.', 'Three.' ], $sentences );
	}

	public function test_shortcodes_excluded_when_ignoring_enabled(): void {
		$content   = '<p>[gallery ids="1,2"] Real sentence.</p>';
		$sentences = \Splecheh_InterpunctionReport::split_content_into_sentences( $content, true );

		$this->assertSame( [ 'Real sentence.' ], $sentences );
	}
}
