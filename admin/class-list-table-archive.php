Warning: truncated output (original token count: 15241)
Total output lines: 1457

<?php
defined('ABSPATH') || exit;

require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

class Revisionary_Archive_List_Table extends WP_List_Table {
	private $post_types = [];
    private $readable_parent_ids = null;
    private $readable_revision_ids = [];
    private $readable_authors = null;
	private $all_revisions_count = null;
	private $my_revisions_count = null;
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

    /** Scope rows, counts and facets to revisions and parents this viewer can read. */
    private function readableParentIds() {
        if (null !== $this->readable_parent_ids) {
            return $this->readable_parent_ids;
        }
        global $wpdb;
        $this->readable_parent_ids = [];
        $types = array_values(array_filter($this->post_types, static function ($type) {
            return is_string($type) && '' !== $type && strlen($type) <= 20 && sanitize_key($type) === $type;
        }));
        if (!$types) { return []; }
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        // Filter unrelated post types and autosaves before object loading or
        // authorization. Keep exact revision checks for delegated permissions.
        $candidate_sql = "SELECT r.ID FROM $wpdb->posts r INNER JOIN $wpdb->posts p ON p.ID = r.post_parent
             LEFT JOIN $wpdb->posts p2 ON p.post_type = 'revision' AND p2.ID = p.post_parent
             WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s
             AND (p.post_type IN ($placeholders) OR (p.post_type = 'revision' AND p2.post_type IN ($placeholders)))"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder tokens are generated from the count of validated post types; only values are passed to prepare().
        $after = 0;
        do {
            // Prime bounded batches; do not share authorization results across requests.
            $ids = $wpdb->get_col($wpdb->prepare(
                $candidate_sql . ' AND r.ID > %d ORDER BY r.ID LIMIT 250',
                array_merge(['%-autosave-v%'], $types, $types, [$after])
            ));
            if (function_exists('_prime_post_caches')) {
                _prime_post_caches(array_map('intval', $ids), false, false);
            }
            foreach ($ids as $id) {
                $id = (int) $id;
                $after = max($after, $id);
                $revision = get_post($id);
                $parent = $revision ? get_post($revision->post_parent) : null;
                if ($parent && in_array($parent->post_type, array_merge($this->post_types, ['revision']), true)
                    && rvy_can_read_revision_and_parent($id)
                    && rvy_can_read_revision_and_parent($parent->ID)) {
                    $this->readable_parent_ids[$parent->ID] = (int) $parent->ID;
                    $this->readable_revision_ids[] = $id;
                }
            }
        } while (count($ids) === 250);
        return $this->readable_parent_ids;
    }

	/**
	 * Override WP_List_Table::prepare_items()
	 */
    public function prepare_items() {
		global $wpdb, $per_page, $current_user;
        $heading_authors = $this->readableAuthors();

		$per_page 		= $this->get_items_per_page( 'revision_archive_per_page' );						//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$paged 			= isset( $_REQUEST['paged'] ) ? max( 0, intval( $_REQUEST['paged'] ) - 1 ) : 0;	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset 		= $paged * $per_page;
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
		if( !empty($_REQUEST['origin_post_author']) && in_array((int) $_REQUEST['origin_post_author'], $heading_authors['origin_post_author'], true) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_author'] = (int) $_REQUEST['origin_post_author'];						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( !empty($_REQUEST['post_author']) && in_array((int) $_REQUEST['post_author'], $heading_authors['post_author'], true) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		if( !empty($_REQUEST['approved_by']) && in_array((int) $_REQUEST['approved_by'], $heading_authors['approved_by'], true) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['approved_by'] = (int) $_REQUEST['approved_by'];						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$base_query = $this->do_query( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"{$base_query} LIMIT %d,%d",															// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$offset,
				$per_page
			)
		);

		unset($args['orderby']);
		unset($args['order']);
		$base_query = $this->do_query( $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_items = $wpdb->get_var(
			"SELECT COUNT(*) as total_items FROM ($base_query) as total_items_subquery"					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ] );

		$this->items = $results;

		// @todo: determine if any items have an approved_by postmeta row
		$post_id_csv = implode("','", wp_list_pluck($results, 'ID'));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->show_approved_by_col = $wpdb->get_var(
			"SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_approved_by' AND meta_value > 0 AND post_id IN ('$post_id_csv') LIMIT 1"  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// 'All Revisions' link with count

		$base_query = $this->do_query();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->all_revisions_count = $wpdb->get_var(
			"SELECT COUNT(*) as all_items FROM ($base_query) as all_items_subquery"						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// 'My Revisions' link with count

		$base_query = $this->do_query( [
			'post_author' => $current_user->ID
		] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->my_revisions_count = $wpdb->get_var(
			"SELECT COUNT(*) as my_items FROM ($base_query) as my_items_subquery"						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
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
			// Display-only GET filter; it does not change data or perform an action.
			$origin_post_type = isset($_REQUEST['origin_post_type']) && is_string($_REQUEST['origin_post_type'])
				? sanitize_key(wp_unslash($_REQUEST['origin_post_type'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive heading context.
			if ($origin_post_type && in_array($origin_post_type, $this->post_types, true)) {
				$obj = get_post_type_object($origin_post_type);
				$this->heading_spacing( $count );
				if ((!empty($obj) && !empty($obj->labels) && !empty($obj->labels->name))) echo esc_html($obj->labels->name); else echo esc_html(ucwords(str_replace('_', ' ', $origin_post_type)));
				$count++;
			}
		}

		// Revision post author
		if( !empty($_REQUEST['post_author']) && in_array((int) $_REQUEST['post_author'], $heading_authors['post_author'], true) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		if( !empty($_REQUEST['origin_post_author']) && in_array((int) $_REQUEST['origin_post_author'], $heading_authors['origin_post_author'], true) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		if( !empty($_REQUEST['approved_by']) && in_array((int) $_REQUEST['approved_by'], $heading_authors['approved_by'], true) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

	/**
	 * Generate count query database SELECT
	 *
	 * @param string $alias	A string to differentiate the query for debugging purposes
	 * @param string  $base	The do_query() query to count records from
	 *
	 * @return string
	 */
	private function count_query( $alias, $base ) {
		return "SELECT COUNT(*) as {$alias} FROM ($base) as {$alias}_subquery";
	}

	/**
	 * Build database query select to retrieve data to display later in table
	 *
	 * @param array $args	The database field to order by (can be an alias from the query)
	 *
	 * @return string
	 */
	private function do_query( $args = [] ) {
		global $wpdb;

		$orderby 	= array_key_exists( 'orderby', $args ) ? $args['orderby'] : '';
		$order 		= array_key_exists( 'order', $args ) ? $args['order'] : 'DESC';

		$query = "SELECT
			r.ID AS ID,
			r.post_type AS post_type,
			r.post_status AS post_status,
			r.post_title AS po…5241 tokens truncated…ctedVariables.user_meta__wpdb__users, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			$authors = [];

			foreach ($_users as $row) {
				$authors[$row->ID] = $row->display_name;
			}

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

			arsort($post_dates);

			$post_dates = array_slice($post_dates, 0, 30, true);
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
		?>
		<ul class="subsubsub">
			<?php if( $this->all_revisions_count ) : ?>
				<li>
					<?php
					$this->echo_filter_link(
						__( 'All', 'revisionary' ),
						[
							'v' => 'all'
						],
						$this->all_revisions_count,
						false
					);
					?>
				</li>
			<?php endif; ?>
			<?php if( $this->my_revisions_count ) : ?>
				<?php if( $this->all_revisions_count ) : ?> | <?php endif;?><li class="mine">
					<?php
					$this->echo_filter_link(
						__( 'My Revisions', 'revisionary' ),
						[
							'post_author' => $current_user->ID,
							'v' => 'mine'
						],
						$this->my_revisions_count,
						false
					);
					?>
				</li>
			<?php endif; ?>
		</ul>
		<?php
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
		esc_html_e( 'No revisions found.', 'revisionary' );
	}
}
