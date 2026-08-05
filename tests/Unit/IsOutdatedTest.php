<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IsOutdatedTest extends TestCase {

	public function test_not_outdated_when_checked_after_last_edit(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-02 00:00:00',
			'2026-01-01 00:00:00',
			'1.0.0',
			false,
			'1.0.0'
		);

		$this->assertFalse( $result );
	}

	public function test_outdated_when_post_edited_after_last_check(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-01 00:00:00',
			'2026-01-02 00:00:00',
			'1.0.0',
			false,
			'1.0.0'
		);

		$this->assertTrue( $result );
	}

	public function test_version_mismatch_ignored_when_setting_disabled(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-02 00:00:00',
			'2026-01-01 00:00:00',
			'1.0.0',
			false,
			'2.0.0'
		);

		$this->assertFalse( $result );
	}

	public function test_version_mismatch_flags_outdated_when_setting_enabled(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-02 00:00:00',
			'2026-01-01 00:00:00',
			'1.0.0',
			true,
			'2.0.0'
		);

		$this->assertTrue( $result );
	}

	public function test_matching_version_not_outdated_when_setting_enabled(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-02 00:00:00',
			'2026-01-01 00:00:00',
			'1.0.0',
			true,
			'1.0.0'
		);

		$this->assertFalse( $result );
	}

	public function test_missing_stored_version_flags_outdated_when_setting_enabled(): void {
		$result = \Splecheh_SpellCheckReport::is_outdated(
			'2026-01-02 00:00:00',
			'2026-01-01 00:00:00',
			null,
			true,
			'1.0.0'
		);

		$this->assertTrue( $result );
	}
}
