<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReplaceAllOccurrencesTest extends TestCase {

	public function test_replaces_every_occurrence(): void {
		$content = 'bērneim un vēl bērneim un bērneim.';

		$result = \Splecheh_SpellCheckReport::replace_all_occurrences( $content, 'bērneim', 'bērniem' );

		$this->assertSame( 'bērniem un vēl bērniem un bērniem.', $result );
	}

	public function test_matches_whole_words_only(): void {
		$result = \Splecheh_SpellCheckReport::replace_all_occurrences( 'unbolldable bolld', 'bolld', 'bold' );

		$this->assertSame( 'unbolldable bold', $result );
	}

	public function test_matches_case_insensitively(): void {
		$result = \Splecheh_SpellCheckReport::replace_all_occurrences( 'Bolld and bolld', 'bolld', 'bold' );

		$this->assertSame( 'bold and bold', $result );
	}

	public function test_empty_word_leaves_content_unchanged(): void {
		$result = \Splecheh_SpellCheckReport::replace_all_occurrences( 'unchanged', '', 'x' );

		$this->assertSame( 'unchanged', $result );
	}
}
