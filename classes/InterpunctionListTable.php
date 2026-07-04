<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Splecheh_InterpunctionListTable extends WP_List_Table {

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
			'cb'           => '<input type="checkbox" />',
			'title'        => __( 'Title', 'splecheh' ),
			'post_type'    => __( 'Post Type', 'splecheh' ),
			'last_checked' => __( 'Last Checked', 'splecheh' ),
			'status'       => __( 'Status', 'splecheh' ),
			'chunks'       => __( 'Chunks', 'splecheh' ),
			'issues'       => __( 'Issues', 'splecheh' ),
			'actions'      => __( 'Actions', 'splecheh' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'title'        => [ 'post_title', false ],
			'last_checked' => [ 'last_checked', false ],
			'issues'       => [ 'issues', true ],
		];
	}

	protected function get_bulk_actions(): array {
		return [
			'splecheh_interpunction_bulk_rerun' => __( 'Re-run Interpunction Check', 'splecheh' ),
		];
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="post_ids[]" value="%d">',
			$item->ID
		);
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

		$per_page         = 20;
		$paged            = $this->get_pagenum();
		$search           = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$orderby          = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
		$order            = strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ) === 'DESC' ? 'DESC' : 'ASC';
		$show_zero_issues = ! empty( $_GET['show_zero_issues'] );

		$args = [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'order'          => $order,
		];

		switch ( $orderby ) {
			case 'issues':
				$args['meta_key'] = '_splecheh_interpunction_issue_count';
				$args['orderby']  = 'meta_value_num';
				break;
			case 'last_checked':
				$args['meta_key'] = '_splecheh_interpunction_checked_at';
				$args['orderby']  = 'meta_value';
				break;
			default:
				$args['orderby'] = 'title';
				break;
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		if ( ! $show_zero_issues ) {
			add_filter( 'posts_join', [ $this, 'filter_join_outdated_or_issues' ] );
			add_filter( 'posts_where', [ $this, 'filter_where_outdated_or_issues' ] );
		}

		$query = new WP_Query( $args );

		if ( ! $show_zero_issues ) {
			remove_filter( 'posts_join', [ $this, 'filter_join_outdated_or_issues' ] );
			remove_filter( 'posts_where', [ $this, 'filter_where_outdated_or_issues' ] );
		}

		$this->items = $query->posts;

		$this->set_pagination_args(
			[
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => $query->max_num_pages,
			]
		);
	}

	/**
	 * Joins the meta needed to identify posts that are outdated/never-checked or
	 * have unresolved issues, so that state can be filtered on in SQL. Used by
	 * prepare_items() to hide up-to-date posts with 0 issues unless requested.
	 */
	public function filter_join_outdated_or_issues( string $join ): string {
		global $wpdb;

		$join .= " LEFT JOIN {$wpdb->postmeta} AS splecheh_ip_pm_checked ON ( splecheh_ip_pm_checked.post_id = {$wpdb->posts}.ID AND splecheh_ip_pm_checked.meta_key = '_splecheh_interpunction_checked_at' )";
		$join .= " LEFT JOIN {$wpdb->postmeta} AS splecheh_ip_pm_issues ON ( splecheh_ip_pm_issues.post_id = {$wpdb->posts}.ID AND splecheh_ip_pm_issues.meta_key = '_splecheh_interpunction_issue_count' )";

		if ( splecheh_invalidate_on_version_change_enabled() ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} AS splecheh_ip_pm_version ON ( splecheh_ip_pm_version.post_id = {$wpdb->posts}.ID AND splecheh_ip_pm_version.meta_key = '_splecheh_interpunction_version' )";
		}

		return $join;
	}

	/**
	 * Restricts results to posts that are outdated/never-checked or have at
	 * least one unresolved issue, mirroring Splecheh_SpellCheckReport::is_outdated().
	 */
	public function filter_where_outdated_or_issues( string $where ): string {
		global $wpdb;

		$conditions = [
			'splecheh_ip_pm_checked.meta_value IS NULL',
			"splecheh_ip_pm_checked.meta_value < {$wpdb->posts}.post_modified_gmt",
			"CAST( COALESCE( splecheh_ip_pm_issues.meta_value, '0' ) AS UNSIGNED ) >= 1",
		];

		if ( splecheh_invalidate_on_version_change_enabled() ) {
			$conditions[] = $wpdb->prepare(
				'( splecheh_ip_pm_version.meta_value IS NULL OR splecheh_ip_pm_version.meta_value != %s )',
				SPLECHEH_VERSION
			);
		}

		$where .= ' AND ( ' . implode( ' OR ', $conditions ) . ' )';

		return $where;
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
				$checked_at = get_post_meta( $item->ID, '_splecheh_interpunction_checked_at', true );
				if ( ! $checked_at ) {
					return '&mdash;';
				}
				return esc_html( get_date_from_gmt( $checked_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

			case 'status':
				return $this->column_status( $item );

			case 'chunks':
				return $this->column_chunks( $item );

			case 'issues':
				return $this->column_issues( $item );

			case 'actions':
				return $this->column_actions( $item );
		}
		return '';
	}

	protected function column_status( WP_Post $item ): string {
		$checked_at = get_post_meta( $item->ID, '_splecheh_interpunction_checked_at', true );
		if ( ! $checked_at ) {
			return '<span class="splecheh-badge splecheh-badge--never">' . esc_html__( 'Never checked', 'splecheh' ) . '</span>';
		}

		$stored_version = get_post_meta( $item->ID, '_splecheh_interpunction_version', true );
		$is_outdated    = Splecheh_SpellCheckReport::is_outdated(
			$checked_at,
			$item->post_modified_gmt,
			$stored_version !== '' ? (string) $stored_version : null,
			splecheh_invalidate_on_version_change_enabled()
		);

		if ( $is_outdated ) {
			return '<span class="splecheh-badge splecheh-badge--outdated">' . esc_html__( 'Outdated', 'splecheh' ) . '</span>';
		}
		return '<span class="splecheh-badge splecheh-badge--current">' . esc_html__( 'Up to date', 'splecheh' ) . '</span>';
	}

	/**
	 * Shows how many of a post's chunks were processed in its saved report (e.g. "6/11"),
	 * so a check that failed partway through — rather than just not completing silently —
	 * is visible as incomplete instead of looking identical to a full, successful check.
	 * Missing meta (never checked, checked before this field existed, or a post with no
	 * sentences to check) shows as "—" rather than a potentially-misleading "0/0".
	 */
	protected function column_chunks( WP_Post $item ): string {
		if ( ! metadata_exists( 'post', $item->ID, '_splecheh_interpunction_chunks_total' ) ) {
			return '&mdash;';
		}

		$processed = (int) get_post_meta( $item->ID, '_splecheh_interpunction_chunks_processed', true );
		$total     = (int) get_post_meta( $item->ID, '_splecheh_interpunction_chunks_total', true );

		if ( $total <= 0 ) {
			return '&mdash;';
		}

		$label = $processed . '/' . $total;

		if ( $processed < $total ) {
			return '<span class="splecheh-badge splecheh-badge--outdated" title="' .
				esc_attr__( 'Check did not finish — re-run to process the remaining chunks.', 'splecheh' ) .
				'">' . esc_html( $label ) . '</span>';
		}

		return esc_html( $label );
	}

	/**
	 * Shows the stored unresolved issue count. Missing meta (never checked, or checked
	 * before this field existed) is treated the same as "never checked" rather than as 0.
	 */
	protected function column_issues( WP_Post $item ): string {
		if ( ! metadata_exists( 'post', $item->ID, '_splecheh_interpunction_issue_count' ) ) {
			return '&mdash;';
		}
		return esc_html( (string) get_post_meta( $item->ID, '_splecheh_interpunction_issue_count', true ) );
	}

	protected function column_actions( WP_Post $item ): string {
		return self::render_actions_html( $item->ID );
	}

	/**
	 * Renders the Run/Re-run button plus, once a report exists, links to the report JSON
	 * and the Interpunction Check Details page. Shared by the list table render and the
	 * AJAX response so both stay in sync.
	 */
	public static function render_actions_html( int $post_id ): string {
		$report_url = Splecheh_InterpunctionReport::get_report_url( $post_id );
		$label      = $report_url ? __( 'Re-run', 'splecheh' ) : __( 'Run Now', 'splecheh' );

		$html  = sprintf(
			'<button class="button splecheh-interpunction-run-check" data-post-id="%d">%s</button>',
			$post_id,
			esc_html( $label )
		);
		$html .= '<span class="splecheh-spinner spinner" style="display:none;float:none;margin:0 4px;vertical-align:middle;"></span>';

		if ( $report_url ) {
			$html .= sprintf(
				' <a class="button button-small" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $report_url ),
				esc_html__( 'View Report', 'splecheh' )
			);

			$details_url = add_query_arg(
				[
					'page'    => 'splecheh-interpunction-details',
					'post_id' => $post_id,
				],
				admin_url( 'admin.php' )
			);

			$html .= sprintf(
				' <a class="button button-small" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $details_url ),
				sprintf(
					/* translators: %d: number of unresolved interpunction issues */
					esc_html__( 'Details (%d)', 'splecheh' ),
					Splecheh_InterpunctionReport::count_unresolved( $post_id )
				)
			);
		}

		return $html;
	}

	/**
	 * Renders the post-type filter dropdown and search box above the table.
	 */
	public function render_filters(): void {
		$enabled_types    = splecheh_get_enabled_post_types();
		$current_type     = sanitize_key( wp_unslash( $_GET['post_type_filter'] ?? '' ) );
		$current_search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$show_zero_issues = ! empty( $_GET['show_zero_issues'] );
		?>
		<input type="hidden" name="page" value="splecheh-interpunction">
		<div class="alignleft actions">
			<label for="splecheh-interpunction-type-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by post type', 'splecheh' ); ?></label>
			<select id="splecheh-interpunction-type-filter" name="post_type_filter">
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
			<label for="splecheh-interpunction-show-zero-issues">
				<input type="checkbox" id="splecheh-interpunction-show-zero-issues" name="show_zero_issues" value="1" <?php checked( $show_zero_issues ); ?>>
				<?php esc_html_e( 'Show also posts with 0 interpunction issues', 'splecheh' ); ?>
			</label>
			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'splecheh' ); ?>">
		</div>
		<div class="alignright">
			<label for="splecheh-interpunction-search" class="screen-reader-text"><?php esc_html_e( 'Search posts', 'splecheh' ); ?></label>
			<input type="search" id="splecheh-interpunction-search" name="s" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search posts…', 'splecheh' ); ?>">
			<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'splecheh' ); ?>">
		</div>
		<?php
	}

	protected function display_tablenav( $which ): void {
		if ( $which === 'top' ) {
			$this->render_filters();
		}
		parent::display_tablenav( $which );
	}
}
