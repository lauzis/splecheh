<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PhpSpellcheck\MisspellingInterface;
use PhpSpellcheck\Spellchecker\SpellcheckerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

/**
 * A fake spellchecker whose supported languages are fixed at construction time,
 * standing in for a real Aspell/Pspell install with a given set of wordlists.
 */
final class FakeSpellchecker implements SpellcheckerInterface {

	/** @param string[] $supportedLanguages */
	public function __construct( private array $supportedLanguages ) {}

	public function check( string $text, array $languages = [], array $context = [] ): iterable {
		return [];
	}

	public function getSupportedLanguages(): iterable {
		return $this->supportedLanguages;
	}
}

final class MissingWordlistTest extends TestCase {

	private function invokeSpellcheck( string $text, string $language, SpellcheckerInterface $checker ) {
		$method = new ReflectionMethod( \Splecheh_SpellCheckReport::class, 'spellcheck' );
		return $method->invoke( null, $text, $language, $checker );
	}

	public function test_returns_friendly_error_when_language_has_no_wordlist(): void {
		$checker = new FakeSpellchecker( [ 'en', 'en_US' ] );

		$result = $this->invokeSpellcheck( 'Some text to check.', 'lv', $checker );

		$this->assertInstanceOf( WP_Error::class, $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'lv', $message );
		$this->assertStringContainsString( 'aspell-lv', $message );
		$this->assertStringContainsString( 'https://github.com/lauzis/splecheh#aspell-dependency', $message );
	}

	public function test_error_data_carries_language_and_docs_url(): void {
		$checker = new FakeSpellchecker( [ 'en' ] );

		$result = $this->invokeSpellcheck( 'Some text to check.', 'lv', $checker );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data( 'missing_wordlist' );
		$this->assertSame( 'lv', $data['language'] );
		$this->assertSame( 'https://github.com/lauzis/splecheh#aspell-dependency', $data['docs_url'] );
	}

	public function test_does_not_error_when_language_wordlist_is_available(): void {
		$checker = new FakeSpellchecker( [ 'en', 'en_US', 'lv' ] );

		$result = $this->invokeSpellcheck( 'Some text to check.', 'lv', $checker );

		$this->assertIsArray( $result );
	}

	public function test_matches_region_variant_wordlists(): void {
		// Only "en_GB" is installed (no bare "en"); requesting "en" should still resolve.
		$checker = new FakeSpellchecker( [ 'en_GB' ] );

		$result = $this->invokeSpellcheck( 'Some text to check.', 'en', $checker );

		$this->assertIsArray( $result );
	}
}
