<?php
require_once( ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php' );

class Revisionary_List_Table extends WP_Posts_List_Table {
	private $published_post_ids_query = '';
	private $published_post_count_ids_query = '';
	private $post_types = [];
	private $posts_clauses_filtered; 

	public function __construct($args = []) {
		global $wpdb, $revisionary;

		parent::__construct([
			'plural' => 'posts',
			'screen' => isset( $args['screen'] ) ? $args['screen'] : null,
		]);

		if ( isset( $args['post_types'] ) )
			$this->post_types = $args['post_types'];
		else
			$this->post_types = array_keys($revisionary->enabled_post_types);
		
		$omit_types = ['forum', 'topic', 'reply'];
		$this->post_types = array_diff( $this->post_types, $omit_types );

		add_filter('manage_revisionary-q_columns', [$this, 'rvy_pending_list_register_columns']);

		add_action('manage_posts_custom_column', [$this, 'rvy_pending_custom_col'], 10, 2);
		add_action('manage_pages_custom_column', [$this, 'rvy_pending_custom_col'], 10, 2);

		if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
			// Don't allow MA to change revision author display. Authors taxonomy storage is only for application to published post.
			global $multiple_authors_addon;
			remove_action('the_post', [$multiple_authors_addon, 'fix_post'], 10);
		}

																					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (!empty($_REQUEST['published_post']) && !rvy_get_post_meta((int) $_REQUEST['published_post'], '_rvy_has_revisions', true)) {
			revisionary_refresh_postmeta((int) $_REQUEST['published_post']);		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Gutenberg will not allow immediate deletion of revisions from within editor
		
		// Ensure deletion of trashed revisions through redundant triggering:
		// * Revision and Post IDs stored to options array on trashed_post action
		// * Revision IDs in redirect URL
		
		if ($triggered_deletions = (array) get_option('_rvy_trigger_deletion')) {
			$clear_trigger_option = true;
		}

		if (!empty($_REQUEST['pp_revisions_deleted'])) {
			check_admin_referer('revisions-deleted');

			global $current_user;
			
			$delete_id = (int) $_REQUEST['pp_revisions_deleted'];

			if (('trash' == get_post_field('post_status', $delete_id))) {
				$revision = get_post($delete_id);
				
				if (!empty($revision->comment_count)) {
					$triggered_deletions[$delete_id] = $revision->comment_count;
				}
			}
		}

		$revision_count = [];

		foreach ($triggered_deletions as $revision_id => $post_id) {
			if (!isset($revision_count[$post_id])) {
				$revision_count[$post_id] = revisionary_count_revisions($post_id);
			}

			if ($revision_id) {
				wp_delete_post($revision_id, true);

				$revision_count[$post_id] = $revision_count[$post_id] ? $revision_count[$post_id] - 1 : 0;
			}
		}

		foreach ($revision_count as $post_id => $num_revisions) {
			revisionary_refresh_postmeta($post_id, compact('num_revisions'));
		}

		if ($clear_trigger_option) {
			delete_option('_rvy_trigger_deletion');
		}

		$this->correctCommentCounts();

		if (!defined('REVISIONARY_DISABLE_WP_CRON_RESTORATION') && rvy_get_option('scheduled_revisions') && rvy_get_option('scheduled_publish_cron')) {
			add_action('admin_footer', [$this, 'act_reschedule_missed_cron_revisions']);
		}

		add_filter('presspermit_skip_postmeta_filtering', [$this, 'flt_skip_cap_filtering'], 10, 3);
	}

	function flt_skip_cap_filtering($skip, $post_id, $orig_cap) {
		global $current_user;
		
		if (in_array($orig_cap, ['read_post', 'read_page'])
		&& !empty($current_user->allcaps['preview_others_revisions'])
		) {
			$skip = true;
		}

		return $skip;
	}

	function act_reschedule_missed_cron_revisions() {
		global $wpdb;

		$time_gmt = current_time('mysql', 1);
	
		$cron_catchup_limit = (defined('REVISIONARY_SCHEDULED_CRON_RESTORATION_LIMIT_SECONDS')) ? REVISIONARY_SCHEDULED_CRON_RESTORATION_LIMIT_SECONDS : 3600 * 24 * 30;

		$timezone = new DateTimeZone('UTC');
		$datetime = new DateTime('now', $timezone);
		$datetime->setTimestamp(strtotime($time_gmt) - $cron_catchup_limit);
		$limit_time_gmt = $datetime->format('Y-m-d H:i:s');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_type != 'revision'"
				. " AND post_status != 'inherit' AND post_mime_type = 'future-revision' AND post_date_gmt > %s AND post_date_gmt < %s"
				. " ORDER BY post_date_gmt DESC",
				
				$limit_time_gmt,
				$time_gmt
			)
		);

		foreach($results as $revision) {
			if (strtotime($time_gmt) - strtotime($revision->post_date_gmt) < $cron_catchup_limit) {  // safeguard to prevent ancient misses from being published now
				if (!wp_get_scheduled_event('publish_revision_rvy', [$revision->ID])) {
					// safeguard to prevent future schedules from being published immediately
					$schedule_time = strtotime($revision->post_date_gmt) < strtotime($time_gmt) ? strtotime($time_gmt) : strtotime($revision->post_date_gmt);
					
					wp_schedule_single_event($schedule_time, 'publish_revision_rvy', [$revision->ID]);
				}
			}
		}
	}

	function do_query( $q = false ) {
		if ( false === $q ) $q = $_GET;										//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// === first, query published posts that have any Revisionary revisions ===

		$qp['cat'] = isset($q['cat']) ? (int) $q['cat'] : 0;

		if ( isset($q['post_type']) && in_array( $q['post_type'], $this->post_types ) )
			$qp['post_type'] = $q['post_type'];
		else
			$qp['post_type'] = $this->post_types;
		
		$omit_stati = ['hidden'];

		$qp['post_status'] = array_diff( rvy_filtered_statuses(), $omit_stati );

		if (!empty($q['published_post'])) {
			$qp['p'] = (int) $q['published_post'];
		}

		$qp['posts_per_page'] = -1;
		$qp['fields'] = 'ids';
		
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		//$qp['meta_key'] = '_rvy_has_revisions';

		global $wpdb;

		if (!empty($q['post_author'])) {
			do_action('revisionary_queue_pre_query');
			$_pre_query = new WP_Query( $qp );

			// workaround for unidentified plugin conflict inserting inappropriate clauses into our query
			if (!defined('REVISIONARY_QUEUE_DISABLE_QUERY_COMPAT_WORKAROUNDS')) {
				if (false !== stripos($_pre_query->request, ", IF ( _has_featured_image.meta_value IS NULL, 0, 1 ) AS has_featured_image")) {
					$_pre_query->request = str_ireplace(
						", IF ( _has_featured_image.meta_value IS NULL, 0, 1 ) AS has_featured_image",
						'',
						$_pre_query->request
					);

					$_pre_query->request = str_ireplace(
						"LEFT JOIN wp_postmeta AS _has_featured_image ON _has_featured_image.post_id = $wpdb->posts.ID and _has_featured_image.meta_key = '_thumbnail_id'",
						'',
						$_pre_query->request
					);

					$_pre_query->request = str_ireplace(
						'ORDER BY has_featured_image DESC,',
						'ORDER BY ',
						$_pre_query->request
					);
				}
			}

			$this->published_post_count_ids_query = $_pre_query->request;
			do_action('revisionary_queue_pre_query_done');

			$qp['author'] = $q['post_author'];
		}

		$filter_name = (defined('REVISIONARY_QUEUE_LEGACY_FILTER')) ? 'posts_clauses' : 'posts_clauses_request';

		do_action('revisionary_queue_pre_query');
		add_filter($filter_name, [$this, 'pre_query_filter'], 5, 2);
		add_filter($filter_name, [$this, 'restore_revisions_filter'], PHP_INT_MAX - 1, 2);

		if (defined('REVISIONARY_USE_QUEUE_EXCEPTIONS_FILTER')) {  // @todo: confirm this is obsolete
			add_filter('presspermit_posts_where_extra_exception_ops', 
				function($exception_ops, $args) {
					$exception_ops []= 'revise';
					return $exception_ops;
				}, 10, 2
			);
		}

		$pre_query = new WP_Query( $qp );

		// workaround for unidentified plugin conflict inserting inappropriate clauses into our query
		if (!defined('REVISIONARY_QUEUE_DISABLE_QUERY_COMPAT_WORKAROUNDS')) {
			if (false !== stripos($pre_query->request, ", IF ( _has_featured_image.meta_value IS NULL, 0, 1 ) AS has_featured_image")) {
				$pre_query->request = str_ireplace(
					", IF ( _has_featured_image.meta_value IS NULL, 0, 1 ) AS has_featured_image",
					'',
					$pre_query->request
				);

				$pre_query->request = str_ireplace(
					"LEFT JOIN wp_postmeta AS _has_featured_image ON _has_featured_image.post_id = $wpdb->posts.ID and _has_featured_image.meta_key = '_thumbnail_id'",
					'',
					$pre_query->request
				);

				$pre_query->request = str_ireplace(
					'ORDER BY has_featured_image DESC,',
					'ORDER BY ',
					$pre_query->request
				);
			}
		}

		remove_filter($filter_name, [$this, 'pre_query_filter'], 5, 2);
		remove_filter($filter_name, [$this, 'restore_revisions_filter'], PHP_INT_MAX - 1, 2);
		do_action('revisionary_queue_pre_query_done');

		$this->published_post_ids_query = $pre_query->request;

		// === now query the revisions ===
		$qr = $q; 

		unset($qr['post_author']);

		$qr['post_type'] = $qp['post_type'];

		$permissions_compat_mode = rvy_get_option('permissions_compat_mode');

		$qr['post_status'] = ($permissions_compat_mode) ? rvy_revision_statuses() : rvy_revision_base_statuses();

		if (isset($qr['m']) && strlen($qr['m']) == 6) {
			$qr['date_query'] = [
				'column' => ( ! empty($_REQUEST['post_mime_type']) && 'future-revision' == $_REQUEST['post_mime_type'] ) ? 'post_date' : 'post_modified',  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'year' => substr($qr['m'], 0, 4),
				'month' => substr($qr['m'], 4)
			];

			unset($qr['m']);
		}

		if ( isset( $q['orderby'] ) && !in_array($q['orderby'], ['post_mime_type', 'post_type']) ) {
			$qr['orderby'] = $q['orderby'];
		} else {
			$qr['orderby'] = ( ! empty($_REQUEST['post_mime_type']) && 'future-revision' == $_REQUEST['post_mime_type'] ) ? 'date' : 'modified';			//phpcs:ignore WordPress.Security.NonceVerification.Recommended									
		}

		if ( isset( $q['order'] ) && !in_array($q['orderby'], ['post_mime_type', 'post_type'] ) ) {
			$qr['order'] = $q['order'];
		} else {
			$qr['order'] = 'DESC';
		}

		$per_page = "revisions_per_page";
		$qr['posts_per_page'] = (int) get_user_option( $per_page );
		
		if ( empty( $qr['posts_per_page'] ) || $qr['posts_per_page'] < 1 )
			$qr['posts_per_page'] = 20;
		
		$status_col = ($permissions_compat_mode) ? 'post_status' : 'post_mime_type';

		if ( isset($q['post_status']) && rvy_is_revision_status( $q['post_status'] ) ) {
			$qr[$status_col] = [$q['post_status']];
		} else {
			$qr[$status_col] = rvy_revision_statuses();
		}

		if (!rvy_get_option('pending_revisions')) {
			$_revision_statuses = array_diff(
				array_map('sanitize_key', rvy_revision_statuses()),
				['future-revision']
			);

			$qr[$status_col] = array_diff($qr[$status_col], $_revision_statuses);
		}

		if (!rvy_get_option('scheduled_revisions')) {
			$qr[$status_col] = array_diff($qr[$status_col], ['future-revision']);
		}

		global $wp_query;

		$filter_name = (defined('REVISIONARY_QUEUE_LEGACY_FILTER')) ? 'posts_clauses' : 'posts_clauses_request';

		add_filter('presspermit_posts_clauses_intercept', [$this, 'flt_presspermit_posts_clauses_intercept'], 10, 4);
		add_filter($filter_name, [$this, 'revisions_filter'], 5, 2);
		add_filter($filter_name, [$this, 'restore_revisions_filter'], PHP_INT_MAX - 1, 2);

		if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
			remove_action('pre_get_posts', ['MultipleAuthors\\Classes\\Query', 'action_pre_get_posts']);
			remove_filter('posts_where', ['MultipleAuthors\\Classes\\Query', 'filter_posts_where'], 10, 2);
			remove_filter('posts_join', ['MultipleAuthors\\Classes\\Query', 'filter_posts_join'], 10, 2);
			remove_filter('posts_groupby', ['MultipleAuthors\\Classes\\Query', 'filter_posts_groupby'], 10, 2);

			if (!defined('PUBLISHPRESS_AUTHORS_DISABLE_FILTER_THE_AUTHOR')) {
				define('PUBLISHPRESS_AUTHORS_DISABLE_FILTER_THE_AUTHOR', true);
			}
		}

		if (defined('PP_AUTHORS_VERSION')) {
			remove_filter(
				'posts_where',
				['MultipleAuthors\\Classes\\Query', 'filter_admin_posts_list_where'],
				10,
				2
			);

			remove_filter(
				'posts_join',
				['MultipleAuthors\\Classes\\Query', 'filter_posts_list_join'],
				10,
				2
			);

			remove_filter(
				'posts_groupby',
				['MultipleAuthors\\Classes\\Query', 'filter_posts_list_groupby'],
				10,
				2
			);

			remove_filter(
				'posts_where',
				['MultipleAuthors\\Classes\\Query', 'filter_author_posts_where'],
				10,
				2
			);

			remove_action('pre_get_posts', ['MultipleAuthors\\Classes\\Query', 'action_pre_get_posts']);
			remove_filter('posts_where', ['MultipleAuthors\\Classes\\Query', 'filter_posts_where'], 10, 2);
			remove_filter('posts_join', ['MultipleAuthors\\Classes\\Query', 'filter_posts_join'], 10, 2);
			remove_filter('posts_groupby', ['MultipleAuthors\\Classes\\Query', 'filter_posts_groupby'], 10, 2);

			if (!defined('PUBLISHPRESS_AUTHORS_DISABLE_FILTER_THE_AUTHOR')) {
				define('PUBLISHPRESS_AUTHORS_DISABLE_FILTER_THE_AUTHOR', true);
			}
		}

		if (!empty($_REQUEST['s'])) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$qr['s'] = sanitize_text_field($_REQUEST['s']);						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$qr = apply_filters('revisionary_queue_vars', $qr);

		$wp_query->is_revisions_query = true;

		add_filter('posts_where', [$this, 'fltFixMimeTypeClause']);
		$wp_query->query($qr);
		remove_filter('posts_where', [$this, 'fltFixMimeTypeClause']);

		$wp_query->is_revisions_query = false;

		do_action('revisionary_queue_done');

		// prevent default display of all revisions
		if (!$wp_query->posts) {
			$wp_query->posts = [true];
		}

		remove_filter('presspermit_posts_clauses_intercept', [$this, 'flt_presspermit_posts_clauses_intercept'], 10, 4);
		remove_filter($filter_name, [$this, 'revisions_filter'], 5, 2);
		remove_filter($filter_name, [$this, 'restore_revisions_filter'], PHP_INT_MAX - 1, 2);

		return $qr[$status_col];
	}

	function fltFixMimeTypeClause($where) {
		$where = str_replace("-revision/%'", "-revision'", $where);
		$where = preg_replace("#post_mime_type LIKE '([a-z0-9_\-]*)/%'#", "post_mime_type LIKE '$1'", $where);

		return $where;
	}

	function flt_presspermit_posts_clauses_intercept( $intercept, $clauses, $_wp_query, $args) {
		return $clauses;
	}

	function pre_query_where_filter($where, $args = []) {
		global $wpdb, $current_user, $revisionary;

		if (!current_user_can('administrator') && empty($args['suppress_author_clause']) && empty($_REQUEST['post_author'])) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (rvy_get_option('revisor_hide_others_revisions') && !current_user_can('list_others_revisions') ) {
			
				$p = (!empty($args['alias'])) ? $args['alias'] : $wpdb->posts;

				$can_edit_others_types = [];

				foreach(array_keys($revisionary->enabled_post_types) as $post_type) {
					if ($type_obj = get_post_type_object($post_type)) {
						if (current_user_can($type_obj->cap->edit_others_posts)) {
							$can_edit_others_types[]= $post_type;
						}
					}
				}

				$can_edit_others_types = array_map('sanitize_key', apply_filters('revisionary_queue_edit_others_types', $can_edit_others_types));

				$type_clause = ($can_edit_others_types) ? "OR $p.post_type IN ('" . implode("','", $can_edit_others_types) . "')" : '';

				$where .= $wpdb->prepare(" AND ($p.post_author = %d $type_clause)", $current_user->ID );						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return $where;
	}

	function restore_revisions_filter($clauses, $_wp_query = false) {
		if (!empty($this->posts_clauses_filtered) && !defined('REVISIONARY_ENABLE_REVISION_QUEUE_FILTERING')) {
			$clauses = $this->posts_clauses_filtered;
		}

		return $clauses;
	}

	function pre_query_filter($clauses, $_wp_query = false) {
		$clauses['where'] = $this->pre_query_where_filter($clauses['where']);

		$this->posts_clauses_filtered = $clauses;

		return $clauses;
	}

	function append_revisions_where($where, $args=[]) {
		// relocated to calling function for clarity
		return '';
	}

	function revisions_where_filter($where, $args = []) {
		global $wpdb, $current_user, $revisionary;
		
		$p = (!empty($args['alias'])) ? sanitize_text_field($args['alias']) : $wpdb->posts;

		$is_count_query = empty($args['revision_query']);

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_my_activity = empty($_REQUEST['all']) && empty($_REQUEST['author']) && empty($_REQUEST['post_author']) && empty($_REQUEST['post_status']);

		if (!empty($args['status_count'])) {
			$id_subquery = (!empty($this->published_post_count_ids_query)) ? $this->published_post_count_ids_query : $this->published_post_ids_query;
		} else {
			$id_subquery = $this->published_post_ids_query;
		}

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ((empty($_REQUEST['post_author']) || !empty($args['status_count'])) && empty($args['my_published_count'])) {
			$revision_status_csv =  implode("','", array_map('sanitize_key', rvy_revision_statuses()));

			$own_revision_and = '';

			if (defined('ICL_SITEPRESS_VERSION')) {
				if (!empty($_REQUEST['lang'])) {						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$lang = sanitize_text_field($_REQUEST['lang']);		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				} else {
					global $sitepress;
					if (!empty($sitepress) && method_exists($sitepress, 'get_admin_language_cookie')) {
						$lang = sanitize_text_field($sitepress->get_admin_language_cookie());
					}
				}

				if (!empty($lang)) {
					$own_revision_and = $wpdb->prepare(
						" AND $p.comment_count IN (SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE element_type LIKE 'post_%' AND language_code = %s)",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$lang
					);
				}
			}

			$status_clause = apply_filters('revisionary_require_base_statuses', true) 
			? "$p.post_status IN ('draft', 'pending') AND " 
			: '';

			if (!empty($_REQUEST['published_post'])) {
				$own_revision_and .= $wpdb->prepare(" AND $p.comment_count = %d", intval($_REQUEST['published_post']));
			}

			$own_revision_clause = $wpdb->prepare(
				" OR ($status_clause $p.post_mime_type IN ('$revision_status_csv') AND $p.post_author = %d {$own_revision_and})",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user->ID
			);
		} else {
			$own_revision_clause = '';
		}

		if (
			(!$is_my_activity 
			&& !$is_count_query 
			&& (
				empty($args['revision_query']) 
				|| (!empty($_REQUEST['author']) && ($current_user->ID != $_REQUEST['author']))					//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			)
			|| rvy_get_option('list_unsubmitted_revisions')
		) {
			$revision_status_clause = '';
		
		} elseif ((!$is_my_activity && !$is_count_query
		&& (empty($_REQUEST['all'])																				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& (empty($_REQUEST['post_status']) || ('draft-revision' != sanitize_key($_REQUEST['post_status'])))	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		)) || !empty($args['my_published_count'])) {
			$revision_status_clause = "AND $p.post_mime_type != 'draft-revision' ";

		} elseif (($is_my_activity && !$is_count_query) || (rvy_get_option('manage_unsubmitted_capability') && !current_user_can("manage_unsubmitted_revisions"))) {
			$revision_status_clause = "AND ($p.post_mime_type != 'draft-revision' OR $p.post_author = '$current_user->ID')";
		} else {
			$revision_status_clause = '';
		}

		$where_append = "($p.comment_count IN ($id_subquery) {$revision_status_clause}$own_revision_clause)";

		$status_csv = implode("','", array_map('sanitize_key', rvy_filtered_statuses()));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$own_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_status IN ('$status_csv') AND post_author = %d",		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user->ID
			)
		);

		if (rvy_get_option('admin_revisions_to_own_posts')) {
			$own_posts = apply_filters('revisionary_own_post_ids', $own_posts, $current_user->ID);
		} else {
			$own_posts = [];
		}

		$own_posts_csv = implode("','", array_map('intval', $own_posts));

		if (rvy_get_option('revisor_hide_others_revisions') && !current_user_can('administrator') 
			&& !current_user_can('list_others_revisions') && empty($args['suppress_author_clause']) 
		) {
			$allow_post_types = apply_filters('revisionary_queue_allow_post_types', []);

			$can_publish_types = [];
			foreach(array_keys($revisionary->enabled_post_types) as $post_type) {
				$type_obj = get_post_type_object($post_type);

				if (
					(
					!empty($allow_post_types[$post_type]) 
					|| (isset($type_obj->cap->edit_published_posts)
						&& current_user_can($type_obj->cap->edit_published_posts)
						&& !empty($current_user->allcaps[$type_obj->cap->edit_others_posts])
						&& current_user_can($type_obj->cap->publish_posts)
					))
					&& (!empty($revisionary->enabled_post_types[$post_type]) || !$revisionary->config_loaded)
				) {
					$can_publish_types[]= $post_type;
				}
			}

			$can_publish_types = array_intersect($can_publish_types, apply_filters('revisionary_manageable_types', $can_publish_types));

			if ($can_publish_types){
				$type_clause = "OR $p.post_type IN ('" . implode("','", array_map('sanitize_key', $can_publish_types)) . "')";
			} else {
				$type_clause = '';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_append .= $wpdb->prepare(" AND (($p.post_author = %d $type_clause) OR ($p.comment_count IN ('$own_posts_csv') $type_clause))", $current_user->ID );

		} elseif ($revisionary->config_loaded) {
			$where_append .= (array_filter($revisionary->enabled_post_types)) 
			? " AND ($p.post_type IN ('" 
				. implode("','", 
					array_map(
						'sanitize_key', 
						array_keys(
							array_filter($revisionary->enabled_post_types)
						)
					)
				) . "'))" 
			
			: " AND 1=2";
		}

		if (empty($args['suppress_author_clause'])) {
			$status_csv = implode("','", array_map('sanitize_key', rvy_filtered_statuses()));

			$where_append .= " AND $p.comment_count IN (SELECT ID FROM $wpdb->posts WHERE post_status IN ('$status_csv'))";
		}

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));
		$where_append .= " AND $p.post_mime_type IN ('$revision_status_csv')";

		$where .= " AND $where_append";

		// Also support Access Circle restrictions
		if (defined('PRESSPERMIT_CIRCLES_VERSION')) {
			$append_clauses = [];

			if (defined('PRESSPERMIT_CLASSPATH') && file_exists(PRESSPERMIT_CLASSPATH . '/DB/Permissions.php')) {
				include_once(PRESSPERMIT_CLASSPATH . '/DB/Permissions.php');
			}

			foreach (array_keys(array_filter($revisionary->enabled_post_types)) as $post_type) {
				if ($append_clause = apply_filters('presspermit_append_query_clause', '', $post_type, 'edit', ['src_table' => $p])) {
					$append_clauses[$post_type] = '(' . $wpdb->prepare("$p.post_type = %s", $post_type) . $append_clause . ')';			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					if (class_exists('PublishPress\Permissions\DB\Permissions')) {
						$append_clauses[$post_type] = '( ' 
						. \PublishPress\Permissions\DB\Permissions::addExceptionClauses($append_clauses[$post_type], 'edit', $post_type, ['additions_only' => true, 'src_table' => $p]) 
						. ' )';
					}
				}
			}

			if ($append_clauses) {
				$and = (0 === strpos(trim(strtoupper($where)), 'AND')) ? ' AND' : '';
				$one_one = ($and) ? '1=1' : '';

				$where = " $and (($one_one $where) AND (" . implode(' OR ', $append_clauses) . '))';
			}
		}

		if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')
		&& !is_content_administrator_rvy() && class_exists('PublishPress\Permissions\DB\Permissions')
		) {
			$defaults = [
				'has_cap_check' => false,   // TRUE: this function call is to determine per-post editing / deletion capability;  FALSE: just determining which posts to list
				'post_types' => [],
				'source_alias' => (!empty($args['alias'])) ? sanitize_text_field($args['alias']) : '',
				'src_table' => '',
				'apply_term_restrictions' => true,
				'include_trash' => 0,
				'required_operation' => 'edit',
				'limit_statuses' => false,
				'skip_teaser' => false,
				'query_contexts' => [],
				'force_types' => false,
				'limit_post_types' => false,
				'join' => '',
				'append_post_type_clause' => false,
			];

			$args = array_merge($defaults, (array)$args);


			foreach(array_keys($revisionary->enabled_post_types) as $_post_type) {
				$where = \PublishPress\Permissions\DB\Permissions::addExceptionClauses($where, 'edit', $_post_type, $args);
			}
		}

		return $where;
	}

	function revisions_filter($clauses, $_wp_query = false) {
		$clauses['where'] = $this->revisions_where_filter($clauses['where'], ['revision_query' => true]);
		$this->posts_clauses_filtered = $clauses;
		return $clauses;
	}
	
	function correctCommentCounts() {
		global $wpdb;

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($revision_ids = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_mime_type IN ('$revision_status_csv') AND comment_count = 0")) {
			foreach($revision_ids as $revision_id) {
				if ($main_post_id = get_post_meta($revision_id, '_rvy_base_post_id', true)) {
					if ($main_post_id != $revision_id) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update($wpdb->posts, ['comment_count' => $main_post_id], ['ID' => $revision_id]);
					}
				}
			}
		}
	}

	function rvy_pending_list_register_columns( $columns ) {
		global $wp_query;
		foreach( $wp_query->posts as $post ) {
			if ( !empty($post) && is_object($post) && (('future-revision' == $post->post_mime_type && 'inherit' != $post->post_status) || (strtotime($post->post_date_gmt) > agp_time_gmt())) ) {
				$have_scheduled = true;
				break;
			}
		}
		
		$arr = [
			'cb' => '<input type="checkbox" />', 
			'title' => pp_revisions_label('queue_col_revision'), 
			'post_status' => esc_html__('Status', 'revisionary'), 
			'post_type' => esc_html__('Post Type', 'revisionary'), 
			'author' => pp_revisions_label('queue_col_revised_by'), 
			'date' =>  pp_revisions_label('queue_col_revision_date'),
		];

		if (!empty($_REQUEST['cat'])) {													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$arr['categories'] = get_taxonomy('category')->labels->name;
		}

		if (! empty( $have_scheduled ) 
		|| (!empty($_REQUEST['orderby']) && 'date_sched' == $_REQUEST['orderby']) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			$arr['date_sched'] = esc_html__('Schedule');
		}

		$arr['published_post'] = pp_revisions_label('queue_col_published_post');

		$arr['post_author'] = pp_revisions_label('queue_col_post_author');

		return apply_filters('revisionary_list_table_columns', $arr);
	}

	function rvy_pending_custom_col( $column_name, $post_id ) {
		if ( ! $post = get_post( $post_id ) )
			return;
		
		$request_url = add_query_arg($_REQUEST, rvy_admin_url('admin.php?page=revisionary-q'));	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ($column_name) {
			case 'post_type':
				$post_type = get_post_field('post_type', $post_id);

				if ( $type_obj = get_post_type_object( $post_type ) ) {
					$link = add_query_arg('post_type', $type_obj->name, esc_url($request_url));
					echo "<a href='" . esc_url($link) . "'>" . esc_html($type_obj->labels->singular_name) . "</a>";
				} else {
					echo esc_html("($post_type)");
				}

				break;

			case 'post_status':
				if (rvy_is_revision_status($post->post_mime_type)) {
					$label = pp_revisions_status_label($post->post_mime_type, 'short');
				} else {
					$label = ucwords($post->post_mime_type);
				}

				$link = add_query_arg('post_status', $post->post_mime_type, esc_url($request_url));
				echo "<a href='" . esc_url($link) . "'>" . esc_html($label) . "</a>";

				break;
		
			case 'date_sched' :
				if ( ('future-revision' === $post->post_mime_type ) || ( strtotime($post->post_date_gmt) > agp_time_gmt() ) ) {
						$t_time = get_the_time( esc_html__( 'Y/m/d g:i:s a', 'revisionary' ) );
						$m_time = $post->post_date;
						
						$time = get_post_time( 'G', true, $post );

						$time_diff = time() - $time;

						if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
							$h_time = sprintf( esc_html__( '%s ago' ), human_time_diff( $time ) );
						} else {
							$h_time = mysql2date( esc_html__( 'Y/m/d g:i a', 'revisionary' ), get_date_from_gmt($post->post_date_gmt) );
							$h_time = str_replace( ' am', '&nbsp;am', $h_time );
							$h_time = str_replace( ' pm', '&nbsp;pm', $h_time );
							$h_time = str_replace( ' ', '<br />', $h_time );
						}

						if ('future-revision' == $post->post_mime_type) {
							$t_time = sprintf(esc_html__('Scheduled publication: %s', 'revisionary'), $t_time);
						} else {
							$h_time = "<span class='rvy-requested-date'>[$h_time]</span>";
							$t_time = sprintf(esc_html__('Requested publication: %s', 'revisionary'), $t_time);
						}

						if ( $time_diff > 0 ) {
							echo '<strong class="error-message">' . esc_html__( 'Missed schedule' ) . '</strong>';
							echo '<br />';
						}

					/** This filter is documented in wp-admin/includes/class-wp-posts-list-table.php */
					$mode = 'list';
																	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<abbr title="' . esc_attr($t_time) . '">' . apply_filters( 'rvy_post_schedule_date_column_time', $h_time, $post, 'date', $mode ) . '</abbr>';
				}

				break;

			case 'published_post':
				if ($parent_post = get_post($post->comment_count)) {
					self::column_title($parent_post, true);

				} elseif ($published_id = rvy_post_id($post->ID)) {
					if ($parent_post = get_post($published_id)) {
						self::column_title($parent_post, true);
					} else {
						echo esc_html($published_id);
					}
				}

				$this->handle_published_row_actions( $parent_post, 'published_post' );

				break;

			case 'post_author':
				$parent_post = get_post(rvy_post_id($post->ID));

				if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
					$authors     = get_multiple_authors($parent_post->ID);
					$authors_str = [];
					foreach ($authors as $author) {
						if (is_object($author)) {
							$url           = add_query_arg('post_author', $author->ID, esc_url($request_url));
							$authors_str[] = '<a href="' . esc_url($url) . '">' . esc_html($author->display_name) . '</a>';
						}
					}

					if (empty($authors_str)) {
						$authors_str[] = '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('No author',
							'revisionary') . '</span>';
					}

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo implode(', ', $authors_str);  // output variables escaped above
				} else {
					$author_caption = get_the_author_meta('display_name', $parent_post->post_author);
					$this->apply_edit_link(add_query_arg('post_author', $parent_post->post_author, esc_url($request_url)), $author_caption);
				}

				break;

			default:
				do_action('revisionary_list_table_custom_col', $column_name, $post);

		} // end switch
	}

	protected function handle_published_row_actions( $post, $column_name ) {
		$post_type_object = get_post_type_object( $post->post_type );
		$can_edit_post    = current_user_can( 'edit_post', $post->ID );
		$actions          = [];

		static $last_past_revision;

		if(!isset($last_past_revision)) {
			$last_past_revision = [];
		}

		if ( $can_edit_post && 'trash' != $post->post_status ) {
			if ($edit_link = get_edit_post_link( $post->ID )) {
				$actions['edit'] = sprintf(
					'<a href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
					$edit_link,
					/* translators: %s: post title */
					esc_attr('Edit published post'),
					esc_html__( 'Edit' )
				);
			}
		}

		$request_url = add_query_arg($_REQUEST, rvy_admin_url('admin.php?page=revisionary-q'));		// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$actions['list_filter'] = sprintf(
			'<a href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',

			add_query_arg('published_post', $post->ID, esc_url($request_url)),
			/* translators: %s: post title */
			esc_attr( sprintf( esc_html__( 'View only revisions of %s', 'revisionary' ), '&#8220;' . $post->post_title . '&#8221;' ) ),
			esc_html__( 'Filter' )
		);

		if ( is_post_type_viewable( $post_type_object ) ) {
			$status_obj = get_post_status_object($post->post_status);

			if (!empty($status_obj->public) || !empty($status_obj->private)) {
				$actions['view'] = sprintf(
					'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
					get_permalink( $post->ID ),
					/* translators: %s: post title */
					esc_attr( esc_html__( 'View published post', 'revisionary' ) ),
					esc_html__( 'View' )
				);
			} else {
				$actions['view'] = sprintf(
					'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
					get_preview_post_link( $post->ID ),
					/* translators: %s: post title */
					esc_attr( esc_html__( 'View published post', 'revisionary' ) ),
					esc_html__( 'Preview' )
				);
			}
		}

		// todo: single query for all listed published posts
		if (!isset($last_past_revision[$post->ID])) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ($revision_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type='revision' AND post_status='inherit' AND post_parent = %d ORDER BY ID DESC LIMIT 1",
					$post->ID
				)
			)) {
				$last_past_revision[$post->ID] = $revision_id;
			}
		}

		if (!empty($last_past_revision[$post->ID])) {
			$actions['history'] = sprintf(
				'<a href="%1$s" title="%2$s" aria-label="%2$s" target="_revision_diff">%3$s</a>',
				admin_url("revision.php?revision={$last_past_revision[$post->ID]}"),
				/* translators: %s: post title */
				esc_attr(esc_html__('Compare Past Revisions', 'revisionary')),
				esc_html__( 'History', 'revisionary' )
			);
		}

		$action_count = count( $actions );

		if ( ! $action_count ) {
			return;
		}

		$mode = get_user_setting( 'posts_list_mode', 'list' );

		$visible = ( 'excerpt' === $mode ) ? ' visible' : '';

		echo '<div class="row-actions' . esc_attr($visible) . '">';

		$i = 0;

		foreach ( $actions as $action => $link ) {
			++$i;

			$sep = ( $i < $action_count ) ? ' | ' : '';

			echo "<span class='" . esc_attr($action) . "'>" . "$link$sep" . "</span>";		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details' ) . '</span></button>';
	}

	/**
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return false;
	}

	/**
	 *
	 * @global array    $avail_post_stati
	 * @global WP_Query $wp_query
	 * @global int      $per_page
	 * @global string   $mode
	 */
	public function prepare_items() {
		global $avail_post_stati, $wp_query, $per_page, $mode;

		// is going to call wp()
		$avail_post_stati = $this->do_query();								// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		
		$per_page = $this->get_items_per_page( 'revisions_per_page' );		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$total_items = $wp_query->found_posts;

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page' => $per_page
		]);

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (empty($_REQUEST['all']) && empty($_REQUEST['author']) && empty($_REQUEST['post_author']) && empty($_REQUEST['post_status'])) :?>
		<script type="text/javascript">
        /* <![CDATA[ */
        jQuery(document).ready( function($) {
            $('span.ppr-my-activity-count').html('(<?php echo esc_attr($total_items);?>)&nbsp;');
        });
        /* ]]> */
		</script>
		<?php endif;
	}

	public function no_items() {
		$post_type = 'page';
		
		if (isset($_REQUEST['post_status']) && 'trash' === sanitize_key($_REQUEST['post_status']))			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo esc_html(get_post_type_object( $post_type )->labels->not_found_in_trash);
		else
			echo esc_html(get_post_type_object( $post_type )->labels->not_found);
	}

	/**
	 * Determine if the current view is the "All" view.
	 *
	 * @since 4.2.0
	 *
	 * @return bool Whether the current view is the "All" view.
	 */
	protected function is_base_request() {
		$vars = $_GET;																						//phpcs:ignore WordPress.Security.NonceVerification.Recommended																
		unset( $vars['paged'] );

		if ( empty( $vars ) ) {
			return true;
		}

		return 1 === count( $vars ) && ! empty( $vars['mode'] );
	}

	private function count_revisions($post_type = '', $statuses = '' ) {
		global $wpdb;

		$status_csv = implode("','", array_map('sanitize_key', (array) $statuses));

		if ($post_type) {
			$type_clause = "AND post_type IN ('" 
			. implode("','", array_map('sanitize_key', (array) $post_type)) 
			. "')";
		}

		$where = $this->revisions_where_filter("post_mime_type IN ('$status_csv') AND post_status != 'trash' $type_clause", ['status_count' => true]);

		$query = "SELECT post_mime_type, COUNT( * ) AS num_posts FROM {$wpdb->posts} WHERE $where";
		$query .= ' GROUP BY post_mime_type';

		// todo: Permissions filter

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		/* 
		$query = apply_filters('presspermit_posts_request', $query, ['has_cap_check' => true]);
		*/

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = (array) $wpdb->get_results( $query, ARRAY_A );
	
		$counts = [];

		foreach ( $results as $row ) {
			$counts[ $row['post_mime_type'] ] = $row['num_posts'];
		}

		if (!rvy_get_option('pending_revisions')) {
			unset($counts['draft-revision']);
			unset($counts['pending-revision']);
		}

		if (!rvy_get_option('scheduled_revisions')) {
			unset($counts['future-revision']);
		}

		$counts = (object) $counts;

		return apply_filters( 'revisionary_count_revisions', $counts, $post_type, $statuses );
	}

	/**
	 *
	 * @return array
	 */
	protected function get_views() {
		global $wp_query, $wpdb, $current_user;
		
		$post_types = rvy_get_manageable_types();
		$revision_statuses = rvy_revision_statuses();

		$num_posts = $this->count_revisions($post_types, $revision_statuses);

		$links = [];

		$links[''] = '';
		$links['all'] = '';

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

		$status_clause = apply_filters('revisionary_require_base_statuses', true) 
		? "AND $wpdb->posts.post_status IN ('draft', 'pending', 'future')" 
		: '';
		
		$where = $this->revisions_where_filter( 
			$wpdb->prepare(
				"$wpdb->posts.post_mime_type IN ('$revision_status_csv') $status_clause AND $wpdb->posts.post_author = '%d'",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user->ID
			),
			['status_count' => true]
		);

		$_request = apply_filters('presspermit_posts_request',
				"SELECT COUNT($wpdb->posts.ID) FROM $wpdb->posts WHERE $where", 
				['has_cap_check' => true]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		if ($my_count = $wpdb->get_var($_request)) {
			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (!empty($_REQUEST['author']) && ($current_user->ID == $_REQUEST['author']) && empty($_REQUEST['post_type']) && empty($_REQUEST['post_author']) && empty($_REQUEST['published_post']) && empty($_REQUEST['post_status'])) {
				$current_link_class = 'mine';
				$link_class = " class='current'";
			} else {
				$link_class = '';
			}

			$links['mine'] = sprintf(
				translate_nooped_plural(pp_revisions_label('my_revisions'), $my_count), 
				"<a href='admin.php?page=revisionary-q&author=$current_user->ID'{$link_class}>", '</a>', "<span class='count'>$my_count</span>"
			) .'&nbsp;';
		}

		$where = $this->revisions_where_filter( 
			$wpdb->prepare(
				"r.post_mime_type IN ('$revision_status_csv') AND p.post_author = '%d'", 	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user->ID
			),
			['alias' => 'r', 'status_count' => true, 'my_published_count' => true]
		);

		$count_query = apply_filters('presspermit_posts_request',
			"SELECT COUNT(r.ID) FROM $wpdb->posts AS p INNER JOIN $wpdb->posts AS r ON r.comment_count = p.ID WHERE $where", 
			['has_cap_check' => true, 'source_alias' => 'p']
		);

		$status_csv = implode("','", array_map('sanitize_key', rvy_filtered_statuses()));
		$count_query .= " AND p.post_status IN ('$status_csv') AND r.post_status != 'trash'";

		// work around some versions of PressPermit inserting non-aliased post_type reference into where clause under some configurations
		$count_query = str_replace("$wpdb->posts.post_type ", "p.post_type ", $count_query);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ($my_post_count = $wpdb->get_var( 
			$count_query						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		)) {
			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (!empty($_REQUEST['post_author']) && ($current_user->ID == $_REQUEST['post_author']) && empty($_REQUEST['post_type']) && empty($_REQUEST['author']) && empty($_REQUEST['published_post']) && empty($_REQUEST['post_status'])) {
				$current_link_class = 'my_posts';
				$link_class = " class='current'";
			} else {
				$link_class = '';
			}

			$links['my_posts'] = sprintf(
				translate_nooped_plural(pp_revisions_label('my_published_posts'), $my_post_count), 
				"<a href='admin.php?page=revisionary-q&post_author=$current_user->ID'{$link_class}>", '</a>', "<span class='count'>$my_post_count</span>"
			) . '&nbsp;';
		}

		$all_count = 0;
		foreach($revision_statuses as $status) {
			if (!isset($num_posts->$status)) {
				$num_posts->$status = 0;
			}

			if (!empty($num_posts->$status)) {
				$status_obj = get_post_status_object($status);

				$status_label = $status_obj ? sprintf(
					translate_nooped_plural( $status_obj->label_count, $num_posts->$status ),
					number_format_i18n( $num_posts->$status )
				) : $status;

				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if (!empty($_REQUEST['post_status']) && ($status == sanitize_key($_REQUEST['post_status'])) && empty($_REQUEST['post_type']) && empty($_REQUEST['author']) && empty($_REQUEST['post_author']) && empty($_REQUEST['published_post'])) {
					$current_link_class = $status;
					$link_class = " class='current'";
				} else {
					$link_class = '';
				}
				$links[$status] = "<a href='admin.php?page=revisionary-q&post_status=$status'{$link_class}>$status_label</a>";

				$all_count += $num_posts->$status;
			}
		}

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (empty($current_link_class) && empty($_REQUEST['post_type']) && empty($_REQUEST['author']) && empty($_REQUEST['post_author']) && empty($_REQUEST['published_post']) && empty($_REQUEST['post_status']) && empty($_REQUEST['all'])) {
			$link_class = " class='current'";
		} else {
			$link_class = '';
		}

		$links[''] = "<a href='admin.php?page=revisionary-q'{$link_class}>" . sprintf( esc_html__('My Activity', 'revisionary'), "<span class='count'>($all_count)</span>" ) . '</a><span class="ppr-my-activity-count"></span>';

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (empty($current_link_class) && empty($_REQUEST['post_type']) && empty($_REQUEST['author']) && empty($_REQUEST['post_author']) && empty($_REQUEST['published_post']) && empty($_REQUEST['post_status']) && !empty($_REQUEST['all'])) {
			$link_class = " class='current'";
		} else {
			$link_class = '';
		}

		$links['all'] = "<a href='admin.php?page=revisionary-q&all=1'{$link_class}>" . sprintf( esc_html__('All %s', 'revisionary'), "<span class='count'>($all_count)</span>" ) . '</a>';
		
		return apply_filters('revisionary_queue_view_links', $links);
	}

	/**
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		global $current_user;

		$actions = array();

		$approval_potential = false;

		foreach(rvy_get_manageable_types() as $post_type) {
			$type_obj = get_post_type_object($post_type);
			if (isset($type_obj->cap->edit_published_posts) && !empty($current_user->allcaps[$type_obj->cap->edit_published_posts])) {
				$approval_potential = true;
				break;
			}
		}

		$actions['submit_revision'] = esc_html__('Submit');

		if ($approval_potential = apply_filters('revisionary_bulk_action_approval', $approval_potential)) {
			$actions['approve_revision'] = esc_html__('Approve');
			$actions['decline_revision'] = esc_html__('Decline');
			$actions['publish_revision'] = esc_html__('Publish');

			if (rvy_get_option('scheduled_revisions')) {
				$actions['unschedule_revision'] = esc_html__('Unschedule', 'revisionary');
			}
		}

		$actions['delete'] = (defined('RVY_DISCARD_CAPTION')) ? esc_html__( 'Discard Revision', 'revisionary' ) : esc_html__( 'Delete Revision', 'revisionary' );

		return $actions;
	}

	protected function categories_dropdown( $post_type ) {
		if ( false !== apply_filters( 'disable_categories_dropdown', false, $post_type ) ) {
			return;
		}

		$cat = (!empty($_REQUEST['cat'])) ? (int) $_REQUEST['cat'] : '';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$dropdown_options = array(
			'show_option_all' => get_taxonomy( 'category' )->labels->all_items,
			'hide_empty' => 0,
			'hierarchical' => 1,
			'show_count' => 0,
			'orderby' => 'name',
			'selected' => $cat
		);

		echo '<label class="screen-reader-text" for="cat">' . esc_html__( 'Filter by category' ) . '</label>';
		wp_dropdown_categories( $dropdown_options );
	}

	
	protected function extra_tablenav( $which ) {
?>
		<div class="alignleft actions">
		</div>
<?php
		do_action( 'manage_posts_extra_tablenav', $which );
	}

	/**
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return [
			'title'    => 'title',
			'post_status' => 'post_status',
			'post_type' => 'post_type',
			'author'	=> 'author',
			'date'     => array( 'modified', true ),
			'date_sched'     => array( 'date_sched', true ),
			'published_post' => 'published_post',
			'post_author'	=> 'post_author',
		];
	}
	
	// Overriding parent class method here to make column sort link double as filter clearance (todo: jQuery?)
	public function print_column_headers( $with_id = true ) {		
		list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

		if (!empty($_SERVER['REQUEST_URI']) && !empty($_SERVER['HTTP_HOST'])) {
			$current_url = set_url_scheme( esc_url(esc_url_raw($_SERVER['HTTP_HOST']) . esc_url_raw($_SERVER['REQUEST_URI']) ));
			$current_url = remove_query_arg( 'paged', $current_url );
		} else {
			$current_url = '';
		}

		if ( isset( $_GET['orderby'] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_orderby = sanitize_key($_GET['orderby']);			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$current_orderby = '';
		}

		if ( isset( $_GET['order'] ) && 'desc' === $_GET['order'] ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_order = 'desc';
		} else {
			$current_order = 'asc';
		}

		if ( ! empty( $columns['cb'] ) ) {
			static $cb_counter = 1;
			$columns['cb']     = '<label class="screen-reader-text" for="cb-select-all-' . $cb_counter . '">' . esc_html__( 'Select All' ) . '</label>'
				. '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />';
			$cb_counter++;
		}

		foreach ( $columns as $column_key => $column_display_name ) {
			$class = array( 'manage-column', "column-$column_key" );

			if ( in_array( $column_key, $hidden ) ) {
				$class[] = 'hidden';
			}

			if ( 'cb' === $column_key ) {
				$class[] = 'check-column';
			} elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ) ) ) {
				$class[] = 'num';
			}

			if ( $column_key === $primary ) {
				$class[] = 'column-primary';
			}

			if ( isset( $sortable[ $column_key ] ) ) {
				list( $orderby, $desc_first ) = $sortable[ $column_key ];

				if ( $current_orderby === $orderby ) {
					$order   = 'asc' === $current_order ? 'desc' : 'asc';
					$class[] = 'sorted';
					$class[] = $current_order;
				} else {
					$order   = $desc_first ? 'desc' : 'asc';
					$class[] = 'sortable';
					$class[] = $desc_first ? 'asc' : 'desc';
				}
			}

			$tag   = ( 'cb' === $column_key ) ? 'td' : 'th';
			$scope = ( 'th' === $tag ) ? 'col' : '';
			$id    = $with_id ? $column_key : '';

			if ( ! empty( $class ) ) {
				$class = join( ' ', $class );
			}

			echo "<" . esc_attr($tag) . " scope='" . esc_attr($scope) . "' id='" . esc_attr($id) . "' class='" . esc_attr($class) . "'>";
			
			$current_url = str_replace('#038;', '&', $current_url);
			$current_url = remove_query_arg('orderby', $current_url);
			$current_url = remove_query_arg('order', $current_url);

			if ( isset( $sortable[ $column_key ] ) ) {
				// kevinB modification: make column sort links double as filter clearance
				// (If results are already filtered by column, first header click clears the filter, second click applies sorting)

				if (!empty($_REQUEST[$column_key])) {						//phpcs:ignore WordPress.Security.NonceVerification.Recommended

					// use post status and post type column headers to reset filter, but not for sorting
					$_url = remove_query_arg($orderby, $current_url);
					
					echo '<a href="' . esc_url($_url) . '"><span>' . esc_html($column_display_name) . '</span><span class="sorting-indicator"></span></a>';
				} else {
																			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if (!empty($_REQUEST['orderby']) && ($column_key == $_REQUEST['orderby'])) {
						echo '<a href="' . esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ) . '"><span>' . esc_html($column_display_name) . '</span><span class="sorting-indicator"></span></a>';
					} else {
						echo '<a href="' . esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ) . '"><span>' . esc_html($column_display_name) . '</span></a>';
					}
				}
			} elseif ('cb' == $column_key) {
				echo $column_display_name;									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo esc_html($column_display_name);	
			}

			echo "</" . esc_attr($tag) .">";
		}
	}

	public function column_title( $post, $simple_link = false ) {
		$can_edit_post = current_user_can( 'edit_post', $post->ID) || $simple_link;

		echo "<strong>";

		$title = _draft_or_post_title($post);

		if ( $can_edit_post && $post->post_status != 'trash' && $edit_link = get_edit_post_link( $post->ID )) {
			printf(
				'<a class="row-title" href="%s" aria-label="%s">%s%s</a>',
				esc_url($edit_link),
				/* translators: %s: post title */
				esc_attr( sprintf( esc_html__( '&#8220;%s&#8221; (Edit)' ), $title ) ),
				'',
				esc_attr($title)
			);
		} else {
			echo esc_html($title);
		}

		echo "</strong>\n";
	}

	public function column_date( $post ) {
		$t_time = get_the_modified_time( esc_html__( 'Y/m/d g:i:s a', 'revisionary' ), $post );
		$time = strtotime($post->post_modified_gmt);
		$time_diff = time() - $time;

		if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
			$h_time = sprintf( esc_html__( '%s ago' ), human_time_diff( $time ) );
			$h_time = str_replace( ' ', '&nbsp;', $h_time );
		} else {
			$h_time = mysql2date( esc_html__( 'Y/m/d g:i a', 'revisionary' ), $t_time );
			$h_time = str_replace( ' am', '&nbsp;am', $h_time );
			$h_time = str_replace( ' pm', '&nbsp;pm', $h_time );
			$h_time = str_replace( ' ', '<br />', $h_time );
		}
		
														 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<abbr title="' . esc_attr($t_time) . '">' . apply_filters( 'post_date_column_time', $h_time, $post, 'date' ) . '</abbr>';
	}
	
	protected function apply_edit_link( $url, $label ) {
		printf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html($label)
		);
	}

	public function column_author( $post ) {
		// Just track single post_author for revision. Authors taxonomy is applied to revise

		$request_url = add_query_arg($_REQUEST, rvy_admin_url('admin.php?page=revisionary-q'));				//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = ['author' => get_the_author_meta( 'ID' )];
		$this->apply_edit_link( add_query_arg('author', $args['author'], esc_url($request_url)), get_the_author_meta('display_name', $args['author']) );
	}

	/**
	 * Generates and displays row action links.
	 *
	 * @since 4.3.0
	 * @access protected
	 *
	 * @param object $post        Post being acted upon.
	 * @param string $column_name Current column name.
	 * @param string $primary     Primary column name.
	 * @return string Row actions output for posts.
	 */

	protected function handle_row_actions( $post, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$post_type_object = get_post_type_object( $post->post_type );

		$can_read_post = current_user_can('read_post', $post->ID);

		$can_edit_post    = current_user_can( 'edit_post', $post->ID );

		$can_read_post = $can_read_post || $can_edit_post; // @todo

		$actions          = array();
		$title            = _draft_or_post_title();

		$main_post_id = rvy_post_id($post->ID);

		if ( $can_edit_post && 'trash' != $post->post_status ) {
			if ($edit_link = get_edit_post_link( $post->ID )) {
				$actions['edit'] = sprintf(
					'<a href="%1$s" title="%2$s" aria-label="%2$s">%3$s</a>',
					get_edit_post_link( $post->ID ),
					/* translators: %s: post title */
					esc_attr('Edit Revision'),
					esc_html__( 'Edit' )
				);
			}

			if ($main_post_id 
			&& in_array($post->post_status, array_merge(['draft', 'pending'], rvy_revision_statuses()))
			&& current_user_can('copy_post', $main_post_id)
			) {
				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';
				$url = rvy_admin_url("admin.php?page=rvy-revisions&amp;post={$post->ID}&amp;action=revise$redirect_arg");
				$actions['copy_revision'] = "<a href='$url'>" . esc_html__('Copy') . '</a>';
			}
		}

		if ( current_user_can( 'delete_post', $post->ID ) ) {
			if ($delete_link = get_delete_post_link( $post->ID, '', true )) {
				if (defined('RVY_DISCARD_CAPTION')) {
					$actions['delete'] = sprintf(
						'<a href="%1$s" class="submitdelete" title="%2$s" aria-label="%2$s">%3$s</a>',
						$delete_link,
						/* translators: %s: post title */
						esc_attr( sprintf( esc_html__( 'Discard Revision', 'revisionary' ), $title ) ),
						esc_html__( 'Discard' )
					);
				} else {
					$delete_caption = (defined('RVY_DISCARD_CAPTION')) ? esc_html__( 'Discard Revision', 'revisionary' ) : esc_html__( 'Delete Revision', 'revisionary' );

					$actions['delete'] = sprintf(
						'<a href="%1$s" class="submitdelete" title="%2$s" aria-label="%2$s">%3$s</a>',
						$delete_link,
						/* translators: %s: post title */
						esc_attr( sprintf( $delete_caption, $title ) ),
						esc_html__( 'Delete' )
					);
				}
			}
		}

		if ( is_post_type_viewable( $post_type_object ) ) {
			if ($can_read_post && $post_type_object && !empty($post_type_object->public)) {
				if (rvy_get_option('revision_preview_links') || current_user_can('administrator') || is_super_admin()) {
					do_action('pp_revisions_get_post_link', $post->ID);

					$preview_link = rvy_preview_url($post);

					$preview_link = remove_query_arg( 'preview_id', $preview_link );
					$actions['view'] = sprintf(
						'<a href="%1$s" rel="bookmark" title="%2$s" aria-label="%2$s">%3$s</a>',
						esc_url( $preview_link ),
						/* translators: %s: post title */
						esc_attr( esc_html__( 'Preview Revision', 'revisionary' ) ),
						esc_html__( 'Preview' )
					);

					do_action('pp_revisions_post_link_done', $post->ID);
				}
			}
		}

		// todo: make this work for Author with Revision exceptions
		if ($can_edit_post) {  
			$actions['diff'] = sprintf(
				'<a href="%1$s" class="" title="%2$s" aria-label="%2$s" target="_revision_diff">%3$s</a>',
				admin_url("revision.php?revision=$post->ID"),
				/* translators: %s: post title */
				esc_attr( sprintf( esc_html__('Compare Changes', 'revisionary'), $title ) ),
				_x('Compare', 'revisions', 'revisionary')
			);
		}

		if ($can_edit_post && ('pending-revision' == $post->post_mime_type)) {
			$actions['decline'] = sprintf(
				'<a href="%1$s" class="" target="_revision_diff">%2$s</a>',
				wp_nonce_url(admin_url("post.php?post=$post->ID&action=decline_revision"), 'decline-revision'),
				_x( 'Decline', 'revisions', 'revisionary' )
			);
		}

		$actions = apply_filters('revisionary_queue_row_actions', $actions, $post);

		return $this->row_actions( $actions );
	}

	// override default nonce field
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-revision-queue' );
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
	 * Outputs the hidden row displayed when inline editing
	 *
	 * @since 3.1.0
	 *
	 * @global string $mode
	 */
	public function inline_edit() {
	}
}
