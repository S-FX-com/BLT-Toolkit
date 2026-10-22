<?php
/**
 * The activity log as a WP_List_Table.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Activity screen's table (SPEC 13.2). It renders inside a `.blt-card`,
 * where the design system drops the table's own border so the card supplies
 * it once.
 *
 * Filters, sorting and paging are all query arguments, so every state of the
 * table is a URL. The Summary column comes first and is the primary column:
 * it holds the row's `<details>` disclosure, so on a narrow screen, where
 * WordPress folds the other columns away, everything recorded is still one
 * click away without JavaScript. The admin stylesheet also keeps the other
 * columns visible there, so the row-toggle button is not printed.
 */
class BLT_Toolkit_Admin_Activity_Table extends WP_List_Table {

	/**
	 * The screen, for labels and the disclosure markup.
	 *
	 * @var BLT_Toolkit_Admin_Activity
	 */
	private $activity;

	/**
	 * Normalized query arguments.
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Constructor.
	 *
	 * @param BLT_Toolkit_Admin_Activity $activity The Activity screen.
	 * @param array                      $args     Arguments from BLT_Toolkit_Log::normalize_args().
	 */
	public function __construct( BLT_Toolkit_Admin_Activity $activity, array $args ) {
		parent::__construct(
			array(
				'singular' => 'blt-toolkit-entry',
				'plural'   => 'blt-toolkit-entries',
				'ajax'     => false,
			)
		);

		$this->activity = $activity;
		$this->args     = $args;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'summary'  => __( 'What happened', 'blt-toolkit' ),
			'when'     => __( 'When', 'blt-toolkit' ),
			'actor'    => __( 'Who', 'blt-toolkit' ),
			'activity' => __( 'Module and action', 'blt-toolkit' ),
			'status'   => __( 'Status', 'blt-toolkit' ),
		);
	}

	/**
	 * Sortable columns. The keys are BLT_Toolkit_Log::SORTABLE's whitelist.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			// WP 6.3+: the fifth value marks When as the column the default
			// view is sorted by, newest first, so its header says so and the
			// first click sorts oldest first.
			'when'     => array( 'when', true, '', '', 'desc' ),
			'actor'    => array( 'actor', false ),
			'activity' => array( 'activity', false ),
		);
	}

	/**
	 * The column that stays visible on a narrow screen.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'summary';
	}

	/**
	 * Load one page of rows for the current filters.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = (int) $this->args['per_page'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'summary' );

		$this->set_pagination_args(
			array(
				'total_items' => BLT_Toolkit_Log::count( $this->args ),
				'per_page'    => $per_page,
			)
		);

		// get_pagenum() holds the page to the last one once the total is known,
		// so a page number past the end shows the last page.
		$this->args['paged'] = max( 1, (int) $this->get_pagenum() );
		$this->items         = BLT_Toolkit_Log::query( $this->args );

		$ids = array();

		foreach ( $this->items as $item ) {
			$ids[] = $item['actor_id'];
			$ids[] = $item['acting_as_id'];

			if ( 'user' === $item['object_type'] ) {
				$ids[] = (int) $item['object_id'];
			}
		}

		$this->activity->prime_users( $ids );
	}

	/**
	 * Shown when a page has no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No activity matches these filters.', 'blt-toolkit' );
	}

	/**
	 * Pagination only: no bulk actions, and no bulk-action nonce, because the
	 * table only reads.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'bottom' === $which && ! $this->has_items() ) {
			return;
		}
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php $this->pagination( $which ); ?>
			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * No row actions and no row-toggle button: the disclosure in the Summary
	 * column replaces both, and works without JavaScript.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return '';
	}

	/**
	 * The Summary column: the line a person reads, what it acted on, and the
	 * disclosure with everything recorded.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_summary( $item ) {
		$object = $this->activity->object_label( $item );
		$text   = '' !== $item['summary'] ? $item['summary'] : $this->activity->action_label( $item['action'] );

		return '<span class="blt-toolkit-activity-summary">' . esc_html( $text ) . '</span>'
			. ( '' !== $object ? '<span class="blt-toolkit-activity-object blt-toolkit-muted">' . esc_html( $object ) . '</span>' : '' )
			. '<details class="blt-toolkit-activity-details">'
			. '<summary>' . wp_kses(
				sprintf(
					/* translators: %d: log entry ID. Only "Details" shows; the hidden part completes what a screen reader announces. */
					__( 'Details<span class="screen-reader-text"> of entry %d</span>', 'blt-toolkit' ),
					(int) $item['id']
				),
				array( 'span' => array( 'class' => true ) )
			) . '</summary>'
			. '<div class="blt-toolkit-activity-details-body">' . $this->activity->details_html( $item ) . '</div>'
			. '</details>';
	}

	/**
	 * Render the other cells.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'when':
				return '<span title="' . esc_attr( $item['created_at'] . ' UTC' ) . '">' . esc_html( $this->activity->site_time( $item['created_at'] ) ) . '</span>'
					. '<span class="blt-toolkit-activity-sub blt-toolkit-muted">' . esc_html( $this->activity->relative_time( $item['created_at'] ) ) . '</span>';

			case 'actor':
				$who = $this->activity->who( $item );

				return '<span>' . esc_html( $who['actor'] ) . '</span>'
					. ( '' !== $who['acting_as']
						? '<span class="blt-toolkit-activity-sub blt-toolkit-muted">' . esc_html(
							sprintf(
								/* translators: %s: name of the user someone had switched to. */
								__( 'acting as %s', 'blt-toolkit' ),
								$who['acting_as']
							)
						) . '</span>'
						: '' );

			case 'activity':
				return '<span class="blt-badge">' . esc_html( $this->activity->module_label( $item['module'] ) ) . '</span>'
					. '<span class="blt-toolkit-activity-sub">' . esc_html( $this->activity->action_label( $item['action'] ) ) . '</span>';

			case 'status':
				return $this->activity->status_badge( $item['status'] );
		}

		return '';
	}
}
