<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_NotificationManager {

	private const DISMISSED_OPTION = 'splecheh_dismissed_notifications';

	/** @var Splecheh_Notification[] */
	private static array $notifications = [];

	public static function register( Splecheh_Notification $notification ): void {
		self::$notifications[] = $notification;
	}

	public static function render(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		$dismissed = (array) get_option( self::DISMISSED_OPTION, [] );

		foreach ( self::$notifications as $notification ) {
			if ( $notification->mode === 'one-time' && in_array( $notification->id, $dismissed, true ) ) {
				continue;
			}
			self::render_notification( $notification );
		}
	}

	private static function render_notification( Splecheh_Notification $notification ): void {
		$type_class = 'notice-' . esc_attr( $notification->type );
		?>
		<div class="notice <?php echo esc_attr( $type_class ); ?> splecheh-toast"
			 data-splecheh-id="<?php echo esc_attr( $notification->id ); ?>"
			 data-splecheh-mode="<?php echo esc_attr( $notification->mode ); ?>">
			<p><?php echo wp_kses_post( $notification->message ); ?></p>
			<button type="button" class="notice-dismiss">
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'splecheh' ); ?></span>
			</button>
		</div>
		<?php
	}

	public static function enqueue_assets(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style(
			'splecheh-notifications',
			plugins_url( 'assets/css/notifications.css', SPLECHEH_PLUGIN_FILE ),
			[],
			SPLECHEH_VERSION
		);

		wp_enqueue_script(
			'splecheh-notifications',
			plugins_url( 'assets/js/notifications.js', SPLECHEH_PLUGIN_FILE ),
			[],
			SPLECHEH_VERSION,
			true
		);

		wp_localize_script(
			'splecheh-notifications',
			'splechehNotifications',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'splecheh_dismiss_notification' ),
			]
		);
	}

	public static function handle_dismiss(): void {
		check_ajax_referer( 'splecheh_dismiss_notification', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		$id = sanitize_key( wp_unslash( $_POST['notification_id'] ?? '' ) );
		if ( empty( $id ) ) {
			wp_send_json_error( 'Invalid notification ID', 400 );
		}

		$dismissed = (array) get_option( self::DISMISSED_OPTION, [] );
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_option( self::DISMISSED_OPTION, $dismissed, false );
		}

		wp_send_json_success();
	}

	private static function is_plugin_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return $screen->id === 'toplevel_page_splecheh' || $screen->parent_base === 'splecheh';
	}
}
