<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionIgnoreList {

	const OPTION_KEY = 'splecheh_interpunction_ignore_list';

	/**
	 * Returns the full ignore list, keyed by language code.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_all(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	/**
	 * Returns the ignored sentences for a given language code.
	 *
	 * @return string[]
	 */
	public static function get_sentences( string $language ): array {
		$all = self::get_all();
		return isset( $all[ $language ] ) ? (array) $all[ $language ] : [];
	}

	/**
	 * Adds a sentence to the global ignore list for a language, if not already present.
	 */
	public static function add_sentence( string $language, string $sentence ): void {
		$sentence = trim( $sentence );
		if ( $sentence === '' || $language === '' ) {
			return;
		}

		$all = self::get_all();
		if ( ! isset( $all[ $language ] ) ) {
			$all[ $language ] = [];
		}
		if ( ! in_array( $sentence, $all[ $language ], true ) ) {
			$all[ $language ][] = $sentence;
			update_option( self::OPTION_KEY, $all, false );
		}
	}
}
