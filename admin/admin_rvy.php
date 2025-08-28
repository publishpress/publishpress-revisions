<?php
/**
 * @package     PublishPress\Revisions\RevisionaryAdmin
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2025 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 *
 * Scripts and filter / action handlers applicable for all wp-admin URLs
 *
 * Selectively load other classes based on URL
 */

if( isset($_SERVER['SCRIPT_FILENAME']) && (basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME']))) )
	die();

define ('RVY_URLPATH', plugins_url('', REVISIONARY_FILE));

class RevisionaryAdmin
{
	function __construct() {
		global $pagenow, $post;

		$script_name = (isset($_SERVER['SCRIPT_NAME'])) ? esc_url_raw($_SERVER['SCRIPT_NAME']) : '';

		add_action('admin_head', [$this, 'admin_head']);
		add_filter('admin_body_class', [$this, 'fltAdminBodyClass'], 20);
		add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
		add_action('revisionary_admin_footer', [$this, 'publishpressFooter']);

		add_action('admin_print_scripts', [$this, 'hideAdminMenuToolbar'], 50);

		if ( ! defined('XMLRPC_REQUEST') && ! strpos($script_name, 'p-admin/async-upload.php' ) ) {
			if ( RVY_NETWORK && ( is_main_site() ) ) {
				require_once( dirname(__FILE__).'/admin_lib-mu_rvy.php' );
				add_action('admin_menu', 'rvy_mu_site_menu', 15 );
			}

			add_action('admin_menu', [$this, 'build_menu']);

			if ( strpos($script_name, 'p-admin/plugins.php') ) {
				add_filter( 'plugin_row_meta', [$this, 'flt_plugin_action_links'], 10, 2 );
			}
		}

		// ===== Special early exit if this is a plugin install script
		if ( strpos($script_name, 'p-admin/plugins.php') || strpos($script_name, 'p-admin/plugin-install.php') || strpos($script_name, 'p-admin/plugin-editor.php') ) {
			if (strpos($script_name, 'p-admin/plugin-install.php') && !empty($_SERVER['HTTP_REFERER']) && strpos(esc_url_raw($_SERVER['HTTP_REFERER']), '=rvy')) {
				add_action('admin_print_scripts', function(){
					echo '<style type="text/css">#plugin_update_from_iframe {display:none;}</style>';
				});
			}

			return; // no further filtering on WP plugin maintenance scripts
		}

		if (in_array($pagenow, array('post.php', 'post-new.php'))) {
			if (empty($post)) {
				$post = get_post(rvy_detect_post_id());		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}

			if ($post && rvy_is_supported_post_type($post->post_type)) {
				// only apply revisionary UI for currently published or scheduled posts
				if (!rvy_in_revision_workflow($post) && (in_array($post->post_status, rvy_filtered_statuses()) || ('future' == $post->post_status))) {
					require_once( dirname(__FILE__).'/filters-admin-ui-item_rvy.php' );
					new RevisionaryPostEditorMetaboxes();

				} elseif (rvy_in_revision_workflow($post)) {
					add_action('the_post', array($this, 'limitRevisionEditorUI'));

					require_once( dirname(__FILE__).'/edit-revision-ui_rvy.php' );
					new RevisionaryEditRevisionUI();

					if (\PublishPress\Revisions\Utils::isBlockEditorActive($post->post_type)) {
						require_once( dirname(__FILE__).'/edit-revision-block-ui_rvy.php' );
						new RevisionaryEditRevisionBlockUI();
					} else {
						if (!rvy_status_revisions_active($post->post_type)) {
							require_once( dirname(__FILE__).'/edit-revision-classic-ui_rvy.php' );
							new RevisionaryEditRevisionClassicUI();
						}
					}
				}
			}
		}

		if ( ! ( defined( 'SCOPER_VERSION' ) || defined( 'PP_VERSION' ) || defined( 'PPCE_VERSION' ) ) || defined( 'USE_RVY_RIGHTNOW' ) ) {
			add_filter('dashboard_glance_items', [$this, 'fltDashboardGlanceItems']);
		}

		if ( rvy_get_option( 'pending_revisions' ) || rvy_get_option( 'scheduled_revisions' ) ) {
			if ('revision.php' == $pagenow) {
				require_once( dirname(__FILE__).'/history_rvy.php' );
				new RevisionaryHistory();
			}
		}

		if ( rvy_get_option( 'scheduled_revisions' ) ) {
			add_filter('dashboard_recent_posts_query_args', [$this, 'flt_dashboard_recent_posts_query_args']);
		}

		if (!empty($_REQUEST['page']) && ('cms-tpv-page-page' == $_REQUEST['page'])) {						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action('pre_get_posts', [$this, 'cmstpv_compat_get_posts']);
		}

		add_filter('presspermit_status_control_scripts', [$this, 'fltDisableStatusControlScripts']);

		add_filter('cme_plugin_capabilities', [$this, 'fltPublishPressCapsSection']);
		add_filter('cme_capability_descriptions', [$this, 'fltCapDescriptions']);

		add_filter('relevanssi_where', [$this, 'ftlRelevanssiWhere']);

		add_action('init', function() { // late execution avoids clash with autoloaders in other plugins
			global $pagenow;
		
			if (
			($pagenow == 'admin.php') && isset($_GET['page']) 												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& in_array($_GET['page'], ['revisionary-q', 'revisionary-deletion', 'revisionary-settings'])	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				global $wp_version;

				if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && rvy_get_option('scheduled_revisions', -1, false, ['bypass_condition_check' => true]) 
				&& rvy_get_option('scheduled_publish_cron') && !rvy_get_option('wp_cron_usage_detected') && apply_filters('revisionary_wp_cron_disabled', true)
				) {
					rvy_notice(
						sprintf(
							__('Scheduled Revisions are not available because WP-Cron is disabled on this site. See %sRevisions > Settings > Scheduled Revisions%s.', 'revisionary'),
							'<a href="' . admin_url("admin.php?page=revisionary-settings&ppr_tab=scheduled_revisions") . '">',
							'</a>'
						)
					);
				}
			}

			if ((
			($pagenow == 'admin.php') 
			&& isset($_GET['page']) 																		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& in_array($_GET['page'], ['revisionary-q', 'revisionary-settings'])							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| (
				defined('DOING_AJAX') && DOING_AJAX && !empty($_REQUEST['action']) 							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& (false !== strpos(sanitize_key($_REQUEST['action']), 'revisionary'))						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			) 
			&& !defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')
			) {
				if (!class_exists('\PublishPress\WordPressReviews\ReviewsController')) {
					include_once RVY_ABSPATH . '/lib/vendor/publishpress/wordpress-reviews/ReviewsController.php';
				}

				if (class_exists('\PublishPress\WordPressReviews\ReviewsController')) {
					$reviews = new \PublishPress\WordPressReviews\ReviewsController(
						'revisionary',
						'PublishPress Revisions',
						plugin_dir_url(REVISIONARY_FILE) . 'common/img/revisions-wp-logo.jpg'
					);
		
					add_filter('publishpress_wp_reviews_display_banner_revisionary', [$this, 'shouldDisplayBanner']);
		
					$reviews->init();
				}
			}
		});

		// Planner Notifications integration
		add_filter('posts_clauses_request', [$this, 'fltPostsClauses'], 50, 2);
	}

	function fltPostsClauses($clauses, $_wp_query = false, $args = [])
	{
		global $pagenow, $typenow, $wpdb;

		// @todo: move these into Planner
		if (!empty($pagenow) && ('edit.php' == $pagenow) && !empty($typenow) && ('psppnotif_workflow' == $typenow)) {
			if (!rvy_get_option('use_publishpress_notifications')) {
				$clauses['where'] .= " AND $wpdb->posts.post_name NOT IN ('revision-scheduled-publication', 'scheduled-revision-published', 'scheduled-revision-is-published', 'revision-scheduled', 'revision-is-scheduled', 'revision-declined', 'revision-deferred-or-rejected', 'revision-submission', 'revision-is-submitted', 'new-revision', 'new-revision-created')";
			}

			if (!defined('PUBLISHPRESS_STATUSES_VERSION')) {
				$clauses['where'] .= " AND $wpdb->posts.post_name NOT IN ('post-status-changed', 'post-deferred-or-rejected')";
			}
		}

		return $clauses;
	}

	// Prevent Pending, Scheduled Revisions from inclusion in admin search results
	function ftlRelevanssiWhere($where) {
		global $wpdb;

		if ($revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()))) {
			$where .= " AND relevanssi.doc IN (SELECT ID FROM $wpdb->posts WHERE post_mime_type NOT IN ('" . $revision_status_csv . "'))";
		}

		return $where;
	}

	function admin_scripts() {
		global $pagenow;

		if (
			in_array($pagenow, ['post.php', 'post-new.php', 'revision.php'])
			|| (!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options', 'revisionary-q', 'revisionary-deletion', 'revisionary-archive']))  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			wp_enqueue_style('revisionary', RVY_URLPATH . '/admin/revisionary.css', [], PUBLISHPRESS_REVISIONS_VERSION);
			wp_enqueue_style('revisionary-tooltip', RVY_URLPATH . '/common/css/_tooltip.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}

		if (in_array($pagenow, ['post.php', 'post-new.php']) 						
		|| (!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options', 'revisionary-q', 'revisionary-deletion', 'revisionary-archive']))) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_style('revisionary-admin-common', RVY_URLPATH . '/common/css/pressshack-admin.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}
		
		if ((!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options']))) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script('revisionary-settings', RVY_URLPATH . '/admin/settings.js', [], PUBLISHPRESS_REVISIONS_VERSION);
			wp_enqueue_style('revisionary-settings', RVY_URLPATH . '/admin/revisionary-settings.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}
		
		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && ('admin.php' == $pagenow) && !empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options']) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_style('revisionary-settings', plugins_url('', REVISIONARY_PRO_FILE) . '/includes-pro/settings-pro.css', [], PUBLISHPRESS_REVISIONS_VERSION);
		}
 	}

	 function fltAdminBodyClass($classes) {

		if (!empty($_REQUEST['page']) && in_array($_REQUEST['page'], ['revisionary-settings', 'rvy-net_options', 'rvy-default_options', 'revisionary-q', 'revisionary-deletion', 'revisionary-archive'])) {
			$classes .= ' revisionary';
			
			switch ($_REQUEST['page']) {
				case 'revisionary-archive':
					$classes .= ' revisionary-archive';
					break;

				case 'revisionary-settings':
				case 'rvy-net_options':
				case 'rvy-default_options':
					$classes .= ' revisionary-settings';
					break;

				case 'revisionary-q':
					$classes .= ' revisionary-q';
					break;

				case 'revisionary-deletion':
					$classes .= ' revisionary-deletion';
					break;
			}
		}

		return $classes;
	}

	function admin_head() {
		global $pagenow;

		if ( isset($_SERVER['REQUEST_URI']) && (false !== strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'admin.php?page=rvy-revisions' ))) {
			// legacy revision management UI for past revisions
			require_once( dirname(__FILE__).'/revision-ui_rvy.php' );
		}

		if ( ! defined('SCOPER_VERSION') ) {
			// old js for notification recipient selection UI
			wp_enqueue_script( 'rvy', RVY_URLPATH . "/admin/revisionary.js", array('jquery'), PUBLISHPRESS_REVISIONS_VERSION, true );
		}

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (($pagenow == 'admin.php') && isset($_GET['page']) && in_array($_GET['page'], ['revisionary-q', 'revisionary-archive'])) {
			add_screen_option(
				'per_page',
				
				['label' => _x('Revisions', 'groups per page (screen options)', 'revisionary'), 
				'default' => 20, 
				'option' => ('revisionary-archive' == $_GET['page']) ? 'revision_archive_per_page' : 'revisions_per_page'	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				]
			);
		}
	}

	public function shouldDisplayBanner() {
		global $pagenow;

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ($pagenow == 'admin.php') && isset($_GET['page']) && in_array($_GET['page'], ['revisionary-q', 'revisionary-deletion', 'revisionary-settings']);
	}

	function fltDashboardGlanceItems($items) {
		require_once(dirname(__FILE__).'/admin-dashboard_rvy.php');
		RevisionaryDashboard::glancePending();

		return $items;
	}

	function moderation_queue() {
		require_once( dirname(__FILE__).'/revision-queue_rvy.php');
	}

	function revision_archive() {
		require_once( dirname( __FILE__ ).'/revision-archive_rvy.php' );
	}

	function build_menu() {
		global $current_user, $revisionary;

		if ( isset($_SERVER['REQUEST_URI']) && (strpos( esc_url_raw($_SERVER['REQUEST_URI']), 'wp-admin/network/' )) )
			return;

		$path = RVY_ABSPATH;

		// For Revisions Manager access, satisfy WordPress' demand that all admin links be properly defined in menu
		if (isset($_SERVER['REQUEST_URI']) && (false !== strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'admin.php?page=rvy-revisions' )) ) {
			add_submenu_page( 'none', esc_html__('Revisions', 'revisionary'), esc_html__('Revisions', 'revisionary'), 'read', 'rvy-revisions', 'rvy_include_admin_revisions' );
		}

		$types = rvy_get_manageable_types();

		$revision_archive = true || current_user_can('administrator') || current_user_can('restore_revisions') || current_user_can('view_revision_archive');

		$can_edit_any = false;

		if ($types || current_user_can('manage_options')) {
			foreach ($types as $_post_type) {
				if ($type_obj = get_post_type_object($_post_type)) {
					if (!empty($current_user->allcaps[$type_obj->cap->edit_posts]) || (is_multisite() && is_super_admin())) {
						$can_edit_any = true;
						break;
					}
				}
			}
		}

		$can_edit_any = apply_filters('revisionary_add_menu', $can_edit_any);

		$menu_slug = 'revisionary-q';

		if ($revision_archive || $can_edit_any || current_user_can('manage_options')) {
			$_menu_caption = ( defined( 'RVY_MODERATION_MENU_CAPTION' ) ) ? RVY_MODERATION_MENU_CAPTION : esc_html__('Revisions');

			$active_post_types_archive = array_diff_key(
				$enabled_post_types_archive = array_filter($revisionary->enabled_post_types_archive),
				$revisionary->getHiddenPostTypesArchive()
			);

			if ($can_edit_any) {
				$menu_func = [$this, 'moderation_queue'];
			} elseif ($revision_archive && $enabled_post_types_archive) {
				$menu_slug = 'revisionary-archive';
				$menu_func = [$this, 'revision_archive'];
			} else {
				$menu_slug = 'revisionary-settings';
				$menu_func = 'rvy_omit_site_options';
			}

			add_menu_page( esc_html__($_menu_caption, 'pp'), esc_html__($_menu_caption, 'pp'), 'read', $menu_slug, $menu_func, 'dashicons-backup', 29 );

			if ($can_edit_any && array_filter($revisionary->enabled_post_types)) {
				add_submenu_page('revisionary-q', esc_html__('Revision Queue', 'revisionary'), esc_html__('Revision Queue', 'revisionary'), 'read', 'revisionary-q', [$this, 'moderation_queue']);
			}

			do_action('revisionary_admin_menu');

			if ($revision_archive && $active_post_types_archive) {
				// Revision Archive page
				add_submenu_page(
					$menu_slug,
					esc_html__( 'Revision Archive', 'revisionary' ),
					esc_html__( 'Revision Archive', 'revisionary' ),
					'read',
					'revisionary-archive',
					[$this, 'revision_archive']
				);
			}
		}

		if ( ! current_user_can( 'manage_options' ) )
			return;

		global $rvy_default_options, $rvy_options_sitewide;

		if ( empty($rvy_default_options) )
			rvy_refresh_default_options();

		if ( ! RVY_NETWORK || ( count($rvy_options_sitewide) != count($rvy_default_options) ) ) {
			add_submenu_page( $menu_slug, esc_html__('PublishPress Revisions Settings', 'revisionary'), esc_html__('Settings', 'revisionary'), 'read', 'revisionary-settings', 'rvy_omit_site_options');
			add_action('revisionary_page_revisionary-settings', 'rvy_omit_site_options' );
		}

		if (!defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
			add_submenu_page(
	            $menu_slug,
	            esc_html__('Upgrade to Pro', 'revisionary'),
	            esc_html__('Upgrade to Pro', 'revisionary'),
	            'read',
	            'revisionary',
	            'rvy_omit_site_options'
	        );
    	}
	}

	function limitRevisionEditorUI() {
		global $post;

		remove_post_type_support($post->post_type, 'author');
		remove_post_type_support($post->post_type, 'custom-fields'); // todo: filter post_id in query
	}

	function flt_dashboard_recent_posts_query_args($query_args) {
		if ('future' == $query_args['post_status']) {
			global $revisionary;
			$revisionary->is_revisions_query = true;

			require_once(dirname(__FILE__).'/admin-dashboard_rvy.php');
			$rvy_dash = new RevisionaryDashboard();
			$query_args = $rvy_dash->recentPostsQueryArgs($query_args);
		}

		return $query_args;
	}

	// adds a Settings link next to Deactivate, Edit in Plugins listing
	function flt_plugin_action_links($links, $file) {
		if (defined('REVISIONARY_FILE') && ($file == plugin_basename(REVISIONARY_FILE))
		|| defined('REVISIONARY_PRO_FILE') && ($file == plugin_basename(REVISIONARY_PRO_FILE))
		) {
			$links[] = '<a href="'. esc_url(admin_url('admin.php?page=revisionary-q')) .'">' . esc_html__('Revision Queue', 'revisionary') . '</a>';
			$links[] = '<a href="'. esc_url(admin_url('admin.php?page=revisionary-archive')) .'">' . esc_html__('Revision Archive', 'revisionary') . '</a>';

			$page = ( defined('RVY_NETWORK') && RVY_NETWORK ) ? 'rvy-net_options' : 'revisionary-settings';
			$links[] = '<a href="'. esc_url(admin_url("admin.php?page=$page")) .'">' . esc_html__('Settings', 'revisionary') . '</a>';

			if (!defined('REVISIONARY_PRO_FILE')) {
				$links[] = '<a href="'. esc_url('https://publishpress.com/links/revisions-plugin-row') .'" class="pp-upgrade">' . esc_html__('Upgrade to Pro', 'revisionary') . '</a>';
			}
		}

		return $links;
	}

	public function fltPublishPressCapsSection($section_caps) {
		$section_caps['PublishPress Revisions'] = ['edit_others_drafts', 'edit_others_revisions', 'list_others_revisions', 'manage_unsubmitted_revisions', 'preview_others_revisions', 'restore_revisions', 'view_revision_archive'];

		// @todo: check Revisions settings for other cap requirements

		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && rvy_get_option('revision_restore_require_cap')) {
			$section_caps['PublishPress Revisions'] []= 'restore_revisions';
		}

		if (!rvy_get_option('manage_unsubmitted_capability')) {
			$section_caps['PublishPress Revisions'] = array_diff($section_caps['PublishPress Revisions'], ['manage_unsubmitted_revisions']);
		}

		if (!rvy_get_option('require_edit_others_drafts')) {
			$section_caps['PublishPress Revisions'] = array_diff($section_caps['PublishPress Revisions'], ['edit_others_drafts']);
		}

		if (!rvy_get_option('revisor_hide_others_revisions')) {
			$section_caps['PublishPress Revisions'] = array_diff($section_caps['PublishPress Revisions'], ['list_others_revisions']);
		}

		if (!rvy_get_option('revisor_lock_others_revisions')) {
			$section_caps['PublishPress Revisions'] = array_diff($section_caps['PublishPress Revisions'], ['edit_others_revisions']);
		}

		return $section_caps;
	}

	public function fltCapDescriptions($cap_descripts)
	{
		$cap_descripts['edit_others_drafts'] = esc_html__('Can edit draft Posts from other users.', 'revisionary');
		$cap_descripts['edit_others_revisions'] = esc_html__('Can edit Revisions from other users.', 'revisionary');
		$cap_descripts['list_others_revisions'] = esc_html__('Can see Revisions from other users in Revision Queue.', 'revisionary');
		$cap_descripts['manage_unsubmitted_revisions'] = esc_html__('Can manage Unsubmitted Revisions.', 'revisionary');
		$cap_descripts['preview_others_revisions'] = esc_html__('Preview other user\'s Revisions (without needing editing access).', 'revisionary');
		$cap_descripts['restore_revisions'] = esc_html__('Restore an archived Revision as the current revision.', 'revisionary');
		$cap_descripts['view_revision_archive'] = esc_html__('View the Revision Archive, a list of past Revisions.', 'revisionary');

		return $cap_descripts;
	}

	public function fltDisableStatusControlScripts($enable_scripts) {
		if ($post_id = rvy_detect_post_id()) {
			if ($post = get_post($post_id)) {
				if (!empty($post) && rvy_in_revision_workflow($post)) {
					$enable_scripts = false;
				}
			}
		}

		return $enable_scripts;
	}

	// Prevent PublishPress Revisions statuses from confusing the CMS Tree Page View plugin page listing
	public function cmstpv_compat_get_posts($wp_query) {
		$wp_query->query['post_mime_type'] = '';
		$wp_query->query_vars['post_mime_type'] = '';
	}

	public function publishpressFooter() {
		?>
		<footer>

		<div class="pp-rating">
		<a href="https://wordpress.org/support/plugin/revisionary/reviews/#new-post" target="_blank" rel="noopener noreferrer">
		<?php printf(
			esc_html__('If you like %s, please leave us a %s rating. Thank you!', 'revisionary'),
			'<strong>PublishPress Revisions</strong>',
			'<span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span>'
			);
		?>
		</a>
		</div>

		<hr>
		<nav>
		<ul>
		<li><a href="https://publishpress.com/revisionary" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('About PublishPress Revisions', 'revisionary');?>"><?php esc_html_e('About', 'revisionary');?>
		</a></li>
		<li><a href="https://publishpress.com/documentation/revisions-start" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('PublishPress Revisions Documentation', 'revisionary');?>"><?php esc_html_e('Documentation', 'revisionary');?>
		</a></li>
		<li><a href="https://publishpress.com/contact" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr('Contact the PublishPress team', 'revisionary');?>"><?php esc_html_e('Contact', 'revisionary');?>
		</a></li>
		</ul>
		</nav>

		<div class="pp-pressshack-logo">
		<a href="https://publishpress.com" target="_blank" rel="noopener noreferrer">

		<img src="<?php echo esc_url(plugins_url('', REVISIONARY_FILE) . '/common/img/publishpress-logo.png');?>" />
		</a>
		</div>

		</footer>
		<?php
	}

	function hideAdminMenuToolbar() {
        $current_screen = get_current_screen();
        if ( $current_screen->id === 'revision' && isset( $_GET['rvy-popup'] ) && $_GET['rvy-popup'] === 'true' ) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <style type="text/css">
            #wpadminbar,
            #adminmenumain,
            #screen-meta-links,
            #wpfooter,
            .wrap > .long-header,
            .wrap > a,
            input.restore-revision,
            .revisions-controls .revisions-checkbox {
                display: none !important;
            }
            #wpbody {
                padding-top: 0 !important;
            }
            #wpcontent {
                margin-left: 0 !important;
            }
            #wpbody-content {
                padding-bottom: 30px !important;
            }
            </style>
            <?php
        }
    }

	function tooltipText($display_text, $tip_text, $use_icon = false) {
		$icon = '';
		
		if ($use_icon) :
			ob_start();
		?>
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 50 50" style="margin-left: 3px; vertical-align: baseline;">
				<path d="M 25 2 C 12.264481 2 2 12.264481 2 25 C 2 37.735519 12.264481 48 25 48 C 37.735519 48 48 37.735519 48 25 C 48 12.264481 37.735519 2 25 2 z M 25 4 C 36.664481 4 46 13.335519 46 25 C 46 36.664481 36.664481 46 25 46 C 13.335519 46 4 36.664481 4 25 C 4 13.335519 13.335519 4 25 4 z M 25 11 A 3 3 0 0 0 25 17 A 3 3 0 0 0 25 11 z M 21 21 L 21 23 L 23 23 L 23 36 L 21 36 L 21 38 L 29 38 L 29 36 L 27 36 L 27 21 L 21 21 z"></path>
			</svg>
		<?php 
			$icon = ob_get_clean();
		endif;
		
		return '<span data-toggle="tooltip" data-placement="top"><span class="tooltip-text"><span>' 
		. $tip_text
		. '</span><i></i></span>'
		. $display_text
		. $icon
		. '</span>';
	}
} // end class RevisionaryAdmin
