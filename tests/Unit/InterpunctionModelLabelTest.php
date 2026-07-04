<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InterpunctionModelLabelTest extends TestCase {

	#[DataProvider( 'commandProvider' )]
	public function test_extract_model_label( string $command, string $expected ): void {
		$this->assertSame( $expected, \Splecheh_InterpunctionBackend::extract_model_label( $command ) );
	}

	public static function commandProvider(): array {
		return [
			'ollama with --model flag'      => [ 'php tools/llm-wrapper.php --provider ollama --model qwen2.5:7b', 'qwen2.5:7b' ],
			'--model= form'                 => [ 'php tools/llm-wrapper.php --model=qwen2.5:32b', 'qwen2.5:32b' ],
			'plain claude command'          => [ 'claude -p', 'claude -p' ],
			'default wrapper, no flags'     => [ 'php tools/llm-wrapper.php', 'claude' ],
			'default wrapper with timeout'  => [ 'php /home/lauzis/Dev/www/gudlenieks.lv/wp-content/plugins/splecheh/tools/llm-wrapper.php --timeout 300', 'claude' ],
			'empty command'                 => [ '', '' ],
		];
	}

	public function test_get_model_label_falls_back_to_commandline_type_without_carbon_fields(): void {
		// carbon_get_theme_option isn't defined in the test bootstrap, so get_type()
		// falls back to 'commandline' and get_command() falls back to ''.
		$this->assertSame( '', \Splecheh_InterpunctionBackend::get_model_label() );
	}
}
