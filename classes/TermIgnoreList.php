<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global, language-scoped list of multi-word "terms" to ignore during spell check.
 *
 * Mirrors Splecheh_IgnoreList, but instead of a single word each entry is a term
 * that may span several words (e.g. "Steam Deck", "Lego Batman"). Aspell flags
 * each word of such a term separately ("Steam" and "Deck"); when a flagged word
 * is one of the words of a listed term and the word's sentence contains every
 * word of that term, the issue is dropped from the report (see
 * Splecheh_SpellCheckReport::filter_term_ignored()).
 *
 * Terms are stored lowercased and whitespace-collapsed, matching the case
 * handling used by the ignore list.
 */
class Splecheh_TermIgnoreList {

	const OPTION_KEY = 'splecheh_term_ignore_list';

	/**
	 * Returns the full term ignore list, keyed by language code.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_all(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	/**
	 * Returns the ignored terms for a given language code, each lowercased.
	 *
	 * @return string[]
	 */
	public static function get_terms( string $language ): array {
		$all = self::get_all();
		return isset( $all[ $language ] ) ? array_map( [ self::class, 'normalize' ], (array) $all[ $language ] ) : [];
	}

	/**
	 * Adds a term to the global term ignore list for a language, if not already present.
	 */
	public static function add_term( string $language, string $term ): void {
		$term = self::normalize( $term );
		if ( $term === '' || $language === '' ) {
			return;
		}

		$all = self::get_all();
		if ( ! isset( $all[ $language ] ) ) {
			$all[ $language ] = [];
		}
		if ( ! in_array( $term, $all[ $language ], true ) ) {
			$all[ $language ][] = $term;
			update_option( self::OPTION_KEY, $all, false );
		}
	}

	/**
	 * Removes a term from the global term ignore list for a language.
	 */
	public static function remove_term( string $language, string $term ): void {
		$term = self::normalize( $term );
		$all  = self::get_all();
		if ( ! isset( $all[ $language ] ) ) {
			return;
		}

		$all[ $language ] = array_values( array_diff( array_map( [ self::class, 'normalize' ], (array) $all[ $language ] ), [ $term ] ) );
		if ( empty( $all[ $language ] ) ) {
			unset( $all[ $language ] );
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Lowercases and collapses internal whitespace of a term, so "Steam  Deck"
	 * and "steam deck" are treated as the same entry.
	 */
	public static function normalize( string $term ): string {
		$term = trim( preg_replace( '/\s+/u', ' ', $term ) ?? $term );
		return mb_strtolower( $term );
	}
}
