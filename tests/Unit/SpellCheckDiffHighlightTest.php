<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpellCheckDiffHighlightTest extends TestCase {

	#[DataProvider( 'diffProvider' )]
	public function test_diff_highlight( string $word, string $suggestion, string $expected ): void {
		$this->assertSame( $expected, \Splecheh_SpellCheckReport::diff_highlight( $word, $suggestion ) );
	}

	public static function diffProvider(): array {
		return [
			'inserted middle letter'  => [ 'TV', 'TAV', 'T<strong>A</strong>V' ],
			'changed last letter'     => [ 'TV', 'TB', 'T<strong>B</strong>' ],
			'appended letter'         => [ 'Up', 'Upe', 'Up<strong>e</strong>' ],
			'identical strings'       => [ 'word', 'word', 'word' ],
			'completely different'    => [ 'abc', 'xyz', '<strong>xyz</strong>' ],
			'html-unsafe suggestion'  => [ 'a', 'a<b>', 'a<strong>&lt;b&gt;</strong>' ],
		];
	}

	public function test_render_suggestions_joins_and_highlights_each(): void {
		$html = \Splecheh_SpellCheckReport::render_suggestions( [ 'TAV', 'TB' ], 'TV' );

		$this->assertSame( 'T<strong>A</strong>V, T<strong>B</strong>', $html );
	}

	public function test_render_suggestions_returns_empty_string_for_no_suggestions(): void {
		$this->assertSame( '', \Splecheh_SpellCheckReport::render_suggestions( [], 'TV' ) );
	}
}
