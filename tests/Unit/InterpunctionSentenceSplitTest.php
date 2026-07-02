<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionSentenceSplitTest extends TestCase {

	public function test_splits_on_sentence_ending_punctuation(): void {
		$result = \Splecheh_InterpunctionReport::split_into_sentences( 'First sentence. Second sentence! Third one?' );

		$this->assertSame(
			[ 'First sentence.', 'Second sentence!', 'Third one?' ],
			$result
		);
	}

	public function test_trims_and_drops_empty_sentences(): void {
		$result = \Splecheh_InterpunctionReport::split_into_sentences( "  First.   \n\n Second.  " );

		$this->assertSame( [ 'First.', 'Second.' ], $result );
	}

	public function test_returns_empty_array_for_empty_text(): void {
		$this->assertSame( [], \Splecheh_InterpunctionReport::split_into_sentences( '' ) );
	}

	public function test_single_sentence_without_trailing_punctuation(): void {
		$result = \Splecheh_InterpunctionReport::split_into_sentences( 'no ending punctuation' );

		$this->assertSame( [ 'no ending punctuation' ], $result );
	}
}
