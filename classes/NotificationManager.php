<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splecheh's admin notice entry point.
 *
 * The implementation lives in the shared lauzis/wp-plugin-packages package; this class
 * is a thin facade that keeps Splecheh's own API and screen scoping, so the
 * call sites throughout the plugin are unchanged.
 */
class Splecheh_NotificationManager {

	private const SLUG = 'splecheh';

	/**
	 * Returns the shared notice manager, or null when the wp-plugin-packages package is
	 * not installed. Splecheh already treats a missing vendor/ as a supported
	 * state, so notices degrade to silence rather than a fatal.
	 *
	 * Also the only place the library gets loaded: the registry boots on first
	 * use, so nothing may reference \Lauzis\WpPackages\Notices\* before calling this.
	 *
	 * @return \Lauzis\WpPackages\Notices\Notices|null
	 */
	private static function manager() {
		if ( ! class_exists( 'WpPackages_Registry' ) ) {
			return null;
		}

		return WpPackages_Registry::notices(
			self::SLUG,
			[
				'store'      => 'option',
				'screen'     => [ self::class, 'is_plugin_screen' ],
				'capability' => 'edit_posts',
			]
		);
	}

	/** Registers the hooks the shared manager needs. */
	public static function init(): void {
		$manager = self::manager();

		if ( $manager ) {
			$manager->boot();
		}
	}

	public static function register( Splecheh_Notification $notification ): void {
		// Resolve the manager first: it boots the library, so the Notice class
		// below is guaranteed to exist.
		$manager = self::manager();

		if ( ! $manager ) {
			return;
		}

		$manager->add(
			new \Lauzis\WpPackages\Notices\Notice(
				$notification->id,
				$notification->message,
				$notification->type,
				'one-time' === $notification->mode
					? \Lauzis\WpPackages\Notices\Notice::ONCE
					: \Lauzis\WpPackages\Notices\Notice::SESSION
			)
		);
	}

	/**
	 * Retained for the previous bootstrap order, where render() and
	 * enqueue_assets() were hooked directly. The shared manager hooks itself in
	 * init(), so these are no longer wired up.
	 *
	 * @deprecated Use init().
	 */
	public static function render(): void {
		$manager = self::manager();

		if ( $manager ) {
			$manager->render();
		}
	}

	/** @deprecated Use init(). */
	public static function enqueue_assets(): void {
		$manager = self::manager();

		if ( $manager ) {
			$manager->enqueue();
		}
	}

	public static function is_plugin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return 'toplevel_page_splecheh' === $screen->id || 'splecheh' === $screen->parent_base;
	}
}
