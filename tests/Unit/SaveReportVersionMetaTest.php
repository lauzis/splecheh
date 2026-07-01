<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SaveReportVersionMetaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_post_meta'] = [];
	}

	public function test_stores_current_plugin_version_alongside_checked_at(): void {
		$method = new ReflectionMethod( \Splecheh_SpellCheckReport::class, 'save_report' );

		$post_id = 42;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'errors'     => [],
		];

		$result = $method->invoke( null, $post_id, $report );

		$this->assertTrue( $result );
		$this->assertSame( SPLECHEH_VERSION, get_post_meta( $post_id, '_splecheh_version', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_splecheh_checked_at', true ) );
	}
}
