<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MarkCompleteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_post_meta'] = [];
		$GLOBALS['__splecheh_test_options']   = [];
	}

	public function test_interpunction_mark_complete_resolves_all_issues_and_pushes_checked_at_forward(): void {
		$save_method = new ReflectionMethod( \Splecheh_InterpunctionReport::class, 'save_report' );

		$post_id = 301;
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
		$this->assertSame( 2, \Splecheh_InterpunctionReport::count_unresolved( $post_id ) );

		$before_checked_at = get_post_meta( $post_id, '_splecheh_interpunction_checked_at', true );

		$result = \Splecheh_InterpunctionReport::mark_complete( $post_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['issues'][0]['resolved'] );
		$this->assertTrue( $result['issues'][1]['resolved'] );
		$this->assertSame( 0, \Splecheh_InterpunctionReport::count_unresolved( $post_id ) );

		$after_checked_at = get_post_meta( $post_id, '_splecheh_interpunction_checked_at', true );
		$this->assertGreaterThan( strtotime( $before_checked_at . ' UTC' ), strtotime( $after_checked_at . ' UTC' ) );
		// Pushed roughly an hour ahead of "now", not just bumped to "now".
		$this->assertGreaterThan( time() + 3000, strtotime( $after_checked_at . ' UTC' ) );
	}

	public function test_interpunction_mark_complete_without_a_report_returns_wp_error(): void {
		$result = \Splecheh_InterpunctionReport::mark_complete( 999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_spellcheck_mark_complete_resolves_all_errors_and_pushes_checked_at_forward(): void {
		$save_method = new ReflectionMethod( \Splecheh_SpellCheckReport::class, 'save_report' );

		$post_id = 302;
		$report  = [
			'post_id'    => $post_id,
			'post_title' => 'Example',
			'checked_at' => gmdate( 'c' ),
			'language'   => 'en',
			'errors'     => [
				[ 'word' => 'teh', 'suggestions' => [ 'the' ], 'excerpt' => 'teh cat' ],
				[ 'word' => 'wrold', 'suggestions' => [ 'world' ], 'excerpt' => 'wrold peace' ],
			],
		];
		$save_method->invoke( null, $post_id, $report );
		$this->assertSame( 2, \Splecheh_SpellCheckReport::count_unresolved( $post_id ) );

		$before_checked_at = get_post_meta( $post_id, '_splecheh_checked_at', true );

		$result = \Splecheh_SpellCheckReport::mark_complete( $post_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['errors'][0]['resolved'] );
		$this->assertTrue( $result['errors'][1]['resolved'] );
		$this->assertSame( 0, \Splecheh_SpellCheckReport::count_unresolved( $post_id ) );

		$after_checked_at = get_post_meta( $post_id, '_splecheh_checked_at', true );
		$this->assertGreaterThan( strtotime( $before_checked_at . ' UTC' ), strtotime( $after_checked_at . ' UTC' ) );
		$this->assertGreaterThan( time() + 3000, strtotime( $after_checked_at . ' UTC' ) );
	}

	public function test_spellcheck_mark_complete_without_a_report_returns_wp_error(): void {
		$result = \Splecheh_SpellCheckReport::mark_complete( 999998 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
