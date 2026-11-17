Warning: truncated output (original token count: 18200)
Total output lines: 1915

<?php
defined('ABSPATH') || exit;
	
/**
 * @package     PublishPress\Revisions
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2026 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */
class Revisionary
{
	var $admin;
	var $content_roles;			// object ref - instance of RevisionaryContentRoles subclass, set by external plugin
	var $doing_rest = false;
	var $rest = '';				// object ref - Revisionary_REST
	var $internal_meta_update = false;
	var $skip_filtering = false;
	var $skip_revisor_post_caps_workaround = false;
	var $is_revisions_query = false;
	var $front = false;

	var $config_loaded = false;		// configuration related to post types and statuses must be loaded late on the init action
	
	var $enabled_post_types = [];	// enabled_post_types property is set (keyed by post type slug) late on the init action. 
	var $enabled_post_types_archive = [];	// enabled_post_types_archive property is set (keyed by post type slug) late on the init action.
	var $hidden_post_types_archive = [];
	var $enabled_post_types_copy = [];
	var $hidden_post_types_copy = [];

	var $enabled_fields = true;
	var $enabled_fields_copy = true;
	var $post_edit_ui;

	// minimal config retrieval to support pre-init usage by WP_Scoped_User before text domain is loaded
	function __construct() {
	}

	function init() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (isset($_SERVER['REQUEST_URI']) && is_admin() && (false !== strpos(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), 'revision.php')) && (!empty($_REQUEST['revision']))) {
			add_action('init', [$this, 'addFilters'], PHP_INT_MAX);
		} else {
			$this->addFilters();
		}
	}

	function addFilters() {
		global $script_name;

		if (!is_admin() && is_user_logged_in() && rvy_get_option('front_end_indicator')) {
			add_action(
				'wp_enqueue_scripts', 
				function() {
					global $wpdb;
					
					if (is_single() || is_page()) {
						global $wp_query;
						
						if (empty($wp_query) || empty($wp_query->queried_object_id)) {
							return;
						}
						$post_id = $wp_query->queried_object_id;
						$post_type = get_post_field('post_type', $post_id);

						if (!$post_type || empty($this->enabled_post_types[$post_type]) 
						|| !current_user_can('edit_post', $post_id)
						|| !rvy_get_post_meta($post_id, '_rvy_has_revisions')
						) {
							return;
						}

						$revision_statuses = array_values(array_map('sanitize_key', array_diff(rvy_revision_statuses(), ['draft-revision'])));
						$revision_count = 0;

						if ($revision_statuses) {
							$revision_count_args = array_merge([$post_id, $post_type], $revision_statuses);

							if (rvy_get_option('permissions_compat_mode')) {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$revision_count = (int) $wpdb->get_var(
								$wpdb->prepare(
										sprintf(
											"SELECT COUNT(r.ID) FROM $wpdb->posts r INNER JOIN $wpdb->posts p ON r.comment_count = p.ID WHERE p.ID = %%d AND r.post_type = %%s AND r.post_status IN (%s)",
											implode(',', array_fill(0, count($revision_statuses), '%s'))
										),
										$revision_count_args
								)
								);
							} else {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$revision_count = (int) $wpdb->get_var(
									$wpdb->prepare(
										sprintf(
											"SELECT COUNT(r.ID) FROM $wpdb->posts r INNER JOIN $wpdb->posts p ON r.comment_count = p.ID WHERE p.ID = %%d AND r.post_type = %%s AND r.post_mime_type IN (%s)",
											implode(',', array_fill(0, count($revision_statuses), '%s'))
										),
										$revision_count_args
							)
								);
							}
						}

						$revision_count = (int) apply_filters('revisionary_front_count', $revision_count, $post_id);

						if ($revision_count) {
							require_once(dirname(__FILE__).'/front-notice.php' );

							$front_notice = new \PublishPress\Revisions\FrontNotice(['post_id' => $post_id]);
							$front_notice->enqueueScripts();
						}
					}
				}
			);
		}

		add_filter('pre_wp_update_comment_count_now', [$this, 'fltUpdateCommentCountBypass'], 10, 3);
		
		// Ensure editing access to past revisions is not accidentally filtered. 
		// @todo: Correct selective application of filtering downstream so Revisors can use a read-only Compare [Past] Revisions screen
		//
		// Note: some filtering is needed to allow users with full editing permissions on the published post to access a Compare Revisions screen with Preview and Manage buttons

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (is_admin() && isset($_SERVER['REQUEST_URI']) && (false !== strpos(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), 'revision.php')) && (!empty($_REQUEST['revision'])) && !is_content_administrator_rvy()) {
			
			if (!empty($_REQUEST['revision'])) {				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				$revision_id = (int) $_REQUEST['revision'];		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			} elseif (isset($_REQUEST['to'])) {					// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				$revision_id = (int) $_REQUEST['to'];			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			} else {
				$revision_id = 0;
			}
			
			if ($revision_id) {
				if ($_post = get_post($revision_id)) {
					if (!rvy_in_revision_workflow($_post)) {
						if ($parent_post = get_post($_post->post_parent)) {
							if (!empty($_POST) || (!empty($_REQUEST['action']) && ('restore' == $_REQUEST['action']))) {	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
								if (!$this->canEditPost($parent_post, ['simple_cap_check' => true])) {
									return;
								}
							}
						}
					}
				}
			}
		}

		$this->setPostTypes();
		$this->setFields();
		$this->setPostTypesArchive();
		$this->setPostTypesCopy();
		$this->setFieldsCopy();

		rvy_refresh_options_sitewide();

		if (defined('DOING_CRON') && DOING_CRON) {
			if (!rvy_get_option('wp_cron_usage_detected')) {
				update_option('rvy_wp_cron_usage_detected', true);
			}
		}

		require_once( dirname(__FILE__).'/classes/PublishPress/Revisions/PluginCompat.php' );
		new PublishPress\Revisions\PluginCompat();

		// NOTE: $_GET['preview'] and $_GET['post_type'] arguments are set by rvy_init() at response to ?p= request when the requested post is a revision.
		if (!is_admin() && !rvy_is_divi_editing_preview() && (!defined('REST_REQUEST') || ! REST_REQUEST)) { // preview_id indicates a regular preview via WP core, based on autosave revision
			$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';
			
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			if ((defined('FL_BUILDER_VERSION') && rvy_in_revision_workflow(rvy_detect_post_id())) || ((!empty($_GET[$preview_arg]) || !empty($_GET['_ppp'])) && empty($_REQUEST['preview_id'])) || !empty($_GET['mark_current_revision']) || !empty($_GET['rvy_approval'])) {
				require_once( dirname(__FILE__).'/front_rvy.php' );
				$this->front = new RevisionaryFront();
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (!is_admin() && (!defined('REST_REQUEST') || ! REST_REQUEST) && (!empty($_GET['preview']) && !empty($_REQUEST['preview_id']))) {			
			if (!defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
				require_once(dirname(__FILE__).'/classes/PublishPress/Revisions/PostPreview.php');
				new PublishPress\Revisions\PostPreview();
			}
		}

		// Include custom post type editors, Revisors and Approvers. Object access
		// is checked separately before comparisons or content are returned.
		$can_load_visual_compare = current_user_can('edit_posts');
		foreach (['edit_others_revisions', 'list_others_revisions', 'preview_others_revisions', 'manage_revision_queue', 'manage_unsubmitted_revisions', 'restore_revisions', 'view_revision_archive'] as $compare_revision_cap) {
			if (current_user_can($compare_revision_cap)) {
				$can_load_visual_compare = true;
				break;
			}
		}
		foreach (get_post_types([], 'objects') as $compare_post_type) {
			if (empty($compare_post_type->cap->edit_posts)) {
				continue;
			}
			$compare_edit_cap = $compare_post_type->cap->edit_posts;
			if (current_user_can($compare_edit_cap)
				|| current_user_can(str_replace('edit_', 'revise_', $compare_edit_cap))
				|| current_user_can(str_replace('edit_', 'approve_', $compare_edit_cap))
			) {
				$can_load_visual_compare = true;
				break;
			}
		}

		if (rvy_use_visual_compare() && $can_load_visual_compare) {
			require_once(dirname(__FILE__).'/visual-compare_rvy.php');
			new RevisionaryVisualCompare();

		} elseif (is_admin() && !empty($_REQUEST['page']) && ('rvy-visual-compare' == $_REQUEST['page'])) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (!is_user_logged_in()) {
				$request_uri = isset($_SERVER['REQUEST_URI'])
					? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))
					: '/wp-admin/admin.php?page=rvy-visual-compare';
				$admin_parts = wp_parse_url(admin_url());
				$redirect_to = sprintf(
					'%s://%s%s%s',
					isset($admin_parts['scheme']) ? $admin_parts['scheme'] : (is_ssl() ? 'https' : 'http'),
					isset($admin_parts['host']) ? $admin_parts['host'] : '',
					isset($admin_parts['port']) ? ':' . absint($admin_parts['port']) : '',
					'/' . ltrim($request_uri, '/')
				);
				wp_safe_redirect(wp_login_url($redirect_to));
				exit;
			}
			wp_die(esc_html__('Visual compare is not enabled.', 'revisionary'));
		}

		add_filter('presspermit_is_preview', [$this, 'fltIsPreview']);
		add_filter('presspermit_query_post_statuses', [$this, 'fltQueryPostStatuses'], 10, 2);

		add_filter('map_meta_cap', [$this, 'fltStatusChangeCap'], 5, 4);

		if ( ! is_content_administrator_rvy() ) {
			add_filter( 'map_meta_cap', array($this, 'flt_post_map_meta_cap'), 5, 4);

			add_filter('map_meta_cap', [$this, 'fltFixReadPreviewMetaCaps'], 99, 4 );
			add_filter('presspermit_posts_clauses_intercept', [$this, 'fltAllowPreviewQuery'], 10, 4);

			add_filter('presspermit_apply_posts_teaser', [$this, 'fltAllowTeaser'], 10, 2);

			add_filter( 'user_has_cap', array( $this, 'flt_user_has_cap' ), 98, 3 );

			add_filter( 'map_meta_cap', array( $this, 'flt_limit_others_drafts' ), 10, 4 );

			add_action(
				'wp_loaded',
				function() {
					global $current_user;

					if (isset($_REQUEST['context']) && ('edit' == $_REQUEST['context']) && empty($_POST)) {	 // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
						if (0 === strpos(wp_unslash($_SERVER['REQUEST_URI']), "/wp-json/wp/v2/blocks/")) {	 // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
							
							$can_edit_any = false;
									
							// We have no context info to limit this provision to Query Loop instances called from within Edit Revision, and also don't know the post type for an Edit Revision instance,
							// so just limit to users who have at least a basic editing capability for at least one post type. 
							foreach(array_keys($this->enabled_post_types) as $_post_type) {
								if ($_type_obj = get_post_type_object($_post_type)) {
									if (!empty($current_user->allcaps[$_type_obj->cap->edit_posts]) || (is_multisite() && is_super_admin())) {
										$can_edit_any = true;
										break;
									}
								}
							}
							
							if (rvy_get_option('query_loop_revision_editor_allowance')) {
    							if (!empty($can_edit_any)) {
    								unset($_REQUEST['context']);
    								unset($_GET['context']);
    							}
    					    }
						}
					}
				}, 20
			);

			// REST collection requests must retain the user's actual capabilities.
			// URI shape cannot establish a revision editor or Query Loop context.

			if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')) {
				add_filter(
					'presspermit_exception_clause', 
					function($clause, $required_operation, $post_type, $args) {
						global $pagenow;

						$defaults = ['logic' => 'NOT IN', 'ids' => [], 'src_table' => ''];
						$args = array_merge($defaults, $args);
						foreach (array_keys($defaults) as $var) {
							$$var = $args[$var];
						}

						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if (
							('exclude' == $args['mod'])
							&& rvy_get_option('apply_post_exceptions')
							&& (
								(('admin.php' == $pagenow) && isset($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-q', 'revisionary-archive'], true))	// //phpcs:ignore WordPress.Security.NonceVerification.Recommended
								|| (in_array($pagenow, ['post.php', 'post-new.php'], true) && rvy_in_revision_workflow(rvy_detect_post_id()))
							)
						) {
							$revision_status_csv = rvy_revision_statuses(['return' => 'csv']);

							$clause .= " AND ({$args['src_table']}.comment_count {$args['logic']} ('" . implode("','", $args['ids']) . "') OR {$args['src_table']}.post_mime_type NOT IN ($revision_status_csv))";
						} else {
							if (defined('PP_REVISIONS_EXTRA_PERMISSIONS_FILTER')) {
								// @todo: move this into Permissions

								if (false !== strpos($clause, 'comment_count')) {
									return $clause;
								}
						
								$clause = "( $clause OR ( $src_table.post_mime_type IN ('" . implode("','", array_map('sanitize_key', rvy_revision_statuses())) . "') AND $src_table.comment_count $logic ('" . implode("','", $ids) . "') ) )";
							}
						}

						return $clause;
					},
					10, 4
				);

				add_filter(
					'presspermit_additions_clause', 
					function($clause, $required_operation, $post_type, $args) {
						global $pagenow;

						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if (
							rvy_get_option('apply_post_exceptions')
							&& (
								(('admin.php' == $pagenow) && isset($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-q', 'revisionary-archive'], true))	// //phpcs:ignore WordPress.Security.NonceVerification.Recommended
								|| (in_array($pagenow, ['post.php', 'post-new.php'], true) && rvy_in_revision_workflow(rvy_detect_post_id()))
							)
						) {
							$revision_status_csv = rvy_revision_statuses(['return' => 'csv']);

							$clause = " (($clause) OR ({$args['src_table']}.comment_count IN ('" . implode("','", $args['ids']) . "') AND {$args['src_table']}.post_mime_type IN ($revision_status_csv)))";
						}

						return $clause;
					},
					10, 4
				);
			}
		}

		if ( is_admin() ) {
			require_once( dirname(__FILE__).'/admin/admin_rvy.php');
			$this->admin = new RevisionaryAdmin();
		}

		add_action( 'wpmu_new_blog', array( $this, 'act_new_blog'), 10, 2 );

		add_action('trashed_post', [$this, 'actTrashedPost']);
		
		add_action( 'deleted_post', [$this, 'actDeletedPost']);

		if ( rvy_get_option( 'scheduled_revisions') ) {
			// users who have edit_published capability for post/page can create a scheduled revision by modifying post date to a future date (without setting "future" status explicitly)
			add_filter( 'wp_insert_post_data', array($this, 'flt_insert_post_data'), 99, 2 );
		}

		add_filter( 'wp_insert_post_data', array($this, 'flt_regulate_revision_status'), 100, 2 );

		add_filter('wp_insert_post_data', [$this, 'fltRemoveInvalidPostDataKeys'], 999, 2);

		// REST logging
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );

		// This is needed, implemented for pending revisions only (check referer downstream)
		if (!empty($_REQUEST['get_new_revision'])) {							//phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if (did_action('wp_default_scripts')) {
				$this->act_new_revision_redirect();
			} else {
				add_action('wp_default_scripts', array($this, 'act_new_revision_redirect'), 1);
			}
		}
																				// check referer downstream
		if (!empty($_REQUEST['edit_new_revision'])) {							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (did_action('wp_default_scripts')) {
				$this->act_edit_revision_redirect();
			} else {
				add_action('wp_default_scripts', array($this, 'act_edit_revision_redirect'), 1);
			}
		}

		add_filter('get_comments_number', array($this, 'flt_get_comments_number'), 10, 2);

		add_action('save_post', array($this, 'actSavePost'), 20, 2);
		add_action('delete_post', [$this, 'actDeletePost'], 10, 3);

		add_action('post_updated', [$this, 'actUpdateRevision'], 10, 2);
		add_action('post_updated', [$this, 'actUpdateRevisionFixCommentCount'], 999, 2);

		add_filter('wp_revisions_to_keep', [$this, 'fltNumRevisions'], 10, 2);

		add_filter('posts_clauses', [$this, 'fltPostsClauses'], 10, 2);

		if (!is_admin()) {
			add_action('admin_bar_menu', [$this, 'adminToolbarItem'], 100);
		}

		add_filter('wp_dropdown_pages', [$this, 'fltDropdownPages'], 10, 3);

		if (!defined('REVISIONARY_DISABLE_RVY_INIT_ACTION')) {
			do_action( 'rvy_init', $this );
		}

		add_action( 'wp_ajax_rvy_dismiss_frontend_notice', [$this, 'dismissFrontendNotice']);
	}

    function dismissFrontendNotice() {
        if ( is_user_logged_in() ) {
			check_ajax_referer( 'rvy_dismiss_frontend_notice', 'nonce' );
			update_user_meta( get_current_user_id(), 'rvy_revisions_has_revisions_hint_dismissed', 1 );
			wp_send_json_success();
        }
    }

	// Work around unfilterable get_pages() query by replacing the wp_dropdown_pages() return array
	function fltDropdownPages($output, $parsed_args, $pages) {
		// ---- Begin PublishPress Modification ---
		global $wpdb;

		// don't recursively execute this filter
		remove_filter('wp_dropdown_pages', [$this, 'fltDropdownPages'], 10, 3);

		$parsed_args['echo'] = 0;

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$parsed_args['exclude'] = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_mime_type IN ('$revision_status_csv') AND post_type !=''");
		// ---- End PublishPress Modification ---

		$pages  = get_pages( $parsed_args );
		$output = '';
		// Back-compat with old system where both id and name were based on $name argument.
		if ( empty( $parsed_args['id'] ) ) {
			$parsed_args['id'] = $parsed_args['name'];
		}
	
		if ( ! empty( $pages ) ) {
			$class = '';
			if ( ! empty( $parsed_args['class'] ) ) {
				$class = " class='" . esc_attr( $parsed_args['class'] ) . "'";
			}
	
			$output = "<select name='" . esc_attr( $parsed_args['name'] ) . "'" . $class . " id='" . esc_attr( $parsed_args['id'] ) . "'>\n";
			if ( $parsed_args['show_option_no_change'] ) {
				$output .= "\t<option value=\"-1\">" . $parsed_args['show_option_no_change'] . "</option>\n";
			}
			if ( $parsed_args['show_option_none'] ) {
				$output .= "\t<option value=\"" . esc_attr( $parsed_args['option_none_value'] ) . '">' . $parsed_args['show_option_none'] . "</option>\n";
			}
			$output .= walk_page_dropdown_tree( $pages, $parsed_args['depth'], $parsed_args );
			$output .= "</select>\n";
		}
	
		/**
		 * Filters the HTML output of a list of pages as a drop down.
		 *
		 * @since 2.1.0
		 * @since 4.4.0 `$parsed_args` and `$pages` added as arguments.
		 *
		 * @param string    $output      …8200 tokens truncated…_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& rvy_in_revision_workflow($post_id)
		) {
			$allow = false;
		}

		return $allow;
	}
	
	function fltAllowPreviewQuery($intercept_clauses, $clauses, $_wp_query = false, $args = []) {
		global $current_user;
		
		if (is_admin() 
		|| (defined('REST_REQUEST') && REST_REQUEST) 
		|| (defined('DOING_AJAX') && DOING_AJAX)
		) {
			return $intercept_clauses;
		}

		$required_operation = (!empty($args['required_operation'])) ? $args['required_operation'] : 'read';

		if ('read' != $required_operation) {
			return $intercept_clauses;
		}

		$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ((!empty($_REQUEST[$preview_arg]) || !empty($_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& (!empty($current_user->allcaps['preview_others_revisions']))
		&& did_action('posts_selection') 
		) {
			if ($post = get_post(rvy_detect_post_id())) {
				if (rvy_in_revision_workflow($post->ID)
				&& ('inherit' != $post->post_status)
				&& !empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded
				) {
					$intercept_clauses = $clauses;
				}
			}
		}

		return $intercept_clauses;
	}

	function fltFixReadPreviewMetaCaps($caps, $cap, $user_id, $args) {
		global $current_user;
		
		$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (in_array($cap, ['read_post', 'read_page'], true)	// WP Query imposes edit_post capability requirement for front end viewing of protected statuses 
		&& (!empty($_REQUEST[$preview_arg]) || !empty($_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& !empty($current_user->allcaps['preview_others_revisions'])
		&& did_action('posts_selection') 
		) {
			if (!empty($args[0])) {
				$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
			} else {
				$post_id = 0;
			}
	
			if ($post = get_post($post_id)) {
				if (('inherit' == $post->post_status)
				|| (empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded)
				) {
					return $caps;
				}
			}

			$type_obj = get_post_type_object($post->post_type);

			// Note: preview_others_revisions capability already confirmed
			if (!empty($type_obj->cap->read_private_posts)) {
				$caps = array_diff($caps, [$type_obj->cap->read_private_posts]);
				$caps []= 'read';
			}
		}

		return $caps;
	}
	
	function flt_post_map_meta_cap($caps, $cap, $user_id, $args) {
		global $current_user;

		static $busy;
        static $additional_ids;

		if (!empty($busy) || !empty($this->skip_filtering)) {
			return $caps;
		}

		if (!in_array($cap, array('read_post', 'read_page', 'edit_post', 'edit_page', 'delete_post', 'delete_page'), true)) {
			return $caps;
		}

		if (!empty($args[0])) {
			$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
		} else {
			$post_id = 0;
		}

		if ($post = get_post($post_id)) {
			if (('inherit' == $post->post_status)
			|| (empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded)
			) {
				return $caps;
			}
		}

		if ($post && (('future-revision' == $post->post_mime_type) 
		|| (in_array($cap, ['read_post', 'read_page'], true) && empty($current_user->allcaps['preview_others_revisions'])))
		) {
            // Reads continue to the visibility policy below.
            if (in_array($cap, ['read_post', 'read_page'], true) && !rvy_can_view_workflow_revision($post)) {
                return ['do_not_allow'];
            }
 
			// allow Revisor to view a preview of their scheduled revision
			
			if (!in_array($cap, ['read_post', 'read_page'], true) && (is_admin() 
			|| (defined('REST_REQUEST') && REST_REQUEST) 
			|| empty($_REQUEST['preview']) || !empty($_POST) 						//phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			|| did_action('template_redirect'))
			) {
				if ($type_obj = get_post_type_object( $post->post_type )) {
					if (isset($type_obj->cap->edit_published_posts)) {
						$check_cap = in_array($cap, ['delete_post', 'delete_page'], true) ? $type_obj->cap->delete_published_posts : $type_obj->cap->edit_published_posts;
						return array_merge($caps, [$check_cap]);
					} else {
						return $caps;
					}
				}
			}
		}

		$busy = true;

		$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (in_array($cap, ['read_post', 'read_page'], true)	// WP Query imposes edit_post capability requirement for front end viewing of protected statuses 
			|| (
				(!empty($_REQUEST[$preview_arg]) || !empty($_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& in_array($cap, array('edit_post', 'edit_page'), true) 
				&& did_action('posts_selection') 
				&& !did_action('template_redirect')
			)
		) {
			if ($post && rvy_in_revision_workflow($post)) {
				$type_obj = get_post_type_object($post->post_type);

				if ($type_obj && !empty($type_obj->cap->edit_others_posts)) {
					$caps = array_diff($caps, [$type_obj->cap->edit_others_posts, 'do_not_allow']);

					$check_post = $post;

					if ($post->ID <= 0) {
						if ($check_id = rvy_detect_post_id()) {
							$check_post = get_post($check_id);
						}
					}

					if (rvy_is_post_author($check_post) || rvy_is_post_author(rvy_post_id($check_post->ID)) || rvy_is_full_editor(rvy_post_id($post->ID)) || current_user_can('preview_others_revisions') || current_user_can('list_others_revisions')) {
						$caps []= 'read';

					} elseif (rvy_get_option('revisor_hide_others_revisions')) {
						$caps []= 'list_others_revisions';
					
					} else {
						$caps []= $type_obj->cap->edit_posts;
					} 
				}

				$busy = false;
				return $caps;
			}
		} elseif (($post_id > 0) && $post && rvy_in_revision_workflow($post) 
			&& rvy_get_option('revisor_lock_others_revisions') && !rvy_is_post_author($post) && !rvy_is_full_editor(rvy_post_id($post->ID))
		) {
			if ($type_obj = get_post_type_object( $post->post_type )) {
				if (in_array($type_obj->cap->edit_others_posts, $caps, true)) {	
					if ((!empty($type_obj->cap->edit_others_posts) && empty($current_user->allcaps[$type_obj->cap->edit_others_posts])) 
					|| (!empty($type_obj->cap->edit_published_posts) && empty($current_user->allcaps[$type_obj->cap->edit_published_posts]))
					) {
						if (!current_user_can('approve_revision', $post_id)) {
							if (apply_filters('revisionary_allow_edit_others_revision', !empty($current_user->allcaps['edit_others_revisions']), $post)) {
								$caps[] = 'read';
							} else {
								if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')) {
									if (!isset($additional_ids)) {
										$additional_ids = [];
									}

									if (!isset($additional_ids[$post->post_type])) {
										$user = presspermit()->getUser();

										if ($ids = $user->getExceptionPosts('edit', 'additional', $post->post_type, ['status' => true])) {
											if (isset($ids[''])) {
												$additional_ids[$post->post_type] = $ids[''];
											}
										}
									}

									if (isset($additional_ids[$post->post_type]) && in_array($post->ID, $additional_ids[$post->post_type], true)) {
										$bypass_edit_others_cap = true;
									}
								}

								if (empty($bypass_edit_others_cap)) {
									$caps []= 'do_not_allow';	// @todo: implement this within user_has_cap filters?
								}
							}
						}
					}
				}
			}
		}

		if (in_array($cap, array('edit_post', 'edit_page'), true)) {
			if ($post && !empty($post->post_status)) {
				if (!in_array($post->post_status, rvy_filtered_statuses(), true)) {
					$busy = false;
					return $caps;
				}
			}

			$busy = true;

			// Run reqd_caps array through the filter which is normally used to implicitly grant edit_published cap to Revisors
			// Applying this adjustment to reqd_caps instead of user caps on 'edit_post' checks allows for better compat with PressPermit and other plugins
			if ($grant_caps = $this->filter_caps(array(), $caps, array(0 => $cap, 1 => $user_id, 2 => $post_id))) {
				$caps = array_diff($caps, array_keys(array_filter($grant_caps)));

				if (!$caps) {
					if ($type_obj = get_post_type_object( $post->post_type )) {
						$caps = [$type_obj->cap->edit_posts];
					}
				}
			}
		}

		$busy = false;
		return $caps;
	}

	function flt_user_has_cap($wp_blogcaps, $reqd_caps, $args) {
		return $this->filter_caps($wp_blogcaps, $reqd_caps, $args);
	}

	private function filter_caps($wp_blogcaps, $reqd_caps, $args, $internal_args = array()) {
		global $current_user;

		static $additional_ids;

		if (!empty($this->skip_filtering) || !rvy_get_option('pending_revisions')) {
			return $wp_blogcaps;
		}

		if (!empty($pagenow) && ('edit.php' == $pagenow)) {
			return $wp_blogcaps;
		}

        if (empty($args[2]) || !is_scalar($args[2])
            || empty($args[0]) || !in_array($args[0], ['edit_post', 'edit_page', 'read_post', 'read_page', 'delete_post', 'delete_page'], true)) {
            return $wp_blogcaps;
        }
    
        $post_id = (int) $args[2];

		if (!$post = get_post($post_id)) {
			if ((-1 == $post_id) && defined('PRESSPERMIT_PRO_VERSION') && !empty(presspermit()->meta_cap_post)) {  // wp_cache_add(-1) does not work for map_meta_cap call on get-revision-diffs ajax call 
				$post = presspermit()->meta_cap_post;
			}
		}

		if (empty($post) || (empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded)) {
			return $wp_blogcaps;
		}

		if (rvy_in_revision_workflow($post)) {
			$object_type_obj = get_post_type_object($post->post_type);

			if (('draft-revision' == $post->post_mime_type) && !rvy_is_post_author($post) && rvy_get_option('manage_unsubmitted_capability') && empty($wp_blogcaps['manage_unsubmitted_revisions'])) {
				if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')) {
					if (!isset($additional_ids)) {
						$additional_ids = [];
					}

					if (!isset($additional_ids[$post->post_type])) {
						$user = presspermit()->getUser();

						if ($ids = $user->getExceptionPosts('edit', 'additional', $post->post_type, ['status' => true])) {
							if (isset($ids[''])) {
								$additional_ids[$post->post_type] = $ids[''];
							}
						}
					}

					if (isset($additional_ids[$post->post_type]) && in_array($post->ID, $additional_ids[$post->post_type], true)) {
						$bypass_cap = true;
					}
				}
				
				if (empty($bypass_cap)) {
					unset($wp_blogcaps[$object_type_obj->cap->edit_others_posts]);
				}
			} else {
				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if (defined('DOING_AJAX') && DOING_AJAX && !empty($_REQUEST['action']) && (false !== strpos(sanitize_key($_REQUEST['action']), 'query-attachments'))) {
					if ('post' == $post->post_type) {
						return $wp_blogcaps;
					}
				}

				// If edit_others capability is being required for this post type, apply edit_others_revisions capability
				if (!empty($object_type_obj->cap) && in_array($object_type_obj->cap->edit_others_posts, $reqd_caps, true)) {
					if (!empty($current_user->allcaps['edit_others_revisions']) || !rvy_get_option('revisor_lock_others_revisions')) {
						$wp_blogcaps[$object_type_obj->cap->edit_others_posts] = true;
					
					} elseif (rvy_get_option('admin_revisions_to_own_posts') && current_user_can('edit_post', rvy_post_id($post_id))) {
						$wp_blogcaps[$object_type_obj->cap->edit_others_posts] = true;
					}
				}
			
				// Grant edit permission for revision if user can edit main post
				if (!empty($args[0]) && ('edit_post' == $args[0]) && array_diff($reqd_caps, array_keys(array_filter($wp_blogcaps)))) {
					$this->skip_filtering = true;

					if (rvy_get_option('admin_revisions_to_own_posts') && current_user_can('edit_post', rvy_post_id($post_id))) {
						$wp_blogcaps = array_merge($wp_blogcaps, array_fill_keys($reqd_caps, true));
					}

					$this->skip_filtering = false;
				}
			}
		}

		return $wp_blogcaps;			
	}

	function fltRemoveInvalidPostDataKeys($data, $postarr) {
		unset($data['filter']);

        // Copy/import/API updates can retain revision MIME data without base metadata.
        // Enforce the live-post invariant after status regulation and editor filters.
        if (in_array($data['post_status'], ['publish', 'private', 'future'], true)
            && !empty($data['post_mime_type'])
            && rvy_is_revision_status($data['post_mime_type'])
        ) {
            $data['post_mime_type'] = '';
            if (empty($postarr['ID'])) {
                // A new normal post has no comments; a copied base ID is not a count.
                $data['comment_count'] = 0;
            }
        }

		return $data;
	}

	// If Scheduled Revisions are enabled, don't allow WP to force current post status to future based on publish date
	function flt_insert_post_data( $data, $postarr ) {
		if ( ( 'future' == $data['post_status'] ) && ( rvy_is_status_published( $postarr['post_status'] ) ) ) {

			if (!empty($postarr['post_type']) && empty($this->enabled_post_types[$postarr['post_type']])) {
				return $data;
			}

			// don't interfere with scheduling of unpublished drafts
			if ( $stored_status = get_post_field ( 'post_status', rvy_detect_post_id() ) ) {
				if ( rvy_is_status_published($stored_status) && !rvy_is_status_published($data['post_status']) && ('future' != $data['post_status']) ) {
					$data['post_status'] = $postarr['post_status'];
				}
			}
		}

		// If this is already a scheduled revision and the date is being modified, replace its publication trigger.
		$stored_revision_status = get_post_field( 'post_mime_type', $postarr['ID'] );
		$submitted_revision_status = ! empty( $postarr['post_mime_type'] ) ? $postarr['post_mime_type'] : $stored_revision_status;
		if (rvy_in_revision_workflow($postarr['ID']) && ('future-revision' == $submitted_revision_status)) {

			$current_post_date_gmt = get_post_field('post_date_gmt', $postarr['ID']);

			if ($data['post_date_gmt'] != $current_post_date_gmt) {
				require_once( dirname(__FILE__) . '/admin/revision-action_rvy.php' );
				rvy_reschedule_revision_publication( $postarr['ID'], $data['post_date_gmt'] );
			}
		}
		
		return $data;
	}

	function flt_regulate_revision_status($data, $postarr) {
		if (rvy_is_revision_status($data['post_status'])) {
			// If post_status is set to a revision status, mirror that to post_mime_type. This is meant to support post updates from one revision status to another.
			if ($data['post_status'] != $data['post_mime_type']) {
				$data['post_mime_type'] = $data['post_status'];
			}

			// Prevent revision status being stored directly to post_status column unless Permissions Compat Mode is enabled.
			if (!rvy_get_option('permissions_compat_mode')) {
				$data['post_status'] = ('draft-revision' == $data['post_status']) ? 'draft' : 'pending';
			}
		} else {
			// Revisions are not published by wp_update_post() execution; Prevent setting to a non-revision status
			// @todo: confirm this is still needed

			$base_post_id = rvy_get_post_meta($postarr['ID'], '_rvy_base_post_id', true);

			if (($base_post_id && ($base_post_id != $postarr['ID']))
			&& ('trash' != $data['post_status'])
			) {
				if (!$revision = get_post($postarr['ID'])) {
					return $data;
				}

				// Elementor integration causes revision post_mime_type to be set to 'pending' on front end "Save Draft"
				if (rvy_in_revision_workflow($revision)) {
					if (in_array($data['post_mime_type'], ['draft', 'pending'], true)) {
						$data['post_mime_type'] = 'draft-revision';
					}
				}

				if (!rvy_status_revisions_active($revision->post_type)) {
					if (empty($this->enabled_post_types[$revision->post_type])) {
						return $data;
					}

					if (!rvy_is_revision_status($postarr['post_mime_type']) || !in_array($postarr['post_status'], rvy_revision_base_statuses(), true)) {
						$revert_status = true;

					} elseif ($revision) {
						if ((($data['post_mime_type'] != $revision->post_mime_type) || ($data['post_status'] != $revision->post_status))
						&& (('future-revision' == $revision->post_mime_type) || ('future-revision' == $postarr['post_mime_type']))
						) {
							$revert_status = true;
						}
					}

					if (!empty($revert_status) && rvy_in_revision_workflow($revision)) {
						$data['post_status'] = $revision->post_status;
						$data['post_mime_type'] = $revision->post_mime_type;
					}
				}

				if (rvy_get_option('permissions_compat_mode') && ('revision' != $data['post_type']) && rvy_is_revision_status($data['post_mime_type'])) {
					$data['post_status'] = $data['post_mime_type'];
				}
			}
		}

		return $data;
	}

	function do_notifications( $notification_type, $status, $post_arr, $args ) {
		global $rvy_workflow_ui;
		if ( ! isset( $rvy_workflow_ui ) ) {
			require_once( dirname(__FILE__).'/revision-workflow_rvy.php' );
			$rvy_workflow_ui = new Rvy_Revision_Workflow_UI();
		}
		
		return $rvy_workflow_ui->do_notifications( $notification_type, $status, $post_arr, $args );
	}

	// Prevent wp_update_comment_count_now() from modifying Pending Revision comment_count field (main post ID)
	function fltUpdateCommentCountBypass($count, $old, $post_id) {
		if (rvy_in_revision_workflow($post_id)) {
			return rvy_post_id($post_id);
		}

		return $count;
	}

	private function isWorkflowPreviewRequest() {
        // Gutenberg's preview and post-save links omit RVY_PREVIEW_ARG.
        // Classify the request only; Permissions and the preview loader still
        // authorize access to both the revision and its parent. Do not call
        // current_user_can() here: Permissions invokes this during cap queries.
        if (is_admin() || !is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_AJAX') && DOING_AJAX)
			|| !isset($_SERVER['REQUEST_METHOD'])
            || 'GET' !== strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) ?? 'GET')) {
            return false;
        }
        // Preview query arguments only identify a candidate; capability checks below authorize it.
        $id = $_GET['page_id'] ?? ($_GET['p'] ?? ($_GET['preview_id'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview context, not a state-changing action.
        if (!is_scalar($id)) {
            return false;
        }
        $id = absint(wp_unslash($id));
        if (!$id) {
            return false;
        }
        $post = get_post((int) $id);
        return $post && rvy_in_revision_workflow($post)
            && !in_array($post->post_status, ['publish', 'private', 'future', 'trash', 'inherit'], true);
    }

	function fltIsPreview($is_preview) {
        if (defined('RVY_PREVIEW_ARG') && RVY_PREVIEW_ARG && !empty($_REQUEST[RVY_PREVIEW_ARG])) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_preview = true;
        }

        return $is_preview || $this->isWorkflowPreviewRequest();
    }

	/*
	 * PublishPress Permissions: Make query filtering allow for revision previews
	 */  
	function fltQueryPostStatuses($statuses, $args) {
        if (((defined('RVY_PREVIEW_ARG') && RVY_PREVIEW_ARG && !empty($_REQUEST[RVY_PREVIEW_ARG])) || $this->isWorkflowPreviewRequest()) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        && !empty($args['required_operation']) 
		&& ('edit' == $args['required_operation']) 
		&& function_exists('rvy_revision_base_statuses')
		) {
            $statuses = array_merge($statuses, array_fill_keys(rvy_revision_base_statuses(), (object)[]));
        }

        return $statuses;
    }
} // end Revisionary class
