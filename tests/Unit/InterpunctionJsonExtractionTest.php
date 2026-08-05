<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionJsonExtractionTest extends TestCase {

	private const ITEM = '{"original": "a b", "fixed": "A b.", "explanation": "cap"}';

	private function expected(): array {
		return [ [ 'original' => 'a b', 'fixed' => 'A b.', 'explanation' => 'cap' ] ];
	}

	public function test_plain_json_array(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( '[' . self::ITEM . ']' )
		);
	}

	public function test_fenced_json_block(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( "```json\n[" . self::ITEM . "]\n```" )
		);
	}

	public function test_fence_without_a_language_tag(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( "```\n[" . self::ITEM . "]\n```" )
		);
	}

	/**
	 * The case the old anchored regex could not handle: anything before the fence meant
	 * the markers survived and the decode failed on a perfectly good reply.
	 */
	public function test_fence_introduced_by_a_sentence(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( "Here is the corrected JSON:\n\n```json\n[" . self::ITEM . "]\n```\n\nLet me know if you need anything else." )
		);
	}

	public function test_bare_array_wrapped_in_prose(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( 'Sure! [' . self::ITEM . '] Hope that helps.' )
		);
	}

	public function test_leading_and_trailing_whitespace(): void {
		$this->assertSame(
			$this->expected(),
			\Splecheh_InterpunctionBackend::extract_json_array( "\n\n  [" . self::ITEM . "]  \n" )
		);
	}

	public function test_empty_array_is_a_valid_result(): void {
		$this->assertSame( [], \Splecheh_InterpunctionBackend::extract_json_array( '[]' ) );
	}

	public function test_truncated_reply_is_not_recoverable(): void {
		$this->assertNull(
			\Splecheh_InterpunctionBackend::extract_json_array( '[{"original": "a b", "fixed": "A b.", "expl' )
		);
	}

	public function test_prose_without_any_array(): void {
		$this->assertNull(
			\Splecheh_InterpunctionBackend::extract_json_array( 'I cannot help with that request.' )
		);
	}

	public function test_empty_reply(): void {
		$this->assertNull( \Splecheh_InterpunctionBackend::extract_json_array( '' ) );
	}

	public function test_truncated_reply_is_described_as_cut_off(): void {
		$description = \Splecheh_InterpunctionBackend::describe_bad_response( '[{"original": "a b", "fixed": "A b' );

		$this->assertStringContainsString( 'cut off mid-generation', $description );
		$this->assertStringContainsString( 'Command Timeout', $description );
	}

	public function test_non_json_reply_is_described_by_its_start(): void {
		$description = \Splecheh_InterpunctionBackend::describe_bad_response( 'I cannot help with that.' );

		$this->assertStringNotContainsString( 'cut off', $description );
		$this->assertStringContainsString( 'I cannot help with that.', $description );
	}

	public function test_empty_reply_is_described(): void {
		$this->assertSame(
			'Nothing was returned at all.',
			\Splecheh_InterpunctionBackend::describe_bad_response( '' )
		);
	}
}
