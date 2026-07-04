<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionPartialProgressTest extends TestCase {

	public function test_extracts_partial_results_and_chunks_processed(): void {
		$error = new \WP_Error( 'interpunction_command_failed', 'boom' );
		$error->add_data(
			[
				'results'          => [ [ 'original' => 'a', 'fixed' => 'A', 'explanation' => '' ] ],
				'chunks_processed' => 3,
				'chunks_total'     => 11,
			]
		);

		$partial = \Splecheh_InterpunctionReport::extract_partial_progress( $error );

		$this->assertNotNull( $partial );
		$this->assertSame( 3, $partial['chunks_processed'] );
		$this->assertSame( [ [ 'original' => 'a', 'fixed' => 'A', 'explanation' => '' ] ], $partial['results'] );
	}

	public function test_returns_null_when_error_has_no_chunk_data(): void {
		// A non-chunked (single-call) failure has no attached progress data at all.
		$error = new \WP_Error( 'interpunction_command_failed', 'boom' );

		$this->assertNull( \Splecheh_InterpunctionReport::extract_partial_progress( $error ) );
	}

	public function test_returns_null_when_error_data_is_not_the_expected_shape(): void {
		$error = new \WP_Error( 'some_error', 'boom' );
		$error->add_data( 'unrelated string data' );

		$this->assertNull( \Splecheh_InterpunctionReport::extract_partial_progress( $error ) );
	}

	public function test_defaults_chunks_processed_to_zero_when_missing(): void {
		$error = new \WP_Error( 'interpunction_command_failed', 'boom' );
		$error->add_data( [ 'results' => [] ] );

		$partial = \Splecheh_InterpunctionReport::extract_partial_progress( $error );

		$this->assertNotNull( $partial );
		$this->assertSame( 0, $partial['chunks_processed'] );
		$this->assertSame( [], $partial['results'] );
	}
}
