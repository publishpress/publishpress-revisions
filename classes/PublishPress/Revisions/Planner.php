<?php
namespace PublishPress\Revisions;

class Planner {
	private $show_revisions;
	private $usermeta_key;
	private $plugin_page = '';

    function init($plugin_page) {
		$this->plugin_page = $plugin_page;

		if (is_admin() && defined('DOING_AJAX') && DOING_AJAX) {
			add_filter('preview_post_link', 
				function($preview_link, $post) {
					if (did_action('wp_ajax_publishpress_calendar_get_data') && rvy_in_revision_workflow($post)) {
						$preview_link = rvy_preview_url($post);
					}

					return $preview_link;
				},
				10, 2
			);

			add_filter('get_delete_post_link', 
				function ($delete_link, $post_id, $force_delete) {
					if (did_action('wp_ajax_publishpress_calendar_get_data') && rvy_in_revision_workflow($post_id)) {
						if ($post_type_object = get_post_type_object(get_post_field('post_type', $post_id))) {
							$delete_link = add_query_arg( 'action', 'delete', admin_url( sprintf( $post_type_object->_edit_link, $post_id ) ) );
							$delete_link = wp_nonce_url( $delete_link, "delete-post_{$post_id}" );
						}
					}

					return $delete_link;
				},
				10, 3
			);
		}
		
		$this->usermeta_key = 'pp_calendar_filters'; // default
		
		if ('pp-calendar' == $plugin_page) {
			add_filter(
				'publishpress_item_action_links', 
				function ($item_actions, $post, $can_edit_post) {
					if (!empty($item_actions['trash']) && rvy_in_revision_workflow($post)) {
						$item_actions['trash'] = get_delete_post_link($post->ID, false, true);
					}

					return $item_actions;
				},
				10, 3
			);

			add_action(
				'admin_print_scripts',
				function() {
					?>
					<script type="text/javascript">
					/* <![CDATA[ */
					jQuery(document).ready(function ($) {
						setInterval(() => {
							if ($('div.publishpress-calendar-popup-form:visible').length) {
								$('#publishpress-calendar-field-revision_status').closest('tr').hide();
							}
						}, 100);
					});
					/* ]]> */
					</script>
					<?php
				}, 50
			);

		} elseif ('pp-content-board' == $plugin_page) {
			$this->usermeta_key = 'PP_Content_Board_filters';
			
			require_once(dirname(__FILE__).'/PlannerContentBoard.php');
			new \PublishPress\Revisions\PlannerContentBoard($this);

		} elseif ('pp-content-overview' == $plugin_page) {
			$this->usermeta_key = 'PP_Content_Overview_filters';

			add_action('init', [$this, 'maybeFilterContentOverviewItemActions']);
		}

		if (in_array($plugin_page, ['pp-calendar', 'pp-content-overview'])) {
			add_filter('publishpress_user_post_status_options', [$this, 'fltUserPostStatusOptions'], 20, 3);
		}

		add_action('init', function() {
			if ($this->showingRevisions()) {
				add_filter('preview_post_link',
					function($preview_link, $post) {
						if (rvy_in_revision_workflow($post)) {
							$preview_link = rvy_preview_url($post);
						}

						return $preview_link;
					},
					10, 2
				);
			}
		});
		
		add_filter('_presspermit_get_post_statuses', [$this, 'fltPostStatuses'], 10, 4);

		add_action(
			'admin_print_scripts',
			function() {
				?>
				<script type="text/javascript">
				/* <![CDATA[ */
				jQuery(document).ready(function ($) {
					<?php if ($this->showingRevisions()) :?>
					setInterval(() => {
						if ($('#pp-content-calendar-general-modal-container:visible, #pp-content-overview-general-modal-container:visible').length) {
							$('div.pp-popup-modal-header div.post-delete a').html('<?php esc_html_e('Delete');?>');
						}
					}, 100);
					<?php endif;?>
				});
				/* ]]> */
				</script>
				<?php
			}, 50
		);

		add_filter('publishpress_content_board_new_post_statuses', [$this, 'fltNewPostStatuses'], 10, 2);
		add_filter('publishpress_content_overview_new_post_statuses', [$this, 'fltNewPostStatuses'], 10, 2);

        add_filter('publishpress_calendar_post_statuses', [$this, 'fltCalendarPostStatuses']);

		add_filter('PP_Content_Overview_posts_query_args', [$this, 'fltCombinedPostsRevisionsQueryArgs'], 10, 2);
		add_filter('pp_calendar_posts_query_args', [$this, 'fltCombinedPostsRevisionsQueryArgs'], 10, 2);
	}

	public function showingRevisions() {
        global $current_user;

		if (!isset($this->show_revisions)) {
			if (!class_exists('PP_Revision_Integration')) {
				$this->show_revisions = false;

			} elseif (isset($_REQUEST['hide_revision'])) {												// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->show_revisions = empty($_REQUEST['hide_revision']);								// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				$user_filters = apply_filters(
					'pp_get_user_meta', 
					get_user_meta($current_user->ID, $this->usermeta_key, true), 
					$current_user->ID, 
					$this->usermeta_key, 
					true
				);

				$this->show_revisions = empty($user_filters['hide_revision']);
			}
		}

		return $this->show_revisions;
    }

	public function fltPostStatuses($post_statuses, $status_args, $return_args, $function_args) {
		if (!class_exists('PP_Revision_Integration') || ('pp-content-board' != $this->plugin_page)) {
			return $post_statuses;
		}

		$revision_statuses = rvy_revision_statuses();
	
		$permissions_compat_mode = rvy_get_option('permissions_compat_mode');

		foreach ($post_statuses as $k => $status) {
			$status_name = (is_object($status)) ? $status->name : $status;

			if ($this->showingRevisions()) {
				if ($permissions_compat_mode) {
					if (!in_array($status_name, $revision_statuses)) {
						unset($post_statuses[$k]);
					}
				}
			} else {
				if (in_array($status_name, $revision_statuses)) {
					unset($post_statuses[$k]);
				}
			}
		}

		return $post_statuses;
	}

	public function fltCalendarPostStatuses($statuses) {
		if (('pp-content-board' == $this->plugin_page) && $this->showingRevisions()) {
			$revision_statuses = rvy_revision_statuses();

			foreach ($statuses as $k => $status_obj) {
				if (!class_exists('PP_Revision_Integration')) {
					if (!empty($status_obj->slug) && in_array($status_obj->slug, $revision_statuses)) {
						unset($statuses[$k]);
					}

				} elseif ($this->showingRevisions()) {
					if (!empty($status_obj->slug) && !in_array($status_obj->slug, $revision_statuses)) {
						unset($statuses[$k]);
					}
				} else {
					if (!empty($status_obj->slug) && in_array($status_obj->slug, $revision_statuses)) {
						unset($statuses[$k]);
					}
				}
			}
		} elseif (did_action('wp_ajax_publishpress_calendar_get_post_type_fields')) {
			$revision_statuses = rvy_revision_statuses();

			foreach ($statuses as $k => $status_obj) {
				if (!empty($status_obj->slug) && in_array($status_obj->slug, $revision_statuses)) {
					unset($statuses[$k]);
				}
			}
		}

		if ('pp-content-board' == $this->plugin_page) {
			if (!defined('PUBLISHPRESS_STATUSES_PRO_VERSION') && $this->showingRevisions() && !rvy_get_option('permissions_compat_mode')) {
				$statuses = rvy_revision_statuses(['output' => 'object']);

				foreach($statuses as $k => $status) {
					if (!is_object($status) || empty($status->name)) {
						continue;
					}

					$statuses[$k]->slug = $status->name;
				}
			}
		}

		return $statuses;
	}

	function fltNewPostStatuses($statuses, $args) {
		$revision_statuses = rvy_revision_statuses();

		foreach ($statuses as $k => $status) {
			if (is_array($status) && in_array($status['value'], $revision_statuses)) {
				unset($statuses[$k]);
				$did_unset = true;
			}
		}

		if (!empty($did_unset)) {
			$statuses = array_values($statuses);
		}

		return $statuses;
	}

	public function maybeFilterContentOverviewItemActions() {
		add_filter('PP_Content_Overview_item_actions', [$this, 'fltContentOverviewItemActions'], 10, 2);
	}

	public function fltContentOverviewItemActions($actions, $post_id) {
		// @todo: support revision trashing
		if (rvy_in_revision_workflow($post_id)) {
			$actions['trash'] = '<a class="submitdelete" href="' . esc_url(get_delete_post_link($post_id, false, true)) . '">' . esc_html__('Delete') . '</a>';
		}

		return $actions;
	}

	public function fltCombinedPostsRevisionsQueryArgs ($args) {
		if (!$this->showingRevisions()) {
			return $args;
		}

		if (('pp-calendar' == $this->plugin_page) && isset($args['revision_status'])) {
			$revision_status = sanitize_key($args['revision_status']);

		} elseif (isset($_REQUEST['revision_status'])) {												// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$revision_status = sanitize_key($_REQUEST['revision_status']);								// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		} else {
			$revision_status = '';
		}

		if (isset($args['post_status'])) {
			$post_status = sanitize_key($args['post_status']);

		} elseif (isset($_REQUEST['post_status'])) {													// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_status = sanitize_key($_REQUEST['post_status']);										// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		} else {
			$post_status = '';
		}

		if ('all' == $revision_status) {
			$revision_status = '';
		}

		// pass requested post_status, revision_status into anonymous function
		add_filter(
			'posts_where', 
			function ($where, $wp_query) use ($post_status, $revision_status) {
				global $wpdb;
				static $done;

				// only modify first posts query we see
				if (!empty($done)) {
					return $where;
				}

				$done = true;

				$revision_statuses = rvy_revision_statuses();

				$revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

				if ('' == $post_status) {
					global $publishpress;

					$statuses = (!empty($publishpress) && method_exists($publishpress, 'getPostStatuses')) 
					? $publishpress->getPostStatuses()
					: get_post_stati(['internal' => false], 'object');

					$status_objects = apply_filters('publishpress_calendar_post_statuses', $statuses);
					$post_status_csv = implode("','", array_column($status_objects, 'name'));
					$post_status_clause = "$wpdb->posts.post_status IN ('$post_status_csv') AND $wpdb->posts.post_mime_type NOT IN ('$revision_status_csv')";

				} elseif ('_' == $post_status) {
					$post_status_clause = "1=2";

				} else {
					$post_status_clause = $wpdb->prepare("$wpdb->posts.post_status = %s AND $wpdb->posts.post_mime_type NOT IN ('$revision_status_csv')", $post_status);	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				if ($revision_status) {
					if (!in_array($revision_status, $revision_statuses)) {
						$revision_status_clause = '1=2';
					} else {
						$revision_status_clause = $wpdb->prepare("$wpdb->posts.post_mime_type = %s", $revision_status);
					}
				} else {
					$revision_status_clause = "$wpdb->posts.post_mime_type IN ('$revision_status_csv')";
				}

				$where = str_replace(
					"($wpdb->posts.post_status = 'draft')", 
					"(" 
					. "($post_status_clause)"
					. ' OR '
					. "($revision_status_clause)"
					. ")",
					$where
				);

				return $where;
			},
			10, 2
		);

		$args['post_status'] = 'draft';
		unset($args['revision_status']);
		
		return $args;
	}

	function fltUserPostStatusOptions($status_options, $post_type = false, $post = false) {
		if (class_exists('PP_Revision_Integration') && $post) {
			static $revision_statuses;

			if (!isset($revision_statuses)) {
				$revision_statuses = [];
			}

			if (!isset($revision_statuses[$post_type])) {
				if (class_exists('PublishPress_Statuses') && defined('PUBLISHPRESS_STATUSES_PRO_VERSION')) {
					$revision_statuses[$post_type] = \PublishPress_Statuses::instance()->getPostStatuses(['for_revision' => true, 'post_type' => $post_type], 'object');
				} else {
					$revision_statuses[$post_type] = rvy_revision_statuses(['output' => 'object']);
				}
			}

			if (rvy_in_revision_workflow($post)) {
				$status_options = [];

				foreach ($revision_statuses[$post_type] as $status_obj) {
					$status_options [] = ['value' => $status_obj->name, 'text' => $status_obj->label];
				}
			} else {
				if (isset($revision_statuses[$post_type])) {
					$_revision_statuses = array_column($revision_statuses[$post_type], 'name');

					foreach ($status_options as $k => $status) {
						if (is_array($status) && in_array($status['value'], $_revision_statuses)) {
							unset($status_options[$k]);
							$did_unset = true;
						}
					}

					if (!empty($did_unset)) {
						$status_options = array_values($status_options);
					}
				}
			}
		}

		return $status_options;
	}
}
