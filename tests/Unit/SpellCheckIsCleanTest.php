<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SpellCheckIsCleanTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__splecheh_test_post_meta'] = [];
		$GLOBALS['__splecheh_test_posts']     = [];
		$GLOBALS['__splecheh_test_options']   = [];
	}

	private function set_post( int $post_id, string $post_modified_gmt ): void {
		$GLOBALS['__splecheh_test_posts'][ $post_id ] = new \WP_Post(
			[
				'ID'                => $post_id,
				'post_modified_gmt' => $post_modified_gmt,
			]
		);
	}

	public function test_never_checked_post_is_not_clean(): void {
		$this->set_post( 1, '2026-01-01 00:00:00' );

		$this->assertFalse( \Splecheh_SpellCheckReport::is_clean( 1 ) );
	}

	public function test_checked_with_unresolved_issues_is_not_clean(): void {
		$this->set_post( 2, '2026-01-01 00:00:00' );
		update_post_meta( 2, '_splecheh_checked_at', '2026-01-02 00:00:00' );
		update_post_meta( 2, '_splecheh_issue_count', 3 );

		$this->assertFalse( \Splecheh_SpellCheckReport::is_clean( 2 ) );
	}

	public function test_checked_with_zero_issues_and_not_outdated_is_clean(): void {
		$this->set_post( 3, '2026-01-01 00:00:00' );
		update_post_meta( 3, '_splecheh_checked_at', '2026-01-02 00:00:00' );
		update_post_meta( 3, '_splecheh_issue_count', 0 );

		$this->assertTrue( \Splecheh_SpellCheckReport::is_clean( 3 ) );
	}

	public function test_post_edited_after_check_is_not_clean_even_with_zero_issues(): void {
		$this->set_post( 4, '2026-01-05 00:00:00' ); // edited after the check below
		update_post_meta( 4, '_splecheh_checked_at', '2026-01-02 00:00:00' );
		update_post_meta( 4, '_splecheh_issue_count', 0 );

		$this->assertFalse( \Splecheh_SpellCheckReport::is_clean( 4 ) );
	}

	public function test_missing_issue_count_meta_is_not_clean(): void {
		// Checked before _splecheh_issue_count existed as a field.
		$this->set_post( 5, '2026-01-01 00:00:00' );
		update_post_meta( 5, '_splecheh_checked_at', '2026-01-02 00:00:00' );

		$this->assertFalse( \Splecheh_SpellCheckReport::is_clean( 5 ) );
	}

	public function test_nonexistent_post_is_not_clean(): void {
		update_post_meta( 6, '_splecheh_checked_at', '2026-01-02 00:00:00' );
		update_post_meta( 6, '_splecheh_issue_count', 0 );
		// No corresponding entry in __splecheh_test_posts.

		$this->assertFalse( \Splecheh_SpellCheckReport::is_clean( 6 ) );
	}
}
