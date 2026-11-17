<?php
defined('ABSPATH') || exit;

require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

class Revisionary_Archive_List_Table extends WP_List_Table {
	private $post_types = [];
    private $readable_parent_ids = [];
    private $readable_revision_ids = [];
    private $readable_authors = null;
    private $archive_page = 1;
    private $archive_has_more = false;
	private $show_approved_by_col = true;

	private $post_id = 0;
	private $direct_edit = false;
	private $from_revision_workflow = false;
	private $parent_in_revision_workflow = false;
	private $parent_from_revision_workflow = false;

	private $active_revision_title;
	private $from_revision_title;

	public function __construct( $args ) {
		global $revisionary;

		$this->active_revision_title = esc_html__('This was an update to a revision which is still in the workflow process.', 'revisionary');

		$this->from_revision_title = esc_html__('This was an update to a revision which was published after further editing.', 'revisionary');

		$args = wp_parse_args(
			$args,
			[
				'plural' => 'posts',
				'screen' => 'revisionary-archive',
			]
		);

		parent::__construct( $args );

		$this->post_types = array_keys(array_filter($revisionary->enabled_post_types_archive));
    }

    /** Parent IDs from the authorized rows on the current SQL page only. */
    private function readableParentIds() {
        return $this->readable_parent_ids;
    }

	/**
	 * Override WP_List_Table::prepare_items()
	 */
    public function prepare_items() {
		global $wpdb, $per_page, $current_user;

		$per_page 		= max(1, (int) $this->get_items_per_page( 'revision_archive_per_page' ));						//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$paged 			= isset($_REQUEST['paged']) && is_scalar($_REQUEST['paged']) ? max( 0, intval( $_REQUEST['paged'] ) - 1 ) : 0;	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset 		= $paged * $per_page;
        $this->archive_page = $paged + 1;
		$orderby		= isset( $_REQUEST['orderby'] )													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& ! empty( $_REQUEST['orderby'] )															//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& in_array(
				$_REQUEST['orderby'],																	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				[
					'origin_post_date',
					'post_date',
					'post_modified',
					'post_count'
				], true
			)
			? sanitize_key( $_REQUEST['orderby'] )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'post_modified';
																										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && ! empty( $_REQUEST['order'] ) && in_array( $_REQUEST['order'], ['asc', 'desc'], true )
			? strtoupper(sanitize_key($_REQUEST['order']))												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'DESC';

		// Filters
		$args = [
			'orderby' 	=> $orderby,
			'order'		=> $order
		];
		if( isset( $_REQUEST['s'] ) && ! empty( trim( sanitize_text_field(wp_unslash($_REQUEST['s'])) ) ) ) {							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['s'] = strtolower( trim( sanitize_text_field(wp_unslash($_REQUEST['s'])) ) );					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] ) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post'] = (int) $_REQUEST['origin_post'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( !empty($_REQUEST['origin_post_author']) && is_scalar($_REQUEST['origin_post_author']) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_author'] = (int) $_REQUEST['origin_post_author'];						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( !empty($_REQUEST['post_author']) && is_scalar($_REQUEST['post_author']) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['post_author'] = (int) $_REQUEST['post_author'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] ) ) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_type'] = sanitize_text_field( wp_unslash($_REQUEST['origin_post_type']) );			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( !empty($_REQUEST['post_parent']) && rvy_can_read_revision_and_parent((int) $_REQUEST['post_parent']) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['post_parent'] = (int) $_REQUEST['post_parent'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['revision_date'] ) && ! empty( $_REQUEST['revision_date'] ) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['revision_date'] = (int) $_REQUEST['revision_date'];								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post_date'] ) && ! empty( $_REQUEST['origin_post_date'] ) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_date'] = (int) $_REQUEST['origin_post_date'];								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( !empty($_REQUEST['approved_by']) && is_scalar($_REQUEST['approved_by']) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['approved_by'] = (int) $_REQUEST['approved_by'];						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

        // Authorize distinct parents, not every historical child. A parent is a
        // necessary permission boundary for every row, but rows still need their
        // own checks after pagination (plugins can deny individual revisions).
        $base_query = $this->do_query($args);
        $parent_ids = $wpdb->get_col("SELECT DISTINCT post_parent FROM ($base_query) AS archive_parent_candidates"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The subquery is produced by the prepared candidate query builder.
        $allowed_parents = [];
        foreach ($parent_ids as $parent_id) {
            $parent_id = (int) $parent_id;
            try {
                if (rvy_can_read_revision_and_parent($parent_id)) {
                    $allowed_parents[] = $parent_id;
                }
            } finally {
                wp_cache_delete($parent_id, 'posts');
                wp_cache_delete($parent_id, 'post_meta');
            }
        }
        $args['readable_parents'] = $allowed_parents;
        $base_query = $this->do_query($args);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"{$base_query} LIMIT %d,%d",															// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$offset,
				$per_page + 1
			)
		);

        // Fetch one extra candidate solely to determine whether another SQL
        // page exists. Never authorize the rest of the archive for totals.
        $this->archive_has_more = count($results) > $per_page;
        $results = array_slice($results, 0, $per_page);
        $this->items = [];
        $this->readable_parent_ids = [];
        $this->readable_revision_ids = [];
        $this->readable_authors = null;
        foreach ($results as $row) {
            if (!rvy_can_read_revision_and_parent((int) $row->ID)
                || !rvy_can_read_revision_and_parent((int) $row->post_parent)) {
                continue;
            }
            $this->items[] = $row;
            $this->readable_parent_ids[(int) $row->post_parent] = (int) $row->post_parent;
            $this->readable_revision_ids[] = (int) $row->ID;
        }
        $this->set_pagination_args([
            'total_items' => count($this->items),
            'per_page' => $per_page,
            'total_pages' => $this->archive_page + (int) $this->archive_has_more,
        ]);
        $post_id_csv = implode(',', $this->readable_revision_ids) ?: '0';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs come only from authorized page rows.
        $this->show_approved_by_col = $wpdb->get_var("SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_approved_by' AND meta_value > 0 AND post_id IN ($post_id_csv) LIMIT 1");

    }

	/**
	 * Generate a heading depeding the filters in use
	 *
	 * @return string
	 */
    private function readableAuthors() {
        if (null !== $this->readable_authors) { return $this->readable_authors; }
        global $wpdb;
        $readable_parent_csv = implode(',', $this->readableParentIds()) ?: '0';
        $readable_revision_csv = implode(',', $this->readable_revision_ids) ?: '0';
        $heading_authors = [];
        $heading_authors['approved_by'] = array_map('intval', $wpdb->get_col("SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_approved_by' AND post_id IN (SELECT ID FROM $wpdb->posts WHERE ID IN ($readable_revision_csv))")); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

        $heading_authors['origin_post_author'] = array_map('intval', $wpdb->get_col("SELECT DISTINCT post_author FROM $wpdb->posts WHERE ID IN ($readable_parent_csv)")); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

        $heading_authors['post_author'] = array_map('intval', $wpdb->get_col("SELECT DISTINCT post_author FROM $wpdb->posts WHERE ID IN ($readable_revision_csv)")); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

        return $this->readable_authors = $heading_authors;
    }

	public function filters_in_heading() {
        $heading_authors = $this->readableAuthors();

		$count = 0;

		$any_filters = ( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] ) )		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] ) )		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['post_author'] ) && ! empty( $_REQUEST['post_author'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['post_parent'] ) && ! empty( $_REQUEST['post_parent'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['post_status'] ) && ! empty( $_REQUEST['post_status'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['revision_date'] ) && ! empty( $_REQUEST['revision_date'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['origin_post_author'] ) && ! empty( $_REQUEST['origin_post_author'] ) )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['origin_post_date'] ) && ! empty( $_REQUEST['origin_post_date'] ) )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['approved_by'] ) && ! empty( $_REQUEST['approved_by'] ) );	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ($any_filters) {
			echo ' (';
		}

		// Post title
		if( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] )				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			if (rvy_can_read_revision_and_parent((int) $_REQUEST['origin_post']) && ($post_title = get_post_field('post_title', (int) $_REQUEST['origin_post']))) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->heading_spacing( $count );
				echo esc_html($post_title);
				$count++;
			}
		} else {
			// Post type
			if( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& in_array( $_REQUEST['origin_post_type'], $this->post_types, true )						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				$obj = get_post_type_object( sanitize_key( $_REQUEST['origin_post_type'] ) );		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->heading_spacing( $count );
				if ((!empty($obj) && !empty($obj->labels) && !empty($obj->labels->name))) echo esc_html($obj->labels->name); else echo esc_html(ucwords(str_replace('_', ' ', sanitize_key($_REQUEST['origin_post_type']))));  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$count++;
			}
		}

		// Revision post author
		if( !empty($_REQUEST['post_author']) && is_scalar($_REQUEST['post_author']) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Revision Author: %s' ,'revisionary' ),
				esc_html(get_the_author_meta('display_name', (int) $_REQUEST['post_author']))				//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		// Revision post parent
		if( !empty($_REQUEST['post_parent']) && rvy_can_read_revision_and_parent((int) $_REQUEST['post_parent']) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			echo '"';
			echo esc_html(get_post_field('post_title', (int) $_REQUEST['post_parent']));											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '"';				
			$count++;
		}

		// Origin post author
		if( !empty($_REQUEST['origin_post_author']) && is_scalar($_REQUEST['origin_post_author']) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Post Author: %s' ,'revisionary' ),
				esc_html(get_the_author_meta('display_name', (int) $_REQUEST['origin_post_author']))		//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		// Revision date
		if( isset( $_REQUEST['revision_date'] ) && ! empty( $_REQUEST['revision_date'] ) ) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Revision Date: %s' ,'revisionary' ),
				date('Y-m-d', intval($_REQUEST['revision_date']))									//phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		// Approved by
		if( !empty($_REQUEST['approved_by']) && is_scalar($_REQUEST['approved_by']) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Approved by: %s' ,'revisionary' ),
				esc_html(get_the_author_meta('display_name', (int) $_REQUEST['approved_by']))				//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		// Published date
		if( isset( $_REQUEST['origin_post_date'] ) && ! empty( $_REQUEST['origin_post_date'] ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Publication Date: %s' ,'revisionary' ),
				date('Y-m-d', intval($_REQUEST['origin_post_date']))								//phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		if($any_filters) {
			echo ')';
		}
	}

	/**
	 * Generate a label next to heading for search results
	 *
	 * @return string
	 */
	public function search_in_heading() {
		if( isset( $_REQUEST['s'] ) && ! empty( trim( sanitize_text_field(wp_unslash($_REQUEST['s'])) ) ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<span class="subtitle">';
			
			printf(
				esc_html__( 'Search results for "%s"', 'revisionary' ),
				esc_html(strtolower(
					trim(
						sanitize_text_field( wp_unslash($_REQUEST['s']) )										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				))
			);

			echo '</span>';
		}
	}

    /** Build the filtered candidate query; prepare_items applies SQL pagination before authorization. */
    private function do_query($args = []) {
        global $wpdb;
        $types = array_values(array_filter($this->post_types, static function ($type) {
            return is_string($type) && '' !== $type && strlen($type) <= 20 && sanitize_key($type) === $type;
        }));
        $query = "SELECT r.ID, r.post_type, r.post_status, r.post_title, r.post_date, r.post_date_gmt,
            r.post_modified, r.post_modified_gmt, r.post_author, r.post_parent, r.post_name,
            r3.post_author AS origin_post_author, r3.post_date AS origin_post_date,
            r3.post_date_gmt AS origin_post_date_gmt,
            CASE WHEN r3.post_type = 'revision' THEN r4.post_type ELSE r3.post_type END AS origin_post_type,
            r3.post_status AS origin_status, r3.post_mime_type AS origin_mime_type";
        if ('post_count' === ($args['orderby'] ?? '')) {
            $query .= ", (SELECT COUNT(*) FROM $wpdb->posts p3 WHERE p3.post_parent = r.post_parent AND p3.post_type = 'revision') AS post_count";
        }
        if (isset($args['origin_post_date'])) {
            $gmt_offset = (int) get_option('gmt_offset');
            $publication_date = 'pm.meta_value';
            if ($gmt_offset) {
                $publication_date = $wpdb->prepare("DATE_ADD(pm.meta_value, INTERVAL %d HOUR)", $gmt_offset);
            }
            $query .= ", (SELECT DATE($publication_date) FROM $wpdb->postmeta pm WHERE pm.meta_key = '_rvy_published_gmt' AND pm.post_id = r.ID LIMIT 1) AS revision_published,
                (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_published_gmt' AND post_id = r3.ID LIMIT 1) AS parent_revision_published_gmt,
                (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_prev_revision_status' AND post_id = r3.ID LIMIT 1) AS parent_prev_revision_status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Publication-date expression contains only fixed SQL and a prepared integer offset.
        }
        if (isset($args['approved_by']) || isset($args['origin_post_date'])) {
            $query .= ", (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_prev_revision_status' AND post_id = r.ID LIMIT 1) AS prev_revision_status";
        }
        if (isset($args['approved_by'])) {
            $query .= ", (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_approved_by' AND post_id = r.ID LIMIT 1) AS approved_by";
        }
        $query .= " FROM $wpdb->posts r INNER JOIN $wpdb->posts r3 ON r.post_parent = r3.ID
            LEFT JOIN $wpdb->posts r4 ON r3.post_type = 'revision' AND r3.post_parent = r4.ID";
        if (!$types) {
            return $query . ' WHERE 1 = 0';
        }
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $query .= " WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s
            AND (r3.post_type IN ($placeholders) OR (r3.post_type = 'revision' AND r4.post_type IN ($placeholders)))"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %s tokens are interpolated; all post types are bound below.
        $values = array_merge(['%-autosave-v%'], $types, $types);
        // Use one outer namespace for projected fields, including publication
        // metadata. This avoids ambiguous join columns and duplicated aliases.
        $query = "SELECT * FROM ($query) AS archive_candidates WHERE 1 = 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The candidate template is fixed SQL with generated placeholders, prepared once below.
        if (isset($args['s'])) {
            $query .= ' AND LOWER(post_title) LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['s']) . '%';
        }
        foreach (['origin_post' => 'post_parent', 'post_parent' => 'post_parent', 'post_author' => 'post_author', 'origin_post_author' => 'origin_post_author'] as $key => $column) {
            if (isset($args[$key])) {
                $query .= " AND $column = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names come only from the fixed map above.
                $values[] = (int) $args[$key];
            }
        }
        if (isset($args['origin_post_type'])) {
            $query .= ' AND origin_post_type = %s';
            $values[] = $args['origin_post_type'];
        }
        if (isset($args['revision_date'])) {
            $query .= ' AND DATE(post_modified) = %s';
            $values[] = gmdate('Y-m-d', $args['revision_date']);
        }
        $statuses = array_map('sanitize_key', rvy_revision_statuses());
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        if (isset($args['origin_post_date'])) {
            $query .= " AND origin_mime_type NOT IN ($status_placeholders) AND (
                (parent_prev_revision_status IS NULL AND parent_revision_published_gmt IS NULL AND DATE(revision_published) = %s)
                OR (DATE(post_modified) = %s AND revision_published IS NULL AND prev_revision_status IS NULL))"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholder tokens are interpolated.
            $values = array_merge($values, $statuses, [gmdate('Y-m-d', $args['origin_post_date']), gmdate('Y-m-d', $args['origin_post_date'])]);
        }
        if (isset($args['approved_by'])) {
            $query .= " AND (approved_by = %d OR (origin_mime_type NOT IN ($status_placeholders) AND approved_by IS NULL AND prev_revision_status IS NULL AND post_author = %d))"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholder tokens are interpolated.
            $values = array_merge($values, [(int) $args['approved_by']], $statuses, [(int) $args['approved_by']]);
        }
        if (isset($args['readable_parents'])) {
            if (!$args['readable_parents']) {
                $query .= ' AND 1 = 0';
            } else {
                $parent_placeholders = implode(',', array_fill(0, count($args['readable_parents']), '%d'));
                $query .= " AND post_parent IN ($parent_placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Generated integer placeholders only; parent IDs are bound below.
                $values = array_merge($values, array_map('intval', $args['readable_parents']));
            }
        }
        $orderby = in_array($args['orderby'] ?? '', ['origin_post_date', 'post_date', 'post_modified', 'post_count'], true) ? $args['orderby'] : 'post_modified';
        $order = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';
        // ID resolves timestamp/count ties so page boundaries remain deterministic.
        $query .= " ORDER BY $orderby $order, ID $order"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both ordering tokens are explicitly allowlisted above.
        return $wpdb->prepare($query, $values); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query templates and identifier maps are fixed above; every value is bound in this single call.
    }


	/**
	 * Check if a key exists in array
	 *
	 * @param string $array	e.g. ['origin_post_author' => $current_user->ID]
	 * @param string $find	Which key are we looking in an array. e.g. 'origin_post_author'
	 *
	 * @return string|bool
	 */
	private function key_in_args( $array, $find ) {
		if ( array_key_exists( $find, $array ) ) {
			$find_value = $array[$find];
		}

		if ( isset( $find_value ) ) {
			return $find_value;
		}

		return false;
	}

	/**
	 * Generate dynamic spacing
	 *
	 * @param int $count Number that later decide the return
	 *
	 * @return string
	 */
	private function heading_spacing( $count ) {
		echo $count > 0 ? ', ' : '';
	}

	protected function get_bulk_actions() {
		$actions = [];

		if (rvy_get_option('revision_archive_deletion')) {
			$actions['delete'] = esc_html__( 'Delete Revision', 'revisionary' );
		}

		return $actions;
	}

	// override default nonce field
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-revision-archive' );
		}
		?>
	<div class="tablenav <?php echo esc_attr( $which ); ?>">

		<?php if ( $this->has_items() ) : ?>
		<div class="alignleft actions bulkactions">
			<?php $this->bulk_actions( $which ); ?>
		</div>
			<?php
		endif;
		$this->extra_tablenav( $which );

		if (!empty($_SERVER['REQUEST_URI'])) {
			$_SERVER['REQUEST_URI'] = str_replace('#038;', '&', esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])));
		}

		$this->pagination( $which );
		?>

		<br class="clear" />
	</div>
		<?php
	}

	/**
	 * Override WP_List_Table::get_columns()
	 */
    public function get_columns() {
        $arr = array(
            'cb'			=> '<label class="screen-reader-text" for="rvy-archive-select-all">' . esc_html__( 'Select all revisions', 'revisionary' ) . '</label><input id="rvy-archive-select-all" type="checkbox" />',
			'post_title' 	=> esc_html__( 'Revision', 'revisionary' ),
			'origin_post_type' 		=> esc_html__( 'Post Type', 'revisionary' ),
			'post_author'	=> esc_html__( 'Revised By', 'revisionary' ),
			'post_modified' 	=> esc_html__( 'Revision Date', 'revisionary' ),
			'publication_method' => esc_html__('Action', 'revisionary'),
			'approved_by'	=> esc_html__('Approved By', 'revisionary'),
			'origin_post_date'		=> esc_html__( 'Published Date', 'revisionary' ),
			'origin_post_author'	=> esc_html__( 'Published Author', 'revisionary' ),
        );

		if (!rvy_get_option('revision_archive_deletion')) {
			unset($arr['cb']);
		}

		if (!$this->show_approved_by_col) {
			unset($arr['approved_by']);
		}

		return $arr;
    }

	/**
	 * Make post datetime friendly
	 *
	 * @return html
	 */
	public function friendly_date( $time, $time_gmt ) {
		$timestamp_gmt 	= strtotime($time_gmt);
		$current_time 	= time();
		$time_diff		= $current_time - $timestamp_gmt;
		
		$timestamp 		= strtotime( $time );
		$date_format 	= sanitize_text_field( get_option( 'date_format' ) );
		$time_format 	= sanitize_text_field( get_option( 'time_format' ) );

		if ( $time_diff < 60 ) {
			$result = esc_html__( 'just now', 'revisionary' );

		} elseif ( $time_diff < 3600 ) {
			$diff = floor( $time_diff / 60 );
			
			$caption = ($diff > 1) ? esc_html__('%s minutes ago', 'revisionary') : esc_html__('%s minute ago', 'revisionary');

			$result = sprintf($caption, $diff);

		} elseif ( $time_diff < 86400 ) {
			$diff = floor( $time_diff / 3600 );
			
			$caption = ($diff > 1) ? esc_html__('%s hours ago', 'revisionary') : esc_html__('%s hour ago', 'revisionary');

			$result = sprintf($caption, $diff);

		} else {
			$result = date_i18n( "$date_format @ $time_format", $timestamp );
		}

		$saved_time = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $timestamp );

		return '<abbr title="' . esc_attr( $saved_time ) . '">' . $result . '</abbr>';
	}

	/**
	 * Override WP_List_Table::column_default()
	 */
    public function column_default( $item, $column_name ) {
        if ($item->ID != $this->post_id) {
			$this->post_id = $item->ID;
			$this->direct_edit = false;
			$this->from_revision_workflow = false;
			$this->parent_in_revision_workflow = false;
			$this->parent_from_revision_workflow = false;

			if (get_post_meta($item->ID, '_rvy_published_gmt', true)) {
				$this->from_revision_workflow = get_post_meta($item->ID, '_rvy_prev_revision_status', true);
				
				if (!$this->from_revision_workflow) {
					$this->from_revision_workflow = true;
				}

			} elseif ($revision_status = rvy_in_revision_workflow($item->post_parent)) {
				$this->parent_in_revision_workflow = $revision_status;
			
			} elseif ($revision_status = rvy_from_revision_workflow($item->post_parent)) {
				$this->parent_from_revision_workflow = $revision_status;
			} else {
				$this->direct_edit = true;
			}
		}
		
		switch ( $column_name ) {
            case 'post_title':
				// Are revisions enabled for the post type of this post parent?
				$post_object 		= get_post( $item->post_parent );
				
				// Show title with link
				printf(
					'<strong><a class="row-title" href="%s" data-label="%s">%s</a></strong>',
					esc_url_raw( rvy_compare_url( $item->ID ) ),
					esc_attr( $item->$column_name ),
					esc_html($item->$column_name)
				);

				break;

			case 'origin_post_type':
				if ('revision' == $item->origin_post_type) {
					$post_type = get_post_field('post_type', $item->post_parent);

					if ('revision' == $post_type) {
						$post_type = get_post_field('post_type', get_post_field('post_parent', $item->post_parent));
					}
				} else {
					$post_type = $item->origin_post_type;
				}

				$type_obj = get_post_type_object($post_type);
				$type_label = (!empty($type_obj) && !empty($type_obj->labels) && !empty($type_obj->labels->singular_name)) ? $type_obj->labels->singular_name : $item->$column_name;

				$this->echo_filter_link(
					$type_label,
					[
						'origin_post_type' => sanitize_key( $post_type )
					]
				);
				break;

			case 'post_modified':
				$url = add_query_arg('revision_date', strtotime(date('Y-m-d', strtotime($item->post_modified))), remove_query_arg('paged', wp_unslash($_SERVER['REQUEST_URI'])));	// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
				return '<a href="' . esc_url($url) . '">' . $this->friendly_date($item->post_modified, $item->post_modified_gmt). '</a>';

				break;

			case 'origin_post_date':
				if ($this->direct_edit) {
					$url = add_query_arg('origin_post_date', strtotime(date('Y-m-d', strtotime($item->post_modified))), remove_query_arg('paged', wp_unslash($_SERVER['REQUEST_URI'])));  // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
					return '<a href="' . esc_url($url) . '">' . $this->friendly_date($item->post_modified, $item->post_modified_gmt). '</a>';

					break;

				} elseif ($this->parent_in_revision_workflow || $this->parent_from_revision_workflow) {
					break;

				} else {
					if (!$published_gmt = get_post_meta($item->ID, '_rvy_published_gmt', true)) {
						$published_gmt = $item->post_date_gmt;
					}

					$url = add_query_arg('origin_post_date', strtotime(date('Y-m-d', strtotime($item->post_modified))), remove_query_arg('paged', wp_unslash($_SERVER['REQUEST_URI'])));  // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
					return '<a href="' . esc_url($url) . '">' . $this->friendly_date(get_date_from_gmt($published_gmt), $published_gmt). '</a>';
				}

				break;

			case 'origin_post_author':
				$this->echo_filter_link(
					get_the_author_meta( 'display_name', $item->$column_name ),
					[
						'origin_post_author' => (int) $item->$column_name
					]
				);
				break;

			case 'post_author':
				$this->echo_filter_link(
					get_the_author_meta( 'display_name', $item->$column_name ),
					[
						'post_author' => (int) $item->$column_name
					]
				);

				break;

			case 'publication_method':
				if ($this->from_revision_workflow) {
					// from_revision_workflow may be boolean true, which a loose switch comparison would match to 'future-revision'
					if ('future-revision' === $this->from_revision_workflow) {
						esc_html_e('Scheduled Revision Publication', 'revisionary');
					} else {
						esc_html_e('Revision Publication', 'revisionary');
					}
				} elseif ($this->parent_in_revision_workflow) {
					if ($status_obj = get_post_status_object($this->parent_in_revision_workflow)) {
						$status_label = $status_obj->label;
					} else {
						$status_label = $status_name;
					}

					if (0 === strpos($item->post_name, $item->post_parent . '-autosave')) {
						printf(
							esc_html__('Autosave of %s', 'revisionary'),
							"<span title='" . esc_attr($this->active_revision_title) . "'>" . esc_html($status_label) . '</span>'
						);
					} else {
					printf(
						esc_html__('Edit of %s', 'revisionary'),
						"<span title='" . esc_attr($this->active_revision_title) . "'>" . esc_html($status_label) . '</span>'
					);
					}

				} elseif ($this->parent_from_revision_workflow) {
					if (0 === strpos($item->post_name, $post->post_parent . '-autosave')) {
						printf("<span title='%s'>%s</span>",
							esc_html($this->from_revision_title),
							esc_html__('Autosave of published Revision', 'revisionary')
						);
					} else {
					printf("<span title='%s'>%s</span>",
						esc_html($this->from_revision_title),
						esc_html__('Edit of published Revision', 'revisionary')
					);
					}
				} elseif ($this->direct_edit) {
					if (0 === strpos($item->post_name, $item->post_parent . '-autosave')) {
						esc_html_e('Autosave', 'revisionary');
					} else {
					esc_html_e('Direct Edit', 'revisionary');
				}
				}

				break;

			case 'approved_by':
				if ($this->direct_edit) {
					$approver_id = $item->post_author;

				} elseif ($this->from_revision_workflow) {
					$approver_id = get_post_meta($item->ID, '_rvy_approved_by', true);
				}

				if (!empty($approver_id)) {
					echo esc_html(get_the_author_meta('display_name', $approver_id));
				}

				break;

			case 'post_count':
				$this->echo_filter_link(
					(int) $item->$column_name,
					[
						'post_parent' => (int) $item->post_parent
					]
				);
				break;

			default:
                return;
        }
    }

	/**
	 * Override WP_List_Table::column_cb()
	 */
    public function column_cb( $item ) {
        $parent = get_post($item->post_parent);
        $type_obj = $parent ? get_post_type_object($parent->post_type) : null;
        if (!$type_obj || !current_user_can($type_obj->cap->delete_post, $parent->ID)) {
            return '';
        }
        return sprintf(
            '<input type="checkbox" name="post[]" value="%s" aria-label="%s" />', $item->ID, esc_attr__( 'Select revision', 'revisionary' )
        );
    }

	/**
	 * Override WP_List_Table::extra_tablenav()
	 */
	protected function extra_tablenav( $which ) {
		global $wpdb;

		?>
		<div class="alignleft actions">
		<?php
		if ( 'top' === $which ) {
            $readable_parent_csv = implode(',', $this->readableParentIds()) ?: '0';
            $readable_revision_csv = implode(',', $this->readable_revision_ids) ?: '0';
            $revision_scope = "ID IN ($readable_revision_csv)";
			$current_option = isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( $_REQUEST['origin_post_type'] )															//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
			?>
			<label class="screen-reader-text" for="rvy-archive-origin-post-type"><?php esc_html_e( 'Filter by original post type', 'revisionary' ); ?></label>
			<select name="origin_post_type" id="rvy-archive-origin-post-type" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Post Types', 'revisionary' ) ?>
				</option>
				<?php foreach( $this->post_types as $type ) :
					$type_obj = get_post_type_object( $type );
					?>
					<option <?php echo $current_option === $type ? 'selected' : '' ?>
						value="<?php echo esc_attr($type) ?>">
						<?php if ((!empty($type_obj) && !empty($type_obj->labels) && !empty($type_obj->labels->singular_name))) echo esc_html($type_obj->labels->singular_name); else echo esc_html(ucwords(str_replace('_', ' ', $type))); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php
			$current_option = isset( $_REQUEST['post_author'] ) && ! empty( $_REQUEST['post_author'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? intval( $_REQUEST['post_author'] )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

			$author_ids = $wpdb->get_col("SELECT DISTINCT post_author FROM $wpdb->posts WHERE $revision_scope"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.
			
			$id_csv = implode( "','", array_map('intval', $author_ids));
			$_users = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users WHERE ID IN ('" . $id_csv . "')"); 	 // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			$authors = [];

			foreach ($_users as $row) {
				$authors[$row->ID] = $row->display_name;
			}

            if ($current_option && !isset($authors[$current_option])) { $authors[$current_option] = '#' . (int) $current_option; }
			asort($authors, SORT_STRING | SORT_FLAG_CASE);
			?>
			<label class="screen-reader-text" for="rvy-archive-post-author"><?php esc_html_e( 'Filter by revision author', 'revisionary' ); ?></label>
			<select name="post_author" id="rvy-archive-post-author" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Revision Authors', 'revisionary' ) ?>
				</option>
				<?php foreach( $authors as $user_id => $display_name ) :
					?>
					<option <?php echo $current_option === $user_id ? 'selected' : '' ?>
						value="<?php echo esc_attr($user_id) ?>">
						<?php 
							if ($user = get_userdata($user_id)) {
							echo esc_html($display_name);
						} 
						?>
					</option>
				<?php endforeach; ?>
			</select>

			
			<?php
			$current_option = isset( $_REQUEST['revision_date'] ) && ! empty( $_REQUEST['revision_date'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? intval($_REQUEST['revision_date'])															//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

			$enabled_post_type_csv = implode("','", array_map('sanitize_key', $this->post_types));

			$_dates = $enabled_post_type_csv
				? $wpdb->get_col(																			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT DISTINCT DATE(r.post_modified)
					FROM $wpdb->posts r
					INNER JOIN $wpdb->posts p ON r.post_parent = p.ID
					LEFT JOIN $wpdb->posts p2 ON p.post_type = 'revision' AND p.post_parent = p2.ID
					WHERE r.post_type = 'revision'
					AND r.post_status NOT IN ('trash', 'auto-draft')
					AND r.post_name NOT LIKE '%-autosave-v%'"
					. " AND (p.post_type IN ('$enabled_post_type_csv') OR (p.post_type = 'revision' AND p2.post_type IN ('$enabled_post_type_csv')))"	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					. " AND r.ID IN ($readable_revision_csv) ORDER BY r.ID DESC LIMIT 60"																									 // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.
				)
				: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			
			$post_dates = [];

			foreach ($_dates as $date_str) {
				$post_dates[strtotime($date_str)] = $date_str;
			}

            if ($current_option) { $post_dates[(int) $current_option] = gmdate('Y-m-d', (int) $current_option); }
			arsort($post_dates);
			?>
			<label class="screen-reader-text" for="rvy-archive-revision-date"><?php esc_html_e( 'Filter by revision date', 'revisionary' ); ?></label>
			<select name="revision_date" id="rvy-archive-revision-date" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Revision Dates', 'revisionary' ) ?>
				</option>
				<?php 
					foreach($post_dates as $date => $date_str) :
					?>
					<option <?php echo $current_option === $date ? 'selected' : '' ?>
						value="<?php echo esc_attr($date) ?>">
						<?php 
							echo esc_html($date_str);
						?>
					</option>
				<?php endforeach; 
				?>
			</select>

			<?php
			$current_option = isset( $_REQUEST['origin_post_date'] ) && ! empty( $_REQUEST['origin_post_date'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? intval($_REQUEST['origin_post_date'])																	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

			$_dates = $wpdb->get_col("SELECT DISTINCT DATE(post_date) FROM $wpdb->posts WHERE ID IN ($readable_parent_csv) AND post_status IN ('publish', 'private') ORDER BY ID DESC LIMIT 60"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.
			
			$post_dates = [];

			foreach ($_dates as $date_str) {
				$post_dates[strtotime($date_str)] = $date_str;
			}

			$_dates = $wpdb->get_col("SELECT DISTINCT DATE(post_modified) FROM $wpdb->posts WHERE ID IN ($readable_parent_csv) AND post_status IN ('publish', 'private') ORDER BY ID DESC LIMIT 60"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

			foreach ($_dates as $date_str) {
				$post_dates[strtotime($date_str)] = $date_str;
			}

			$gmt_offset = get_option('gmt_offset');

			if ($gmt_offset > 0) {
				$revision_published_clause = $wpdb->prepare("DATE_ADD(meta_value, INTERVAL %d HOUR)", $gmt_offset);

			} elseif ($gmt_offset < 0) {
				$revision_published_clause = $wpdb->prepare("DATE_SUB(meta_value, INTERVAL %d HOUR)", abs($gmt_offset));
			} else {
				$revision_published_clause = "meta_value";
			}

			$_dates = $wpdb->get_col("SELECT DISTINCT DATE($revision_published_clause) FROM $wpdb->postmeta WHERE meta_key = '_rvy_published_gmt' AND post_id IN (SELECT ID FROM $wpdb->posts WHERE $revision_scope) ORDER BY meta_id DESC LIMIT 60");				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ($_dates as $date_str) {
				$post_dates[strtotime($date_str)] = $date_str;
			}

            if ($current_option) { $post_dates[(int) $current_option] = gmdate('Y-m-d', (int) $current_option); }
			arsort($post_dates);

			$post_dates = array_slice($post_dates, 0, 30, true);
            if ($current_option) { $post_dates[(int) $current_option] = gmdate('Y-m-d', (int) $current_option); }
			?>
			<label class="screen-reader-text" for="rvy-archive-origin-post-date"><?php esc_html_e( 'Filter by published date', 'revisionary' ); ?></label>
			<select name="origin_post_date" id="rvy-archive-origin-post-date" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Publish Dates', 'revisionary' ) ?>
				</option>
				<?php foreach($post_dates as $date => $date_str) :
					if ($date < 0) continue;

					?>
					<option <?php echo $current_option === $date ? 'selected' : '' ?>
						value="<?php echo esc_attr($date) ?>">
						<?php 
							echo esc_html($date_str);
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php
			$current_option = isset( $_REQUEST['approved_by'] ) && ! empty( $_REQUEST['approved_by'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? intval( $_REQUEST['approved_by'] )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

			$_approver_ids = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_rvy_approved_by' AND post_id IN (SELECT ID FROM $wpdb->posts WHERE $revision_scope)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

			$id_csv = implode( "','", array_map('intval', $_approver_ids));
			$_users = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users WHERE ID IN ('" . $id_csv . "')");			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

			$approvers = [];

			foreach ($_users as $row) {
				$approvers[$row->ID] = $row->display_name;
			}

            if ($current_option && !isset($approvers[$current_option])) { $approvers[$current_option] = '#' . (int) $current_option; }
			asort($approvers, SORT_STRING | SORT_FLAG_CASE);
			?>
			<label class="screen-reader-text" for="rvy-archive-approved-by"><?php esc_html_e( 'Filter by approver', 'revisionary' ); ?></label>
			<select name="approved_by" id="rvy-archive-approved-by" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Approvers', 'revisionary' ) ?>
				</option>
				<?php foreach( $approvers as $user_id => $display_name ) :
					?>
					<option <?php echo $current_option === $user_id ? 'selected' : '' ?>
						value="<?php echo esc_attr($user_id) ?>">
						<?php 
							if ($user = get_userdata($user_id)) {
							echo esc_html($display_name);
						} 
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php

			$current_option = isset( $_REQUEST['origin_post_author'] ) && ! empty( $_REQUEST['origin_post_author'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? intval( $_REQUEST['origin_post_author'] )																	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

			$author_ids = $wpdb->get_col("SELECT DISTINCT post_author FROM $wpdb->posts WHERE ID IN ($readable_parent_csv)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope contains only integer authorized revision or parent IDs; facet queries require current values.

			$id_csv = implode( "','", array_map('intval', $author_ids));
			$_users = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users WHERE ID IN ('" . $id_csv . "')");   // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

			$authors = [];

			foreach ($_users as $row) {
				$authors[$row->ID] = $row->display_name;
			}

            if ($current_option && !isset($authors[$current_option])) { $authors[$current_option] = '#' . (int) $current_option; }
			asort($authors, SORT_STRING | SORT_FLAG_CASE);
			?>
			<label class="screen-reader-text" for="rvy-archive-origin-post-author"><?php esc_html_e( 'Filter by published author', 'revisionary' ); ?></label>
			<select name="origin_post_author" id="rvy-archive-origin-post-author" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All Authors', 'revisionary' ) ?>
				</option>
				<?php foreach( $authors as $user_id => $display_name ) :
					?>
					<option <?php echo $current_option === $user_id ? 'selected' : '' ?>
						value="<?php echo esc_attr($user_id) ?>">
						<?php 
							if ($user = get_userdata($user_id)) {
							echo esc_html($display_name);
						} 
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php

            echo '<input type="hidden" name="paged" value="1" />';
			submit_button( esc_html__( 'Filter', 'revisionary' ), '', 'filter_action', false, array( 'id' => 'post-query-submit' ) );

			if( count( $_REQUEST ) > 1 ) :		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>
				<a href="<?php echo esc_url_raw(add_query_arg( ['page' => 'revisionary-archive'], admin_url( 'admin.php' ) ) ) ?>"
					class="button">
					<?php esc_html_e( 'Reset Filters', 'revisionary' ) ?>
				</a>
				<?php
			endif;
		}
		?>
		</div>
		<?php
	}

	/**
	 * Override WP_List_Table::get_sortable_columns()
	 */
	protected function get_sortable_columns() {
		return [
			'post_modified' 	=> 'post_modified',
			'post_count' 	=> 'post_count',
		];
	}

	/**
	 * Override WP_List_Table::get_views()
	 */
    protected function get_views() {
        global $current_user;
        echo '<ul class="subsubsub"><li>';
        $this->echo_filter_link(esc_html__('All', 'revisionary'), ['v' => 'all'], null, false);
        echo '</li> | <li class="mine">';
        $this->echo_filter_link(esc_html__('My Revisions', 'revisionary'), ['post_author' => (int) $current_user->ID, 'v' => 'mine'], null, false);
        echo '</li></ul>';
    }

    /** Candidate-page navigation avoids global counts that need a full permission scan. */
    protected function pagination($which) {
        if (1 === $this->archive_page && !$this->archive_has_more) {
            return;
        }
        $url = add_query_arg('page', 'revisionary-archive', admin_url('admin.php'));
        // Preserve only supported archive filters and sort parameters.
        foreach (['s', 'origin_post', 'origin_post_type', 'post_parent', 'post_author', 'origin_post_author', 'approved_by', 'revision_date', 'origin_post_date', 'orderby', 'order', 'v'] as $key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; row capabilities are checked on every request.
            if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitize navigation input, never use it as authorization.
                $url = add_query_arg($key, sanitize_text_field(wp_unslash($_REQUEST[$key])), $url);
            }
        }
        echo '<div class="tablenav-pages"><span class="pagination-links">';
        if ($this->archive_page > 1) {
            echo '<a class="button prev-page" href="' . esc_url(add_query_arg('paged', $this->archive_page - 1, $url)) . '">' . esc_html__('Previous page', 'revisionary') . '</a> ';
        }
        /* translators: %d: current archive page number. */
        echo '<span class="paging-input">' . esc_html(sprintf(__('Page %d', 'revisionary'), $this->archive_page)) . '</span>';
        if ($this->archive_has_more) {
            echo ' <a class="button next-page" href="' . esc_url(add_query_arg('paged', $this->archive_page + 1, $url)) . '">' . esc_html__('Next page', 'revisionary') . '</a>';
        }
        echo '</span></div>';
    }

	/**
	 * Override WP_List_Table::handle_row_actions()
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		static $is_administrator;

		if (!isset($is_administrator)) {
			$is_administrator = is_content_administrator_rvy();
		}

		$post_status_obj = get_post_status_object(get_post_field('post_status', $item->post_parent));
		
		$can_edit_parent = current_user_can('edit_post', $item->post_parent);

		$actions 			= [];
		$can_read_post		= !empty($post_status_obj) && ($can_edit_parent || current_user_can( 'read_post', $item->ID ) || current_user_can( 'read_post', $item->ID ));
		$can_edit_post		= $is_administrator || (!empty($post_status_obj && $can_edit_parent));
		
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		//$can_delete_post	= current_user_can( 'delete_post', $item->ID );

		$post_type_object 	= get_post_type_object( $item->origin_post_type );
		$post_object 		= get_post( $item->post_parent );
		$revisions_enabled	= true;

		if (empty($post_type_object->name)) {
			return;
		}

		if ( ( $can_read_post || $can_edit_post ) && $revisions_enabled ) {
			if (rvy_use_visual_compare()) {
				$actions['compare'] = sprintf(
				'<a href="%1$s" class="" title="%2$s" aria-label="%2$s" target="_revision_diff">%3$s</a>',
				rvy_compare_url($item->ID),
				esc_attr(
					sprintf(
							esc_html__( 'Visually compare changes in %s', 'revisionary' ),
						$item->post_title
					)
				),
				_x( 'Compare', 'revisions', 'revisionary' )
				);
			}

			if (defined('REVISIONARY_TEXT_DIFF_POPUP')) {
				$diff_url = get_edit_post_link( $item->ID ) . '&width=900&height=600&rvy-popup=true&TB_iframe=1';
				$diff_class = 'rvy-open-popup';
			} else {
				$diff_url = get_edit_post_link( $item->ID );
				$diff_class = '';
			}

			$actions['diff'] = sprintf(
				'<a href="%s" class="' . esc_attr($diff_class) . '" title="%2$s" aria-label="%2$s" data-label="%3$s">%4$s</a>',
				!empty($diff_url) ? esc_url_raw( $diff_url ) : '',
				esc_attr(
					sprintf(
						esc_html__( 'Classic text diff for %s', 'revisionary' ),
						$item->post_title
					)
				),
				esc_html__($item->post_title),
				_x( 'Diff', 'revisions', 'revisionary' )
			);
		}

		if ( is_post_type_viewable( $post_type_object ) || ('revision' == $post_type_object->name) ) {
			if ( $can_read_post && $post_type_object && (! empty( $post_type_object->public || ('revision' == $post_type_object->name) ) ) ) {
				if ( rvy_get_option( 'revision_preview_links' ) || $is_administrator ) {
					do_action('pp_revisions_get_post_link', $item->ID);

					$preview_link = rvy_preview_url( $item );

					$preview_link = remove_query_arg( 'preview_id', $preview_link );
					$actions['view'] = sprintf(
						'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
						esc_url( $preview_link ),
						esc_attr__( 'Preview Revision', 'revisionary' ),
						esc_html_x( 'Preview', 'verb', 'revisionary' )
					);

					do_action('pp_revisions_post_link_done', $item->ID);
				}
			}
		}

		if ($can_edit_parent) {
			if ($edit_link = get_edit_post_link( $item->post_parent )) {
				$actions['edit_parent'] = sprintf(
					'<a href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
					$edit_link,
					(rvy_in_revision_workflow($item->post_parent)) ? esc_attr__('Edit parent revision', 'revisionary') : esc_attr__('Edit parent post', 'revisionary'),
					str_replace(' ', '&nbsp;', esc_html__( 'Edit Parent', 'revisionary' ))
				);
			}
		}

		$uri = (isset($_SERVER['REQUEST_URI'])) ? add_query_arg($_REQUEST, esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))) : '';	// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$post_filter_url = add_query_arg(
			'origin_post',
			$item->post_parent,
			untrailingslashit( site_url( '' ) ) . $uri
		);
		$post_filter_url = remove_query_arg( 's', $post_filter_url );

		$actions['post_filter'] = sprintf(
			'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
			esc_url( $post_filter_url ),
			esc_attr__( 'Filter: list revisions of this post', 'revisionary' ),
			esc_html__( 'Filter', 'revisionary' )
		);

		$parent = get_post($item->post_parent);
        $type_obj = $parent ? get_post_type_object($parent->post_type) : null;
		if ($type_obj && current_user_can($type_obj->cap->delete_post, $parent->ID) && rvy_get_option('revision_archive_deletion')) {
			$delete_link = esc_url(wp_nonce_url(
				"admin.php?page=rvy-revisions&amp;action=delete&amp;revision={$item->ID}", 
				'delete-revision_' . $item->ID 
			));

			$actions['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete" title="%2$s" aria-label="%2$s">%3$s</a>',
				$delete_link,
				esc_html__( 'Delete Past Revision', 'revisionary' ),
				esc_html__( 'Delete', 'revisionary' )
			);
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Generate all the hidden input fields to use as filters in database
	 *
	 * @return html
	 */
	public function hidden_input() {
		?>
		<input type="hidden" name="page" value="revisionary-archive" />
		<?php
		$this->single_hidden_input( 'origin_post_type' );
		$this->single_hidden_input( 'origin_post_author', true );
		$this->single_hidden_input( 'post_author', true );
		$this->single_hidden_input( 'post_parent', true );
	}

	/**
	 * Generate hidden input fields to use as filters in database
	 *
	 * @param string $field	The field name from database query
	 * @param bool $integer	The field is a number?
	 *
	 * @return html
	 */
	public function single_hidden_input( $field, $integer = false ) {
		if( isset( $_REQUEST[$field] ) && ! empty( $_REQUEST[$field] ) ) :				  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<input type="hidden"
				name="<?php echo esc_attr($field) ?>" 
				value="<?php if ($integer) echo (int) $_REQUEST[$field]; else echo esc_attr(sanitize_key( $_REQUEST[$field])); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>" />
			<?php
		endif;
	}

	/**
	 * Generate a link with filter params
	 *
	 * @param string $label		The label to display
	 * @param array $args		URL filter parameters for the generated link
	 * @param integer $count	Number of records to display
	 * @param bool $url_args	Include parameters from URL?
	 *							e.g. from: admin.php?origin_post_type=post&page=revisionary-archive&v=all
	 *							take origin_post_type value
	 *
	 * @return html
	 */
	public function echo_filter_link( $label, $args, $count = null, $url_args = true ) {
		foreach (['origin_post', 'origin_post_type', 'post_author', 'origin_post_author'] as $var) {
			// Include origin_post_type filter if enabled and exists
			if( $url_args && isset( $_REQUEST[$var] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args = array_merge(
					[
						$var => sanitize_key( $_REQUEST[$var] )							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					],
					$args
				);
			}
		}

		// Check if $args['v'] exists and is current page
		$v = '';
		if( array_key_exists( 'v', $args )
			&& isset( $_REQUEST['v'] )													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& sanitize_key( $_REQUEST['v'] ) === $args['v']							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			$v = 'current';
		}

		echo '<a href="' . esc_url(add_query_arg( $args, admin_url( 'admin.php?page=revisionary-archive' ) ) ) . '" class="' . esc_attr($v) . '"' . ( $v ? ' aria-current="page"' : '' ) . '>'
		. $label;																		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if (true == $count) echo ' <span class="count">(' . esc_html($count) . ')</span>';
		
		echo '</a>';
	}

	/**
	 * Override WP_List_Table::no_items()
	 */
	public function no_items() {
        if ($this->archive_has_more) {
            esc_html_e('No accessible revisions on this page. Continue to the next page or adjust the filters.', 'revisionary');
        } else {
            esc_html_e('No revisions found.', 'revisionary');
        }
	}
}

