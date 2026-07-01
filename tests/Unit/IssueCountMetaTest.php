<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class IssueCountMetaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_post_meta'] = [];
	}

	public function test_save_report_stores_unresolved_issue_count(): void {
		$method = new ReflectionMethod( \Splecheh_SpellCheckReport::class, 'save_report' );

		$post_id = 101;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'errors'     => [
				[ 'word' => 'wrng', 'suggestions' => [], 'excerpt' => '' ],
				[ 'word' => 'stil', 'suggestions' => [], 'excerpt' => '' ],
			],
		];

		$method->invoke( null, $post_id, $report );

		$this->assertSame( 2, get_post_meta( $post_id, '_splecheh_issue_count', true ) );
	}

	public function test_update_report_refreshes_unresolved_issue_count(): void {
		$save_method = new ReflectionMethod( \Splecheh_SpellCheckReport::class, 'save_report' );

		$post_id = 102;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'errors'     => [
				[ 'word' => 'wrng', 'suggestions' => [], 'excerpt' => '' ],
				[ 'word' => 'stil', 'suggestions' => [], 'excerpt' => '' ],
			],
		];

		$save_method->invoke( null, $post_id, $report );
		$this->assertSame( 2, get_post_meta( $post_id, '_splecheh_issue_count', true ) );

		$report['errors'][0]['resolved'] = true;
		\Splecheh_SpellCheckReport::update_report( $post_id, $report );

		$this->assertSame( 1, get_post_meta( $post_id, '_splecheh_issue_count', true ) );
	}

	public function test_missing_issue_count_meta_is_distinguishable_from_zero(): void {
		$post_id = 103;

		$this->assertFalse( metadata_exists( 'post', $post_id, '_splecheh_issue_count' ) );

		update_post_meta( $post_id, '_splecheh_issue_count', 0 );

		$this->assertTrue( metadata_exists( 'post', $post_id, '_splecheh_issue_count' ) );
		$this->assertSame( 0, get_post_meta( $post_id, '_splecheh_issue_count', true ) );
	}
}
