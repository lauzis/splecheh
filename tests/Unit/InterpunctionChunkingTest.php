<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionChunkingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_options'] = [];
		$GLOBALS['__splecheh_test_filters'] = [];
		\Splecheh_Logs::clearLogs();
	}

	protected function tearDown(): void {
		remove_all_filters( 'splecheh_interpunction_chunk_size' );
	}

	/**
	 * Command that echoes back each sentence it was actually called with, plus the
	 * size of that call's batch in "explanation" — lets a test see both the final
	 * merged order and how the sentences were actually split across calls.
	 */
	private function echo_batch_size_command(): string {
		return escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
			'$p = json_decode($argv[1], true); $n = count($p["sentences"]);'
			. 'fwrite(STDOUT, json_encode(array_map('
			. 'fn($s) => ["original" => $s, "fixed" => $s, "explanation" => (string) $n],'
			. '$p["sentences"]'
			. ')));'
		);
	}

	public function test_check_splits_large_sentence_lists_into_chunks_and_merges_in_order(): void {
		add_filter( 'splecheh_interpunction_chunk_size', function () {
			return 2;
		} );

		$result = \Splecheh_InterpunctionBackend::check(
			[ 'a', 'b', 'c', 'd', 'e' ],
			'en',
			$this->echo_batch_size_command()
		);

		$this->assertSame( [ 'a', 'b', 'c', 'd', 'e' ], array_column( $result, 'original' ) );
		// Chunks of 2: [a,b] [c,d] [e] -> batch sizes 2,2,2,2,1
		$this->assertSame( [ '2', '2', '2', '2', '1' ], array_column( $result, 'explanation' ) );
	}

	public function test_check_does_not_chunk_when_under_the_chunk_size(): void {
		add_filter( 'splecheh_interpunction_chunk_size', function () {
			return 5;
		} );

		$result = \Splecheh_InterpunctionBackend::check( [ 'a', 'b' ], 'en', $this->echo_batch_size_command() );

		// Single call with both sentences -> batch size 2 for each.
		$this->assertSame( [ '2', '2' ], array_column( $result, 'explanation' ) );
	}

	public function test_check_chunking_disabled_sends_everything_in_one_call(): void {
		add_filter( 'splecheh_interpunction_chunk_size', function () {
			return 0;
		} );

		$result = \Splecheh_InterpunctionBackend::check( [ 'a', 'b', 'c' ], 'en', $this->echo_batch_size_command() );

		$this->assertSame( [ '3', '3', '3' ], array_column( $result, 'explanation' ) );
	}

	public function test_check_stops_and_returns_error_when_a_chunk_fails(): void {
		add_filter( 'splecheh_interpunction_chunk_size', function () {
			return 1;
		} );

		$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
			'$p = json_decode($argv[1], true);'
			. 'if ($p["sentences"][0] === "bad") { fwrite(STDERR, "boom"); exit(1); }'
			. 'fwrite(STDOUT, json_encode([["original" => $p["sentences"][0], "fixed" => $p["sentences"][0], "explanation" => ""]]));'
		);

		$result = \Splecheh_InterpunctionBackend::check( [ 'good', 'bad', 'good2' ], 'en', $command );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
