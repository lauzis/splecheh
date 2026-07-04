<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InterpunctionFormatIssuesForDetailsTest extends TestCase {

	public function test_maps_issue_fields_and_defaults_resolved_to_false(): void {
		$issues = [
			[
				'original'    => 'this is wrong',
				'fixed'       => 'This is wrong.',
				'explanation' => 'Missing capitalization and full stop.',
			],
		];

		$result = \Splecheh_InterpunctionReport::format_issues_for_details( $issues );

		$this->assertSame(
			[
				[
					'original'    => 'this is wrong',
					'fixed'       => 'This is wrong.',
					'explanation' => 'Missing capitalization and full stop.',
					'resolved'    => false,
					'diff'        => '<strong>This</strong> is <strong>wrong.</strong>',
				],
			],
			$result
		);
	}

	public function test_preserves_resolved_flag(): void {
		$issues = [
			[
				'original'    => 'a',
				'fixed'       => 'A.',
				'explanation' => '',
				'resolved'    => true,
			],
		];

		$result = \Splecheh_InterpunctionReport::format_issues_for_details( $issues );

		$this->assertTrue( $result[0]['resolved'] );
	}

	public function test_returns_empty_array_when_no_issues(): void {
		$this->assertSame( [], \Splecheh_InterpunctionReport::format_issues_for_details( [] ) );
	}
}
