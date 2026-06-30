<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Splecheh_SpellCheckListTable extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'post',
				'plural'   => 'posts',
				'ajax'     => false,
			]
		);
	}

	public function get_columns(): array {
		return [
			'title'        => __( 'Title', 'splecheh' ),
			'post_type'    => __( 'Post Type', 'splecheh' ),
			'last_checked' => __( 'Last Checked', 'splecheh' ),
			'status'       => __( 'Status', 'splecheh' ),
			'report'       => __( 'Report', 'splecheh' ),
			'actions'      => __( 'Actions', 'splecheh' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'title'        => [ 'post_title', false ],
			'last_checked' => [ 'last_checked', false ],
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$enabled_types = splecheh_get_enabled_post_types();

		$type_filter = sanitize_key( wp_unslash( $_GET['post_type_filter'] ?? '' ) );
		if ( ! empty( $type_filter ) && in_array( $type_filter, $enabled_types, true ) ) {
			$post_types = [ $type_filter ];
		} else {
			$post_types = $enabled_types;
		}

		$per_page = 20;
		$paged    = $this->get_pagenum();
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query       = new WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			[
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => $query->max_num_pages,
			]
		);
	}

	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'title':
				$edit_link = get_edit_post_link( $item->ID );
				if ( $edit_link ) {
					return '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $item->post_title ) . '</a>';
				}
				return esc_html( $item->post_title );

			case 'post_type':
				$pt = get_post_type_object( $item->post_type );
				return esc_html( $pt ? $pt->label : $item->post_type );

			case 'last_checked':
				$checked_at = get_post_meta( $item->ID, '_splecheh_checked_at', true );
				if ( ! $checked_at ) {
					return '&mdash;';
				}
				$ts = strtotime( $checked_at . ' UTC' );
				return esc_html( get_date_from_gmt( $checked_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

			case 'status':
				return $this->column_status( $item );

			case 'report':
				return $this->column_report( $item );

			case 'actions':
				return $this->column_actions( $item );
		}
		return '';
	}

	private function column_status( WP_Post $item ): string {
		$checked_at = get_post_meta( $item->ID, '_splecheh_checked_at', true );
		if ( ! $checked_at ) {
			return '<span class="splecheh-badge splecheh-badge--never">' . esc_html__( 'Never checked', 'splecheh' ) . '</span>';
		}
		$post_modified_gmt = strtotime( $item->post_modified_gmt );
		$checked_ts        = strtotime( $checked_at . ' UTC' );
		if ( $post_modified_gmt > $checked_ts ) {
			return '<span class="splecheh-badge splecheh-badge--outdated">' . esc_html__( 'Outdated', 'splecheh' ) . '</span>';
		}
		return '<span class="splecheh-badge splecheh-badge--current">' . esc_html__( 'Up to date', 'splecheh' ) . '</span>';
	}

	private function column_report( WP_Post $item ): string {
		$url = Splecheh_SpellCheckReport::get_report_url( $item->ID );
		if ( ! $url ) {
			return '&mdash;';
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View Report', 'splecheh' ) . '</a>';
	}

	private function column_actions( WP_Post $item ): string {
		return sprintf(
			'<button class="button splecheh-run-check" data-post-id="%d">%s</button>
			<span class="splecheh-spinner spinner" style="display:none;float:none;margin:0 4px;vertical-align:middle;"></span>',
			$item->ID,
			esc_html__( 'Spell Check', 'splecheh' )
		);
	}

	/**
	 * Renders the post-type filter dropdown and search box above the table.
	 */
	public function render_filters(): void {
		$enabled_types  = splecheh_get_enabled_post_types();
		$current_type   = sanitize_key( wp_unslash( $_GET['post_type_filter'] ?? '' ) );
		$current_search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		?>
		<form method="get" action="">
			<input type="hidden" name="page" value="splecheh">
			<div class="alignleft actions">
				<label for="splecheh-type-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by post type', 'splecheh' ); ?></label>
				<select id="splecheh-type-filter" name="post_type_filter">
					<option value=""><?php esc_html_e( 'All post types', 'splecheh' ); ?></option>
					<?php foreach ( $enabled_types as $type_slug ) :
						$pt = get_post_type_object( $type_slug );
						if ( ! $pt ) {
							continue;
						}
					?>
					<option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $current_type, $type_slug ); ?>>
						<?php echo esc_html( $pt->label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'splecheh' ); ?>">
			</div>
			<div class="alignright">
				<label for="splecheh-search" class="screen-reader-text"><?php esc_html_e( 'Search posts', 'splecheh' ); ?></label>
				<input type="search" id="splecheh-search" name="s" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search posts…', 'splecheh' ); ?>">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'splecheh' ); ?>">
			</div>
		</form>
		<?php
	}

	protected function display_tablenav( $which ): void {
		if ( $which === 'top' ) {
			$this->render_filters();
		}
		parent::display_tablenav( $which );
	}
}
