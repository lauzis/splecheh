<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_IgnoreList {

	const OPTION_KEY = 'splecheh_ignore_list';

	/**
	 * Returns the full ignore list, keyed by language code.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_all(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	/**
	 * Returns the ignored words for a given language code.
	 *
	 * @return string[]
	 */
	public static function get_words( string $language ): array {
		$all = self::get_all();
		return isset( $all[ $language ] ) ? array_map( 'mb_strtolower', (array) $all[ $language ] ) : [];
	}

	/**
	 * Adds a word to the global ignore list for a language, if not already present.
	 */
	public static function add_word( string $language, string $word ): void {
		$word = mb_strtolower( trim( $word ) );
		if ( $word === '' || $language === '' ) {
			return;
		}

		$all = self::get_all();
		if ( ! isset( $all[ $language ] ) ) {
			$all[ $language ] = [];
		}
		if ( ! in_array( $word, $all[ $language ], true ) ) {
			$all[ $language ][] = $word;
			update_option( self::OPTION_KEY, $all, false );
		}
	}

	/**
	 * Removes a word from the global ignore list for a language.
	 */
	public static function remove_word( string $language, string $word ): void {
		$word = mb_strtolower( trim( $word ) );
		$all  = self::get_all();
		if ( ! isset( $all[ $language ] ) ) {
			return;
		}

		$all[ $language ] = array_values( array_diff( $all[ $language ], [ $word ] ) );
		if ( empty( $all[ $language ] ) ) {
			unset( $all[ $language ] );
		}
		update_option( self::OPTION_KEY, $all, false );
	}
}
