<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_Notification {

	public function __construct(
		public string $id,
		public string $message,
		public string $type = 'info',
		public string $mode = 'one-time',
	) {}
}
