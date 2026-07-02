<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InterpunctionCommandlineTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_options'] = [];
		$GLOBALS['__splecheh_test_filters'] = [];
		\Splecheh_Logs::clearLogs();
	}

	protected function tearDown(): void {
		remove_all_filters( 'splecheh_interpunction_command_timeout' );
	}

	private function invoke_check_commandline( array $sentences, string $language, string $prompt, string $command ) {
		$check_commandline = new ReflectionMethod( \Splecheh_InterpunctionBackend::class, 'check_commandline' );
		return $check_commandline->invoke( null, $sentences, $language, $prompt, $command );
	}

	private function latest_log_contents(): string {
		$files = \Splecheh_Logs::getLogFiles();
		if ( empty( $files ) ) {
			return '';
		}
		return (string) file_get_contents( SPLECHEH_LOG_PATH . '/' . $files[0] );
	}

	public function test_hanging_command_times_out_and_returns_wp_error(): void {
		add_filter(
			'splecheh_interpunction_command_timeout',
			function () {
				return 0.2;
			}
		);

		$result = $this->invoke_check_commandline( [ 'Example sentence.' ], 'en', 'Fix punctuation.', 'sh -c "sleep 5"' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'exceeded the timeout', $result->get_error_message() );

		$log = $this->latest_log_contents();
		$this->assertStringContainsString( 'Interpunction commandline started', $log );
		$this->assertStringContainsString( 'Interpunction commandline failed', $log );
	}

	public function test_successful_command_is_parsed_and_logged(): void {
		$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
			'fwrite(STDOUT, json_encode([["original" => "a", "fixed" => "A", "explanation" => "cap"]]));'
		);

		$result = $this->invoke_check_commandline( [ 'a' ], 'en', 'Fix punctuation.', $command );

		$this->assertSame( [ [ 'original' => 'a', 'fixed' => 'A', 'explanation' => 'cap' ] ], $result );

		$log = $this->latest_log_contents();
		$this->assertStringContainsString( 'Interpunction commandline started', $log );
		$this->assertStringNotContainsString( 'Interpunction commandline failed', $log );
	}

	public function test_non_zero_exit_code_returns_wp_error_with_stderr(): void {
		$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
			'fwrite(STDERR, "boom"); exit(1);'
		);

		$result = $this->invoke_check_commandline( [ 'a' ], 'en', 'Fix punctuation.', $command );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'boom', $result->get_error_message() );

		$log = $this->latest_log_contents();
		$this->assertStringContainsString( 'Interpunction commandline failed', $log );
	}
}
