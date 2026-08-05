<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionCommandTimeoutTest extends TestCase {

	private const WRAPPER = 'php /var/www/wp-content/plugins/splecheh/tools/llm-wrapper.php';

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_filters'] = [];
	}

	protected function tearDown(): void {
		remove_all_filters( 'splecheh_interpunction_command_timeout' );
	}

	public function test_defaults_to_the_default_command_timeout(): void {
		$this->assertSame(
			(float) \Splecheh_InterpunctionBackend::DEFAULT_COMMAND_TIMEOUT,
			\Splecheh_InterpunctionBackend::get_command_timeout()
		);
	}

	public function test_filter_takes_precedence(): void {
		add_filter( 'splecheh_interpunction_command_timeout', fn() => 300 );

		$this->assertSame( 300.0, \Splecheh_InterpunctionBackend::get_command_timeout() );
	}

	public function test_wrapper_command_gets_the_timeout_appended_with_a_margin(): void {
		$this->assertSame(
			self::WRAPPER . ' --timeout 295',
			\Splecheh_InterpunctionBackend::with_wrapper_timeout( self::WRAPPER, 300.0 )
		);
	}

	public function test_default_timeout_keeps_the_wrappers_historical_55_seconds(): void {
		$this->assertSame(
			self::WRAPPER . ' --timeout 55',
			\Splecheh_InterpunctionBackend::with_wrapper_timeout(
				self::WRAPPER,
				(float) \Splecheh_InterpunctionBackend::DEFAULT_COMMAND_TIMEOUT
			)
		);
	}

	public function test_wrapper_flags_are_preserved(): void {
		$command = self::WRAPPER . ' --provider ollama --model qwen2.5:7b';

		$this->assertSame(
			$command . ' --timeout 115',
			\Splecheh_InterpunctionBackend::with_wrapper_timeout( $command, 120.0 )
		);
	}

	/**
	 * A --timeout written into the Commandline Command by hand is the author's
	 * explicit choice and must not be overridden (nor duplicated on the command line).
	 */
	public function test_existing_timeout_flag_is_left_alone(): void {
		$spaced  = self::WRAPPER . ' --timeout 600';
		$equals  = self::WRAPPER . ' --timeout=600';

		$this->assertSame( $spaced, \Splecheh_InterpunctionBackend::with_wrapper_timeout( $spaced, 60.0 ) );
		$this->assertSame( $equals, \Splecheh_InterpunctionBackend::with_wrapper_timeout( $equals, 60.0 ) );
	}

	/**
	 * Only the bundled wrapper understands --timeout; a custom script (see
	 * bin/interpunction-check.sh) would choke on an unexpected flag.
	 */
	public function test_non_wrapper_command_is_left_alone(): void {
		$command = 'claude -p';

		$this->assertSame( $command, \Splecheh_InterpunctionBackend::with_wrapper_timeout( $command, 300.0 ) );
	}

	public function test_wrapper_timeout_never_drops_below_one_second(): void {
		$this->assertSame(
			self::WRAPPER . ' --timeout 1',
			\Splecheh_InterpunctionBackend::with_wrapper_timeout( self::WRAPPER, 2.0 )
		);
	}
}
