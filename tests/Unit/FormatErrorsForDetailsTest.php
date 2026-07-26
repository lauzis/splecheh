<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormatErrorsForDetailsTest extends TestCase {

	public function test_maps_error_fields_and_highlights_excerpt(): void {
		$errors = [
			[
				'word'        => 'bolld',
				'excerpt'     => 'This has a bolld typo in it.',
				'suggestions' => [ 'bold', 'bald' ],
			],
		];

		$result = \Splecheh_SpellCheckReport::format_errors_for_details( $errors );

		$this->assertSame(
			[
				[
					'word'            => 'bolld',
					'wordHtml'        => 'bolld',
					'typeHtml'        => '<span class="splecheh-badge">spelling</span>',
					'isWhitespace'    => false,
					'excerpt'         => 'This has a <strong>bolld</strong> typo in it.',
					'suggestion'      => 'bold',
					'suggestionsHtml' => 'bold, b<strong>a</strong>ld',
					'resolved'        => false,
				],
			],
			$result
		);
	}

	public function test_maps_a_whitespace_entry_with_visible_markers(): void {
		$errors = [
			[
				'type'        => 'whitespace',
				'word'        => 'double  space',
				'excerpt'     => 'A double  space in the source.',
				'suggestions' => [ 'double space' ],
			],
		];

		$result = \Splecheh_SpellCheckReport::format_errors_for_details( $errors );

		$this->assertTrue( $result[0]['isWhitespace'] );
		$this->assertSame( '<span class="splecheh-badge splecheh-badge--outdated">whitespace</span>', $result[0]['typeHtml'] );
		$this->assertSame( 'double<span class="splecheh-whitespace-marker">␣␣</span>space', $result[0]['wordHtml'] );
		$this->assertSame( 'A double<span class="splecheh-whitespace-marker">␣␣</span>space in the source.', $result[0]['excerpt'] );
		$this->assertSame( 'double space', $result[0]['suggestion'] );
	}

	public function test_marks_resolved_flag_and_defaults_missing_suggestion(): void {
		$errors = [
			[
				'word'        => 'teh',
				'excerpt'     => 'teh quick fox',
				'suggestions' => [],
				'resolved'    => true,
			],
		];

		$result = \Splecheh_SpellCheckReport::format_errors_for_details( $errors );

		$this->assertTrue( $result[0]['resolved'] );
		$this->assertSame( '', $result[0]['suggestion'] );
	}

	public function test_returns_empty_array_when_no_errors(): void {
		$this->assertSame( [], \Splecheh_SpellCheckReport::format_errors_for_details( [] ) );
	}
}
