<?php
namespace PublishPress\Revisions;

class PluginCompat {
    function __construct() {
        global $revisionary;

        // Project Nami
        add_filter( 'wp_insert_post_data', array($this, 'fltODBCworkaround'), 101, 2 );

        // Prevent PublishPress editorial comment insertion from altering comment_count field
		add_action('pp_post_insert_editorial_comment', [$this, 'actInsertEditorialCommentPreserveCommentCount']);

        add_filter('publishpress_notif_workflow_receiver_post_authors', [$this, 'flt_publishpress_notification_authors'], 10, 3);

        add_filter('presspermit_exception_clause', [$this, 'fltPressPermitExceptionClause'], 10, 4);

        if (defined('CUSTOM_PERMALINKS_PLUGIN_VERSION') && !is_admin() && !$revisionary->doing_rest) {

			function enable_custom_permalinks_workaround($retval) {
				add_filter('query', [$this, 'flt_custom_permalinks_query']);
				return $retval;
			}
		
			function disable_custom_permalinks_workaround($retval) {
				remove_filter('query', [$this, 'flt_custom_permalinks_query']);
				return $retval;
			}

			add_filter('custom_permalinks_request_ignore', function($retval) {
				add_filter('query', [$this, 'flt_custom_permalinks_query']);
				return $retval;
			});

			add_filter('cp_remove_like_query', function($retval) {
				remove_filter('query', [$this, 'flt_custom_permalinks_query']);
				return $retval;
			});
		}

		add_filter('authors_default_author', [$this, 'fltAuthorsDefaultAuthor'], 10, 2);

		if (defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6.0-beta', '>=')) {
			global $pagenow;

			if (is_admin() && !empty($pagenow) && ('admin.php' == $pagenow) && !empty($_REQUEST['page'])) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$plugin_page = sanitize_key($_REQUEST['page']);													// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	
			} elseif (is_admin() && defined('DOING_AJAX') && DOING_AJAX && !empty($_REQUEST['action'])) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$action = sanitize_key($_REQUEST['action']);													// phpcs:ignore WordPress.Security.NonceVerification.Recommended

				switch ($action) {
					case 'publishpress_calendar_get_post_type_fields':
					case 'publishpress_calendar_get_data':
						$plugin_page = 'pp-calendar';
						break;

					case 'publishpress_content_board_update_post_status':
						$plugin_page = 'pp-content-board';
						break;
				}
			}
			
			if (!empty($plugin_page) 
			&& in_array($plugin_page, ['pp-calendar', 'pp-content-board', 'pp-content-overview'])
			&& (apply_filters('revisionary_planner_filters', true, $plugin_page))
			) {
				global $revisionary_planner_compat;

				require_once(dirname(__FILE__).'/Planner.php');
				$revisionary_planner_compat = new \PublishPress\Revisions\Planner();
				$revisionary_planner_compat->init($plugin_page);
			}
		}
    }

	function fltAuthorsDefaultAuthor($default_author, $post) {
		if (rvy_in_revision_workflow($post)) {
			return false;
		}

		return $default_author;
	}

    function fltODBCworkaround($data, $postarr) {
		// ODBC does not support UPDATE of ID
		if (function_exists('get_projectnami_version')) {
			unset($data['ID']);
		}

		return $data;
    }
    
    function fltPressPermitExceptionClause($clause, $required_operation, $post_type, $args) {
		global $revisionary;
		
		if (empty($revisionary->enabled_post_types[$post_type]) && $revisionary->config_loaded) {
			return $clause;
		}

		if (('edit' == $required_operation) && in_array($post_type, rvy_get_manageable_types()) && rvy_get_option('apply_post_exceptions') 
		) {
			foreach(['mod', 'src_table', 'logic', 'ids'] as $var) {
				if (!empty($args[$var])) {
					$$var =  $args[$var];
				} else {
					return $clause;
				}
			}

			$revision_base_status_csv = implode("','", array_map('sanitize_key', rvy_revision_base_statuses()));
			$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

			if ('include' == $mod) {
				$clause = "(($clause) OR ($src_table.post_status IN ('$revision_base_status_csv') AND $src_table.post_mime_type IN ('$revision_status_csv') AND $src_table.comment_count IN ('" . implode("','", array_map('intval', $ids)) . "')))";
			} elseif ('exclude' == $mod) {
				$clause = "(($clause) AND ($src_table.post_mime_type NOT IN ('$revision_status_csv') OR $src_table.comment_count NOT IN ('" . implode("','", array_map('intval', $ids)) . "')))";
			}
		}

		return $clause;
    }
    
    // Restore comment_count field (main post ID) on PublishPress editorial comment insertion
	function actInsertEditorialCommentPreserveCommentCount($comment) {
		global $wpdb;

		if ($comment && !empty($comment->comment_post_ID)) {
			if ($_post = get_post($comment->comment_post_ID)) {
				if (rvy_in_revision_workflow($_post)) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->posts, 
						['comment_count' => rvy_post_id($comment->comment_post_ID)], 
						['ID' => $comment->comment_post_ID]
					);
				}
			}
		}
	}

    /**
	 * Filters the list of PublishPress Notification receivers, but triggers only when "Authors of the Content" is selected in Notification settings.
	 *
	 * @param array $receivers
	 * @param WP_Post $workflow
	 * @param array $args
	 */
	function flt_publishpress_notification_authors($receivers, $workflow, $args) {
		global $current_user;

		if (empty($args['post']) || !rvy_in_revision_workflow($args['post'])) {
			return $receivers;
		}

		$revision = $args['post'];
		$recipient_ids = [];

		if ($revision->post_author != $current_user->ID) {
			$recipient_ids []= $revision->post_author;
		}

		$post_author = get_post_field('post_author', rvy_post_id($revision->ID));
		
		if (!in_array($post_author, [$current_user->ID, $revision->post_author])) {
			$recipient_ids []= $post_author;
		}

		foreach($recipient_ids as $user_id) {
			$channel = get_user_meta($user_id, 'psppno_workflow_channel_' . $workflow->ID, true);

			// If no channel is set yet, use the default one
			if (empty($channel)) {
				if (!isset($notification_options)) {
					// Avoid reference to PublishPress module class, object schema
					$notification_options = get_option('publishpress_improved_notifications_options');
				}

				if (!empty($notification_options) && !empty($notification_options->default_channels)) {
					if (!empty($notification_options->default_channels[$workflow->ID])) {
						$channel = $notification_options->default_channels[$workflow->ID];
					}
				}
			}

			// @todo: config retrieval method for Slack, other channels
			if (!empty($channel) && ('email' == $channel)) {
				if ($user = new WP_User($user_id)) {
					$receivers []= "{$channel}:{$user->user_email}";
				}
			}
		}

		return $receivers;
    }
    
    function flt_custom_permalinks_query($query) {
		global $wpdb;

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

		if (strpos($query, " WHERE pm.meta_key = 'custom_permalink' ") && strpos($query, "$wpdb->posts AS p")) {
			$query = str_replace(
				" ORDER BY FIELD(",
				" AND p.post_mime_type NOT IN ('$revision_status_csv') ORDER BY FIELD(",
				$query
			);
		}

		return $query;
	}
}
