<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InterpunctionTestPayloadTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_options'] = [];
	}

	public function test_build_test_payload_uses_configured_language_and_prompt(): void {
		$payload = \Splecheh_InterpunctionBackend::build_test_payload();

		$this->assertSame( 'en', $payload['language'] );
		$this->assertStringNotContainsString( '{language}', $payload['prompt'] );
		$this->assertStringContainsString( 'en editor', $payload['prompt'] );
		$this->assertNotEmpty( $payload['sentences'] );
		$this->assertGreaterThanOrEqual( 2, count( $payload['sentences'] ) );
		$this->assertLessThanOrEqual( 3, count( $payload['sentences'] ) );
	}

	public function test_default_prompt_documents_the_required_json_output_format(): void {
		$response_format_instructions = new ReflectionMethod( \Splecheh_InterpunctionBackend::class, 'response_format_instructions' );

		$default_prompt = \Splecheh_InterpunctionReport::DEFAULT_PROMPT;
		$instructions    = $response_format_instructions->invoke( null );

		$this->assertStringContainsString( '"original"', $default_prompt );
		$this->assertStringContainsString( '"fixed"', $default_prompt );
		$this->assertStringContainsString( '"explanation"', $default_prompt );
		$this->assertStringContainsString( 'JSON array', $default_prompt );
		$this->assertStringContainsString( 'JSON array', $instructions );
	}

	public function test_check_surfaces_error_when_no_commandline_command_is_configured(): void {
		$result = \Splecheh_InterpunctionBackend::check( [ 'Example sentence.' ], 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	public function test_parse_results_surfaces_error_for_invalid_json(): void {
		$parse_results = new ReflectionMethod( \Splecheh_InterpunctionBackend::class, 'parse_results' );

		$result = $parse_results->invoke( null, 'not valid json' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( '', $result->get_error_message() );
	}
}
