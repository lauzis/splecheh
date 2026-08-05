<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SpellCheckExtractSentenceTest extends TestCase {

	private function extract_sentence( string $text, string $word ): string {
		$ref    = new ReflectionClass( \Splecheh_SpellCheckReport::class );
		$method = $ref->getMethod( 'extract_sentence' );

		return $method->invoke( null, $text, $word );
	}

	#[DataProvider( 'substringProvider' )]
	public function test_does_not_match_word_as_substring_of_another_word( string $text, string $word ): void {
		// Regression test: "TV" must not match inside "atvērtā", nor "Up" inside "Super" —
		// extract_sentence() previously used a plain substring search (mb_stripos()), so a
		// short misspelled word could latch onto an unrelated sentence just because its
		// letters happened to appear inside a longer, correctly-spelled word.
		$this->assertSame( '', $this->extract_sentence( $text, $word ) );
	}

	public static function substringProvider(): array {
		return [
			'TV inside atvērtā'  => [ 'Šī ir atvērtā koda spēle bez maksas.', 'TV' ],
			'Up inside Super'    => [ 'Super Tux Kart ir bezmaksas spēle.', 'Up' ],
		];
	}

	public function test_matches_word_as_a_whole_word(): void {
		$text = 'Spēle ir pieejama uz Android TV un iOS. Tā ir bezmaksas.';

		$this->assertSame(
			'Spēle ir pieejama uz Android TV un iOS.',
			$this->extract_sentence( $text, 'TV' )
		);
	}

	public function test_falls_back_to_short_excerpt_when_no_sentence_boundary_found(): void {
		$text = 'Viena no iecienītākajam spēlēm ko spēlēt kopā ir Pile Up bez punktuācijas beigās';

		$this->assertSame( $text, $this->extract_sentence( $text, 'Up' ) );
	}
}
