<?php

declare(strict_types=1);

namespace Splecheh\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContentSplitterTest extends TestCase {

	public function test_each_paragraph_is_its_own_chunk(): void {
		$chunks = \Splecheh_ContentSplitter::split( '<p>First paragraph.</p><p>Second paragraph.</p>' );

		$this->assertCount( 2, $chunks );
		$this->assertSame( 'p', $chunks[0]['tag'] );
		$this->assertSame( 'First paragraph.', $chunks[0]['text'] );
		$this->assertSame( 'Second paragraph.', $chunks[1]['text'] );
	}

	public function test_header_and_paragraph_are_never_merged(): void {
		$texts = \Splecheh_ContentSplitter::plain_texts( '<h2>A heading</h2><p>A paragraph.</p>' );

		$this->assertSame( [ 'A heading', 'A paragraph.' ], $texts );
	}

	public function test_list_items_are_separate_chunks(): void {
		$chunks = \Splecheh_ContentSplitter::split( '<ul><li>First item</li><li>Second item</li></ul>' );

		$this->assertCount( 2, $chunks );
		$this->assertSame( 'li', $chunks[0]['tag'] );
		$this->assertSame( 'First item', $chunks[0]['text'] );
		$this->assertSame( 'Second item', $chunks[1]['text'] );
	}

	public function test_recurses_into_nested_block_containers(): void {
		$texts = \Splecheh_ContentSplitter::plain_texts( '<div><p>Inner one.</p><p>Inner two.</p></div>' );

		// The wrapping <div> is not emitted itself; only its leaf blocks are.
		$this->assertSame( [ 'Inner one.', 'Inner two.' ], $texts );
	}

	public function test_leaf_block_keeps_inline_formatting_in_html(): void {
		$chunks = \Splecheh_ContentSplitter::split( '<p>A <strong>bold</strong> and <a href="/x">linked</a> word.</p>' );

		$this->assertCount( 1, $chunks );
		$this->assertSame( 'A bold and linked word.', $chunks[0]['text'] );
		$this->assertStringContainsString( '<strong>bold</strong>', $chunks[0]['html'] );
		$this->assertStringContainsString( '<a href="/x">linked</a>', $chunks[0]['html'] );
	}

	public function test_br_inside_block_becomes_a_space(): void {
		$chunks = \Splecheh_ContentSplitter::split( '<p>First line.<br>Second line.</p>' );

		$this->assertSame( 'First line. Second line.', $chunks[0]['text'] );
	}

	public function test_loose_text_between_blocks_is_its_own_anonymous_chunk(): void {
		$chunks = \Splecheh_ContentSplitter::split( 'Loose intro.<p>A paragraph.</p>' );

		$this->assertCount( 2, $chunks );
		$this->assertSame( '', $chunks[0]['tag'] );
		$this->assertSame( 'Loose intro.', $chunks[0]['text'] );
		$this->assertSame( 'p', $chunks[1]['tag'] );
	}

	public function test_table_cells_are_separate_chunks(): void {
		$texts = \Splecheh_ContentSplitter::plain_texts( '<table><tr><td>Cell one.</td><td>Cell two.</td></tr></table>' );

		$this->assertSame( [ 'Cell one.', 'Cell two.' ], $texts );
	}

	public function test_empty_and_whitespace_only_content_yields_no_chunks(): void {
		$this->assertSame( [], \Splecheh_ContentSplitter::split( '' ) );
		$this->assertSame( [], \Splecheh_ContentSplitter::split( '   ' ) );
		$this->assertSame( [], \Splecheh_ContentSplitter::split( '<p>   </p>' ) );
	}

	public function test_utf8_content_is_preserved(): void {
		$chunks = \Splecheh_ContentSplitter::split( '<p>Iesaistot bērnus pasakā.</p>' );

		$this->assertSame( 'Iesaistot bērnus pasakā.', $chunks[0]['text'] );
	}

	public function test_blockquote_is_a_chunk_boundary(): void {
		$texts = \Splecheh_ContentSplitter::plain_texts( '<blockquote>Quoted.</blockquote><p>After.</p>' );

		$this->assertSame( [ 'Quoted.', 'After.' ], $texts );
	}
}
