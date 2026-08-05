<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the term-matching logic that drops a spell check error when the flagged
 * word belongs to a listed multi-word term whose full term appears in the sentence.
 */
final class TermIgnoreMatchTest extends TestCase {

	public function test_subset_word_dropped_when_full_term_present(): void {
		$sentence = 'Steam Deck platformā var nopirkt spēles ar atlaidēm';
		$terms    = [ 'steam deck' ];

		// Both "Steam" and "Deck" (Aspell flags each separately) should be suppressed.
		$this->assertTrue( \Splecheh_SpellCheckReport::is_term_ignored( 'Steam', $sentence, $terms ) );
		$this->assertTrue( \Splecheh_SpellCheckReport::is_term_ignored( 'Deck', $sentence, $terms ) );
	}

	public function test_case_insensitive_match(): void {
		$this->assertTrue(
			\Splecheh_SpellCheckReport::is_term_ignored( 'steam', 'The STEAM DECK is here', [ 'Steam Deck' ] )
		);
	}

	public function test_partial_term_still_flagged(): void {
		// Only "Steam" appears, not the full "Steam Deck" term.
		$this->assertFalse(
			\Splecheh_SpellCheckReport::is_term_ignored( 'Steam', 'Steam ir spēļu platforma', [ 'steam deck' ] )
		);
	}

	public function test_word_not_part_of_any_term_flagged(): void {
		$this->assertFalse(
			\Splecheh_SpellCheckReport::is_term_ignored( 'nopirkt', 'Steam Deck var nopirkt', [ 'steam deck' ] )
		);
	}

	public function test_multi_word_term_of_three_words(): void {
		$terms = [ 'the lego batman' ];
		$this->assertTrue(
			\Splecheh_SpellCheckReport::is_term_ignored( 'Lego', 'We watched the Lego Batman movie', $terms )
		);
		// Missing "the" — not a complete term in this sentence.
		$this->assertFalse(
			\Splecheh_SpellCheckReport::is_term_ignored( 'Lego', 'We watched a Lego Batman movie', $terms )
		);
	}

	public function test_single_word_term_ignored(): void {
		// A one-word entry is not a term; it belongs on the plain ignore list.
		$this->assertFalse(
			\Splecheh_SpellCheckReport::is_term_ignored( 'Steam', 'Steam is here', [ 'steam' ] )
		);
	}

	public function test_empty_terms_never_match(): void {
		$this->assertFalse(
			\Splecheh_SpellCheckReport::is_term_ignored( 'Steam', 'Steam Deck', [] )
		);
	}
}
