<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits post HTML into block-level "chunks" by walking the HTML tree, so both
 * Spell Check and Interpunction Check work on one block at a time and never merge
 * text across block boundaries (see issue #62).
 *
 * The walk mirrors the intuition from the issue: a block-level element whose
 * descendants contain no further block-level elements is a *leaf block* and becomes
 * one chunk; a block that contains child blocks is recursed into instead of being
 * emitted itself. Loose inline/text content that sits directly between blocks is
 * collected into its own anonymous chunk. Each chunk keeps both its plain text (for
 * spell check / sentence splitting) and its inner HTML (so a corrected word/sentence
 * can be written back into the exact node, keeping surrounding inline formatting like
 * <strong>, <em>, <a>).
 *
 * Uses PHP's built-in DOMDocument (libxml) rather than a new Composer dependency:
 * it ships with every supported PHP build, needs no changes to the committed
 * production vendor/ folder, and is the most stable option for walking an HTML tree.
 */
class Splecheh_ContentSplitter {

	/**
	 * Block-level tag names that act as chunk boundaries. Text is never merged across
	 * any of these — matches the set the issue called out (h1–h6, p, li, div,
	 * blockquote, td, …) plus the common table/list/section relatives.
	 *
	 * @var string[]
	 */
	const BLOCK_TAGS = [
		'p',
		'div',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'li',
		'blockquote',
		'td',
		'th',
		'dd',
		'dt',
		'caption',
		'figcaption',
		'pre',
		'section',
		'article',
		'header',
		'footer',
		'aside',
		'address',
		'main',
	];

	/**
	 * Splits $html into an ordered list of chunks. Each chunk is:
	 *   [ 'tag' => 'p'|'h2'|''(loose inline), 'text' => plain text, 'html' => inner HTML ].
	 * Chunks with no visible text are dropped.
	 *
	 * @return array<int, array{tag: string, text: string, html: string}>
	 */
	public static function split( string $html ): array {
		$html = trim( $html );
		if ( $html === '' ) {
			return [];
		}

		$root = self::load_root( $html );
		if ( $root === null ) {
			// Parsing failed for some reason — fall back to a single plain-text chunk
			// so callers still get something to check rather than nothing.
			$text = self::normalize_whitespace( wp_strip_all_tags( $html ) );
			return $text === '' ? [] : [ [ 'tag' => '', 'text' => $text, 'html' => $html ] ];
		}

		$chunks = [];
		self::walk( $root, $chunks );
		return $chunks;
	}

	/**
	 * Convenience: the per-block plain-text strings only, in document order.
	 *
	 * @return string[]
	 */
	public static function plain_texts( string $html ): array {
		return array_map(
			static function ( array $chunk ): string {
				return $chunk['text'];
			},
			self::split( $html )
		);
	}

	/**
	 * Recursively walks a node's children, emitting a chunk per leaf block and
	 * flushing any loose inline/text runs between blocks into their own chunk.
	 *
	 * @param array<int, array{tag: string, text: string, html: string}> $chunks
	 */
	private static function walk( DOMNode $node, array &$chunks ): void {
		$inline = [];

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMElement && self::is_block( $child ) ) {
				self::flush_inline( $inline, $chunks );

				if ( self::has_block_descendant( $child ) ) {
					// A block wrapping further blocks (e.g. a <div> of <p>s) is not a
					// leaf — recurse so each inner leaf block becomes its own chunk.
					self::walk( $child, $chunks );
				} else {
					self::emit( $child->tagName, self::node_text( $child ), self::inner_html( $child ), $chunks );
				}
				continue;
			}

			// A non-block wrapper that still contains block children (e.g. <ul>/<ol>
			// around <li>, <table>/<tr> around <td>) is recursed into as well, so its
			// blocks don't get flattened together into one anonymous run.
			if ( $child instanceof DOMElement && self::has_block_descendant( $child ) ) {
				self::flush_inline( $inline, $chunks );
				self::walk( $child, $chunks );
				continue;
			}

			// Text node or inline element — buffer it until the next block boundary.
			$inline[] = $child;
		}

		self::flush_inline( $inline, $chunks );
	}

	/**
	 * Emits the buffered loose inline/text nodes as a single anonymous chunk, if any
	 * of them carry visible text, then clears the buffer.
	 *
	 * @param DOMNode[]                                                    $inline
	 * @param array<int, array{tag: string, text: string, html: string}> $chunks
	 */
	private static function flush_inline( array &$inline, array &$chunks ): void {
		if ( empty( $inline ) ) {
			return;
		}

		$text = '';
		$html = '';
		foreach ( $inline as $child ) {
			$text .= self::node_text( $child );
			$html .= self::save_html( $child );
		}
		$inline = [];

		self::emit( '', $text, $html, $chunks );
	}

	/**
	 * Adds a chunk unless it has no visible text (e.g. an empty paragraph or a wrapper
	 * that only held whitespace).
	 *
	 * @param array<int, array{tag: string, text: string, html: string}> $chunks
	 */
	private static function emit( string $tag, string $text, string $html, array &$chunks ): void {
		$text = self::normalize_whitespace( $text );
		if ( $text === '' ) {
			return;
		}
		$chunks[] = [
			'tag'  => strtolower( $tag ),
			'text' => $text,
			'html' => trim( $html ),
		];
	}

	private static function is_block( DOMElement $element ): bool {
		return in_array( strtolower( $element->tagName ), self::BLOCK_TAGS, true );
	}

	/**
	 * Whether $element contains any block-level descendant element (making it a
	 * container to recurse into rather than a leaf block to emit).
	 */
	private static function has_block_descendant( DOMElement $element ): bool {
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			if ( self::is_block( $child ) || self::has_block_descendant( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plain text of a node, treating <br> as a space so lines separated only by a
	 * line break aren't glued into one word (e.g. "line.<br>Second" → "line. Second").
	 */
	private static function node_text( DOMNode $node ): string {
		if ( $node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE ) {
			return $node->nodeValue ?? '';
		}
		if ( $node instanceof DOMElement && strtolower( $node->tagName ) === 'br' ) {
			return ' ';
		}
		$text = '';
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$text .= self::node_text( $child );
		}
		return $text;
	}

	/**
	 * The inner HTML of an element (its children serialized), keeping inline markup.
	 */
	private static function inner_html( DOMElement $element ): string {
		$html = '';
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			$html .= self::save_html( $child );
		}
		return $html;
	}

	private static function save_html( DOMNode $node ): string {
		$doc = $node->ownerDocument;
		return $doc ? (string) $doc->saveHTML( $node ) : '';
	}

	private static function normalize_whitespace( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Parses an HTML fragment and returns the wrapper element whose children are the
	 * top-level nodes of $html, or null if parsing produced nothing usable. The
	 * "<?xml encoding>" prefix and the NOIMPLIED/NODEFDTD flags keep UTF-8 intact and
	 * stop libxml adding a doctype/<html>/<body> we'd have to skip past.
	 */
	private static function load_root( string $html ): ?DOMElement {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8"?><div id="splecheh-content-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$root = $dom->getElementById( 'splecheh-content-root' );
		return $root instanceof DOMElement ? $root : null;
	}
}
