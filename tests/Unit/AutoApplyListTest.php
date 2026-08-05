<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AutoApplyListTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_options'] = [];
	}

	public function test_add_pair_stores_word_lowercased_with_replacement(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', 'Bērneim', 'bērniem' );

		$this->assertSame( [ 'bērniem' ], array_values( \Splecheh_AutoApplyList::get_pairs( 'lv' ) ) );
		$this->assertSame( 'bērniem', \Splecheh_AutoApplyList::get_pairs( 'lv' )['bērneim'] );
	}

	public function test_get_pairs_returns_empty_for_unknown_language(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', 'bērneim', 'bērniem' );

		$this->assertSame( [], \Splecheh_AutoApplyList::get_pairs( 'en' ) );
	}

	public function test_add_pair_ignores_empty_word_or_replacement(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', '', 'x' );
		\Splecheh_AutoApplyList::add_pair( 'lv', 'x', '' );
		\Splecheh_AutoApplyList::add_pair( '', 'x', 'y' );

		$this->assertSame( [], \Splecheh_AutoApplyList::get_all() );
	}

	public function test_add_pair_updates_existing_replacement(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', 'teksts', 'first' );
		\Splecheh_AutoApplyList::add_pair( 'lv', 'teksts', 'second' );

		$this->assertSame( 'second', \Splecheh_AutoApplyList::get_pairs( 'lv' )['teksts'] );
	}

	public function test_remove_word_drops_pair_and_language_when_empty(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', 'bērneim', 'bērniem' );
		\Splecheh_AutoApplyList::remove_word( 'lv', 'Bērneim' );

		$this->assertSame( [], \Splecheh_AutoApplyList::get_all() );
	}

	public function test_pairs_are_language_scoped(): void {
		\Splecheh_AutoApplyList::add_pair( 'lv', 'teksts', 'lv-fix' );
		\Splecheh_AutoApplyList::add_pair( 'en', 'teksts', 'en-fix' );

		$this->assertSame( 'lv-fix', \Splecheh_AutoApplyList::get_pairs( 'lv' )['teksts'] );
		$this->assertSame( 'en-fix', \Splecheh_AutoApplyList::get_pairs( 'en' )['teksts'] );
	}
}
