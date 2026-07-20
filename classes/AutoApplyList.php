<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global, language-scoped "auto-apply" list of word→replacement pairs.
 *
 * Mirrors Splecheh_IgnoreList, but instead of just suppressing a word it stores
 * the correction to apply to it: when a spell check finds a word that's on this
 * list for the post's language, the saved replacement is written into the post
 * automatically (see Splecheh_SpellCheckReport::run()) and the issue is not
 * flagged. Entries are added via "Fix everywhere in {language}" on the Spell
 * Check Details page.
 */
class Splecheh_AutoApplyList {

	const OPTION_KEY = 'splecheh_auto_apply_list';

	/**
	 * Returns the full auto-apply list, keyed by language code, each value being
	 * a map of lowercased word => replacement.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_all(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	/**
	 * Returns the word→replacement pairs for a given language code, keyed by the
	 * lowercased flagged word.
	 *
	 * @return array<string, string>
	 */
	public static function get_pairs( string $language ): array {
		$all = self::get_all();
		if ( ! isset( $all[ $language ] ) || ! is_array( $all[ $language ] ) ) {
			return [];
		}

		$pairs = [];
		foreach ( $all[ $language ] as $word => $replacement ) {
			$pairs[ mb_strtolower( (string) $word ) ] = (string) $replacement;
		}
		return $pairs;
	}

	/**
	 * Adds (or updates) a word→replacement pair for a language.
	 */
	public static function add_pair( string $language, string $word, string $replacement ): void {
		$word        = mb_strtolower( trim( $word ) );
		$replacement = trim( $replacement );
		if ( $word === '' || $replacement === '' || $language === '' ) {
			return;
		}

		$all = self::get_all();
		if ( ! isset( $all[ $language ] ) || ! is_array( $all[ $language ] ) ) {
			$all[ $language ] = [];
		}
		$all[ $language ][ $word ] = $replacement;
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Removes a word from the auto-apply list for a language.
	 */
	public static function remove_word( string $language, string $word ): void {
		$word = mb_strtolower( trim( $word ) );
		$all  = self::get_all();
		if ( ! isset( $all[ $language ][ $word ] ) ) {
			return;
		}

		unset( $all[ $language ][ $word ] );
		if ( empty( $all[ $language ] ) ) {
			unset( $all[ $language ] );
		}
		update_option( self::OPTION_KEY, $all, false );
	}
}
