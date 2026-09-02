<?php
namespace PublishPress\Revisions;

class PlannerContentBoard {
    private $plannerCompat;

    function __construct($plannerCompat) {
        $this->plannerCompat = $plannerCompat;

		if (!defined('PUBLISHPRESS_CALENDAR_REVISION_PREFIX')) {
			define('PUBLISHPRESS_CALENDAR_REVISION_PREFIX', '');
		}

        add_action('init', function() use ($plannerCompat) {
            if ($plannerCompat->showingRevisions()) {
                add_filter('PP_Content_Board_item_actions',
                    function($actions, $post_id) {
                        // @todo: support revision trashing
                        if (rvy_in_revision_workflow($post_id)) {
                            $actions['trash'] = '<a class="submitdelete" href="' . esc_url(get_delete_post_link($post_id, '', true)) . '">' . esc_html__('Delete') . '</a>';
                        }

                        return $actions;
                    },
                    10, 2
                );
            }
        });

        add_filter(
			'publishpress_user_post_status_options', 
			function($status_options, $post_type = false) use ($plannerCompat) {
                if (class_exists('PP_Revision_Integration') && $plannerCompat->showingRevisions()) {
                    foreach ($status_options as $k => $opt) {
                        if (isset($opt['value']) && ('future-revision' == $opt['value'])) {
                            unset($status_options[$k]);
                        }
                    }

                    if (version_compare('PUBLISHPRESS_VERSION', '4.6.1-beta', '<')) {
                        // Work around plugin API bug in Planner 4.6.0
                        global $pp_post_type_status_options;
                        $pp_post_type_status_options = [];
                    }
                }

				return $status_options;
			}, 10, 2
		);

        add_action(
            'admin_print_scripts',
            function()  use ($plannerCompat) {
                if ($plannerCompat->showingRevisions()) :?>
                    <script type="text/javascript">
                    /* <![CDATA[ */
                    jQuery(document).ready(function ($) {
						<?php if (defined('PUBLISHPRESS_VERSION') && version_compare('PUBLISHPRESS_VERSION', '4.6.2-beta', '<')):?>
						$('div.pp-content-board-filters button.post_status').html('<?php esc_html_e('Revision Status', 'revisionary');?>').show();
						<?php endif;?>

						<?php if (!rvy_get_option('permissions_compat_mode')):?>
						$('div.content-board-inside div.status-future').hide();
						$('div.content-board-inside div.status-private').hide();
						<?php endif;?>
                    });
                    /* ]]> */
                    </script>

                    <?php if (!rvy_get_option('permissions_compat_mode') || !defined('PUBLISHPRESS_STATUSES_PRO_VERSION')) :?>
                    <style type="text/css">
                    div.can_not_move_to div.drag-message {opacity: 0 !important;}
                    </style>
                    <?php endif;?>

                    <style type="text/css">
					<?php /* Hide on load to prevent caption flash from "Post Status" to "Revision Status" */
					if (defined('PUBLISHPRESS_VERSION') && version_compare('PUBLISHPRESS_VERSION', '4.6.2-beta', '<')):?>
					div.pp-content-board-filters button.post_status {
						display: none;
					}
					<?php endif;?>

                    div.pp-content-board-manage div.new-post {
                        display: none !important;
                    }
                    </style>
                    <?php
                endif;
            }, 50
        );

        add_filter(
			'PP_Content_Board_posts_query_args',
			function ($args) use ($plannerCompat) {
				if ($plannerCompat->showingRevisions() && !defined('PUBLISHPRESS_STATUSES_PRO_VERSION')) {
					$revision_status = $args['post_status'];
					
					// @todo: API adjustment to eliminate this workaround when Statuses Pro is not active
					add_filter(
						'posts_where', 
						function ($where, $wp_query) use ($revision_status) {
							global $wpdb;
							static $done;

							// only modify first posts query we see
							if (!empty($done)) {
								return $where;
							}

							$revision_statuses = rvy_revision_statuses();

							$revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

							if ($revision_status) {
								if (!in_array($revision_status, $revision_statuses())) {
									$revision_status_clause = '1=2';
								} else {
									$revision_status_clause = $wpdb->prepare("$wpdb->posts.post_mime_type = %s", $revision_status);
								}
							} else {
								$revision_status_clause = "$wpdb->posts.post_mime_type IN ('$revision_status_csv')";
							}

							$where = str_replace(
								"($wpdb->posts.post_status = 'draft')", 
								"($revision_status_clause)",
								$where
							);

							return $where;
						},
						10, 2
					);

					$args['post_status'] = 'draft';
				}

				add_filter('posts_fields', [$this, 'fltQueryFields'], 10, 2);
				add_filter('posts_where', [$this, 'fltFilterRevisions'], 10, 2);
				return $args;
			}
		);
    }

    function fltQueryFields($fields, $wp_query) {
		global $wpdb;
		
		if (class_exists('PP_Revision_Integration') && $this->plannerCompat->showingRevisions()) {
			$fields = "ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_mime_type AS post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count";
		}

		return $fields;
	}

	function fltFilterRevisions($where, $wp_query) {
		global $wpdb;
		
		$revision_statuses = rvy_revision_statuses();

		// Prevent inactive revisions from being displayed as normal posts if Statuses Pro was deactivated
		if (!defined('PUBLISHPRESS_STATUSES_PRO_VERSION') && !$this->plannerCompat->showingRevisions()) {
			$revision_statuses = array_merge($revision_statuses, ['revision-deferred', 'revision-needs-work', 'revision-rejected']);
			
			if (!taxonomy_exists('pp_revision_status')) {
				register_taxonomy(
					'pp_revision_status',
					'post',
					[
						'hierarchical'          => false,
						'query_var'             => false,
						'rewrite'               => false,
						'show_ui'               => false,
					]
				);
			}
			
			static $stored_statuses;

			if (!isset($stored_statuses)) {
				$stored_statuses = get_terms(['taxonomy' => 'pp_revision_status', 'hide_empty' => false]);
			}

			foreach ($stored_statuses as $status) {
				if (!in_array($status->slug, $revision_statuses)) {
					$revision_statuses[] = $status->slug;
				}
			}
		}

		$revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));
		
		$where .= ($this->plannerCompat->showingRevisions()) ? " AND post_mime_type IN ('$revision_status_csv')" : " AND post_mime_type NOT IN ('$revision_status_csv')";

		if (class_exists('PP_Revision_Integration') && $this->plannerCompat->showingRevisions() && !rvy_get_option('permissions_compat_mode')) {
			$where = str_replace("(($wpdb->posts.post_status =", "(($wpdb->posts.post_mime_type =", $where);
		}

		remove_filter('posts_where', [$this, 'fltFilterRevisions'], 10, 2);

		return $where;
	}
}
