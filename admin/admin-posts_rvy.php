<?php
class RevisionaryAdminPosts {
    private $post_revision_count = array();
	private $trashed_revisions;
	private $filtering_edit_link = [];
	private $skip_has_cap_filtering = false;

    function __construct() {
        if ( ! empty( $_REQUEST['revision_action'] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_action( 'all_admin_notices', [$this, 'revision_action_notice']);
        }

        add_action('admin_enqueue_scripts', [$this, 'fltAdminPostsListing'], 50);  // 'the_posts' filter is not applied on edit.php for hierarchical types

        add_filter('display_post_states', [$this, 'flt_display_post_states'], 50, 2);
		add_filter('page_row_actions', [$this, 'revisions_row_action_link']);
		add_filter('post_row_actions', [$this, 'revisions_row_action_link']);

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (!empty($_REQUEST['post_status']) && ('trash' == sanitize_key($_REQUEST['post_status']))) {
			add_filter('display_post_states', [$this, 'fltTrashedPostState'], 20, 2 );
			add_filter('get_comments_number', [$this, 'fltCommentsNumber'], 20, 2);
		}

		// If a revision was just deleted from within post editor, redirect to Revision Queue
																						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (!empty($_REQUEST['trashed']) && !empty($_REQUEST['post_type']) && !empty($_REQUEST['ids']) && is_scalar($_REQUEST['ids'])) {
		
			$post_type = sanitize_key($_REQUEST['post_type']);							//phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if (in_array($post_type, rvy_get_manageable_types())) {
				$deleted_id = (int) $_REQUEST['ids'];									//phpcs:ignore WordPress.Security.NonceVerification.Recommended

				if (!empty($_SERVER['HTTP_REFERER']) && (
					(false !== strpos(esc_url_raw($_SERVER['HTTP_REFERER']), admin_url("post.php?post={$deleted_id}&action=edit")))
					|| (false !== strpos(esc_url_raw($_SERVER['HTTP_REFERER']), admin_url("post-new.php")))
				)) {
					$_post = get_post($deleted_id);

					if (!$_post || (('trash' == $_post->post_status) && in_array($_post->post_mime_type, rvy_revision_statuses()))) {
						if (apply_filters('revisionary_deletion_redirect_to_queue', true, $deleted_id, $post_type)) {
							$url = wp_nonce_url(admin_url("admin.php?page=revisionary-q&pp_revisions_deleted={$deleted_id}"), 'revisions-deleted');
							
							if (!empty($_SERVER['REQUEST_URI']) && false === strpos(esc_url_raw($_SERVER['REQUEST_URI']), $url)) {
								wp_redirect($url);
								exit;
							}
						}
					}
				}
			}
		}

		add_filter('query', [$this, 'fltPostCountQuery']);

		add_filter('posts_where', [$this, 'fltFilterRevisions'], 10, 2);

		if (empty($_REQUEST['page']) || (0 !== strpos($_REQUEST['page'], 'cms-tpv'))) {
			add_filter('posts_results', [$this, 'fltPostsResults'], 10, 1);
			add_filter('manage_product_posts_custom_column', [$this, 'actProductsCol'], 10, 1);
			add_filter('get_edit_post_link', [$this, 'fltGetEditPostLink'], 50, 3);
		}
    }

	// Ensure that the thumbnail is displayed without a PHP warning, even for Revisors who can't edit published posts
	public function actProductsCol($column) {
		global $post;

		if (defined('REVISIONARY_NO_REVISOR_POSTS_CAPS_WORKAROUND')) {
			return;
		}

		if ('thumb' == $column) {
			$this->skip_has_cap_filtering = true;

			if (!empty($post) && !current_user_can('edit_post', $post->ID)) {
				add_filter('user_has_cap', [$this, 'actUserHasCap'], 999, 3);

				// Trigger javascript to remove the non-functional Edit link which this filtering will cause
				$this->filtering_edit_link[$post->ID] = true;
			}

			$this->skip_has_cap_filtering = false;
		}
	}

	// Ensure that the title is displayed without a PHP warning, even for Revisors who can't edit published posts
	public function fltPostsResults($posts) {
		if (defined('REVISIONARY_NO_REVISOR_POSTS_CAPS_WORKAROUND')) {
			return $posts;
		}

		$this->skip_has_cap_filtering = true;

		foreach ($posts as $_post) {
			if (!current_user_can('edit_post', $_post->ID)) {
				// Trigger javascript to remove the non-functional Edit link which this filtering will cause
				$this->filtering_edit_link[$_post->ID] = true;
				$set_flag = true;	
			}
		}

		$this->skip_has_cap_filtering = false;

		if (!empty($set_flag)) {
			add_filter('user_has_cap', [$this, 'actUserHasCap'], 999, 3);
		}

		return $posts;
	}

	// Prevent PHP warnings for Revisors who can't edit published posts (but should still see the post listed with New Revision link)
	public function actUserHasCap($wp_blogcaps, $reqd_caps, $args) {
		if (!defined('REVISIONARY_NO_REVISOR_POSTS_CAPS_WORKAROUND') && !$this->skip_has_cap_filtering && !empty($args[2]) && array_diff($reqd_caps, array_keys(array_filter($wp_blogcaps)))) {
			if (!empty($args[0]) && in_array($args[0], ['edit_post', 'edit_page'])) {
				$this->filtering_edit_link[$args[2]] = true;
				$wp_blogcaps = array_merge($wp_blogcaps, array_fill_keys($reqd_caps, true));
			}
		}

		return $wp_blogcaps;
	}

	public function fltGetEditPostLink($link, $post_id, $context) {
		if (!empty($this->filtering_edit_link[$post_id])) {
			if (defined('REVISIONARY_NO_REVISOR_POSTS_CAPS_WORKAROUND')) {
				return $link;
			}
			
			remove_filter('user_has_cap', [$this, 'actUserHasCap'], 10, 3);

			add_action(
				'admin_print_footer_scripts',
				function () use ($link) {
					if ($ipos = strpos($link, '&')) {
						$link = substr($link, 0, $ipos - 1);
					}
				?>
					<script type="text/javascript">
					/* <![CDATA[ */
					jQuery(document).ready( function($) {
						if ($('#the-list').length) {
							$('td.column-name a[href*="<?php echo $link;?>"]').contents().unwrap().closest('div.row-actions').find('span.edit,span.inline,span.trash').hide().closest('tr').find('.check-column input[type="checkbox"]').hide();
							$('td.column-title a[href*="<?php echo $link;?>"]').contents().unwrap().closest('div.row-actions').find('span.edit,span.inline,span.trash').hide().closest('tr').find('.check-column input[type="checkbox"]').hide();
						}
					});
					/* ]]> */
					</script>
				<?php
				}
			);
		}

		return $link;
	}
    
    function revision_action_notice() {
		if ( ! empty($_GET['restored_post'] ) ) {										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class='updated' style="padding-top: 10px; padding-bottom: 10px"><?php esc_html_e('The revision was restored.', 'revisionary');?>
			</div>
			<?php
		} elseif ( ! empty($_GET['scheduled'] ) ) {										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class='updated' style="padding-top: 10px; padding-bottom: 10px"><?php esc_html_e('The revision was scheduled for publication.', 'revisionary');?>
			</div>
			<?php
		} elseif ( ! empty($_GET['published_post'] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class='updated' style="padding-top: 10px; padding-bottom: 10px"><?php esc_html_e('The revision was published.', 'revisionary');?>
			</div>
			<?php																		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
    	} elseif ( !empty($_GET['revision_action']) && ('blocked_unfiltered' == $_GET['revision_action'] ) ) {
			?>
			<div class='error' style="padding-top: 10px; padding-bottom: 10px"><?php printf(esc_html__('The unfiltered_html capability is required to create a revision of this post. See %sdocumentation%s.', 'revisionary'), '<a href="https://publishpress.com/knowledge-base/troubleshooting-revisionary/" target="_blank">', '</a>');?>
			</div>
			<?php																		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
    	} elseif ( !empty($_GET['revision_action']) && ('blocked_revision_limit' == $_GET['revision_action'] ) ) {
			?>
			<div class='error' style="padding-top: 10px; padding-bottom: 10px"><?php esc_html_e('The post already has a revision in process.', 'revisionary');?>
			</div>
			<?php	
    	}
    }
    
    public function fltAdminPostsListing() {
		global $wpdb, $wp_query, $typenow;

		$listed_ids = array();

		if (!empty($typenow) && !rvy_is_supported_post_type($typenow)) {
            return;
        }

		if ( ! empty( $wp_query->posts ) ) {
			foreach ($wp_query->posts as $row) {
				$listed_ids[] = $row->ID;
			}	
		}

		if ($listed_ids) {
			$id_csv = implode("','", array_map('intval', $listed_ids));
			$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

			$revision_base_statuses = array_map('sanitize_key', rvy_revision_base_statuses());
			$revision_base_status_csv = implode("','", $revision_base_statuses);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				"SELECT comment_count AS published_post, COUNT(comment_count) AS num_revisions FROM $wpdb->posts"
				. " WHERE $wpdb->posts.comment_count IN ('$id_csv')"			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. " AND $wpdb->posts.post_mime_type IN ('$revision_status_csv') AND $wpdb->posts.post_type != '' GROUP BY comment_count"		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			
			foreach($results as $row) {
				$this->post_revision_count[$row->published_post] = $row->num_revisions;
			}
		}
    }
    
    private function logTrashedRevisions() {
		global $wpdb, $wp_query;
		
		if (!empty($wp_query) && !empty($wp_query->posts)) {
			$listed_ids = [];
			
			foreach($wp_query->posts AS $row) {
				$listed_ids []= $row->ID;
			}

			$listed_post_csv = implode("','", array_map('intval', $listed_ids));

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->trashed_revisions = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_base_post_id' AND post_id IN ('$listed_post_csv')");
		} else {
			$this->trashed_revisions = [];
		}
	}

	/**
	 * Adds "Pending Revision" or "Scheduled Revision" to the list of display states for trashed revisions in the Posts list table.
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 * @return array Filtered array of post display states.
	 */
	public function fltTrashedPostState($post_states, $post) {
		if (!$post->comment_count) { // revisions buffer base post id to comment_count column for perf
			return $post_states;
		}

		if (!isset($this->trashed_revisions)) {
			$this->logTrashedRevisions();
		}

		if (in_array($post->ID, $this->trashed_revisions)) {		
			if ($status_obj = get_post_status_object($post->post_mime_type)) {
				$post_states['rvy_revision'] = $status_obj->label;
			}

			if (!isset($post_states['rvy_revision'])) {
				$post_states['rvy_revision'] = esc_html__('Revision', 'revisionary');
			}
		}

		return $post_states;
	}

	function fltCommentsNumber($comment_count, $post_id) {
		if (isset($this->trashed_revisions) && in_array($post_id, $this->trashed_revisions)) {
			$comment_count = 0;
		}

		return $comment_count;
	}

	function flt_display_post_states($post_states, $post) {
		if (!empty($this->post_revision_count[$post->ID]) && !defined('REVISIONARY_SUPPRESS_POST_STATE_DISPLAY')) {
			$post_states []= esc_html__('Has Revision', 'revisionary');
		}

		return $post_states;
	}

	function revisions_row_action_link($actions = array()) {
		global $post;

		if (!empty($post) && !rvy_is_supported_post_type($post->post_type)) {
            return $actions;
        }

		if (!empty($this->post_revision_count[$post->ID])) {
			if ( 'trash' != $post->post_status && wp_check_post_lock( $post->ID ) === false ) {
				$actions['revision_queue'] = "<a href='admin.php?page=revisionary-q&published_post={$post->ID}&all=1'>" . esc_html__('Revision Queue', 'revisionary') . '</a>';
			}
		}
		
		$status_obj = get_post_status_object($post->post_status);

		if (!empty($status_obj->public) || !empty($status_obj->private) || rvy_get_option('pending_revision_unpublished')) {
			if ($revision_blocked = rvy_post_revision_blocked($post, ['context' => 'admin_posts'])) {
				if (('blocked_revision_limit' == $revision_blocked['code']) && rvy_get_option('revision_limit_compat_mode')) {
					revisionary_refresh_postmeta($post->ID);
					$revision_blocked = rvy_post_revision_blocked($post, ['context' => 'admin_posts']);
				}
			}

			if (rvy_get_option('pending_revisions') && current_user_can('copy_post', $post->ID) && !$revision_blocked) {
				$uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';
				$referer_arg = '&referer=' . $uri;

				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';
				$url = rvy_admin_url("admin.php?page=rvy-revisions&amp;post={$post->ID}&amp;action=revise{$referer_arg}$redirect_arg");
				
				$url = remove_query_arg(['post_status', 'action', 'cat', 'seo-filter', 'schema-filter', 'paged', 'action2'], $url);

				$caption = (isset($actions['edit']) || !rvy_get_option('caption_copy_as_edit')) ? pp_revisions_status_label('draft-revision', 'submit') : esc_html__('Edit');
				$caption = str_replace(' ', '&nbsp;', $caption);

				$actions['create_revision'] = "<a href='" . esc_url($url) . "'>" . $caption . '</a>';
			}
		}

		return $actions;
	}

	public function fltPostCountQuery($query)
    {
        global $wpdb;

        $posts = $wpdb->posts;

		$matches = [];

        // wp_count_posts() :
        // SELECT post_status, COUNT( * ) AS num_posts FROM {$wpdb->posts} WHERE post_type = %s

        /*
        SELECT COUNT( 1 )
			FROM $wpdb->posts
			WHERE post_type = %s
			AND post_status NOT IN ( '" . implode( "','", $exclude_states ) . "' )
			AND post_author = %d
        */

        $pos_from = strpos($query, "FROM $posts");
		$pos_where = strpos($query, "WHERE ");
        
        // todo: use 'wp_count_posts' filter instead?

        if ((strpos($query, "ELECT post_status, COUNT( * ) AS num_posts ") || (strpos($query, "ELECT COUNT( 1 )") && $pos_from && (!$pos_where || ($pos_from < $pos_where)))) 
        && preg_match("/FROM\s*{$posts}\s*WHERE post_type\s*=\s*'([^ ]+)'/", $query, $matches)
        ) {
            $_post_type = (!empty($matches[1])) ? $matches[1] : PWP::findPostType();

            if ($_post_type) {
				$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));
				
				if (!function_exists('presspermit')) {
					// avoid counting posts stored with a status that's no longer registered
					$statuses = get_post_stati();
					$statuses_clause = " AND post_status IN ('" 
											. implode("','", array_map('sanitize_key', $statuses)) 
										. "')";
				} else {
					$statuses_clause = '';
				}

				if (!strpos($query, "AND $wpdb->posts.post_mime_type NOT IN ('$revision_status_csv')")) {
					$query = str_replace(
						" post_type = '{$matches[1]}'", 
						"( post_type = '{$matches[1]}' AND $wpdb->posts.post_mime_type NOT IN ('$revision_status_csv'){$statuses_clause} )", 
						$query
					);
				}
			}
		}

		return $query;
	}

	function fltFilterRevisions($where, $wp_query) {
		global $wpdb, $typenow;

		$revision_statuses = rvy_revision_statuses();

		$post_type = (!empty($typenow)) ? $typenow : '';

		// Prevent inactive revisions from being displayed as normal posts if Statuses Pro was deactivated
		if (!rvy_status_revisions_active($post_type)) {
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
				$stored_statuses = get_terms('pp_revision_status', ['hide_empty' => false, 'return' => 'name']);
			}

			foreach ($stored_statuses as $status) {
				if (!in_array($status->slug, $revision_statuses)) {
					$revision_statuses[] = $status->slug;
				}
			}
		}

		$revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

		$where .= " AND $wpdb->posts.post_mime_type NOT IN ('$revision_status_csv') AND $wpdb->posts.post_status NOT IN ('$revision_status_csv')";

		return $where;
	}
}
