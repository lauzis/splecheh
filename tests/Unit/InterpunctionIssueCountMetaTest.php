<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InterpunctionIssueCountMetaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_post_meta'] = [];
		$GLOBALS['__splecheh_test_options']   = [];
	}

	public function test_save_report_stores_unresolved_issue_count(): void {
		$method = new ReflectionMethod( \Splecheh_InterpunctionReport::class, 'save_report' );

		$post_id = 201;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'issues'     => [
				[ 'original' => 'a', 'fixed' => 'A.', 'explanation' => '' ],
				[ 'original' => 'b', 'fixed' => 'B.', 'explanation' => '' ],
			],
		];

		$method->invoke( null, $post_id, $report );

		$this->assertSame( 2, get_post_meta( $post_id, '_splecheh_interpunction_issue_count', true ) );
	}

	public function test_update_report_refreshes_unresolved_issue_count(): void {
		$save_method = new ReflectionMethod( \Splecheh_InterpunctionReport::class, 'save_report' );

		$post_id = 202;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'issues'     => [
				[ 'original' => 'a', 'fixed' => 'A.', 'explanation' => '' ],
				[ 'original' => 'b', 'fixed' => 'B.', 'explanation' => '' ],
			],
		];

		$save_method->invoke( null, $post_id, $report );
		$this->assertSame( 2, get_post_meta( $post_id, '_splecheh_interpunction_issue_count', true ) );

		$report['issues'][0]['resolved'] = true;
		\Splecheh_InterpunctionReport::update_report( $post_id, $report );

		$this->assertSame( 1, get_post_meta( $post_id, '_splecheh_interpunction_issue_count', true ) );
		$this->assertSame( 1, \Splecheh_InterpunctionReport::count_unresolved( $post_id ) );
	}

	public function test_missing_issue_count_meta_is_distinguishable_from_zero(): void {
		$post_id = 203;

		$this->assertFalse( metadata_exists( 'post', $post_id, '_splecheh_interpunction_issue_count' ) );

		update_post_meta( $post_id, '_splecheh_interpunction_issue_count', 0 );

		$this->assertTrue( metadata_exists( 'post', $post_id, '_splecheh_interpunction_issue_count' ) );
		$this->assertSame( 0, get_post_meta( $post_id, '_splecheh_interpunction_issue_count', true ) );
	}
}
