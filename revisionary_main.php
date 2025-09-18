<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die( 'This page cannot be called directly.' );
	
/**
 * @package     PublishPress\Revisions
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2025 PublishPress. All rights reserved.
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
	var $is_revisions_query = false;
	var $front = false;

	var $config_loaded = false;		// configuration related to post types and statuses must be loaded late on the init action
	
	var $enabled_post_types = [];	// enabled_post_types property is set (keyed by post type slug) late on the init action. 
	var $enabled_post_types_archive = [];	// enabled_post_types_archive property is set (keyed by post type slug) late on the init action.
	var $hidden_post_types_archive = [];

	var $post_edit_ui;

	// minimal config retrieval to support pre-init usage by WP_Scoped_User before text domain is loaded
	function __construct() {
	}

	function init() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (isset($_SERVER['REQUEST_URI']) && is_admin() && (false !== strpos(esc_url_raw($_SERVER['REQUEST_URI']), 'revision.php')) && (!empty($_REQUEST['revision']))) {
			add_action('init', [$this, 'addFilters'], PHP_INT_MAX);
		} else {
			$this->addFilters();
		}
	}

	function addFilters() {
		global $script_name;

		add_filter('pre_wp_update_comment_count_now', [$this, 'fltUpdateCommentCountBypass'], 10, 3);
		
		// Ensure editing access to past revisions is not accidentally filtered. 
		// @todo: Correct selective application of filtering downstream so Revisors can use a read-only Compare [Past] Revisions screen
		//
		// Note: some filtering is needed to allow users with full editing permissions on the published post to access a Compare Revisions screen with Preview and Manage buttons

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (is_admin() && isset($_SERVER['REQUEST_URI']) && (false !== strpos(esc_url_raw($_SERVER['REQUEST_URI']), 'revision.php')) && (!empty($_REQUEST['revision'])) && !is_content_administrator_rvy()) {
			
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
		$this->setPostTypesArchive();

		rvy_refresh_options_sitewide();

		if (defined('DOING_CRON') && DOING_CRON) {
			if (!rvy_get_option('wp_cron_usage_detected')) {
				update_option('rvy_wp_cron_usage_detected', true);
			}
		}

		require_once( dirname(__FILE__).'/classes/PublishPress/Revisions/PluginCompat.php' );
		new PublishPress\Revisions\PluginCompat();

		// NOTE: $_GET['preview'] and $_GET['post_type'] arguments are set by rvy_init() at response to ?p= request when the requested post is a revision.
		if (!is_admin() && (!defined('REST_REQUEST') || ! REST_REQUEST)) { // preview_id indicates a regular preview via WP core, based on autosave revision
			$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';
			
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			if ((defined('FL_BUILDER_VERSION') && rvy_in_revision_workflow(rvy_detect_post_id())) || ((!empty($_GET[$preview_arg]) || !empty($_GET['_ppp'])) && empty($_REQUEST['preview_id'])) || !empty($_GET['mark_current_revision'])) {
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

			if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')) {
				add_filter(
					'presspermit_exception_clause', 
					function($clause, $required_operation, $post_type, $args) {
						global $pagenow;

						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if (
							('exclude' == $args['mod'])
							&& rvy_get_option('apply_post_exceptions')
							&& (
								(($pagenow == 'admin.php') && isset($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-q', 'revisionary-archive']))
								|| (in_array($pagenow, ['post.php', 'post-new.php']) && rvy_in_revision_workflow(rvy_detect_post_id()))
							)
						) {
							$revision_status_csv = rvy_revision_statuses(['return' => 'csv']);

							$clause .= " AND ({$args['src_table']}.comment_count {$args['logic']} ('" . implode("','", $args['ids']) . "') OR {$args['src_table']}.post_mime_type NOT IN ($revision_status_csv))";
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
								(($pagenow == 'admin.php') && isset($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-q', 'revisionary-archive']))
								|| (in_array($pagenow, ['post.php', 'post-new.php']) && rvy_in_revision_workflow(rvy_detect_post_id()))
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

		if ( rvy_get_option('scheduled_revisions') ) {
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
		 * @param string    $output      HTML output for drop down list of pages.
		 * @param array     $parsed_args The parsed arguments array. See wp_dropdown_pages()
		 *                               for information on accepted arguments.
		 * @param WP_Post[] $pages       Array of the page objects.
		 */
		$html = apply_filters( 'wp_dropdown_pages', $output, $parsed_args, $pages );
	
		if ( $parsed_args['echo'] ) {
			echo $html;					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	
		// PublishPress: restore this filter hook
		add_filter('wp_dropdown_pages', [$this, 'fltDropdownPages'], 10, 3);

		return $html;
	}

	function adminToolbarItem($admin_bar) {
		global $post;

		if (!empty($post) && rvy_get_option('pending_revisions') && !rvy_in_revision_workflow($post) && ('revision' != $post->post_type) && rvy_is_supported_post_type($post->post_type)) {
			$status_obj = get_post_status_object($post->post_status);

			if (!empty($status_obj->public) || !empty($status_obj->private) || rvy_get_option('pending_revision_unpublished')) {
				if ($type_obj = get_post_type_object($post->post_type)) {

					if (current_user_can('copy_post', $post->ID) && rvy_post_revision_supported($post)) {
						$admin_bar->add_menu([
								'id'    => 'rvy-create-revision',
								'title' => pp_revisions_status_label('draft-revision', 'submit_short'), // Your menu title
								'href'  => rvy_admin_url("admin.php?page=rvy-revisions&amp;post={$post->ID}&amp;action=revise&amp;front=1"), // URL
								'meta'  => [
									'target' => '_blank',
								]
							]
						);
					}
				}
			}
		}
	}

	function configurationLateInit() {
		$this->setPostTypes();
		$this->setPostTypesArchive();
		$this->config_loaded = true;
	}

	public function fltPostsClauses($clauses, $_wp_query, $args = []) {
		global $wpdb, $revisionary;

		$defaults = [
			'is_revisions_query' => false,
            'post_types' => [],
            'source_alias' => false,
        ];
        $args = array_merge($defaults, (array)$args);
        foreach (array_keys($defaults) as $var) {
            $$var = $args[$var];
        }

		if ($is_revisions_query 
		|| !empty($_wp_query->is_revisions_query) 
		|| !empty($_wp_query->query['is_revisions_query']) 
		|| (!empty($revisionary) && !empty($revisionary->is_revisions_query)) 
		|| $_wp_query->is_preview
		|| (isset($_wp_query->query_vars) && isset($_wp_query->query_vars['hide_revision']) && !$_wp_query->query_vars['hide_revision'])
		) {
			return $clauses;
		}

		// Allow revision retrieval by front end editors / previews
		if (!is_admin() && (!defined('REST_REQUEST') || ! REST_REQUEST)) {
			return $clauses;
		}

		if (empty($clauses['where'])) {
			$clauses['where'] = '1=1';
		}

		$src_table = ($source_alias) ? $source_alias : $wpdb->posts;
        $args['src_table'] = $src_table;

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));
		$clauses['where'] .= " AND $src_table.post_mime_type NOT IN ('$revision_status_csv')";

		return $clauses;
	}

	// This is intentionally called twice: once for code that fires on 'init' and then very late on 'init' for types which were registered late on 'init'
	public function setPostTypes() {
		$enabled_post_types = get_option('rvy_enabled_post_types', false);

		if (false === $enabled_post_types) {
			$enabled_post_types = array_fill_keys(['post', 'page'], true);
			
			$available_post_types = get_post_types(['public' => true], 'object');

			// by default, enable public post types that have type-specific capabilities defined
			foreach($available_post_types as $post_type => $type_obj) {
				if ((!empty($type_obj->cap) && !empty($type_obj->cap->edit_posts) && !in_array($type_obj->cap->edit_posts, ['edit_posts', 'edit_pages']))
				|| defined('REVISIONARY_ENABLE_' . strtoupper($post_type) . '_TYPE')
				) {
					$enabled_post_types[$post_type] = true;
				}
			}

			if (class_exists('WooCommerce')) {
				$enabled_post_types['product'] = true;
				$enabled_post_types['order'] = true;
			}

			if (class_exists('Tribe__Events__Main')) {
				$enabled_post_types['tribe_events'] = true;
			}

			if (!defined('REVISIONARY_NO_PRIVATE_TYPES')) {
				$private_types = array_merge(
					get_post_types(['public' => false], 'object'), 
					get_post_types(['public' => null], 'object')
				);
				
				// by default, enable private post types that have type-specific capabilities defined
				foreach($private_types as $post_type => $type_obj) {
					if ((!empty($type_obj->cap) && !empty($type_obj->cap->edit_posts) && !in_array($type_obj->cap->edit_posts, ['edit_posts', 'edit_pages']))
					|| defined('REVISIONARY_ENABLE_' . strtoupper($post_type) . '_TYPE')
					) {
						$enabled_post_types[$post_type] = true;
					}
				}
			}
		}

		$enabled_post_types = apply_filters(
			'revisionary_enabled_post_types', 
			array_diff_key(
				$enabled_post_types,
				['attachment' => true, 'tablepress_table' => true, 'acf-field-group' => true, 'acf-field' => true, 'acf-post-type' => true, 'acf-taxonomy' => true, 'nav_menu_item' => true, 'custom_css' => true, 'customize_changeset' => true, 'wp_block' => true, 'wp_template' => true, 'wp_template_part' => true, 'wp_global_styles' => true, 'wp_navigation' => true, 'ppma_boxes' => true, 'ppmacf_field' => true, 'psppnotif_workflow' => true, 'wpcf7_contact_form' => true]
			)
		);

		$enabled_post_types = array_intersect_key($enabled_post_types, array_fill_keys(get_post_types([], 'names'), true));

		$this->enabled_post_types = array_merge($this->enabled_post_types, $enabled_post_types);

		unset($this->enabled_post_types['attachment']);
		$this->enabled_post_types = array_filter($this->enabled_post_types);
	}

	function getHiddenPostTypesArchive() {
		return $this->hidden_post_types_archive;
	}

	private function setHiddenPostTypesArchive() {
		$this->hidden_post_types_archive = ['attachment' => true, 'tablepress_table' => true, 'acf-field-group' => true, 'acf-field' => true, 'acf-post-type' => true, 'acf-taxonomy' => true, 'nav_menu_item' => true, 'custom_css' => true, 'customize_changeset' => true, 'wp_block' => true, 'wp_template' => true, 'wp_template_part' => true, 'wp_global_styles' => true, 'wp_navigation' => true, 'ppma_boxes' => true, 'ppmacf_field' => true, 'psppnotif_workflow' => true];
	}

	public function setPostTypesArchive() {
		global $current_user;

		$this->setHiddenPostTypesArchive();

	    $enabled_post_types_archive = get_option('rvy_enabled_post_types_archive', false);

	    if (false === $enabled_post_types_archive) {
			$types = get_post_types(['public' => true]);

			$enabled_post_types_archive = array_fill_keys(
	            $types, true
	        );

			if (!defined('REVISIONARY_NO_PRIVATE_TYPES')) {
	            $private_types = array_merge(
	                get_post_types(['public' => false], 'object'),
	                get_post_types(['public' => null], 'object')
	            );

	            // by default, enable non-public post types that have type-specific capabilities defined
	            foreach($private_types as $post_type => $type_obj) {
	                if ((!empty($type_obj->cap) && !empty($type_obj->cap->edit_posts) && !in_array($type_obj->cap->edit_posts, ['edit_posts', 'edit_pages']))
	                || defined('REVISIONARY_ENABLE_' . strtoupper($post_type) . '_TYPE')
	                ) {
	                    $enabled_post_types_archive[$post_type] = true;
	                }
	            }
	        }

			foreach (array_keys($enabled_post_types_archive) as $post_type) {
				if (!post_type_supports($post_type, 'revisions')) {
					unset($enabled_post_types_archive[$post_type]);
				}
			}

	        if (class_exists('WooCommerce')) {
	            $enabled_post_types_archive['product'] = true;
	            $enabled_post_types_archive['order'] = true;
	        }

	        if (class_exists('Tribe__Events__Main')) {
	            $enabled_post_types_archive['tribe_events'] = true;
	        }
	    }

	    $enabled_post_types_archive = array_diff_key(
			$enabled_post_types_archive,
			[
				'attachment' => true,
				'tablepress_table' => true,
				'acf-field-group' => true,
				'acf-field' => true,
				'nav_menu_item' => true,
				'custom_css' => true,
				'customize_changeset' => true,
				'wp_block' => true,
				'wp_template' => true,
				'wp_template_part' => true,
				'wp_global_styles' => true,
				'wp_navigation' => true,
				'product_variation' => true,
				'shop_order_refund' => true
			]
		);

		$this->enabled_post_types_archive = array_merge(
			$this->enabled_post_types_archive,
			$enabled_post_types_archive
		);

		$this->enabled_post_types_archive = apply_filters(
			'revisionary_archive_post_types', 
			$this->enabled_post_types_archive
		);
	}

	function fltNumRevisions ($num, $post) {
        if (isset($this->enabled_post_types_archive[$post->post_type]) && empty($this->enabled_post_types_archive[$post->post_type])) {
            $num = 0;
        } else {
            $num = rvy_get_option('num_revisions');

            if (in_array($num, [true, ''], true)) {
                $num = -1;
            }
        }

        return $num;
    }

	function canEditPost($post, $args = []) {
		global $current_user;

		$args = (array) $args;

		if (is_numeric($post)) {
			$post = get_post($post);
		}

		if (!is_object($post) 
		|| empty($post->ID)
		|| !$type_obj = get_post_type_object($post->post_type)
		|| !$status_obj = get_post_status_object($post->post_status)
		) {
			return false;
		}

		if (!empty($args['simple_cap_check']) && (!empty($status_obj->public) || !empty($status_obj->private))) {
			return isset($type_obj->cap->edit_published_posts) && !empty($current_user->allcaps[$type_obj->cap->edit_published_posts]);
		} else {
			static $last_result;

			if (!isset($last_result)) {
				$last_result = [];
			
			} elseif (!empty($last_result) && isset($last_result[$post->ID])) {
				return $last_result[$post->ID];
			}

			$caps = map_meta_cap('edit_post', $current_user->ID, $post->ID);

			$return = true;

			foreach($caps as $cap) {
				if (empty($current_user->allcaps[$cap])) {
					$return = false;
					break;
				}
			}

			$last_result[$post->ID] = $return;
			return $return;
		}
	}

	function actTrashedPost($revision_id) {
		if (rvy_in_revision_workflow($revision_id, ['include_trash' => true])) {
			$post_id = rvy_post_id($revision_id);

			if (!$triggered_deletions = get_option('_rvy_trigger_deletion')) {
				$triggered_deletions = [];
			}

			$triggered_deletions[$revision_id] = $post_id;

			update_option('_rvy_trigger_deletion', $triggered_deletions);
		}
	}

	// On post deletion, clear corresponding _rvy_has_revisions postmeta flag
	function actDeletedPost($post_id) {
		delete_post_meta($post_id, '_rvy_has_revisions');
	}

	function actSavePost($post_id, $post) {
		global $current_user;
		
		static $busy;

		if (!empty($busy)) {
			return;
		}

		// Track updaters for use with Notifications, Archive
		if ($is_revision = rvy_in_revision_workflow($post_id)) {
			if (!$revision_updaters = get_post_meta($post_id, '_rvy_updated_by', true)) {
				$revision_updaters = [];
			}

			if (empty($revision_updaters[$current_user->ID])) {
				$revision_updaters[$current_user->ID] = current_time( 'mysql', 1 );
				rvy_update_post_meta($post_id, '_rvy_updated_by', $revision_updaters);
			}
		}
		
		if (strtotime($post->post_date_gmt) > agp_time_gmt()) {
			require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
			
			if (rvy_get_option('revision_publish_cron')) {
				if ($is_revision && ('future-revision' == $post->post_mime_type)) {
					rvy_update_next_publish_date(['revision_id' => $post_id]);
				}
			} else {
				rvy_update_next_publish_date();
			}
		}

		$busy = false;
	}

	// Immediately prior to post deletion, also delete its pending revisions and future revisions (and their meta data)
	function actDeletePost($post_id) {
		global $wpdb;

		if (!$post_id) {
			return;
		}

		$num_revisions = revisionary_count_revisions($post_id);

		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$any_trashed_posts = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_status = 'trash' AND comment_count > 0 AND post_mime_type IN ('$revision_status_csv') LIMIT 1");

		$trashed_clause = ($any_trashed_posts) 
		? $wpdb->prepare( 
			" OR (ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_base_post_id' AND meta_value = %d) AND post_status = 'trash')",
			$post_id
		) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status FROM $wpdb->posts WHERE (post_mime_type IN ('$revision_status_csv') AND comment_count = %d) $trashed_clause",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			)
		);

		$post_ids = [];

		foreach($results as $row) {
			wp_delete_post($row->ID, true);

			if ('trash' != $row->post_status) {
				$num_revisions = $num_revisions - 1;
			}

			$post_ids []= $row->ID;
		}

		revisionary_refresh_revision_flags($post_id, ['ignore_revision_ids' => $post_ids, 'num_revisions' => $num_revisions]);

		$post = get_post($post_id);

		if ($post && rvy_in_revision_workflow($post)) {
			$wpdb->query(														// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM $wpdb->postmeta WHERE post_id = %d", 
					$post_id
				)
			);

			$meta_args = ['ignore_revisions' => [$post->ID]];

			if (rvy_get_option('revision_limit_per_post')) {
				delete_post_meta(rvy_post_id($post->ID), '_rvy_has_revisions');
				$meta_args['insert_only'] = true;
			}

			revisionary_refresh_postmeta(rvy_post_id($post->ID), $meta_args);
		}
	}

	function actUpdateRevision($post_id, $revision) {
		if (rvy_in_revision_workflow($revision)
		&& (rvy_get_option('revision_update_notifications')) 
		) {
			$published_post = get_post(rvy_post_id($revision));

			if (apply_filters('revisionary_do_revision_notice', !$this->doing_rest, $revision, $published_post)) {
				if (('future-revision' != $revision->post_mime_type) && rvy_get_option('revision_update_notifications')) {
					$args = ['update' => true, 'revision_id' => $revision->ID, 'published_post' => $published_post, 'object_type' => $published_post->post_type];
					
					
					if ( !empty( $_REQUEST['prev_cc_user'] ) ) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$args['selected_recipients'] = array_map('intval', $_REQUEST['prev_cc_user']);		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					} else {
						// If the UI that triggered this notification does not support recipient selection, send to default recipients for this post
						require_once( dirname(__FILE__) . '/revision-workflow_rvy.php' );
						$result = Rvy_Revision_Workflow_UI::default_notification_recipients($published_post->ID, ['object_type' => $published_post->post_type]);
						$args['selected_recipients'] = array_keys(array_filter($result['default_ids']));
					}

					$this->do_notifications('pending-revision', 'pending-revision', (array) $revision, $args);
				}
			}
		}
	}

	function actUpdateRevisionFixCommentCount($post_id, $revision) {
		global $wpdb;
		
		if (rvy_in_revision_workflow($revision)) {
			if (empty($revision->comment_count)) {
				if ($main_post_id = get_post_meta($revision->ID, '_rvy_base_post_id', true)) {
					if ($main_post_id != $revision->ID) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update($wpdb->posts, ['comment_count' => $main_post_id], ['ID' => $revision->ID]);
					}
				}
			}
		}
	}

	// Return zero value for revision comments because:
	// * comments are not supported for revisions
	// * published post ID is stored to comment_count column is used for query efficiency 
	function flt_get_comments_number($count, $post_id) {
		if ($post = get_post($post_id)) {
			if (rvy_in_revision_workflow($post)) {
				$count = 0;
			}
		}

		return $count;
	}

	function get_last_revision($post_id, $user_id) {
		require_once(dirname(__FILE__).'/admin/admin-init_rvy.php');

		if ( $revisions = rvy_get_post_revisions( $post_id, '', array( 'order' => 'DESC', 'orderby' => 'ID' ) ) ) {  // @todo: retrieve revision_id in block editor js, pass as redirect arg
			foreach( $revisions as $revision ) {
				if ((false === $user_id) || rvy_is_post_author($revision, $user_id)) {
					return $revision;
				}
			}
		}

		return false;
	}

	function act_new_revision_redirect() {
		global $current_user, $post;

		if (!empty($_REQUEST['get_new_revision'])) {
			check_admin_referer('new-revision');
		} else {
			return;
		}

		$published_post_id = (int) $_REQUEST['get_new_revision'];

		$published_url = get_permalink($published_post_id);

		$revision = $this->get_last_revision($published_post_id, $current_user->ID);

		if ($revision) {
			$args = [];
			if (!empty($_REQUEST['nc'])) { // with a specified link target, avoid multiple browser tabs in the same editor instance
				$args['nc'] = sanitize_key($_REQUEST['nc']);
			}

			$type_obj = get_post_type_object(get_post_field('post_type', $published_post_id));

			$preview_link = (!empty($type_obj) && !empty($type_obj->public)) ? rvy_preview_url($revision, $args) : admin_url("post.php?post={$published_post_id}&action=edit");

			wp_redirect($preview_link);
			exit;
		}

		// If logged user does not have a pending revision of this post, redirect to published permalink
		wp_redirect($published_url);
		exit;
	}

	function act_edit_revision_redirect() {
		global $current_user, $post;

		if (!empty($_REQUEST['edit_new_revision'])) {
			check_admin_referer('edit-new-revision');
		} else {
			return;
		}

		$published_post_id = (!empty($_REQUEST['edit_new_revision'])) ? rvy_post_id((int) $_REQUEST['edit_new_revision']) : rvy_post_id($post->ID);
		$published_url = get_permalink($published_post_id);

		$revision = $this->get_last_revision($published_post_id, $current_user->ID);

		if ($revision) {
			$args = [];
			if (!empty($_REQUEST['nc'])) { // with a specified link target, avoid multiple browser tabs in the same editor instance
				$args['nc'] = sanitize_key($_REQUEST['nc']);
			}

			$edit_link = admin_url("post.php?post={$revision->ID}&action=edit&nc={$args['nc']}");
			wp_redirect($edit_link);
			exit;
		}

		// If logged user does not have a pending revision of this post, redirect to published permalink
		wp_redirect($published_url);
		exit;
	}

	// log post type and ID from REST handler for reference by subsequent PP filters 
	function rest_pre_dispatch( $rest_response, $rest_server, $request ) {
		$this->doing_rest = true;

		require_once( dirname(__FILE__).'/rest_rvy.php' );
		$this->rest = new Revisionary_REST();
		
		return $this->rest->pre_dispatch( $rest_response, $rest_server, $request );
	}

	// prevent revisors from editing other users' regular drafts and pending posts
	function flt_limit_others_drafts( $caps, $meta_cap, $user_id, $args ) {
		global $current_user;
		
		if (!empty($this->skip_filtering)) {
			return $caps;
		}

		if ( ! in_array( $meta_cap, array( 'edit_post', 'edit_page' ) ) )
			return $caps;
		
		$object_id = ( is_array($args) && ! empty($args[0]) ) ? (int) $args[0] : $args;
		
		if ( ! $object_id || ! is_scalar($object_id) || ( $object_id < 0 ) || ! rvy_get_option('require_edit_others_drafts') ) {
			return $caps;
		}

		if ( $post = get_post( $object_id ) ) {
			if ( ('revision' != $post->post_type) && ! rvy_in_revision_workflow($post) ) {

				if (empty($this->enabled_post_types[$post->post_type])
				|| !apply_filters('revisionary_require_edit_others_drafts', true, $post->post_type, $post->post_status, $args)) {
					return $caps;
				}

				$status_obj = get_post_status_object( $post->post_status );

				if (!rvy_is_post_author($post) && $status_obj && ! $status_obj->public && ! $status_obj->private) {

					$post_type_obj = get_post_type_object( $post->post_type );
					if (isset($post_type_obj->cap->edit_published_posts) && current_user_can( $post_type_obj->cap->edit_published_posts)) {	// don't require any additional caps for sitewide Editors
						return $caps;
					}

					static $stati;

					if ( ! isset($stati) ) {
						$stati = get_post_stati( array( 'internal' => false, 'protected' => true ) );
						$stati = array_diff( $stati, array( 'future' ) );
					}

					if ( in_array( $post->post_status, $stati ) ) {	// isset check because doing_cap_check property was undefined prior to Permissions 3.3.8
						if ((!function_exists('presspermit') || (isset(presspermit()->doing_cap_check) && !presspermit()->doing_cap_check)) && $post_type_obj) {
							if (!empty($post_type_obj->cap->edit_others_posts)) {
								$caps[] = str_replace('edit_', 'list_', $post_type_obj->cap->edit_others_posts);
							}
						}
						
						if (empty($current_user->allcaps['edit_others_drafts'])) {
							$caps[] = "edit_others_drafts";
						}
					}
				}
			}
		}

		return $caps;
	}

	function fltStatusChangeCap($caps, $cap, $user_id, $args) {
		global $current_user;
		
		if ('copy_post' == $cap) {
			if (!rvy_get_option('pending_revisions')) {
				return array_diff_key($caps, [$cap => true]);
			}

			if (!empty($args[0])) {
				$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
			} else {
				$post_id = 0;
			}

			if (rvy_in_revision_workflow($post_id)) {
				return array_diff_key($caps, [$cap => true]);
			}

			$filter_args = [];

			if (!$can_copy = rvy_is_full_editor($post_id)) {
				if ($_post = get_post($post_id)) {
					$type_obj = get_post_type_object($_post->post_type);
				}

				if (!empty($type_obj)) {
					if (rvy_get_option("copy_posts_capability")) {		
						$base_prop = (rvy_is_post_author($post_id)) ? 'edit_posts' : 'edit_others_posts';
						$copy_cap_name = str_replace('edit_', 'copy_', $type_obj->cap->$base_prop);

						if (false === strpos($copy_cap_name, 'copy_')) {
							if ('page' == $_post->post_type) {
								$copy_cap_name = (rvy_is_post_author($post_id)) ? 'copy_pages' : 'copy_others_pages';
							} else {
								$copy_cap_name = (rvy_is_post_author($post_id)) ? 'copy_posts' : 'copy_others_posts';
							}
						}

						$can_copy = current_user_can($copy_cap_name);
					} else {
						$can_copy = current_user_can($type_obj->cap->edit_posts);
					}

					$filter_args = compact('type_obj');
				}
			}

			if (!empty($caps)) {
				$can_copy = $can_copy && !array_diff($caps, array_keys(array_filter($current_user->allcaps)), ['copy_post']);
			}

			// allow PublishPress Permissions to apply 'copy' exceptions
			if ($can_copy = apply_filters('revisionary_can_copy', $can_copy, $post_id, 'draft', 'draft-revision', $filter_args)
			|| apply_filters('revisionary_can_submit', $can_copy, $post_id, 'pending', 'pending-revision', $filter_args)
			) {
				$caps = ['read'];
			} else {
				$caps = array_diff_key($caps, [$cap => true]);
			}
		
		} elseif (0 === strpos($cap, 'set_revision_')) {
			if (!rvy_get_option('pending_revisions')) {
				return array_diff_key($caps, [$cap => true]);
			}
			
			if (!empty($args[0])) {
				$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
			} else {
				$post_id = 0;
			}

			if (!rvy_in_revision_workflow($post_id)) {
				return $caps;
			}

			$filter_args = [];

			if ($can_submit = current_user_can('edit_post', $post_id)) {  // require basic editing capabilties for revision ID
				$main_post_id = rvy_post_id($post_id);

				if (rvy_get_option("revise_posts_capability") && !rvy_is_full_editor($main_post_id)) { // bypass capability check for those with full editing caps on main post
					if ($_post = get_post($post_id)) {
						if ($type_obj = get_post_type_object($_post->post_type)) {
							$base_prop = (rvy_is_post_author($main_post_id)) ? 'edit_posts' : 'edit_others_posts';
							$submit_cap_name = str_replace('edit_', 'revise_', $type_obj->cap->$base_prop);
							$can_submit = current_user_can($submit_cap_name);
							$filter_args = compact('main_post_id', 'type_obj');
						}
					}
				}
			}

			$revision_status = substr($cap, strlen('set_revision_') - 1);

			// allow PublishPress Permissions to apply 'revise' exceptions
			if ($can_submit = apply_filters('revisionary_can_submit', $can_submit, $post_id, 'pending', $revision_status, $filter_args)) {
				$caps = ['read'];
			}
		} elseif ($cap == 'approve_revision') {
			if (!empty($args[0])) {
				$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
			} else {
				$post_id = 0;
			}

			if (!rvy_in_revision_workflow($post_id)) {
				return $caps;
			}

			$filter_args = [];

			if ($can_approve = current_user_can('edit_post', $post_id)) {  // require basic editing capabilties for revision ID
				$main_post_id = rvy_post_id($post_id);

				if (!rvy_is_full_editor($main_post_id)) { // bypass capability check for those with full editing caps on main post
					if ($_post = get_post($post_id)) {
						if ($type_obj = get_post_type_object($_post->post_type)) {
							$base_prop = (rvy_is_post_author($main_post_id)) ? 'edit_posts' : 'edit_others_posts';
							$approve_cap_name = str_replace('edit_', 'approve_', $type_obj->cap->$base_prop);
							$can_approve = current_user_can($approve_cap_name);
							$filter_args = compact('main_post_id', 'type_obj');
						}
					}
				}
			}

			// allow PublishPress Permissions to apply 'revise' exceptions
			if ($can_approve = apply_filters('revisionary_can_approve', $can_approve, $post_id, $filter_args)) {
				$caps = ['read'];
			}
		}

		return $caps;
	}

	function set_content_roles( $content_roles_obj ) {
		$this->content_roles = $content_roles_obj;

		if ( ! defined( 'RVY_CONTENT_ROLES' ) ) {
			define( 'RVY_CONTENT_ROLES', true );
		}
	}
	
	
	function act_new_blog( $blog_id, $user_id ) {
		rvy_add_revisor_role( $blog_id );
	}
	
	function fltAllowTeaser($allow, $post_id) {
		$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ((!empty($_REQUEST[$preview_arg]) || !empty($_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		if (in_array($cap, ['read_post', 'read_page'])	// WP Query imposes edit_post capability requirement for front end viewing of protected statuses 
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
				|| empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded
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

		if (!in_array($cap, array('read_post', 'read_page', 'edit_post', 'edit_page', 'delete_post', 'delete_page'))) {
			return $caps;
		}

		if (!empty($args[0])) {
			$post_id = (is_object($args[0])) ? $args[0]->ID : (int) $args[0];
		} else {
			$post_id = 0;
		}

		if ($post = get_post($post_id)) {
			if (('inherit' == $post->post_status)
			|| empty($this->enabled_post_types[$post->post_type]) && $this->config_loaded
			) {
				return $caps;
			}
		}

		if ($post && (('future-revision' == $post->post_mime_type) 
		|| (in_array($cap, ['read_post', 'read_page']) && empty($current_user->allcaps['preview_others_revisions'])))
		) {
			if (in_array($cap, ['read_post', 'read_page'])) {
				return $caps;
			}
 
			// allow Revisor to view a preview of their scheduled revision
			
			if (is_admin() 
			|| (defined('REST_REQUEST') && REST_REQUEST) 
			|| empty($_REQUEST['preview']) || !empty($_POST) 						//phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			|| did_action('template_redirect')
			) {
				if ($type_obj = get_post_type_object( $post->post_type )) {
					if (isset($type_obj->cap->edit_published_posts)) {
						$check_cap = in_array($cap, ['delete_post', 'delete_page']) ? $type_obj->cap->delete_published_posts : $type_obj->cap->edit_published_posts;
						return array_merge($caps, [$check_cap]);
					} else {
						return $caps;
					}
				}
			}
		}

		$busy = true;

		$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (in_array($cap, ['read_post', 'read_page'])	// WP Query imposes edit_post capability requirement for front end viewing of protected statuses 
			|| (
				(!empty($_REQUEST[$preview_arg]) || !empty($_GET['preview'])) 		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& in_array($cap, array('edit_post', 'edit_page')) 
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

					if (rvy_is_post_author($check_post) || rvy_is_post_author(rvy_post_id($check_post->ID)) || rvy_is_full_editor($post)) {
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
				if (in_array($type_obj->cap->edit_others_posts, $caps)) {	
					if ((!empty($type_obj->cap->edit_others_posts) && empty($current_user->allcaps[$type_obj->cap->edit_others_posts])) 
					|| (!empty($type_obj->cap->edit_published_posts) && empty($current_user->allcaps[$type_obj->cap->edit_published_posts]))
					) {
						if (!current_user_can('approve_revision', $post_id)) {
							if (!empty($current_user->allcaps['edit_others_revisions'])) {
								$caps[] = 'edit_others_revisions';
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

									if (isset($additional_ids[$post->post_type]) && in_array($post->ID, $additional_ids[$post->post_type])) {
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

		if (in_array($cap, array('edit_post', 'edit_page'))) {
			if ($post && !empty($post->post_status)) {
				if (!in_array($post->post_status, rvy_filtered_statuses())) {
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

		$post_id = ( ! empty($args[2]) ) ? $args[2] : rvy_detect_post_id();

		if (!$post = get_post($post_id)) {
			if (($post_id == -1) && defined('PRESSPERMIT_PRO_VERSION') && !empty(presspermit()->meta_cap_post)) {  // wp_cache_add(-1) does not work for map_meta_cap call on get-revision-diffs ajax call 
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

					if (isset($additional_ids[$post->post_type]) && in_array($post->ID, $additional_ids[$post->post_type])) {
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
				if (!empty($object_type_obj->cap) && in_array($object_type_obj->cap->edit_others_posts, $reqd_caps)) {
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

		// If this is already a scheduled revision and the date is being modified, update the WP-Cron entry
		if (rvy_in_revision_workflow($postarr['ID']) && ('future-revision' == $postarr['post_mime_type'])) {

			$current_post_date_gmt = get_post_field('post_date_gmt', $postarr['ID']);

			if ($data['post_date_gmt'] != $current_post_date_gmt) {
				wp_unschedule_event(strtotime($current_post_date_gmt), 'publish_revision_rvy', [$postarr['ID']]);

				wp_schedule_single_event(strtotime($data['post_date_gmt']), 'publish_revision_rvy', [$postarr['ID']]);
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
					if (in_array($data['post_mime_type'], ['draft', 'pending'])) {
						$data['post_mime_type'] = 'draft-revision';
					}
				}

				if (!rvy_status_revisions_active($revision->post_type)) {
					if (empty($this->enabled_post_types[$revision->post_type])) {
						return $data;
					}

					if (!rvy_is_revision_status($postarr['post_mime_type']) || !in_array($postarr['post_status'], rvy_revision_base_statuses())) {
						$revert_status = true;

					} elseif ($revision) {
						if (($data['post_mime_type'] != $revision->post_mime_type) || ($data['post_status'] != $revision->post_status)
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

	function fltIsPreview($is_preview) {
        if (defined('RVY_PREVIEW_ARG') && RVY_PREVIEW_ARG && !empty($_REQUEST[RVY_PREVIEW_ARG])) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_preview = true;
        }

        return $is_preview;
    }

	/*
	 * PublishPress Permissions: Make query filtering allow for revision previews
	 */  
	function fltQueryPostStatuses($statuses, $args) {
        if (((defined('RVY_PREVIEW_ARG') && RVY_PREVIEW_ARG && !empty($_REQUEST[RVY_PREVIEW_ARG])))	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
        && !empty($args['required_operation']) 
		&& ('edit' == $args['required_operation']) 
		&& function_exists('rvy_revision_base_statuses')
		) {
            $statuses = array_merge($statuses, array_fill_keys(rvy_revision_base_statuses(), (object)[]));
        }

        return $statuses;
    }
} // end Revisionary class
