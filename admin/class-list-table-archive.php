<?php
require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

class Revisionary_Archive_List_Table extends WP_List_Table {
	private $post_types = [];
	private $all_revisions_count = null;
	private $my_revisions_count = null;
	private $show_approved_by_col = true;

	private $post_id = 0;
	private $direct_edit = false;
	private $revision_published_gmt = '';
	private $from_revision_workflow = false;
	private $parent_in_revision_workflow = false;
	private $parent_from_revision_workflow = false;

	private $active_revision_title;
	private $from_revision_title;

	public function __construct( $args ) {
		global $revisionary;

		$this->active_revision_title = __('This was an update to a revision which is still in the workflow process.', 'revisionary');

		$this->from_revision_title = __('This was an update to a revision which was published after further editing.', 'revisionary');

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

	/**
	 * Override WP_List_Table::prepare_items()
	 */
    public function prepare_items() {
		global $wpdb, $per_page, $current_user;

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
					'post_count'
				]
			)
			? sanitize_key( $_REQUEST['orderby'] )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'post_date';
																										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && ! empty( $_REQUEST['order'] ) && in_array( $_REQUEST['order'], ['asc', 'desc'] )
			? strtoupper(sanitize_key($_REQUEST['order']))												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'DESC';

		// Filters
		$args = [
			'orderby' 	=> $orderby,
			'order'		=> $order
		];
		if( isset( $_REQUEST['s'] ) && ! empty( trim( sanitize_text_field($_REQUEST['s']) ) ) ) {							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['s'] = strtolower( sanitize_text_field( trim( sanitize_text_field($_REQUEST['s']) ) ) );					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] ) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post'] = (int) $_REQUEST['origin_post'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post_author'] ) && ! empty( $_REQUEST['origin_post_author'] ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_author'] = (int) $_REQUEST['origin_post_author'];						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['post_author'] ) && ! empty( $_REQUEST['post_author'] ) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['post_author'] = (int) $_REQUEST['post_author'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] ) ) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['origin_post_type'] = sanitize_text_field( $_REQUEST['origin_post_type'] );			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if( isset( $_REQUEST['post_parent'] ) && ! empty( $_REQUEST['post_parent'] ) ) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['post_parent'] = (int) $_REQUEST['post_parent'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	public function filters_in_heading() {
		$count = 0;

		$any_filters = ( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] )		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] ) )		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['post_author'] ) && ! empty( $_REQUEST['post_author'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['post_parent'] ) && ! empty( $_REQUEST['post_parent'] ) )					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| ( isset( $_REQUEST['origin_post_author'] ) && ! empty( $_REQUEST['origin_post_author'] ) )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		if ($any_filters) {
			echo ' (';
		}

		// Post title
		if( isset( $_REQUEST['origin_post'] ) && ! empty( $_REQUEST['origin_post'] )				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			if ($post_title = get_post_field('post_title', (int) $_REQUEST['origin_post'])) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->heading_spacing( $count );
				echo esc_html($post_title);
				$count++;
			}
		} else {
			// Post type
			if( isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& in_array( $_REQUEST['origin_post_type'], $this->post_types )						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				$obj = get_post_type_object( sanitize_key( $_REQUEST['origin_post_type'] ) );		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->heading_spacing( $count );
				echo esc_html($obj->labels->name);
				$count++;
			}
		}

		// Revision post author
		if( isset( $_REQUEST['post_author'] ) && ! empty( $_REQUEST['post_author'] ) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Revision Author: %s' ,'revisionary' ),
				get_the_author_meta( 'display_name', (int) $_REQUEST['post_author'] )				//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			);
			$count++;
		}

		// Revision post parent
		if( isset( $_REQUEST['post_parent'] ) && ! empty( $_REQUEST['post_parent'] ) ) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			echo '"';
			the_title( (int) $_REQUEST['post_parent'] );											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '"';				
			$count++;
		}

		// Origin post author
		if( isset( $_REQUEST['origin_post_author'] ) && ! empty( $_REQUEST['origin_post_author'] ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->heading_spacing( $count );
			printf(
				esc_html__( 'Post Author: %s' ,'revisionary' ),
				get_the_author_meta( 'display_name', (int) $_REQUEST['origin_post_author'] )		//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
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
		if( isset( $_REQUEST['s'] ) && ! empty( trim( sanitize_text_field($_REQUEST['s']) ) ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<span class="subtitle">';
			
			printf(
				esc_html__( 'Search results for "%s"', 'revisionary' ),
				esc_html(strtolower(
					trim(
						sanitize_text_field( $_REQUEST['s'] )										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		$orderby 	= array_key_exists( 'orderby', $args ) ? $args['orderby'] : 'post_date';
		$order 		= array_key_exists( 'order', $args ) ? $args['order'] : 'DESC';

		$query = "SELECT
			r.ID AS ID,
			r.post_type AS post_type,
			r.post_status AS post_status,
			r.post_title AS post_title,
			r.post_date AS post_date,
			r.post_date_gmt as post_date_gmt,
			r.post_modified as post_modified,
			r.post_modified_gmt as post_modified_gmt,
			r.post_author AS post_author,
			r.post_parent AS post_parent,
			(
				SELECT COUNT(*)
				FROM $wpdb->posts p3
				WHERE p3.post_parent = r.post_parent
				AND p3.post_type = 'revision'
			) AS post_count,
			(
				SELECT p2.post_author
				FROM $wpdb->posts p2
				WHERE p2.ID = r.post_parent
				ORDER BY p2.ID DESC
				LIMIT 0,1
			) AS origin_post_author,			
			(
				SELECT p2.post_date
				FROM $wpdb->posts p2
				WHERE p2.ID = r.post_parent
				ORDER BY p2.ID DESC
				LIMIT 0,1
			) AS origin_post_date,
			(
				SELECT p2.post_date_gmt
				FROM $wpdb->posts p2
				WHERE p2.ID = r.post_parent
				ORDER BY p2.ID DESC
				LIMIT 0,1
			) AS origin_post_date_gmt,
			(
				SELECT p2.post_type
				FROM $wpdb->posts p2
				WHERE p2.ID = r.post_parent
				ORDER BY p2.ID DESC
				LIMIT 0,1
			) AS origin_post_type
		FROM $wpdb->posts r
		LEFT JOIN $wpdb->posts r3 ON r.post_parent = r3.ID
		WHERE r.post_type = 'revision' AND r.post_name NOT LIKE '%-autosave-v%'";

		// Only when Search input is valid
		if( isset( $args['s'] ) ) {
			$query .= $wpdb->prepare(
				" AND LOWER(r.post_title) LIKE '%s'",
				'%' . $wpdb->esc_like( $args['s'] ) . '%'
			);
		}

		$count = 0;

		// Filter by origin_post_author
		if( isset( $args['origin_post'] ) ) {
			$query .= $wpdb->prepare(
				"{$this->having_and( $count )} post_parent = %d",						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args['origin_post']
			);
			$count++;
		}

		// Filter by origin_post_author
		if( isset( $args['origin_post_author'] ) ) {
			$query .= $wpdb->prepare(
				"{$this->having_and( $count )} origin_post_author LIKE %d",				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $args['origin_post_author'] )
			);
			$count++;
		}

		// Filter by post_author
		if( isset( $args['post_author'] ) ) {
			$query .= $wpdb->prepare(
				"{$this->having_and( $count )} post_author LIKE %d",					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $args['post_author'] )
			);
			$count++;
		}

		// Filter by post_parent
		if( isset( $args['post_parent'] ) ) {
			$query .= $wpdb->prepare(
				"{$this->having_and( $count )} post_parent LIKE %d",					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $args['post_parent'] )
			);
			$count++;
		}

		// Filter by origin_post_type
		if( isset( $args['origin_post_type'] ) ) {
			$query .= $wpdb->prepare(
				"{$this->having_and( $count )} origin_post_type LIKE %s",				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $args['origin_post_type'] )
			);
			$count++;
		}

		$query_types = array_merge($this->post_types, ['revision']);

		$query .= $this->having_and( $count ) . ' origin_post_type IN ("' . implode('","', $query_types ) . '")';
		$count++;

		// Set order by and order
		$query .= ' ORDER BY ' . $orderby . ' ' . strtoupper( $order );

		return $query;
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
	 * HAVING clause helper to build dynamic query
	 *
	 * @param int $count Number that later decide the return
	 *
	 * @return string
	 */
	private function having_and( $count ) {
		return $count > 0 ? ' AND' : ' HAVING';
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
			$_SERVER['REQUEST_URI'] = str_replace('#038;', '&', esc_url_raw($_SERVER['REQUEST_URI']));
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
            'cb'			=> '<input type="checkbox" />',
			'post_title' 	=> __( 'Revision', 'revisionary' ),
			/*'post_count' 	=> __( 'Count', 'revisionary' ),*/
			'origin_post_type' 		=> __( 'Post Type', 'revisionary' ),
			'post_author'	=> __( 'Revised By', 'revisionary' ),
			'post_date' 	=> __( 'Revision Date', 'revisionary' ),
			'publication_method' => __('Action', 'revisionary'),
			'approved_by'	=> __('Approved By', 'revisionary'),
			'origin_post_date'		=> __( 'Published Date', 'revisionary' ),
			'origin_post_author'	=> __( 'Published Author', 'revisionary' ),
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

		$saved_time = gmdate( 'Y/m/d H:i:s', $timestamp );

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

			if ($this->revision_published_gmt = get_post_meta($item->ID, '_rvy_published_gmt', true)) {
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
				
				//$revisions_enabled	= wp_revisions_enabled( $post_object );
				
				//if( $revisions_enabled ) {
					// Show title with link
					printf(
						'<strong><a class="row-title rvy-open-popup" href="%s" data-label="%s">%s</a></strong>',
						esc_url_raw( get_edit_post_link( $item->ID ) . '&width=900&height=600&rvy-popup=true&TB_iframe=1' ),
						esc_attr( $item->$column_name ),
						esc_html($item->$column_name)
					);
				
				/*
				} else {
					// Show title WITHOUT link
					printf(
						'<strong>%s</strong> %s',
						esc_html($item->$column_name),
						sprintf(
							'<span class="dashicons dashicons-info" title="%s"></span>',
							sprintf(
								esc_attr__( 'Revisions are disabled for %s post type', 'revisionary' ),
								esc_attr($item->origin_post_type)
							)
						)
					);
				}
				*/

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
				$type_label = (!empty($type_obj)) ? $type_obj->labels->singular_name : $item->$column_name;

				$this->echo_filter_link(
					$type_label,
					[
						'origin_post_type' => sanitize_key( $post_type )
					]
				);
				break;

			case 'post_date':
				$prev_revision_status = get_post_meta($item->ID, '_rvy_prev_revision_status', true);

				//if ('future-revision' == $prev_revision_status) {
					return $this->friendly_date($item->post_modified, $item->post_modified_gmt);
				//} else {
					//return $this->friendly_date($item->post_date, $item->post_date_gmt);
				//}

				break;

			case 'origin_post_date':
				if ($this->direct_edit) {
					$published_gmt = $item->post_modified_gmt;

				} elseif ($this->parent_in_revision_workflow || $this->parent_from_revision_workflow) {
					break;

				} else {
					$published_gmt = $item->post_date_gmt;
				}

				//} elseif (!$published_gmt = get_post_meta($item->ID, '_rvy_published_gmt', true)) {
				//	$published_gmt = $item->origin_post_date_gmt;
				//}

                return $this->friendly_date(get_date_from_gmt($published_gmt), $published_gmt);
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
					switch ($this->from_revision_workflow) {
						case 'future-revision':
							esc_html_e('Scheduled Revision Publication', 'revisionary');
							break;
	
						default:
							esc_html_e('Revision Publication', 'revisionary');
					}
				} elseif ($this->parent_in_revision_workflow) {
					if ($status_obj = get_post_status_object($this->parent_in_revision_workflow)) {
						$status_label = $status_obj->label;
					} else {
						$status_label = $status_name;
					}

					printf(
						esc_html__('Edit of %s', 'revisionary'),
						"<span title='$this->active_revision_title'>" . $status_label . '</span>'
					);

				} elseif ($this->parent_from_revision_workflow) {
					printf("<span title='%s'>%s</span>",
						$this->from_revision_title,
						esc_html__('Edit of published Revision', 'revisionary')
					);
				} elseif ($this->direct_edit) {
					esc_html_e('Direct Edit', 'revisionary');
				}

				break;

			case 'approved_by':
				if ($this->direct_edit) {
					$approver_id = $item->post_author;

				} elseif ($this->from_revision_workflow) {
					$approver_id = get_post_meta($item->ID, '_rvy_approved_by', true);
				}

				if (!empty($approver_id)) {
					echo get_the_author_meta('display_name', $approver_id);
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
        return sprintf(
            '<input type="checkbox" name="post[]" value="%s" />', $item->ID
        );
    }

	/**
	 * Override WP_List_Table::extra_tablenav()
	 */
	protected function extra_tablenav( $which ) {
		?>
		<div class="alignleft actions">
		<?php
		if ( 'top' === $which ) {
			$current_option = isset( $_REQUEST['origin_post_type'] ) && ! empty( $_REQUEST['origin_post_type'] )	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( $_REQUEST['origin_post_type'] )															//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
			?>
			<select name="origin_post_type" class="postform">
				<option <?php echo $current_option === '' ? 'selected' : '' ?>
					value="">
					<?php esc_html_e( 'All post types', 'revisionary' ) ?>
				</option>
				<?php foreach( $this->post_types as $type ) :
					$type_obj = get_post_type_object( $type );
					?>
					<option <?php echo $current_option === $type ? 'selected' : '' ?>
						value="<?php echo esc_attr($type) ?>">
						<?php echo esc_html($type_obj->labels->singular_name); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php

			submit_button( __( 'Filter' ), '', 'filter_action', false, array( 'id' => 'post-query-submit' ) );

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
			'origin_post_date' 		=> 'origin_post_date',
			'post_date' 	=> 'post_date',
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
			$is_administrator = current_user_can('administrator') || is_super_admin();
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
		$revisions_enabled	= true; // wp_revisions_enabled( $post_object );

		if ( ( $can_read_post || $can_edit_post ) && $revisions_enabled ) {
			$actions['diff'] = sprintf(
				'<a href="%1$s" class="" title="%2$s" aria-label="%2$s" target="_revision_diff">%3$s</a>',
				admin_url( "revision.php?revision=$item->ID" ),
				esc_attr(
					sprintf(
						esc_html__( 'Compare Changes in %s', 'revisionary' ),
						$item->post_title
					)
				),
				_x( 'Compare', 'revisions', 'revisionary' )
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
						esc_html__( 'Preview' )
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
					esc_html__( 'Edit Parent', 'revisionary' )
				);
			}
		}

		$uri = (isset($_SERVER['REQUEST_URI'])) ? add_query_arg($_REQUEST, esc_url_raw($_SERVER['REQUEST_URI'])) : '';	// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$actions['post_filter'] = sprintf(
			'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
			add_query_arg('origin_post', $item->post_parent, esc_url(untrailingslashit(site_url('')) . $uri )),
			esc_attr__( 'List Revisions of this Post', 'revisionary' ),
			esc_html__( 'Filter', 'revisionary' )
		);

		if ( $can_edit_post && rvy_get_option('revision_archive_deletion')) {
			$delete_link = esc_url(wp_nonce_url(
				"admin.php?page=rvy-revisions&amp;action=delete&amp;revision={$item->ID}", 
				'delete-revision_' . $item->ID 
			));

			$actions['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete" title="%2$s" aria-label="%2$s">%3$s</a>',
				$delete_link,
				esc_html__( 'Delete Past Revision', 'revisionary' ),
				esc_html__( 'Delete' )
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

		echo '<a href="' . esc_url(add_query_arg( $args, admin_url( 'admin.php?page=revisionary-archive' ) ) ) . '" class="' . esc_attr($v) . '">'
		. $label;																		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ($count ==! null) echo ' <span class="count">(' . esc_html($count) . ')</span>';
		
		echo '</a>';
	}

	/**
	 * Override WP_List_Table::no_items()
	 */
	public function no_items() {
		_e( 'No revisions found.', 'revisionary' );
	}
}
