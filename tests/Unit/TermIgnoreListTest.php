<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TermIgnoreListTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_options'] = [];
	}

	public function test_add_term_stores_normalized_lowercase(): void {
		\Splecheh_TermIgnoreList::add_term( 'lv', '  Steam   Deck ' );

		$this->assertSame( [ 'steam deck' ], \Splecheh_TermIgnoreList::get_terms( 'lv' ) );
	}

	public function test_add_term_deduplicates_case_and_whitespace_insensitively(): void {
		\Splecheh_TermIgnoreList::add_term( 'lv', 'Steam Deck' );
		\Splecheh_TermIgnoreList::add_term( 'lv', 'steam  deck' );

		$this->assertSame( [ 'steam deck' ], \Splecheh_TermIgnoreList::get_terms( 'lv' ) );
	}

	public function test_add_term_ignores_empty_term_or_language(): void {
		\Splecheh_TermIgnoreList::add_term( 'lv', '   ' );
		\Splecheh_TermIgnoreList::add_term( '', 'Steam Deck' );

		$this->assertSame( [], \Splecheh_TermIgnoreList::get_all() );
	}

	public function test_remove_term_drops_language_when_empty(): void {
		\Splecheh_TermIgnoreList::add_term( 'lv', 'Steam Deck' );
		\Splecheh_TermIgnoreList::remove_term( 'lv', 'steam deck' );

		$this->assertSame( [], \Splecheh_TermIgnoreList::get_all() );
	}

	public function test_terms_are_language_scoped(): void {
		\Splecheh_TermIgnoreList::add_term( 'lv', 'Steam Deck' );
		\Splecheh_TermIgnoreList::add_term( 'en', 'Lego Batman' );

		$this->assertSame( [ 'steam deck' ], \Splecheh_TermIgnoreList::get_terms( 'lv' ) );
		$this->assertSame( [ 'lego batman' ], \Splecheh_TermIgnoreList::get_terms( 'en' ) );
	}
}
