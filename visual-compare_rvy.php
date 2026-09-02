<?php

class RevisionaryVisualCompare {
    private $compare_page_hook = '';

    function __construct() {
        /**
		 * Registers a hidden wp-admin endpoint for external comparison URLs.
		 */
		$this->compare_page_hook = add_submenu_page(
			null,
			__( 'Compare Revisions', 'revisionary' ),
			__( 'Compare Revisions', 'revisionary' ),
			'read',
			'rvy-visual-compare',
			function() {
				require_once(dirname(__FILE__).'/includes/visual-post-compare/visual-post-compare.php');
				\PublishPress\Visual_Post_Compare::render_compare_screen();
			}
		);

		if ($this->compare_page_hook) {
			add_action( 
				'load-' . $this->compare_page_hook, 
				function() {
					require_once(dirname(__FILE__).'/includes/visual-post-compare/visual-post-compare.php');
					\PublishPress\Visual_Post_Compare::route_compare_screen();
				}
			);

            add_filter( 
                'visual_post_compare_listed_revisions', 
                function ( $listed, $revision ) {
                    global $wpdb;
    
                    $comparison_key = '';

                    if ($past_revision_parent = wp_is_post_revision($revision)) {
                        $listed = self::get_associated_posts($past_revision_parent, 'inherit', 30);
                        $comparison_key = 'compare-past-revision';

                    } else {
                        if (is_string($revision) && rvy_is_revision_status($revision)) {
                            $main_post_id = rvy_post_id($revision);
                            $is_new_revision = $revision;

                        } elseif ($is_new_revision = rvy_in_revision_workflow($revision)) {
                            $main_post_id = rvy_post_id($revision);
                        }
        
                        if ($is_new_revision) {
                            // Dedicated compare screen defaults to including all revision statuses except draft and future
                            if ('future-revision' == $is_new_revision) {
                                $listed = self::get_associated_posts($main_post_id, 'future-revision', 30);
                                $comparison_key = 'compare-future-revision';
                            
                            } elseif ('pending-revision' == $is_new_revision) {
                                $listed = self::get_associated_posts($main_post_id, 'pending-revision', 30);
                                $comparison_key = 'compare-pending-revision';

                            } elseif ('draft-revision' == $is_new_revision) {
                                $listed = self::get_associated_posts($main_post_id, 'draft-revision', 30);
                                $comparison_key = 'compare-pending-revision';

                            } else {
                                // For now, display only A / B comparison for custom revision statuses
                                $listed = [$main_post_id, $revision];
                                $comparison_key = 'compare-pending-revision';
                            }
                        }
                    }

                    return compact('listed', 'comparison_key');
                }
                , 10, 2
            );

            add_filter(
                'visual_post_compare_compare_screen_headline',
                function ( $headline, $revision_id, $comparison_key ) {
                    if ($revision_status = rvy_in_revision_workflow($revision_id)) {
                        if (!empty($_REQUEST['revision']) && rvy_is_revision_status(sanitize_key($_REQUEST['revision']))) {                 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                            $status_obj = get_post_status_object(sanitize_key($_REQUEST['revision']));                                      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                            $status_caption = (is_object($status_obj) && !empty($status_obj->label)) ? '(' . $status_obj->label . ')' : '';

                            $headline = sprintf(
                                __('Compare Revisions %s', 'revisionary'),
                                $status_caption
                            );
                        } elseif ('future-revision' == $revision_status) {
                            $headline = __('Compare Scheduled Revisions', 'revisionary');

                        } elseif ('pending-revision' == $revision_status) {
                            $headline = __('Compare Submitted Revisions', 'revisionary');

                        } elseif ('draft-revision' == $revision_status) {
                            $headline = __('Compare Unsubmitted Revisions', 'revisionary');

                        } else {
                            $headline = __('Compare New Revision', 'revisionary');
                        }
                    } elseif (wp_is_post_revision($revision_id)) {
                        $headline = __('Compare Past Revisions', 'revisionary');
                    }

                    return $headline;
                }, 10, 3
            );

            add_filter(
                'visual_post_compare_compare_screen_approve_caption',
                function ( $caption, $revision_id, $comparison_key ) {
                    if (!rvy_in_revision_workflow($revision_id) && (wp_is_post_revision($revision_id))) {
                        $caption = __('Restore', 'revisionary');
                    }

                    return $caption;
                }, 10, 3
            );

            add_filter(
                'visual_post_compare_compare_screen_current_post_first',
                function ( $current_post_first, $revision_id, $comparison_key ) {
                    if (!rvy_in_revision_workflow($revision_id) && (wp_is_post_revision($revision_id))) {
                        $current_post_first = false;
                    }

                    return $current_post_first;
                }, 10, 3
            );
		}

		add_action( 
			'enqueue_block_editor_assets', 
			function() {
				require_once(dirname(__FILE__).'/includes/visual-post-compare/visual-post-compare.php');
				\PublishPress\Visual_Post_Compare::enqueue_editor_assets();
			}
		);

        add_filter(
            'visual_post_compare_sidebars',
            function($sidebars, $post_id, $compare_class, $args = []) {
                if (empty($post_id) && !empty($_REQUEST['post'])) {         // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $post_id = intval($_REQUEST['post']);                   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                }

                $metabox_limit = (defined('REVISIONARY_MAX_METABOX_ITEMS')) ? constant('REVISIONARY_MAX_METABOX_ITEMS') : 10;
                
                if (empty($args['key']) || ('compare-past-revision' == $args['key'])) {
                    $past_revisions = (!empty($args['posts'])) ? $args['posts'] : self::get_associated_posts($post_id, 'inherit', $metabox_limit);

                    if ($past_revisions || ('compare-past-revision' == $args['key'])) {
                        $sidebars []= $compare_class::comparison_sidebar_definition(
                            'compare-past-revision',
                            __( 'Past Revisions', 'revisionary' ),
                            $past_revisions,
                            [
                                'currentPostFirst' => false,
                                'restoreButtonCaption' => '',
                                'showStatus' => false,
                            ]
                        );
                    }
                }
        
                if (empty($args['key']) || ('compare-pending-revision' == $args['key'])) {
                    $pending_revisions = (!empty($args['posts'])) ? $args['posts'] : self::get_associated_posts($post_id, 'pending-revision', $metabox_limit);

                    if ($pending_revisions || ('compare-pending-revision' == $args['key'])) {
                        $sidebars []= $compare_class::comparison_sidebar_definition(
                            'compare-pending-revision',
                            __( 'Submitted Revisions', 'revisionary' ),
                            $pending_revisions,
                            [
                                'mime_type_status' => !rvy_get_option('permissions_compat_mode'),
                            ]
                        );
                    }
                }

                if (empty($args['key']) || ('compare-future-revision' == $args['key'])) {
                    $scheduled_revisions = (!empty($args['posts'])) ? $args['posts'] : self::get_associated_posts($post_id, 'future-revision', $metabox_limit);

                    if ($scheduled_revisions || ('compare-future-revision' == $args['key'])) {
                        $sidebars []= $compare_class::comparison_sidebar_definition(
                            'compare-future-revision',
                            __( 'Scheduled Revisions', 'revisionary' ),
                            $scheduled_revisions,
                            [   
                                'sort_by' => 'post_date',
                                'show_post_date' => true,
                                'post_date_prefix' => __( 'Scheduled:', 'revisionary' ),
                                'mime_type_status' => !rvy_get_option('permissions_compat_mode'),
                            ]
                        );
                    }
                }

                return $sidebars;

            }, 10, 4
        );

		add_action( 
			'admin_enqueue_scripts', 
			function( $hook_suffix ) {
				if ($hook_suffix == $this->compare_page_hook) {
					require_once(dirname(__FILE__).'/includes/visual-post-compare/visual-post-compare.php');
					\PublishPress\Visual_Post_Compare::enqueue_compare_screen_assets($hook_suffix);
				}
			}
		);

		add_action( 
			'rest_api_init', 
			function() {
				if ( isset( $_GET['rest_route'] ) ) {                           // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$route_hint = (string) wp_unslash( $_GET['rest_route'] );   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
				} else {
					$route_hint = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
				}

				if ( false !== strpos( $route_hint, 'rvy-visual-compare/v1' ) 
				|| ( false !== strpos( $route_hint, '/revisions' ) || (isset( $_SERVER['HTTP_X_VPC_COMPARE'] ) && '1' === (string) wp_unslash($_SERVER['HTTP_X_VPC_COMPARE'] ) ) )   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
				) {
					add_filter( 
						'visual_post_compare_editor_slider_posts', 
						function ( $posts, $definition, $current_post ) {
                            $slider_limit = (defined('REVISIONARY_MAX_SLIDER_ITEMS')) ? constant('REVISIONARY_MAX_SLIDER_ITEMS') : 30;

							switch ($definition['key']) {
								case 'compare-pending-revision':
									$posts = self::get_associated_posts($current_post, 'pending-revision', $slider_limit);
									break;
								
								case 'compare-future-revision':
									$posts = self::get_associated_posts($current_post, 'future-revision', $slider_limit);
									break;
			
								case 'compare-past-revision':
									$posts = self::get_associated_posts($current_post, 'inherit', $slider_limit);
									break;
							}
			
							return $posts;
						}
						, 10, 3 
					);

					require_once(dirname(__FILE__).'/includes/visual-post-compare/visual-post-compare.php');
					\PublishPress\Visual_Post_Compare::maybe_load_rest_handler();
				}
			}, 0
		);
    }

    public static function get_associated_posts($main_post_id, $status, $limit, $args = []) {
		global $wpdb;

        $posts = [];

		if ('inherit' == $status) {
			$posts = wp_get_post_revisions($main_post_id);

		} elseif (is_array($status) && !empty($args['is_new_revision'])) {
            $status_csv = implode("','", array_map('sanitize_key', $status));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_mime_type IN ('" . $status_csv . "') AND comment_count = %d ORDER BY ID DESC LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$main_post_id,
					$limit
				)
			);
		} elseif (rvy_is_revision_status($status)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_mime_type = %s AND comment_count = %d ORDER BY ID DESC LIMIT %d",
					$status,
					$main_post_id,
					$limit
				)
			);
		}

		if (count($posts) > $limit) {
			$posts = array_slice($posts, 0, $limit);
		}

		return $posts;
	}
}
