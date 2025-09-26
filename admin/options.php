<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die( 'This page cannot be called directly.' );

do_action('revisionary_load_options_ui');

class RvyOptionUI {
	private static $instance = null;

	private const UPGRADE_PRO_URL = 'https://publishpress.com/revisions/';

	private $sitewide;
	private $customize_defaults;
	var $form_options;
	private $tab_captions;
	var $section_captions;
	var $option_captions;
	private $all_options;
	private $all_otype_options;
	private $def_otype_options;
	private $display_hints = true;

	var $defined_integrations = [];
	var $available_integrations = [];

	var $section_name = '';
	var $subsection_name = '';
	var $section_options = false;
	var $subsection_options = false;

	public static function instance($args = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new RvyOptionUI($args);
        }

        return self::$instance;
	}

    private function __construct($args = [])
    {
		$defaults = ['sitewide' => false, 'customize_defaults' => false];
		$args = array_merge($defaults, (array)$args);

		$this->sitewide = $args['sitewide'];
		$this->customize_defaults = $args['customize_defaults'];
		$this->display_hints = rvy_get_option( 'display_hints' );

		$this->loadIntegrations();
    }

	function setSection($section_name) {
		$this->section_name = $section_name;
		$this->section_options = false;
	}

	function setSubsection($subsection_name) {
		$this->subsection_name = $subsection_name;
		$this->subsection_options = false;
	}

	function option_checkbox( $option_name, $tab_name, $section_name, $hint_text, $unused_arg = '', $args = '') {
		$return = array( 'in_scope' => false, 'val' => '', 'subcaption' => '', 'style' => '', 'hide' => false, 'no_escape' => false, 'disabled' => false );

		if ( ! is_array($args) )
			$args = array();

		if (!empty($this->form_options[$tab_name][$section_name]) && in_array($option_name, $this->form_options[$tab_name][$section_name])) {
			$this->all_options []= $option_name;

			if (!isset($args['val'])) {
				$return['val'] = rvy_get_option($option_name, $this->sitewide, $this->customize_defaults, ['bypass_condition_check' => true]);
			}

			echo "<div class='agp-vspaced_input'";

			if (!empty($args['style'])) {
				echo " style='" . esc_attr($args['style']) . "'";
			} elseif (!empty($args['hide'])) {
				echo " style='display:none'";
			}
			
			echo ">";

			echo "<label for='" . esc_attr($option_name) . "'><input name='" . esc_attr($option_name) . "' type='checkbox' id='" . esc_attr($option_name) . "' value='1' " . checked('1', $return['val'], false) . disabled('1', !empty($args['disabled']), false) . " autocomplete='off' " . " /> ";

			if (!empty($args['no_escape'])) {
				echo $this->option_captions[$option_name];	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo esc_html($this->option_captions[$option_name]);
			}

			echo "</label>";

			if ( $hint_text && $this->display_hints ) {
				echo "<div class='rvy-subtext'>";
				
				if (!empty($args['no_escape'])) {
					echo $hint_text;
				} else {
					echo esc_html($hint_text);
				}
				
				echo "</div>";
			}

			if ( ! empty($args['subcaption']) )
				echo $args['subcaption'];		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			echo "</div>";

			$return['in_scope'] = true;
			$this->section_options = true;
			$this->subsection_options = true;
		}

		return $return;
	}

	function closeSubsection() {
		if (!$this->subsection_options) :?>
			<h4>
			<?php esc_html_e('All settings on this tab are assigned network-wide.', 'revisionary'); ?>
			</h4>
		<?php endif;
	}

	function register_option($option_name) {
		if (!in_array($option_name, $this->all_options)) {
			$this->all_options []= $option_name;
		}
	}

function options_ui( $sitewide = false, $customize_defaults = false ) {

global $revisionary;
global $rvy_options_sitewide, $rvy_default_options;

if ( ! current_user_can( 'manage_options' ) || ( $sitewide && ! is_super_admin() ) )
	wp_die('');

?>
<div class="wrap pressshack-admin-wrapper">
<?php

if ( $sitewide )
	$customize_defaults = false;	// this is intended only for storing custom default values for site-specific options

rvy_refresh_default_options();

$this->all_options = array();

$this->tab_captions = array( 'features' => esc_html__( 'Settings', 'revisionary' ), 'optscope' => esc_html__( 'Setting Scope', 'revisionary' ) );

$this->section_captions = array(
	'features' => array(
		'post_types'			=> esc_html__('Features', 'revisionary'),
		'archive'				=> esc_html__('Past Revisions', 'revisionary'),
		'working_copy'			=> esc_html__('New Revisions', 'revisionary'),
		'revisions'				=> esc_html__('Options', 'revisionary'),
		'integrations'			=> esc_html__('Integrations', 'revisionary'),
		'statuses'				=> esc_html__('Statuses', 'revisionary'),
		'notifications'			=> esc_html__('Notifications', 'revisionary'),
	)
);

// TODO: replace individual _e calls with these (and section, tab captions)
$pending_revision_singular = pp_revisions_status_label('pending-revision', 'name');
$pending_revision_plural = rvy_get_option('revision_statuses_noun_labels') ? pp_revisions_status_label('pending-revision', 'plural') : esc_html__('Revision Submission', 'revisionary');
$pending_revision_basic = pp_revisions_status_label('pending-revision', 'basic');
$future_revision_singular = pp_revisions_status_label('future-revision', 'name');

$pp_notif_url = admin_url('edit.php?post_type=psppnotif_workflow');

$this->option_captions = apply_filters('revisionary_option_captions',
	[
	'revision_statuses_noun_labels' =>			esc_html__('Use alternate labeling: "Working Copy" > "Change Request" > "Scheduled Change"', 'revisionary'),
	'revision_queue_capability' =>				esc_html__("Revision Queue access requires role capability", 'revisionary'),
	'manage_unsubmitted_capability' =>			sprintf(esc_html__('Managing %s requires role capability', 'revisionary'), pp_revisions_status_label('draft-revision', 'plural')),
	'copy_posts_capability' =>					rvy_get_option('revision_statuses_noun_labels') ? esc_html__("Working Copy creation requires role capability", 'revisionary') : esc_html__("Revision creation requires role capability", 'revisionary'),
	'caption_copy_as_edit' =>					sprintf(esc_html__('Posts / Pages list: Use "Edit" caption for %s link', 'revisionary'), pp_revisions_status_label('draft-revision', 'submit_short')),
	'pending_revisions' => 						sprintf(esc_html__('Enable %s', 'revisionary'), $pending_revision_plural),
	'revision_limit_per_post' =>				esc_html__("Limit to one active revision per post", 'revisionary'),
	'revision_limit_compat_mode' =>				esc_html__('Refresh "Has Revision" flag before suppressing New Revision', 'revisionary'),
	'revision_unfiltered_html_check' =>			esc_html__("If post contains custom html, require unfiltered_html capability", 'revisionary'),
	'auto_submit_revisions' =>					esc_html__("Auto-submit revisions created by a user with publishing capability", 'revisionary'),
	'scheduled_revisions' => 					sprintf(esc_html__('Enable %s', 'revisionary'), pp_revisions_status_label('future-revision', 'plural')),
	'revise_posts_capability' =>				rvy_get_option('revision_statuses_noun_labels') ? esc_html__("Change Request submission require role capability", 'revisionary') : esc_html__("Revision submission requires role capability", 'revisionary'),
	'revisor_lock_others_revisions' =>			esc_html__("Editing others' Revisions requires role capability", 'revisionary'),
	'revisor_hide_others_revisions' => 			esc_html__("Listing others' Revisions requires role capability", 'revisionary'),
	'admin_revisions_to_own_posts' =>			esc_html__("Users can always administer revisions to their own editable posts", 'revisionary'),
	'revision_update_notifications' =>			esc_html__('Also notify on Revision Update', 'revisionary'),
	'trigger_post_update_actions' => 			esc_html__('Apply API actions to mimic Post Update', 'revisionary'),
	'diff_display_strip_tags' => 				esc_html__('Hide html tags on Compare screen', 'revisionary'),
	'scheduled_publish_cron' =>					esc_html__('Use WP-Cron scheduling', 'revisionary'),
	'wp_cron_usage_detected' =>					esc_html__('Site uses a custom trigger for WP-Cron tasks', 'revisionary'),
	'async_scheduled_publish' => 				esc_html__('Asynchronous Publishing', 'revisionary'),
	'scheduled_revision_update_post_date' => 	esc_html__('Update Publish Date', 'revisionary'),
	'pending_revision_update_post_date' => 		esc_html__('Update Publish Date', 'revisionary'),
	'scheduled_revision_update_modified_date' => esc_html__('Update Modified Date', 'revisionary'),
	'pending_revision_update_modified_date' => 	esc_html__('Update Modified Date', 'revisionary'),

	'use_publishpress_notifications' =>			(defined('PUBLISHPRESS_VERSION'))
												? sprintf(__('Use %1$sPlanner Notifications%2$s', 'revisionary'), "<strong><a class='planner-notif-link' href='$pp_notif_url'>", '</a></strong>')
												: sprintf(__('Use %1$sPlanner Notifications%2$s', 'revisionary'), '', ''),
	
	'pending_rev_notify_author' => 				sprintf(esc_html__('Email original Author when a %s is submitted', 'revisionary'), $pending_revision_basic),
	'rev_approval_notify_author' => 			sprintf(esc_html__('Email the original Author when a %s is approved', 'revisionary'), $pending_revision_singular),
	'rev_approval_notify_revisor' => 			sprintf(esc_html__('Email the Revisor when a %s is approved', 'revisionary'), $pending_revision_singular),
	'publish_scheduled_notify_author' => 		sprintf(esc_html__('Email the original Author when a %s is published', 'revisionary'), $future_revision_singular),
	'publish_scheduled_notify_revisor' => 		sprintf(esc_html__('Email the Revisor when a %s is published', 'revisionary'), $future_revision_singular),
	'use_notification_buffer' => 				esc_html__('Enable notification buffer', 'revisionary'),
	'revisor_role_add_custom_rolecaps' => 		esc_html__('Revisors can create a new revision for any custom post type', 'revisionary' ),
	'require_edit_others_drafts' => 			esc_html__("Prevent Revisors from editing others' unpublished Posts", 'revisionary' ),
	'display_hints' => 							esc_html__('Display Hints', 'revisionary'),
	'delete_settings_on_uninstall' => 			esc_html__('Delete settings and Revisions if plugin is deleted', 'revisionary'),
	'revision_preview_links' => 				esc_html__('Show Preview Links', 'revisionary'),
	'preview_link_type' => 						esc_html__('Preview Link Type', 'revisionary'),
	'preview_link_alternate_preview_arg' =>		esc_html__('Modify preview link for better theme compatibility', 'revisionary'),
	'home_preview_set_home_flag' =>				esc_html__('Theme Compat: For front page revision preview, set home flag', 'revisionary'),
	'compare_revisions_direct_approval' => 		esc_html__('Approve Button on Compare screen', 'revisionary'),
	'copy_revision_comments_to_post' => 		esc_html__('Copy revision comments to published post', 'revisionary'),
	'past_revisions_order_by' =>				esc_html__('Compare Past Revisions ordering:', 'revisionary'), 
	'list_unsubmitted_revisions' => 			sprintf(esc_html__('List %s in Revision Queue for "My Activity" or "Revisions to My Posts" view', 'revisionary'), pp_revisions_status_label('draft-revision', 'plural')),
	'archive_postmeta' =>						esc_html__('Store custom fields of submitted and scheduled revisions for archive', 'revisionary'),
	'extended_archive' =>						esc_html__('Keep an archive of revision edits, even after the revision is published', 'revisionary'),
	'rev_publication_delete_ed_comments' =>		esc_html__('On Revision publication, delete Editorial Comments', 'revisionary'),
	'deletion_queue' => 						esc_html__('Enable deletion queue', 'revisionary'),
	'revision_archive_deletion' => 				esc_html__('Allow Past Revisions to be deleted', 'revisionary'),
	'revision_restore_require_cap' =>			esc_html__('Restoring a Revision requires role capability', 'revisionary'),
	'permissions_compat_mode' => 				esc_html__('Compatibility Mode', 'revisionary'),
	'planner_notifications_access_limited' =>	esc_html__('Planner Notifications Access-Limited', 'revisionary'),
	'num_revisions' =>							esc_html__('Maximum Revisions per post', 'revisionary'),
	'apply_post_exceptions' =>					esc_html__('Apply Post Permissions to Revisions', 'revisionary'),
	'legacy_notifications' =>					esc_html__('Enable legacy email notifications', 'revisionary'),
	'approve_button_verbose' =>					esc_html__('Use extended captions for Approve button in Post Editor', 'revisionary'),
	'create_revision_direct_link' =>			esc_html__('Create Revision button in editor opens new tab', 'revisionary'),
	'revision_edit_disable_rank_math' => 		esc_html__('Disable Rank Math SEO panel for Revision edit', 'revisionary'),
	]
);

if ( defined('RVY_CONTENT_ROLES') ) {
	$this->option_captions['pending_rev_notify_admin'] = 		sprintf(esc_html__('Email designated Publishers when a %s is submitted', 'revisionary'), $pending_revision_basic);
	$this->option_captions['publish_scheduled_notify_admin'] = 	sprintf(esc_html__('Email designated Publishers when a %s is published', 'revisionary'), $future_revision_singular);
	$this->option_captions['rev_approval_notify_admin'] = 		sprintf(esc_html__('Email designated Publishers when a %s is approved', 'revisionary'), $pending_revision_singular);
} else {
	$this->option_captions['pending_rev_notify_admin'] = 		sprintf(esc_html__('Email Editors and Administrators when a %s is submitted', 'revisionary'), $pending_revision_basic);
	$this->option_captions['publish_scheduled_notify_admin'] = 	sprintf(esc_html__('Email Editors and Administrators when a %s is published', 'revisionary'), $future_revision_singular);
	$this->option_captions['rev_approval_notify_admin'] = 		sprintf(esc_html__('Email Editors and Administrators when a %s is approved', 'revisionary'), $pending_revision_singular);
}


$this->form_options = apply_filters('revisionary_option_sections', [
'features' => [
	'post_types' =>			 ['enabled_post_types', 'enabled_post_types_archive'],
	'statuses' => 			 [true],
	'archive' =>			 ['num_revisions', 'archive_postmeta', 'extended_archive', 'revision_archive_deletion', 'revision_restore_require_cap', 'past_revisions_order_by'],
	'working_copy' =>		 ['copy_posts_capability', 'revisor_role_add_custom_rolecaps', 'revision_limit_per_post', 'revision_limit_compat_mode', 'create_revision_direct_link', 'revision_unfiltered_html_check', 'auto_submit_revisions', 'caption_copy_as_edit', 'permissions_compat_mode', 'pending_revisions', 'revise_posts_capability', 'pending_revision_update_post_date', 'pending_revision_update_modified_date', 'scheduled_revisions', 'scheduled_publish_cron', 'async_scheduled_publish', 'wp_cron_usage_detected', 'scheduled_revision_update_post_date', 'scheduled_revision_update_modified_date', 'approve_button_verbose', 'trigger_post_update_actions', 'copy_revision_comments_to_post', 'rev_publication_delete_ed_comments', 'revision_statuses_noun_labels', 'revision_queue_capability', 'manage_unsubmitted_capability', 'revisor_lock_others_revisions', 'revisor_hide_others_revisions', 'admin_revisions_to_own_posts', 'list_unsubmitted_revisions', 'deletion_queue', 'compare_revisions_direct_approval', 'use_publishpress_notifications', 'planner_notifications_access_limited', 'legacy_notifications', 'pending_rev_notify_admin', 'pending_rev_notify_author', 'revision_update_notifications', 'rev_approval_notify_admin', 'rev_approval_notify_author', 'rev_approval_notify_revisor', 'publish_scheduled_notify_admin', 'publish_scheduled_notify_author', 'publish_scheduled_notify_revisor', 'use_notification_buffer'],
	'notifications' =>		 [true],
	'integrations' =>		 [true],
	'revisions'		=>		 ['revision_preview_links', 'preview_link_type', 'preview_link_alternate_preview_arg', 'home_preview_set_home_flag', 'require_edit_others_drafts', 'apply_post_exceptions', 'diff_display_strip_tags', 'revision_edit_disable_rank_math', 'display_hints', 'delete_settings_on_uninstall'],
	'license' =>			 ['edd_key'],
]
]);

if (defined('PUBLISHPRESS_STATUSES_PRO_VERSION') || $sitewide || $customize_defaults) {
	unset($this->section_captions['features']['statuses']);
	unset($this->form_options['features']['statuses']);
}

if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') || $sitewide || $customize_defaults) {
	unset($this->section_captions['features']['notifications']);
	unset($this->form_options['features']['notifications']);
}

if ($sitewide || $customize_defaults) {
	unset($this->section_captions['features']['integrations']);
	unset($this->form_options['features']['integrations']);
}

if ( RVY_NETWORK ) {
	if ( $sitewide )
		$available_form_options = $this->form_options;

	foreach ( $this->form_options as $tab_name => $sections ) {
		foreach ( $sections as $section_name => $option_names ) {
			if ($sitewide) {
				$this->form_options[$tab_name][$section_name] = array_intersect( $this->form_options[$tab_name][$section_name], array_keys($rvy_options_sitewide) );
			} elseif ('license' != $section_name) {
				$this->form_options[$tab_name][$section_name] = array_diff( $this->form_options[$tab_name][$section_name], array_keys($rvy_options_sitewide) );
			}
		}
	}

	foreach ( $this->form_options as $tab_name => $sections )
		foreach ( array_keys($sections) as $section_name )
			if ( empty( $this->form_options[$tab_name][$section_name] ) )
				unset( $this->form_options[$tab_name][$section_name] );

	if (!$sitewide) {
		unset($this->form_options['features']['license']);
	}
}

do_action('revisionary_settings_ui', $this, $sitewide, $customize_defaults);
?>
<header>

<?php
echo '<form action="" method="post" autocomplete="off">';
wp_nonce_field( 'rvy-update-options' );

if ( $sitewide )
	echo "<input type='hidden' name='rvy_options_doing_sitewide' value='1' />";

if ( $customize_defaults )
	echo "<input type='hidden' name='rvy_options_customize_defaults' value='1' />";

?>
<table><tr>
<td>
<h1 class="wp-heading-inline"><?php
if ( $sitewide )
	esc_html_e('PublishPress Revisions Network Settings', 'revisionary');
elseif ( $customize_defaults )
	esc_html_e('PublishPress Revisions Network Defaults', 'revisionary');
elseif ( RVY_NETWORK )
	esc_html_e('PublishPress Revisions Site Settings', 'revisionary');
else
	esc_html_e('PublishPress Revisions Settings', 'revisionary');
?>
</h1>
</td>
<td>
</td>
</tr></table>

</header>

<?php
$div_class = apply_filters('publishpress_revisions_settings_sidebar_class', '');
?>

<div id="poststuff">

	<div id="post-body" class="metabox-holder <?php echo esc_attr($div_class);?>">	

	<div class="pp-group-wrapper" style="display:flex;width: 100%;flex-wrap:wrap;">
	<div id="post-body-content" style="flex-basis: <?php if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) echo '100%'; else echo 'calc(99% - 270px)';?>" class="ppseries-settings-body-content">

<?php
if ( $sitewide ) {
	$color_class = 'rs-backgray';

} elseif ( $customize_defaults ) {
	$color_class = 'rs-backgreen';
	echo '<p style="margin-top:0">';
	esc_html_e( 'These are the default settings for options which can be adjusted per-site.', 'revisionary' );
	echo '</p>';

} else
	$color_class = 'rs-settings';

if ( $sitewide || $customize_defaults ) {
	$class_selected = "agp-selected_agent agp-agent $color_class";
	$class_unselected = "agp-unselected_agent agp-agent";

	// todo: prevent line breaks in these links
	echo "<ul class='rs-list_horiz' style='margin-bottom:-0.1em'>"
		. "<li class='" . esc_attr($class_selected) . "'>"
		. "<a id='rvy_show_features' href='javascript:void(0)' onclick=\""
		. "agp_swap_display('rvy-features', 'rvy-optscope', 'rvy_show_features', 'rvy_show_optscope', '" . esc_attr($class_selected) . "', '" . esc_attr($class_unselected) . "');"
		. "\">" . esc_html($this->tab_captions['features']) . '</a>'
		. '</li>';

	if ( $sitewide ) {
		echo "<li class='" . esc_attr($class_unselected) . "'>"
			. "<a id='rvy_show_optscope' href='javascript:void(0)' onclick=\""
			. "agp_swap_display('rvy-optscope', 'rvy-features', 'rvy_show_optscope', 'rvy_show_features', '" . esc_attr($class_selected) . "', '" . esc_attr($class_unselected) . "');"
			. "\">" . esc_html($this->tab_captions['optscope']) . '</a>'
			. '</li>';
	}

	echo '</ul>';
}

// ------------------------- BEGIN Features tab ---------------------------------

$tab = 'features';
echo "<div id='rvy-features' style='clear:both;margin:0' class='" . esc_attr($color_class) . "'>";

if ( rvy_get_option('display_hints', $sitewide, $customize_defaults) ) {
	echo '<div class="rs-optionhint publishpress-headline"><span>';

	if ( $sitewide ) {
		printf( esc_html__('Use this tab to make NETWORK-WIDE changes to PublishPress Revisions settings. %s', 'revisionary'), '' );
		
		if ( count( $rvy_options_sitewide ) < count( $rvy_default_options ) ) {
			printf( esc_html__( 'You can also specify %1$sdefaults for site-specific settings%2$s.', 'revisionary' ), '<a href="admin.php?page=rvy-default_options">', '</a>' );
		}
	} elseif ( $customize_defaults ) {
		esc_html_e('Here you can change the default value for settings which are controlled separately on each site.', 'revisionary');
	}

	if ( RVY_NETWORK && is_super_admin() ) {
		if ( ! $sitewide ) {
			global $blog_id;

			echo ' ';

			if ( is_main_site($blog_id) ) {
				printf( esc_html__('Note that %1$s network-wide settings%2$s may also be available.', 'revisionary'), "<a href='admin.php?page=rvy-net_options'>", '</a>');
			} else {
				printf( esc_html__('Note that %1$s network-wide settings%2$s may also be available.', 'revisionary'), '', '' );
			}
		}
	}
	?>
	</span>

	</div>
	<?php
}

if (empty(array_filter($revisionary->enabled_post_types_archive))) {
	unset($this->section_captions['features']['archive']);
}

if (empty(array_filter($revisionary->enabled_post_types))) {
	unset($this->section_captions['features']['working_copy']);
}

/*
if (empty(array_filter($revisionary->enabled_post_types)) && empty(array_filter($revisionary->enabled_post_types_archive))) {
	unset($this->section_captions['features']['preview']);
	unset($this->section_captions['features']['compare']);
}
*/
?>

<ul id="publishpress-revisions-settings-tabs" class="nav-tab-wrapper">
	<?php
	if (!empty($_REQUEST['ppr_tab'])) {															//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$setActiveTab = str_replace('ppr-tab-', '', sanitize_key($_REQUEST['ppr_tab']));		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} else {
		// Set first tab and content as active
		$setActiveTab = '';
	}

	if (empty($setActiveTab)) {
		$setActiveTab = 'post_types';
	}

	foreach($this->section_captions['features'] as $section_name => $label) {
		if (!empty($this->form_options[$tab][$section_name])) {
		?>
		<li class="nav-tab<?php echo (empty($setActiveTab) || ($setActiveTab == $section_name)) ? ' nav-tab-active' : '' ?>">
			<a href="#ppr-tab-<?php echo esc_attr($section_name) ?>">
				<?php echo esc_html($label) ?>
			</a>

			<?php
			if (in_array($section_name, ['statuses', 'notifications'])) {
				$badge =
				[
					'text' => 'PRO',
					'bg_color' => '#8B5CF6',
					'class' => 'pp-pro-badge'
				];
				$badge_text = isset($badge['text']) ? esc_html($badge['text']) : 'PRO';
				$badge_color = isset($badge['color']) ? esc_attr($badge['color']) : '#8B5CF6';
				$badge_bg_color = isset($badge['bg_color']) ? esc_attr($badge['bg_color']) : '#8B5CF6';
				$badge_class = isset($badge['class']) ? esc_attr($badge['class']) : '';
				
				printf(
					'<span class="pp-tab-badge %s" style="background: %s; color: white; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 10px; margin-left: 0; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.2);">%s</span>',
					$badge_class,
					$badge_bg_color,
					$badge_text
				);
			}
			?>

			<?php if (('integrations' == $section_name) && !empty($this->available_integrations)) :?>
				<span class="pp-integrations <?php echo (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) ? 'pp-integrations-active' : 'pp-integrations-missing';?> count-<?php echo intval(count($this->available_integrations));?>"><span class="plugin-count"><?php echo intval(count($this->available_integrations));?></span></span>
			<?php endif;?>
		</li>
		<?php
			if (empty($setActiveTab)) {
				$setActiveTab = $section_name;
			}
		}
	}

	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !empty($this->form_options['features']['license'])) {
		?>
		<li class="nav-tab nav-tab-license <?php if ($setActiveTab == 'license') echo 'nav-tab-active';?>">
			<a href="#ppr-tab-license">
				<?php esc_html_e('License', 'revisionary') ?>
			</a>
		</li>
		<?php
	}
	?>
</ul>

<div>

<?php
// possible TODO: replace redundant hardcoded IDs with $id

	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !empty($this->form_options['features']['license'])) {
		require_once(REVISIONARY_PRO_ABSPATH . '/includes-pro/SettingsLicense.php');
		$license_ui = new RevisionaryLicenseSettings();
		?>
		<table class="form-table rs-form-table" id="ppr-tab-license"<?php echo ($setActiveTab != 'license') ? ' style="display:none;"' : '' ?>>
			<?php $license_ui->display($sitewide, $customize_defaults); ?>
		</table>
		<?php
	}


	$section = 'post_types';				// --- POST TYPES SECTION ---

	if ( ! empty( $this->form_options[$tab][$section] ) ) :?>
		<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

		<table id="rvy_post_types_frame">
		<tr>

		<?php
        $option_name = 'enabled_post_types_archive';

		$this->register_option($option_name);

		?>
		<td style="padding-right: 100px">
		<h3 style="margin-top:0; margin-bottom:8px"><?php esc_html_e('Past Revisions', 'revisionary');?>
        <?php 
		echo $revisionary->admin->tooltipText(
			'',
			__('Past Revisions are earlier versions of a post. They are listed in the Revision Archive.', 'revisionary'),
			true
		);
		?>
        </h3>
		<?php
		$locked_types = [];
		$no_revision_types = [];

		$types = get_post_types(['public' => true, 'show_ui' => true], 'object', 'or');
		$type_names = get_post_types(['public' => true, 'show_ui' => true], 'name', 'or');

		$_ordered_types = rvy_order_types($type_names);

		$ordered_types['page'] = $types['page'];
		$ordered_types['post'] = $types['post'];
		
		$ordered_types = array_merge(
			$ordered_types,
			array_diff_key(
				$_ordered_types,
				array_fill_keys(['page', 'post'], true)
			)
			);

		foreach (array_keys($ordered_types) as $key) {
			if (!$key) {
				continue;
			}

			if (!isset($types[$key])) {
				continue;
			}

			$obj = $types[$key];

			if (!post_type_supports($key, 'revisions')) {
			    unset($revisionary->enabled_post_types_archive[$key]);
			    $locked_types[$key] = true;
			    $no_revision_types[$key] = true;
			}

			$id = $option_name . '-' . $key;
			$name = $option_name . "[$key]";
			?>

			<?php if (!isset($revisionary->hidden_post_types_archive[$key])) :
				$locked = (!empty($locked_types[$key])) ? ' disabled ' : '';
			?>
			<div class="agp-vtight_input">
				<input name="<?php echo esc_attr($name); ?>" type="hidden" value="0"/>
				<label for="<?php echo esc_attr($id); ?>">
					<?php if (!empty($locked_types[$key])):
						echo $revisionary->admin->tooltipText(
							'<input name="' . esc_attr($name) . '" type="checkbox" id="' . esc_attr($id) . '" value="0" disabled />',
							esc_html__('This post type does not support Past Revisions.', 'revisionary')
						);
					?>
					<?php else: ?>
					<input name="<?php echo esc_attr($name); ?>" type="checkbox" id="<?php echo esc_attr($id); ?>"
						value="1" <?php checked('1', !empty($revisionary->enabled_post_types_archive[$key])); ?> />
					<?php endif;?>

					<?php
					if (isset($obj->labels_pp)) {
						echo esc_html($obj->labels_pp->name);
					} elseif (isset($obj->labels->name)) {
						echo esc_html($obj->labels->name);
					} else {
						echo esc_html($key);
					}

					echo '</label>';
					
					if ((!isset($revisionary->enabled_post_types_archive[$key]) || !empty($revisionary->enabled_post_types_archive[$key])) && isset($obj->capability_type) && !in_array($obj->capability_type, [$obj->name, 'post', 'page'])) {
						if ($cap_type_obj = get_post_type_object($obj->capability_type)) {
							echo '&nbsp;(' . esc_html(sprintf(__('%s capabilities'), $cap_type_obj->labels->singular_name)) . ')';
						}
					}

					echo '</div>';
				endif;

		} // end foreach src_otype
		?>
		</td>

		<?php
		$option_name = 'enabled_post_types';

		$this->all_options []= $option_name;
		?>
		<td>
		<h3 style="margin-top:0; margin-bottom:8px"><?php esc_html_e('New Revisions', 'revisionary');?>
		<?php 
		echo $revisionary->admin->tooltipText(
			'',
			__('New Revisions are changes which are not yet published. They are listed in the Revision Queue.', 'revisionary'),
			true
		);
		?>
		</h3>
		<?php

		$hidden_types = ['attachment' => false, 'psppnotif_workflow' => false, 'tablepress_table' => false, 'acf-field-group' => false, 'acf-field' => false, 'acf-post-type' => false, 'acf-taxonomy' => false, 'nav_menu_item' => false, 'custom_css' => false, 'customize_changeset' => false, 'wp_block' => false, 'wp_template' => false, 'wp_template_part' => false, 'wp_global_styles' => false, 'wp_navigation' => false, 'wp_font_family' => false, 'wp_font_face' => false, 'ppma_boxes' => false, 'ppmacf_field' => false, 'product_variation' => false, 'shop_order_refund' => false, 'wpcf7_contact_form' => false];
		$locked_types = [];

		$types = get_post_types(['public' => true, 'show_ui' => true], 'object', 'or');

		if (!defined('REVISIONARY_NO_PRIVATE_TYPES')) {
			$available_private_types = [];
			
			$private_types = array_merge(
				get_post_types(['public' => false], 'object'),
				get_post_types(['public' => null], 'object')
			);

			// by default, enable non-public post types that have type-specific capabilities defined
			foreach($private_types as $post_type => $type_obj) {
				if ((!empty($type_obj->cap) && !empty($type_obj->cap->edit_posts) && !in_array($type_obj->cap->edit_posts, ['edit_posts', 'edit_pages']) && !in_array($post_type, $hidden_types))
				|| defined('REVISIONARY_ENABLE_' . strtoupper($post_type) . '_TYPE')
				) {
					$available_private_types[$post_type] = $type_obj;
				}
			}

			$available_private_types = array_intersect_key(
				$available_private_types,
				(array) apply_filters('revisionary_available_private_types', array_fill_keys(array_keys($available_private_types), true))
			);

			$types = array_merge($types, $available_private_types);
		}

		$type_names = [];

		foreach ($types as $key => $obj) {
			$type_names[$key] = $obj->label;
		}

		$_ordered_types = rvy_order_types($type_names);

		$ordered_types['page'] = $types['page'];
		$ordered_types['post'] = $types['post'];
		
		$ordered_types = array_merge(
			$ordered_types,
			array_diff_key(
				$_ordered_types,
				array_fill_keys(['page', 'post'], true)
			)
		);

		foreach (array_keys($ordered_types) as $key) {
			if (!$key) {
				continue;
			}

			if (!isset($types[$key])) {
				continue;
			}

			$obj = $types[$key];

			$id = $option_name . '-' . $key;
			$name = $option_name . "[$key]";
			?>

			<?php if ('nav_menu' == $key) : ?>
				<input name="<?php echo esc_attr($name); ?>" type="hidden" id="<?php echo esc_attr($id); ?>" value="1"/>
			<?php else : ?>
			<?php if (isset($hidden_types[$key])) : ?>
				<input name="<?php echo esc_attr($name); ?>" type="hidden" value="<?php echo esc_attr($hidden_types[$key]); ?>"/>
			<?php else : 
					$locked = (!empty($locked_types[$key])) ? ' disabled ' : '';
				?>
			<div class="agp-vtight_input">
				<input name="<?php echo esc_attr($name); ?>" type="hidden" value="<?php echo (empty($locked_types[$key])) ? '0' : '1';?>"/>
				<label for="<?php echo esc_attr($id); ?>" title="<?php echo esc_attr($key); ?>">
					<input name="<?php if (empty($locked_types[$key])) echo esc_attr($name); ?>" type="checkbox" id="<?php echo esc_attr($id); ?>"
						value="1" <?php checked('1', !empty($revisionary->enabled_post_types[$key])); echo esc_attr($locked); ?> />

					<?php
					if (isset($obj->labels_pp)) {
						echo esc_html($obj->labels_pp->name);
					} elseif (isset($obj->labels->name)) {
						echo esc_html($obj->labels->name);
					} else {
						echo esc_html($key);
					}

					echo '</label>';
					
					if (!empty($revisionary->enabled_post_types[$key]) && isset($obj->capability_type) && !in_array($obj->capability_type, [$obj->name, 'post', 'page'])) {
						if ($cap_type_obj = get_post_type_object($obj->capability_type)) {
							echo '&nbsp;(' . esc_html(sprintf(__('%s capabilities'), $cap_type_obj->labels->singular_name)) . ')';
						}
					}

					echo '</div>';
				endif;
			endif; // displaying checkbox UI

		} // end foreach src_otype
		?>
		</div></td></tr></table>

	<div class="rvy-subtext">
	<?php
	esc_html_e('Note: Third party code may cause some post types to be incompatible with PublishPress Revisions.', 'revisionary');
	?>
	</div>

	<?php
		if (defined('PUBLISHPRESS_PRO_VERSION') && version_compare(PUBLISHPRESS_PRO_VERSION, '4.6.0-rc', '<')) :
			?>
			<div id="planner-pro-notice" class="activating rvy-plugin-notice" style="margin-top: 20px;">
			<?php
			printf(
				esc_html__('For Revisions integration on the Content Calendar, Overview and Content Board, please update %sPublishPress Planner Pro%s.', 'revisionary'),
				'<a href="' . esc_url(self_admin_url('plugins.php')) . '" target="_blank">',
				'</a>'
			);
			?>
			</div>
		<?php endif;
	?>

		</div></td></tr></table>
	<?php endif; // any options accessable in this section


$section = 'statuses';				// --- STATUSES SECTION ---

if (!defined('PUBLISHPRESS_STATUSES_PRO_VERSION') && ! empty( $this->form_options[$tab][$section] ) ) :?>
	<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

	<!-- CTA Section -->
	<div class="pp-cta-section">
		<h4>
			<?php esc_html_e('Ready to enhance your revision workflow?', 'revisionary'); ?>
		</h4>
		<p>
			<?php esc_html_e('Get PublishPress Statuses Pro and optimize your revision process with custom revision statuses.', 'revisionary'); ?>
		</p>

		<div class="pp-revisions-pro-features">
			<ul>
				<li>
					&nbsp;<?php _e('Define your own statuses before or after Revision Submitted', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('New predefined statuses: Deferred, Needs Work, Rejected', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('Statuses can be specific to a post type', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('Control access to statuses per-role', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('Status workflow can be nested with sub-statuses', 'revisionary');?>
				</li>
			</ul>
		</div>

		<div class="pp-cta-buttons">
			<a href="https://publishpress.com/statuses/" 
				class="button-primary button-large pp-upgrade-btn" 
				target="_blank">
				<?php esc_html_e('Get PublishPress Statuses Pro', 'revisionary'); ?>
			</a>
			<a href="https://publishpress.com/knowledge-base/revisions-statuses/" 
				target="_blank"
				class="pp-learn-more-link">
				<?php esc_html_e('Learn More', 'revisionary'); ?>
			</a>
		</div>
	</div>

	<?php if (!empty($_REQUEST['rvy_promo_img'])):?>
	<br>
	<div class="pp-integration-card">
	<div style="border: 1px solid #ccc; border-radius: 8px">
	<img src="<?php echo esc_url(plugins_url('', REVISIONARY_FILE) . '/admin/revision-statuses.png');?>" style="width: 100%" />
	</div>

	<div class="pp-upgrade-overlay">
		<h4><?php esc_html_e('Pro Plugin', 'revisionary'); ?></h4>
		<p><?php esc_html_e('Install Statuses Pro to unlock custom revision statuses.', 'revisionary');?></p>
		<p><?php esc_html_e('Configure for any post type and role to match your editing workflow.', 'revisionary');?></p>
		<div class="pp-upgrade-buttons">
			<a href="<?php echo esc_url('https://publishpress.com/knowledge-base/revisions-statuses/'); ?>" target="_blank" class="pp-upgrade-btn-secondary">
				<?php esc_html_e('Learn More', 'revisionary'); ?>
			</a>

			<a href="https://publishpress.com/statuses/" target="_blank" class="pp-upgrade-btn-primary">
			<?php esc_html_e('Get Statuses Pro', 'revisionary');?>
			</a>
		</div>
	</div>
	</div>
	<?php endif;?>

	</div></td></tr></table>
<?php endif; // any options accessable in this section


$section = 'archive';				// --- ARCHIVE SECTION ---

if ( ! empty( $this->form_options[$tab][$section] ) ) :?>
	<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

	<?php
	$option_name = 'num_revisions';
	$wp_num_revisions = (defined('WP_POST_REVISIONS')) ? WP_POST_REVISIONS : 0;

	$opt_val = get_option('rvy_num_revisions', $wp_num_revisions);

	$class_name = '';
	$this->register_option($option_name);
	?>
	<div class=agp-vspaced_input style="vertical-align: middle;">
	<label for="<?php echo esc_html($option_name);?>">
	<?php esc_html_e('Maximum revisions per post:', 'revisionary-pro');?></label>
	<input class="<?php echo esc_attr($class_name); ?>" name="<?php echo esc_attr($option_name); ?>" type="text" id="<?php echo esc_attr($option_name); ?>" size="10" 
	value="<?php echo (in_array($opt_val, [true, ''], true)) ? '' : intval($opt_val);?>" placeholder="<?php echo (true === $wp_num_revisions) ? esc_html__('(unlimited)', 'revisionary-pro') : '';?>" 
	style="vertical-align:middle" autocomplete="off" />

	<?php
	if (rvy_get_option('display_hints')) :?>
		<div class="rvy-subtext">
		<?php
		printf(
			esc_html__('Note that archiving can also be disabled by %1$sPost Type%2$s.', 'revisionary-pro'),
			'<a class="rvyTabPostTypes" href="javascript:void(0)">',
			'</a>'
		);
		?>
		</div>

		<script>
		/* <![CDATA[ */
		jQuery(document).ready( function($) {
			$('a.rvyTabPostTypes').on('click', function() {
				$('#publishpress-revisions-settings-tabs li a[href="#ppr-tab-post_types"]').trigger('click');
			});
		});
		/* ]]> */
		</script>
	<?php endif;

	?>
	</div>
	<?php

	do_action('revisionary_option_ui_archive', $this, $sitewide, $customize_defaults);

	$this->option_checkbox( 'extended_archive', $tab, $section, '', '' );

	$this->option_checkbox( 'archive_postmeta', $tab, $section, '', '' );

	$hint = '';
	$this->option_checkbox( 'revision_archive_deletion', $tab, $section, $hint, '' );

	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
		$checkbox_args = [];
		
		if (defined('PUBLISHPRESS_CAPS_VERSION')) {
			$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');
	
			$cap_caption = sprintf(__('%s capability', 'revisionary'), 'restore_revisions');

			if (rvy_get_option('revision_restore_require_cap')) {
				$link = $revisionary->admin->tooltipText(
					"<a href='$url'>" . $cap_caption . '</a>',
					__('Assign capability to roles', 'revisionary')
				);
			} else {
				$link = $cap_caption;
			}

			$hint = sprintf(
				__('Prevent non-Administrators from restoring a revision without the %s.', 'revisionary'),
				$link
			);

			$checkbox_args['no_escape'] = true;
		} else {
			$hint = esc_html__('Prevent non-Administrators from restoring a revision without the restore_revisions capability', 'revisionary');
		}

		echo '<br><br>';

		$id = 'past_revisions_order_by';
		if ( in_array( $id, $this->form_options[$tab][$section] ) ) {
			echo esc_html($this->option_captions[$id]);
	
			$this->all_options []= $id;
			$current_setting = rvy_get_option($id, $sitewide, $customize_defaults);
	
			echo " <select name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' autocomplete='off' style='vertical-align: baseline'>";
			$captions = ['' => esc_html__('Post Date', 'revisionary'), 'modified' => esc_html__('Modification Date', 'revisionary')];
			foreach ( $captions as $key => $value) {
				$selected = ( $current_setting == $key ) ? 'selected' : '';
				echo "\n\t<option value='" . esc_attr($key) . "' " . esc_attr($selected) . ">" . esc_html($captions[$key]) . "</option>";
			}
			echo '</select>&nbsp;';
	
			echo "<br /><br />";
		}

		$this->option_checkbox( 'revision_restore_require_cap', $tab, $section, $hint, '', $checkbox_args );
	}
	?>
	</div></td></tr></table>
<?php endif; // any options accessable in this section


$section = 'working_copy';			// --- NEW REVISIONS SECTION ---

if ( ! empty( $this->form_options[$tab][$section] ) ) :?>
	<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

	<?php
	$pending_revisions_available = rvy_get_option( 'pending_revisions' ); // ! RVY_NETWORK || $sitewide || empty( $rvy_options_sitewide['pending_revisions'] ) || rvy_get_option( 'pending_revisions', true );

	$scheduled_revisions_available =  rvy_get_option( 'scheduled_revisions' ); // ! RVY_NETWORK || $sitewide || empty( $rvy_options_sitewide['scheduled_revisions'] ) || rvy_get_option( 'scheduled_revisions', true );

	$_sections = [
		'revision-creation' => esc_html__('Revision Creation', 'revisionary'),
		'revision-submission' => esc_html__('Submission', 'revisionary'),
		'revision-scheduling' => esc_html__('Scheduling', 'revisionary'),
		'revision-publication' => esc_html__('Publication', 'revisionary'),
		'revision-queue' => esc_html__('Revision Queue', 'revisionary'),
		'notifications' => esc_html__('Notifications', 'revisionary'),
	];

	if (!$pending_revisions_available && !$scheduled_revisions_available) {
		unset($_sections['revision-queue']);
	}

	if (empty($_REQUEST['ppr_subtab'])) {
		$subtab = 'revision-creation';
	} else {
		$subtab = sanitize_key($_REQUEST['ppr_subtab']);
	}
	?>

	<ul class="rvy-option-section-tabs">
	<?php foreach ($_sections as $_section => $caption) :?>
	<?php if (!empty($first_done)) :?>
		<li><?php echo "&nbsp;|&nbsp";?></li>
	<?php endif;?>
	<li class="<?php if ($_section == $subtab) echo 'active';?>"><a href="javascript:void(0);" class="<?php echo $_section;?>"><?php echo esc_html($caption);?></a></li>
	<?php 
		$first_done = true;
	endforeach;?>
	</ul>

	<script type="text/javascript">
	/* <![CDATA[ */
	jQuery(document).ready( function($) {
		<?php if (empty($_REQUEST['ppr_tab'])):?>
		$('#publishpress-revisions-settings-tabs li:first').click();
		<?php endif;?>

		$('#ppr-tab-working_copy div.rvy-opt-wrap ul.rvy-option-section-tabs li a').on('click', function(e) {
			$('#ppr-tab-working_copy div.rvy-opt-wrap ul.rvy-option-section-tabs li').removeClass('active');
			$(this).parent().addClass('active');
			$('#ppr-tab-working_copy div.rvy-opt-wrap > div').hide();
			$('#ppr-tab-working_copy div.rvy-opt-wrap > div.' + $(this).attr('class')).show();
		});
	});
	/* ]]> */
	</script>

	<div class="revision-creation" <?php if ('revision-creation' != $subtab) echo 'style="display:none"';?>>
	<?php

	$this->setSubsection('revision-creation');

	$checkbox_args = [];

	if (defined('PUBLISHPRESS_CAPS_VERSION')) {
		$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=copy');

		$cap_caption = __('Create Revision capabilities', 'revisionary');

		if (rvy_get_option('copy_posts_capability')) {
			$link = $revisionary->admin->tooltipText(
				"<a href='$url'>" . $cap_caption . '</a>',
				__('Assign capabilities to roles', 'revisionary')
			);
		} else {
			$link = $cap_caption;
		}

		$hint = sprintf(
			__('If the user can\'t edit the published post, require %s.', 'revisionary'),
			$link
		);

		$checkbox_args['no_escape'] = true;
	} else {
		$hint = esc_html__('If the user can\'t edit the published post, require Create Revision capabilities (copy_posts, copy_others_pages, etc.)', 'revisionary');
	}

	if (defined('PRESSPERMIT_VERSION')) {
		if (defined('PUBLISHPRESS_CAPS_VERSION')) {
			$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=list');
	
			$hint .= ' ' . sprintf(
				__('To expand the Pages list, assign %s.', 'revisionary'),
				
				$revisionary->admin->tooltipText(
					"<a href='$url'>" . __('Listing capabilities', 'revisionary') . '</a>',
					__('Assign capabilities to roles', 'revisionary')
				)
			);
	
			$checkbox_args['no_escape'] = true;
		} else {
			$hint .= ' ' . esc_html__('To expand the Pages list, assign Listing capabilities (list_others_pages, list_published_posts, etc.)', 'revisionary');
		}
	}
	
	$this->option_checkbox( 'copy_posts_capability', $tab, $section, $hint, '', $checkbox_args );

	$hint = esc_html__('This plugin introduces a new user role, "Revisor."', 'revisionary');
	$this->option_checkbox( 'revisor_role_add_custom_rolecaps', $tab, $section, $hint, '' );

	$checkbox_args = [];

	if (defined('PUBLISHPRESS_CAPS_VERSION')) {
		$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=admin');

		$cap_caption = sprintf(__('%s capability', 'revisionary'), 'unfiltered_html');

		if (rvy_get_option('revision_unfiltered_html_check')) {
			$link = $revisionary->admin->tooltipText(
				"<a href='$url'>" . $cap_caption . '</a>',
				__('Assign capability to roles', 'revisionary')
			);
		} else {
			$link = $cap_caption;
		}

		$hint = sprintf(
			__('Revision by a user who does not have the %s will cause all custom html tags to be stripped out.', 'revisionary'),
			$link
		);

		$checkbox_args['no_escape'] = true;
	} else {
		$hint = esc_html__('Revision by a user who does not have the unfiltered_html capability will cause all custom html tags to be stripped out.', 'revisionary');
	}

	$this->option_checkbox( 'revision_unfiltered_html_check', $tab, $section, $hint, '', $checkbox_args );

	if (!empty($this->form_options[$tab]['working_copy']) && in_array('permissions_compat_mode', $this->form_options[$tab]['working_copy'])) {
		$this->subsection_options = true;
		
		echo '<br>';
		$id = 'permissions_compat_mode';

		$this->register_option($id);
		$current_setting = rvy_get_option($id, $sitewide, $customize_defaults);

		echo esc_html($this->option_captions[$id]) . ':';

		$standard_caption = (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION'))
		? esc_html__('Broadest compat including Elementor, Divi, Beaver Builder', 'revisionary')
		: esc_html__('Standard storage schema for broadest 3rd party compat', 'revisionary');

		echo " <select name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' autocomplete='off'>";
		$captions = [
			'' => $standard_caption, 
			1 => esc_html__('Enhanced Revision access control with PublishPress plugins', 'revisionary'),
		];

		foreach ( $captions as $key => $value) {
			$selected = ( $current_setting == $key ) ? 'selected' : '';
			echo "\n\t<option value='" . esc_attr($key) . "' " . esc_attr($selected) . ">" . esc_html($captions[$key]) . "</option>";
		}
		echo '</select>&nbsp;';

		if (revisionary()->getOption('display_hints')) :?>
			<div class="rvy-subtext">
			<?php _e('In enhanced mode, a Revision\'s status is stored by standard WordPress schema. Some plugins are incompatible.', 'revisionary');?>
			</div>
		<?php endif;

		echo '<br>';
	}

	$hint = esc_html__('Prevent Revision creation if the post already has another Revision in progress.', 'revisionary');
	$this->option_checkbox( 'revision_limit_per_post', $tab, $section, $hint, '' );

	$hide = empty(rvy_get_option('revision_limit_per_post'));
	$hint = esc_html__('Work around cache plugin conflicts by requerying for revisions before suppressing the New Revision link.', 'revisionary');
	$this->option_checkbox( 'revision_limit_compat_mode', $tab, $section, $hint, '', compact('hide') );
	?>
	<script type="text/javascript">
	/* <![CDATA[ */
	jQuery(document).ready( function($) {
		$('#revision_limit_per_post').on('click', function(e) {
			$('#revision_limit_compat_mode').closest('div').toggle($(e).prop('checked'));
		});
	});
	/* ]]> */
	</script>

	<?php
	$hint = '';
	$this->option_checkbox( 'create_revision_direct_link', $tab, $section, $hint, '');
	?>

	<?php
	$hint = sprintf(esc_html__('If the user does not have a regular Edit link, recaption the %s link as "Edit."', 'revisionary'), pp_revisions_status_label('draft-revision', 'submit_short'));
	$this->option_checkbox( 'caption_copy_as_edit', $tab, $section, $hint, '' );

	$hint = esc_html__('Default labels are "Not Submitted for Approval", "Submitted for Approval", "Scheduled Revision"', 'revisionary');
	$this->option_checkbox( 'revision_statuses_noun_labels', $tab, $section, $hint, '' );
	?>

	<?php
	do_action('revisionary_auto_submit_setting_ui', $this, $tab, $section);

	$this->closeSubsection();
	?>
	</div>


	<div class="revision-submission" <?php if ('revision-submission' != $subtab) echo 'style="display:none"';?>>
		<?php

		$this->setSubsection('revision-submission');

		$hint = sprintf(
			esc_html__( 'Enable published content to be copied, edited, submitted for approval and managed in %sRevision Queue%s.', 'revisionary' ),
			"<a href='" . esc_url(rvy_admin_url('admin.php?page=revisionary-q')) . "'>",
			'</a>');

		$this->option_checkbox('pending_revisions', $tab, $section, $hint, '', ['no_escape' => true]);

		if ( 	// To avoid confusion, don't display any revision settings if pending revisions / scheduled revisions are unavailable
			$pending_revisions_available ) :

			$checkbox_args = [];

			if (defined('PUBLISHPRESS_CAPS_VERSION')) {
				$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=revise');
		
				$cap_caption = __('Submit Revision capabilities', 'revisionary');

				if (rvy_get_option('revise_posts_capability')) {
					$link = $revisionary->admin->tooltipText(
						"<a href='$url'>" . $cap_caption . '</a>',
						__('Assign capabilities to roles', 'revisionary')
					);
				} else {
					$link = $cap_caption;
				}

				$hint = sprintf(
					__('If the user can\'t edit the published post, require %s.', 'revisionary'),
					$link
				);

				$checkbox_args['no_escape'] = true;
			} else {
				$hint = esc_html__('If the user can\'t edit the published post, require Submit Revision capabilities (revise_posts, revise_others_pages, etc.)', 'revisionary');
			}

			$this->option_checkbox( 'revise_posts_capability', $tab, $section, $hint, '', $checkbox_args );

			$hint = sprintf(esc_html__( 'When a %s is published, update post publish date to current time.', 'revisionary' ), pp_revisions_status_label('pending-revision', 'name'));
			$this->option_checkbox( 'pending_revision_update_post_date', $tab, $section, $hint, '' );

			$hint = sprintf(esc_html__( 'When a %s is published, update post modified date to current time.', 'revisionary' ), pp_revisions_status_label('pending-revision', 'name'));
			$this->option_checkbox( 'pending_revision_update_modified_date', $tab, $section, $hint, '' );

			$hint = esc_html__('When a user who has publishing capabilities creates a Revision, set it to "Submitted" status.', 'revisionary');
			$this->option_checkbox( 'auto_submit_revisions', $tab, $section, $hint, '' );

			$hint = esc_html__('This allows immediate approval within the Compare screen. If disabled, a Preview link is provided instead.', 'revisionary');
			$this->option_checkbox( 'compare_revisions_direct_approval', $tab, $section, $hint, '' );

			do_action('revisionary_option_ui_pending_revisions', $this, $sitewide, $customize_defaults);

			$this->closeSubsection();
		?>
	<?php endif; // revision submission enabled
	?>

		</div>
	

		<div class="revision-scheduling" <?php if ('revision-scheduling' != $subtab) echo 'style="display:none"';?>>
		<?php
		$this->setSubsection('revision-scheduling');

		$hint = esc_html__( 'If a currently published post or page is edited and a future date set, the change will not be applied until the selected date.', 'revisionary' );
		$this->option_checkbox( 'scheduled_revisions', $tab, $section, $hint, '' );

		if ( 	// To avoid confusion, don't display any revision settings if pending revisions / scheduled revisions are unavailable
		$scheduled_revisions_available ) :

			$hint = sprintf(esc_html__( 'When a %s is published, update post publish date to current time.', 'revisionary' ), pp_revisions_status_label('future-revision', 'name'));
			$this->option_checkbox( 'scheduled_revision_update_post_date', $tab, $section, $hint, '' );

			$hint = sprintf(esc_html__( 'When a %s is published, update post modified date to current time.', 'revisionary' ), pp_revisions_status_label('future-revision', 'name'));
			$this->option_checkbox( 'scheduled_revision_update_modified_date', $tab, $section, $hint, '' );

			global $wp_version;
			
			$hint = esc_html__( 'Publish scheduled revisions using the WP-Cron mechanism. On some sites, publication will fail if this setting is disabled.', 'revisionary' );
			$this->option_checkbox( 'scheduled_publish_cron', $tab, $section, $hint, '' );

			if (!rvy_get_option('scheduled_publish_cron')) {
				$hint = esc_html__( 'Publish scheduled revisions asynchronously, via a secondary http request from the server.  This is usually best since it eliminates delay, but some servers may not support it.', 'revisionary' );
				$this->option_checkbox( 'async_scheduled_publish', $tab, $section, $hint, '' );
			}

			if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
				$hint = esc_html__( 'The WP-Cron trigger is disabled, but scheduled tasks are still excecuted using a custom trigger.', 'revisionary' );
				$this->option_checkbox( 'wp_cron_usage_detected', $tab, $section, $hint, '' );
			}
		endif; // scheduled revisions enabled

		$this->closeSubsection();
		?>
		</div>

		<div class="revision-publication" <?php if ('revision-publication' != $subtab) echo 'style="display:none"';?>>

		<?php
		$this->setSubsection('revision-publication');

		$hint = __('Caption the button as either "Approve and Publish" or "Approve and Schedule."', 'revisionary');
		$this->option_checkbox( 'approve_button_verbose', $tab, $section, $hint, '' );

		if (defined('PUBLISHPRESS_VERSION')) {
			$this->option_checkbox( 'rev_publication_delete_ed_comments', $tab, $section, '', '' );
		}

		$this->option_checkbox( 'copy_revision_comments_to_post', $tab, $section, '', '' );

		$hint = esc_html__('This may improve compatibility with some plugins.', 'revisionary');
		$this->option_checkbox( 'trigger_post_update_actions', $tab, $section, $hint, '' );

		do_action('revisionary_option_ui_revision_options', $this, $sitewide, $customize_defaults);

		$this->closeSubsection();
		?>
		</div>
		

		<div class="revision-queue" <?php if ('revision-queue' != $subtab) echo 'style="display:none"';?>>

		<?php
		$this->setSubsection('revision-queue');

		if ( 	// To avoid confusion, don't display any revision settings if pending revisions / scheduled revisions are unavailable
			$pending_revisions_available || $scheduled_revisions_available ) :
		
			$checkbox_args = [];

			if (defined('PUBLISHPRESS_CAPS_VERSION')) {
				$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');
		
				$cap_caption = sprintf(__('%s capability', 'revisionary'), 'manage_revision_queue');

				if (rvy_get_option('revision_queue_capability')) {
					$link = $revisionary->admin->tooltipText(
						"<a href='$url'>" . $cap_caption . '</a>',
						__('Assign capability to roles', 'revisionary')
					);
				} else {
					$link = $cap_caption;
				}

				$hint = sprintf(
					__('Control Revision Queue access with the %s.', 'revisionary'),
					$link
				);

				$checkbox_args['no_escape'] = true;
			} else {
				$hint = esc_html__('Control Revision Queue access with the manage_revision_queue capability.', 'revisionary');
			}

			$this->option_checkbox('revision_queue_capability', $tab, $section, $hint, '', $checkbox_args);

			if (defined('PUBLISHPRESS_CAPS_VERSION')) {
				$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');

				$cap_caption = sprintf(__('%s capability', 'revisionary'), 'manage_unsubmitted_revisions');

				if (rvy_get_option('manage_unsubmitted_capability')) {
					$link = $revisionary->admin->tooltipText(
						"<a href='$url'>" . $cap_caption . '</a>',
						__('Assign capability to roles', 'revisionary')
					);
				} else {
					$link = $cap_caption;
				}

				$hint = sprintf(
					__('Users will need the %s to edit others\' Unsubmitted Revisions.', 'revisionary'),
					$link
				);

				$checkbox_args['no_escape'] = true;
			} else {
				$hint = esc_html__('Users will need the manage_unsubmitted_revisions capability to edit others\' Unsubmitted Revisions.', 'revisionary');
			}

			$this->option_checkbox('manage_unsubmitted_capability', $tab, $section, $hint, '', $checkbox_args);

			if (defined('PUBLISHPRESS_CAPS_VERSION')) {
				$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');
		
				$cap_caption = sprintf(__('%s capability', 'revisionary'), 'edit_others_revisions');

				if (rvy_get_option('revisor_lock_others_revisions')) {
					$link = $revisionary->admin->tooltipText(
						"<a href='$url'>" . $cap_caption . '</a>',
						__('Assign capability to roles', 'revisionary')
					);
				} else {
					$link = $cap_caption;
				}

				$hint = sprintf(
					__('If the user can\'t edit the published post, require the %s.', 'revisionary'),
					$link
				);

				$checkbox_args['no_escape'] = true;
			} else {
				$hint = esc_html__('If the user can\'t edit the published post, require the edit_others_revisions capability.', 'revisionary');
			}

			$this->option_checkbox( 'revisor_lock_others_revisions', $tab, $section, $hint, '', $checkbox_args );

			$checkbox_args = [];

			if (defined('PUBLISHPRESS_CAPS_VERSION')) {
				$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');
		
				$cap_caption = sprintf(__('%s capability', 'revisionary'), 'list_others_revisions');

				if (rvy_get_option('revisor_hide_others_revisions')) {
					$link = $revisionary->admin->tooltipText(
						"<a href='$url'>" . $cap_caption . '</a>',
						__('Assign capability to roles', 'revisionary')
					);
				} else {
					$link = $cap_caption;
				}

				$hint = sprintf(
					__('If the user can\'t edit the published post, require the %s.', 'revisionary'),
					$link
				);

				$checkbox_args['no_escape'] = true;
			} else {
				$hint = esc_html__('If the user can\'t edit the published post, require the list_others_revisions capability.', 'revisionary');
			}

			$this->option_checkbox( 'revisor_hide_others_revisions', $tab, $section, $hint, '', $checkbox_args );

			$hint = esc_html__('Bypass the above restrictions for others\' revisions to logged in user\'s own posts.', 'revisionary');
			$this->option_checkbox( 'admin_revisions_to_own_posts', $tab, $section, $hint, '' );

			$hint = '';
			$this->option_checkbox( 'list_unsubmitted_revisions', $tab, $section, $hint, '' );

			do_action('revisionary_option_ui_revision_queue_options', $this, $sitewide, $customize_defaults);
			?>
	
			<?php if (!empty($_SERVER['REQUEST_URI']) && !$customize_defaults && !$sitewide):?>
			<p style="margin-top:25px">
			<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('rvy_flush_flags', 1, esc_url(esc_url_raw($_SERVER['REQUEST_URI']))), 'flush-flags') )?>"><?php esc_html_e('Regenerate "post has revision" flags', 'revisionary');?></a>
			
			<?php if ($this->display_hints) :
				$hint = esc_html__('Apply this maintenance operation if Has Revision labels on Posts / Pages screens mismatch the Revision Queue listing.', 'revisionary');
				echo "<div class='rvy-subtext'>" . esc_html($hint) . "</div>";
			endif;?>
		
			</p>
			<?php endif;
		endif;

		$this->closeSubsection();
		?>
		</div>

		<div class="notifications" <?php if ('notifications' != $subtab) echo 'style="display:none"';?>>
		
		<?php
		$this->setSubsection('notifications');

		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !defined('PUBLISHPRESS_VERSION')) :
			if (!$customize_defaults):?>
			<div id="rvy-planner-notice" class="activating rvy-plugin-notice" style="margin-bottom: 20px">
			<?php
			$plugin_slug = 'publishpress';
			$info_url = self_admin_url("plugin-install.php?tab=plugin-information&plugin=$plugin_slug&TB_iframe=true&width=640&height=678");

			printf(
				esc_html__('For enhanced notifications, install %sPublishPress Planner%s.', 'revisionary'),
				'<a href="' . esc_url($info_url) . '" class="thickbox">',
				'</a>'
			);
			?>
			</div>
			<br />
			<?php endif;?>
		<?php elseif (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=')) :
			?>
			<div id="rvy-planner-notice" class="activating rvy-plugin-notice" style="margin-bottom: 20px">
			<?php
			printf(
				esc_html__('For enhanced notifications, update %sPublishPress Planner%s.', 'revisionary'),
				'<a href="' . esc_url(admin_url('plugin-install.php?s=publishpress%2520planner&tab=search&type=term')) . '" target="_blank">',
				'</a>'
			);
			?>
			</div>
			<br />
		<?php endif;

		if ((defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=')) || !defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') || !defined('PUBLISHPRESS_VERSION')
		|| empty($pp_notifications)
		) {
			$pp_notifications = rvy_get_option('use_publishpress_notifications');

			$chk_args = ['no_escape' => true];

			if (!defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
				$chk_args['disabled'] = true;
				$chk_args['val'] = 0;
			}

			$hint_text = (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION'))
			? __('Send revision action notifications using the customizable %1$sPublishPress Planner Notifications system%2$s.', 'revisionary')
			: __('Upgrade to Revisions Pro to send revision action notifications using the customizable %1$sPublishPress Planner Notifications system%2$s.', 'revisionary');

			$hint = sprintf(
				$hint_text,
				'<a href="https://publishpress.com/knowledge-base/customize-email-notifications/" target="_blank">',
				'</a>'
			);

			$this->option_checkbox( 'use_publishpress_notifications', $tab, $section, $hint, '', $chk_args);

			if ($pp_notifications && defined('PRESSPERMIT_VERSION') && defined('RVY_CONTENT_ROLES')) {
				echo '<br />';
				$hint = __('Users matching Planner > Notifications configuration get revision notifications only if they can edit the published post.', 'revisionary');
				$this->option_checkbox( 'planner_notifications_access_limited', $tab, $section, $hint, '', ['no_escape' => true] );
			}

			if (!empty($this->form_options[$tab]['notfications']) && in_array('legacy_notifications', $this->form_options[$tab]['notifications'])) {
				echo '<h3 style="margin-top:30px;';
				
				if ($pp_notifications) echo 'display:none;';

				echo '">';
				_e('Legacy Email Notifications:');
				echo '</h3>';
			}

			$hint = '';
			$this->option_checkbox( 'legacy_notifications', $tab, $section, $hint, '' );

			$legacy_notifications = rvy_get_option('legacy_notifications');
		}

		?>

		<script type="text/javascript">
		/* <![CDATA[ */
		jQuery(document).ready( function($) {
			$('#use_publishpress_notifications').on('click', function(e) {
				$('div.rvy_legacy_email').toggle($(e).prop('checked'));
			});

			$('#legacy_notifications').on('click', function(e) {
				$('div.rvy_legacy_email').toggle($(e).prop('checked'));
			});
		});
		/* ]]> */
		</script>

		<div class="rvy_legacy_email" style="<?php if (!empty($pp_notifications) || empty($legacy_notifications)) echo 'display:none';?>">

		<?php
		if( $pending_revisions_available ) {
			$id = 'pending_rev_notify_admin';
			if ( in_array( $id, $this->form_options[$tab][$section] ) ) {
				$this->all_options []= $id;
				$current_setting = rvy_get_option($id, $sitewide, $customize_defaults);

				echo "<select name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' autocomplete='off'>";
				$captions = array( 0 => esc_html__('Never', 'revisionary'), 1 => esc_html__('By default', 'revisionary'), 'always' => esc_html__('Always', 'revisionary') );
				foreach ( $captions as $key => $value) {
					$selected = ( $current_setting == $key ) ? 'selected' : '';
					echo "\n\t<option value='" . esc_attr($key) . "' " . esc_attr($selected) . ">" . esc_html($captions[$key]) . "</option>";
				}
				echo '</select>&nbsp;';

				echo esc_html($this->option_captions[$id]);

				// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInTernaryCondition
				echo ( defined('RVY_CONTENT_ROLES') && $group_link = $revisionary->content_roles->get_metagroup_edit_link( 'Pending Revision Monitors' ) ) ?
				sprintf( " &bull;&nbsp;<a href='%s'>" . esc_html__('select recipients', 'revisionary') . "</a>", esc_url($group_link) ) : '';

				echo "<br />";
			}

			$id = 'pending_rev_notify_author';
			if ( in_array( $id, $this->form_options[$tab][$section] ) ) {
				$this->all_options []= $id;
				$current_setting = rvy_get_option($id, $sitewide, $customize_defaults);

				echo "<select name='" . esc_attr($id) . "' id='" . esc_attr($id) . "' autocomplete='off'>";
				$captions = array( 0 => esc_html__('Never', 'revisionary'), 1 => esc_html__('By default', 'revisionary'), 'always' => esc_html__('Always', 'revisionary') );
				foreach ( $captions as $key => $value) {
					$selected = ( $current_setting == $key ) ? 'selected' : '';
					echo "\n\t<option value='" . esc_attr($key) . "' " . esc_attr($selected) . ">" . esc_html($captions[$key]) . "</option>";
				}
				echo '</select>&nbsp;';

				echo esc_html($this->option_captions[$id]);
				echo "<br />";
			}

			if (rvy_get_option('pending_rev_notify_admin') || rvy_get_option('pending_rev_notify_author')) {
				$hint = '';
				$this->option_checkbox( 'revision_update_notifications', $tab, $section, $hint, '' );
			}

			$hint = '';
			$this->option_checkbox( 'pending_rev_notify_revisor', $tab, $section, $hint, '' );

			echo '<br />';

			$hint = '';
			$this->option_checkbox( 'rev_approval_notify_admin', $tab, $section, $hint, '' );

			$hint = '';
			$this->option_checkbox( 'rev_approval_notify_author', $tab, $section, $hint, '' );

			$hint = '';
			$this->option_checkbox( 'rev_approval_notify_revisor', $tab, $section, $hint, '' );
		}

		if( $scheduled_revisions_available ) {
			echo '<br />';

			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInTernaryCondition
			$subcaption = ( defined('RVY_CONTENT_ROLES') && $group_link = $revisionary->content_roles->get_metagroup_edit_link( 'Scheduled Revision Monitors' ) ) ?
				sprintf( " &bull;&nbsp;<a href='%s'>" . esc_html__('select recipients', 'revisionary') . "</a>", $group_link ) : '';

			$hint = '';
			$this->option_checkbox( 'publish_scheduled_notify_admin', $tab, $section, $hint, '', array( 'subcaption' => $subcaption ) );

			$hint = '';
			$this->option_checkbox( 'publish_scheduled_notify_author', $tab, $section, $hint, '' );

			$hint = '';
			$this->option_checkbox( 'publish_scheduled_notify_revisor', $tab, $section, $hint, '' );
		}

		echo '<br />';

		$hint = esc_html__('To avoid notification failures, buffer emails for delayed sending once minute, hour or day limits are exceeded', 'revisionary');
		$this->option_checkbox( 'use_notification_buffer', $tab, $section, $hint, '' );

		if (!empty($_REQUEST['truncate_mail_log'])) {										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_option('revisionary_sent_mail');
		}

		if (!empty($_REQUEST['clear_mail_buffer'])) {										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_option('revisionary_mail_buffer');
		}

		if (!empty($_SERVER['REQUEST_URI'])) {
			$uri = esc_url(esc_url_raw($_SERVER['REQUEST_URI']));
		} else {
			$uri = '';
		}

		if (!empty($_REQUEST['mailinfo'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$verbose = !empty($_REQUEST['verbose']);										//phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ($q = get_option('revisionary_mail_buffer')) {
				echo '<h3>' . esc_html__('Notification Buffer', 'revisionary') . '</h3>';
				foreach($q as $row) {
					if (!$verbose) {
						unset($row['message']);
					} elseif(!empty($row['message'])) {
						$row['message'] = '<br />' . str_replace("\r\n", '<br />', $row['message']);
					}

					$row['time_gmt'] = gmdate('Y-m-d, H:i:s', $row['time_gmt']);
					if (isset($row['time'])) {
						$row['time'] = gmdate('Y-m-d, g:i:s a', $row['time']);
					}

					foreach($row as $k => $val) {
						if ($k != 'message') {
							echo "<b>" . esc_html($k) . "</b> : " . esc_html($val) . "<br />";
						}
					}

					if ($verbose && !empty($row['message'])) {
						echo "<b>message</b> : " . esc_html($row['message']) . "<br />";
					}

					echo '<hr />';
				}
			}

			if ($log = get_option('revisionary_sent_mail')) {
				echo '<h3>' . esc_html__('Notification Log', 'revisionary') . '</h3>';
				foreach($log as $row) {
					if (!$verbose) {
						unset($row['message']);
					} elseif(!empty($row['message'])) {
						$row['message'] = '<br />' . str_replace("\r\n", '<br />', $row['message']);
					}

					$row['time_gmt'] = gmdate('Y-m-d, H:i:s', $row['time_gmt']);
					if (isset($row['time'])) {
						$row['time'] = gmdate('Y-m-d, g:i:s a', $row['time']);
					}

					foreach($row as $k => $val) {
						if ($k != 'message') {
							echo "<b>" . esc_html($k) . "</b> : " . esc_html($val) . "<br />";
						}
					}

					if ($verbose && !empty($row['message'])) {
						echo "<b>message</b> : " . esc_html($row['message']) . "<br />";
					}

					echo '<hr />';
				}
			}

			if (get_option('revisionary_mail_buffer')):?>
				<br />
				<a href="<?php echo esc_url(add_query_arg('clear_mail_buffer', '1', $uri));?>"><?php esc_html_e('Purge Notification Buffer', 'revisionary');?></a>
				<br />
			<?php endif;?>

			<?php if (get_option('revisionary_sent_mail')):?>
				<br />
				<a href="<?php echo esc_url(add_query_arg('truncate_mail_log', '1', $uri));?>"><?php esc_html_e('Truncate Notification Log', 'revisionary');?></a>
			<?php endif;

			$mail_info = rvy_mail_check_buffer([], ['log_only' => true]);
			?>
			<br /><br />
			<p><?php echo esc_html(sprintf(__('Sent in last minute: %d / %d', 'revisionary'), $mail_info->sent_counts['minute'], $mail_info->send_limits['minute']));?></p>
			<p><?php echo esc_html(sprintf(__('Sent in last hour: %d / %d', 'revisionary'), $mail_info->sent_counts['hour'], $mail_info->send_limits['hour']));?></p>
			<p><?php echo esc_html(sprintf(__('Sent in last day: %d / %d', 'revisionary'), $mail_info->sent_counts['day'], $mail_info->send_limits['day']));?></p>
			<?php
			if (!empty($q)) {
				if ($cron_timestamp = wp_next_scheduled('rvy_mail_buffer_hook')) {
					$wait_sec = $cron_timestamp - time();
					if ($wait_sec > 0) {
						echo '<br />';
						echo esc_html(sprintf(__('Seconds until next buffer processing time: %d', 'revisionary'), $wait_sec));
					}
				}
			}
		}

		if (empty($_REQUEST['mailinfo'])):							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
			<br />
			<div style="padding-left:22px">

			<a href="<?php echo esc_url(add_query_arg('ppr_tab', 'notification', add_query_arg('mailinfo', '1', $uri)));?>"><?php esc_html_e('Show Notification Log / Buffer', 'revisionary');?></a>
			<br /><br />
			<a href="<?php echo esc_url(add_query_arg('ppr_tab', 'notification', add_query_arg('verbose', '1', add_query_arg('mailinfo', '1', $uri))));?>"><?php esc_html_e('Show with message content', 'revisionary');?></a>
			</div>
		<?php endif;
		?>

		<?php
		echo '</div>';

		$this->closeSubsection();
		?>
	</div></td></tr></table>
<?php endif; // any options accessable in this section


$section = 'notifications';				// --- NOTIFICATIONS SECTION ---

if (!defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !empty( $this->form_options[$tab][$section] ) ) :?>
	<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">
	
	<!-- CTA Section -->
	<div class="pp-cta-section">
		<h4>
			<?php esc_html_e('Ready to enhance your revision notifications?', 'revisionary'); ?>
		</h4>
		<p>
			<?php esc_html_e('Upgrade to Revisions Pro for integration with our PublishPress Planner Notifications framework.', 'revisionary'); ?>
		</p>

		<div class="pp-revisions-pro-features">
			<ul>
				<li>
					&nbsp;<?php _e('Customize notification message and subject', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('Notify for a specific post type, category or term', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('Target specific roles, users, or user groups', 'revisionary');?>
				</li>
				<li>
					&nbsp;<?php _e('With PublishPress Planner Pro, send notifications to a Slack channel', 'revisionary');?>
				</li>
			</ul>
		</div>

		<div class="pp-cta-buttons">
			<a href="https://publishpress.com/revisions/" 
				class="button-primary button-large pp-upgrade-btn" 
				target="_blank">
				<?php esc_html_e('Upgrade to Pro', 'revisionary'); ?>
			</a>
			<a href="https://publishpress.com/knowledge-base/advanced-revisions-notifications/" 
				target="_blank"
				class="pp-learn-more-link">
				<?php esc_html_e('Learn More', 'revisionary'); ?>
			</a>
		</div>
	</div>

	<?php if (!empty($_REQUEST['rvy_promo_img'])):?>
	<br>
	<div class="pp-integration-card">
	<div style="border: 1px solid #ccc; border-radius: 8px">
	<img src="<?php echo esc_url(plugins_url('', REVISIONARY_FILE) . '/admin/revision-notifications.png');?>" style="width: 100%" />
	</div>

	<div class="pp-upgrade-overlay">
		<h4><?php esc_html_e('Pro Feature', 'revisionary'); ?></h4>
		<p><?php esc_html_e('Upgrade to Revisions Pro to unlock Planner Notifications integration.', 'revisionary');?></p>
		<p><?php esc_html_e('Customize notification content and recipients for each notification type.', 'revisionary');?></p>
		<div class="pp-upgrade-buttons">
			<a href="<?php echo esc_url('https://publishpress.com/knowledge-base/customize-email-notifications/'); ?>" target="_blank" class="pp-upgrade-btn-secondary">
				<?php esc_html_e('Learn More', 'revisionary'); ?>
			</a>

			<a href="https://publishpress.com/links/revisions-banner/" target="_blank" class="pp-upgrade-btn-primary">
			<?php esc_html_e('Upgrade to Pro', 'revisionary');?>
			</a>
		</div>
	</div>
	</div>
	<?php endif;?>

	</div></td></tr></table>
<?php endif; // any options accessable in this section


	$section = 'revisions';			// --- REVISIONS SECTION ---

	if ( ! empty( $this->form_options[$tab][$section] ) ) :?>
		<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

		<?php
		$hint = esc_html__('Some themes may block revision preview.', 'revisionary');
		$this->option_checkbox( 'revision_preview_links', $tab, $section, $hint, '' );

		$preview_links = rvy_get_option('revision_preview_links');

		$id = 'preview_link_type';
		if ( in_array( $id, $this->form_options[$tab][$section] ) ) {
			$this->all_options []= $id;
			$current_setting = rvy_get_option($id, $sitewide, $customize_defaults);
			?>
			<div id="rvy_preview_options" <?php if (!$preview_links) echo 'display: none;';?>">
			<label for="<?php echo esc_attr($id);?>"><?php echo esc_html($this->option_captions[$id]);?>: </label>

			<select name="<?php echo esc_attr($id);?>" id="<?php echo esc_attr($id);?>" autocomplete="off">

			<?php
			$captions = [
				'' => esc_html__('Published Post Slug', 'revisionary'), 
				'revision_slug' => esc_html__('Revision Slug', 'revisionary'), 
				'id_only' => esc_html__('Revision ID only', 'revisionary') 
			];

			foreach ( $captions as $key => $value) {
				$selected = ( $current_setting == $key ) ? 'selected' : '';
				echo "\n\t<option value='" . esc_attr($key) . "' " . esc_attr($selected) . ">" . esc_html($captions[$key]) . "</option>";
			}
			?>
			</select>&nbsp;

			<?php
			if ( $this->display_hints ) : ?>
				<br />
				<div class="rvy-subtext">
				<?php
				esc_html_e('Some themes or plugins may require Revision Slug or Revision ID link type for proper template loading and field display.', 'revisionary');
				?>
				</div>
			<?php endif;

			do_action('revisionary_option_ui_preview_options', $this, $sitewide, $customize_defaults);

			if (defined('RVY_PREVIEW_ARG_LOCKED') && defined('RVY_PREVIEW_ARG')) {
				printf(
					esc_html__(
						'The revision preview argument is configured by constant definition: %s',
						'revisionary'
					),
					esc_html(RVY_PREVIEW_ARG)
				);
			} else {
				$hint = esc_html__('Adjust preview links to use "rv_preview" argument instead of "preview". Experiment to see which works best with your theme.', 'revisionary');
				$this->option_checkbox( 'preview_link_alternate_preview_arg', $tab, $section, $hint, '' );
			}

			$hint = esc_html__('Some themes may require this setting for correct revision preview display.', 'revisionary');
			$this->option_checkbox( 'home_preview_set_home_flag', $tab, $section, $hint, '' );
			?>
			</div>
			
			<script type="text/javascript">
			/* <![CDATA[ */
			jQuery(document).ready( function($) {
				$('#revision_preview_links').on('click', function(e) {
					$('#rvy_preview_options').toggle($(e).prop('checked'));
				});
			});
			/* ]]> */
			</script>
			<?php
		}
		?>
		<br><br>

		<?php
		$checkbox_args = [];

		if (defined('PUBLISHPRESS_CAPS_VERSION')) {
			$url = admin_url('admin.php?page=pp-capabilities&pp_caps_tab=publishpress-revisions');
	
			$cap_caption = sprintf(__('%s capability', 'revisionary'), 'edit_others_drafts');

			if (rvy_get_option('require_edit_others_drafts')) {
				$link = $revisionary->admin->tooltipText(
					"<a href='$url'>" . $cap_caption . '</a>',
					__('Assign capability to roles', 'revisionary')
				);
			} else {
				$link = $cap_caption;
			}

			$hint = sprintf(
				__('If the user can\'t publish, require the %s to edit others\' unpublished Posts.', 'revisionary'),
				$link
			);

			$checkbox_args['no_escape'] = true;
		} else {
			$hint = esc_html__('If the user can\'t publish, require the edit_others_drafts capability to edit others\' unpublished Posts.', 'revisionary');
		}

		$this->option_checkbox( 'require_edit_others_drafts', $tab, $section, $hint, '', $checkbox_args );
		
		if (defined('PRESSPERMIT_VERSION') && version_compare(PRESSPERMIT_VERSION, '4.4.3-beta2', '>=')) {
			$hint = __('If post-specific permissions restrict or expand access to a post, apply those permissions to its revisions also.', 'revisionary');
			$this->option_checkbox('apply_post_exceptions', $tab, $section, $hint, '');
		}

		$hint = '';
		$this->option_checkbox( 'diff_display_strip_tags', $tab, $section, $hint, '' );

		if (class_exists('RankMath')) {
			echo '<br>';
			$hint = esc_html__('Rank Math SEO may prevent Revision update on some sites.', 'revisionary');
			$this->option_checkbox( 'revision_edit_disable_rank_math', $tab, $section, $hint, '' );
		}

		if ((defined('REVISIONARY_PRO_VERSION') || defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) && defined('ICL_SITEPRESS_VERSION') && defined('WPML_TM_VERSION')) :?>

		<div>
			<h3><?php esc_html_e('WPML Translation Management', 'revisionary') ?></h3>
			<p>
			<?php
			$url = admin_url('admin.php?page=revisionary-settings&rvy_wpml_sync_needs_update=1');
			?>
			<a href="<?php echo esc_url($url);?>"><?php esc_html_e('Sync "Needs Update" flags', 'revisionary');?></a>

			<div class="rvy-subtext">
			<?php
			esc_html_e('Set "Needs Update" for any post with translations which was updated (possibly by revision approval) more recently than its translations.', 'revisionary');
			?>
			</div>

			</p>
		</div>

		<?php endif;?>

		<?php
		$hint = esc_html__( 'Show descriptive captions for PublishPress Revisions settings.', 'revisionary' );
		$this->option_checkbox( 'display_hints', $tab, $section, $hint, '' );

		$hint = esc_html__('Plugin settings, Revision Queue contents and related data will be deleted, but only after the last copy of Revisions / Revisions Pro is deleted.', 'revisionary');
		$this->option_checkbox('delete_settings_on_uninstall', $tab, $section, $hint);
		?>

	</div></td></tr></table>
	<?php endif; // any options accessable in this section


	$section = 'integrations';			// --- INTEGRATIONS SECTION ---

	/*if ( ! empty( $this->form_options[$tab][$section] ) ) :*/?>
		<table class="form-table rs-form-table" id="<?php echo esc_attr("ppr-tab-$section");?>"<?php echo ($setActiveTab != $section) ? ' style="display:none;"' : '' ?>><tr><td><div class="rvy-opt-wrap">

		<?php
			do_action('revisionary_integrations_ui', $this);
		?>

		<?php if (!defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')): ?>
			<div class="pp-integrations-upgrade-cta">
				<div class="pp-pro-banner">
					<div>
						<h2><?php esc_html_e('Unlock Pro Integrations', 'revisionary');?></h2>
						<p><?php esc_html_e("Upgrade to the Pro version for optimal compatibility and prompt, professional support.", 'revisionary');?></p>
					</div>
					<div class="pp-pro-badge-banner">
						<a href="<?php echo self::UPGRADE_PRO_URL; ?>" target="_blank" class="pp-upgrade-btn">
							<?php esc_html_e('Upgrade to Pro', 'revisionary'); ?>
						</a>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="pp-integrations-container">
			<!-- Category Filters -->
			<div class="pp-category-labels">
				<div class="pp-category-label active" data-category="all">
					<?php esc_html_e('All', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="admin">
					<?php esc_html_e('Admin', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="builder">
					<?php esc_html_e('Builder', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="cache">
					<?php esc_html_e('Cache', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="ecommerce">
					<?php esc_html_e('E-Commerce', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="fields">
					<?php esc_html_e('Fields', 'revisionary'); ?>
				</div>
				<!--
				<div class="pp-category-label" data-category="multilingual">
					<?php esc_html_e('Multilingual', 'revisionary'); ?>
				</div>
				-->
				<div class="pp-category-label" data-category="seo">
					<?php esc_html_e('SEO', 'revisionary'); ?>
				</div>
				<div class="pp-category-label" data-category="workflow">
					<?php esc_html_e('Workflow', 'revisionary'); ?>
				</div>
			</div>

			<div class="pp-integrations-grid">
				<?php
					// Fallback to hardcoded integrations if registry not available
					$this->renderIntegrations();
				?>
			</div>
		</div>

		<script type="text/javascript">
			jQuery(function ($) {
				// Category filtering
				$(".pp-category-label").on("click", function () {
					$(".pp-category-label").removeClass("active");
					$(this).addClass("active");
					const category = $(this).data("category");
					$(".pp-integration-card").each(function () {
						const categories = ($(this).data("categories") || "all")
							.toString()
							.split(",");
						if (category === "all" || categories.includes(category)) {
							$(this).removeClass("pp-hidden");
						} else {
							$(this).addClass("pp-hidden");
						}
					});
				});

				// Disabled checkbox upgrade message
				$('.pp-integration-card.pp-disabled input[type="checkbox"]').on(
					"click",
					function (e) {
						e.preventDefault();
						const card = $(this).closest(".pp-integration-card");
						card
							.find(".pp-upgrade-overlay")
							.css("opacity", "1")
							.delay(3000)
							.animate({ opacity: "0" }, 500);
						if (!card.find(".pp-temp-message").length) {
							$(
								'<div class="pp-temp-message" style="position:absolute;top:10px;right:10px;background:#ff5722;color:white;padding:5px 10px;border-radius:3px;font-size:12px;z-index:999;">Pro Feature</div>'
							)
								.appendTo(card)
								.delay(2000)
								.fadeOut(500, function () {
									$(this).remove();
								});
						}
					}
				);
			});
		</script>

		</div></td></tr></table>
	<?php /*endif;*/ // any options accessable in this section
	?>
</div>

</div>

<?php


// ------------------------- BEGIN Option Scope tab ---------------------------------
$tab = 'optscope';

if ( $sitewide ) : ?>
<?php
echo "<div id='rvy-optscope' style='clear:both;margin:0' class='rs-options agp_js_hide " . esc_attr($color_class) . "'>";

echo '<ul>';
$all_movable_options = array();

unset($available_form_options['features']['license']);

foreach ( $available_form_options as $tab_name => $sections ) {
	echo '<li>';

	$explanatory_caption = __( 'Specify which PublishPress Revisions Settings to control network-wide. Unselected settings are controlled separately on each site.', 'revisionary' );

	if ( isset( $this->tab_captions[$tab_name] ) )
		$tab_caption = $this->tab_captions[$tab_name];
	else
		$tab_caption = $tab_name;

	echo '<div style="margin:1em 0 1em 0">';
	if ( count( $available_form_options ) > 1 ) {
		if ( $this->display_hints )
			printf( esc_html(_x( '%1$s%2$s%3$s (%4$s)', 'opentag option_tabname closetag (explanatory note)', 'revisionary' )), '<span class="rs-h3text">', esc_html($tab_caption), '</span>', esc_html($explanatory_caption) );
		else
			echo esc_html($tab_caption);
	} elseif ( $this->display_hints ) {
		echo esc_html($explanatory_caption);
	}

	echo '</div>';

	echo '<ul style="margin-left:2em">';

	foreach ( $sections as $section_name => $option_names ) {
		if ( empty( $sections[$section_name] ) )
			continue;

		echo '<li><strong>';

		if ( isset( $this->section_captions[$tab_name][$section_name] ) )
			echo esc_html($this->section_captions[$tab_name][$section_name]);
		else
			echo esc_html(ucwords(str_replace('_', ' ', $section_name)));

		echo '</strong><ul style="margin-left:2em">';

		foreach ( $option_names as $option_name ) {
			if ( $option_name && !empty($this->option_captions[$option_name]) ) {
				$all_movable_options []= $option_name;
				echo '<li>';

				$disabled = ( in_array( $option_name, array( 'file_filtering', 'mu_sitewide_groups' ) ) ) ? "disabled" : '';

				$id = "{$option_name}_sitewide";
				$val = isset( $rvy_options_sitewide[$option_name] );
				echo "<label for='" . esc_attr($id) . "'>";
				echo "<input name='rvy_options_sitewide[]' type='checkbox' id='" . esc_attr($id) . "' value='" . esc_attr($option_name) . "' " . esc_attr($disabled) . " " . checked('1', $val, false) . " />";

				printf( esc_html__( 'network-wide control of "%s"', 'revisionary' ), esc_html($this->option_captions[$option_name]) );

				echo '</label></li>';
			}
		}

		echo '</ul><br />';
	}
	echo '</ul><br /><hr />';
}
echo '</ul>';

echo '</div>';

$all_movable_options = implode(',', $all_movable_options);
echo "<input type='hidden' name='rvy_all_movable_options' value='" . esc_attr($all_movable_options) . "' />";

endif; // any options accessable in this tab
// ------------------------- END Option Scope tab ---------------------------------


$this->all_options = implode(',', $this->all_options);
echo "<input type='hidden' name='all_options' value='" . esc_attr($this->all_options) . "' />";

echo "<input type='hidden' name='rvy_submission_topic' value='options' />";
?>
<p class="submit">
<input type="submit" name="rvy_submit" class="button button-primary" value="<?php echo esc_attr__('Save Changes', 'revisionary');?>" />
<input type="submit" name="rvy_defaults" class="button button-secondary" value="<?php echo esc_attr__('Revert to Defaults', 'revisionary') ?>" onclick="<?php 
echo "javascript:if (confirm('" 
. esc_html__( "All settings in this form (including those on unselected tabs) will be reset to DEFAULTS.  Are you sure?", 'revisionary' ) 
. "')) {return true;} else {return false;}";
?>" style="float:right;" />
</p>

</div>

<?php do_action('publishpress_revisions_settings_sidebar');?>

</div>

</div>

</div>

</form>

<p style='clear:both'></p>

<?php
do_action('revisionary_admin_footer');
?>

</div>

<?php
} // end function


private function loadIntegrations() {
	// Fallback integrations when CompatibilityRegistry is not available
	$this->defined_integrations = \PublishPress\Revisions::getDefinedIntegrations();
	
	$this->available_integrations = [];

	foreach ($this->defined_integrations as $i => $integration) {
		if ($this->defined_integrations[$i]['available']) {
			$this->available_integrations[$integration['id']] = true;
		}
	}
}

private function renderCompatibilityPack($integration)
{
	$is_pro = defined('PUBLISHPRESS_REVISIONS_PRO_VERSION');
	$is_enabled = $is_pro;

	$is_disabled = !$is_pro || !$integration['available'];
	$is_checked = true;
	$card_class = $is_disabled ? 'pp-integration-card pp-disabled' : 'pp-integration-card';

	if ($integration['available']) {
		$card_class .= ' pp-available';
	}

	if ($integration['free']) {
		$card_class .= ' pp-free';
	}

	$icon_class = 'pp-integration-icon ' . $integration['icon_class'];
	$categories_string = implode(',', $integration['categories']);

	// Determine category tag
	$category_tag = '';
	if (in_array('builder', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-builder">' . esc_html__('Builder', 'revisionary') . '</div>';
	}  elseif (in_array('admin', $integration['categories'])) {
		$category_tag = '<span class="pp-category-tag pp-tag-admin">' . esc_html__('Admin', 'revisionary') . '</span>';
	} elseif (in_array('cache', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-cache">' . esc_html__('Cache', 'revisionary') . '</div>';
	} elseif (in_array('seo', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-seo">' . esc_html__('SEO', 'revisionary') . '</div>';
	} elseif (in_array('ecommerce', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-ecommerce">' . esc_html__('Commerce', 'revisionary') . '</div>';
	} elseif (in_array('fields', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-fields">' . esc_html__('Fields', 'revisionary') . '</div>';
	} elseif (in_array('multilingual', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-multilingual">' . esc_html__('Multilang', 'revisionary') . '</div>';
	} elseif (in_array('community', $integration['categories'])) {
		$category_tag = '<div class="pp-category-tag pp-tag-community">' . esc_html__('Community', 'revisionary') . '</div>';
	} elseif (in_array('workflow', $integration['categories'])) {
		$category_tag = '<span class="pp-category-tag pp-tag-workflow">' . esc_html__('Workflow', 'revisionary') . '</span>';
	}
	?>
	<div class="<?php echo esc_attr($card_class); ?>" data-categories="<?php echo esc_attr($categories_string); ?>">
		<div class="pp-integration-icon-wrap">
			<div class="pp-integration-icon <?php echo esc_attr($integration['icon_class']); ?>">
			</div>

			<?php echo $category_tag; ?>
		</div>

		<div class="pp-integration-content">
			<h3 class="pp-integration-title">
				<?php echo esc_html($integration['title']); ?>
				
				<?php if (!$is_pro && !$integration['free']): ?>
					<span class="pp-badge pp-pro-badge"><?php esc_html_e('Pro', 'revisionary');?></span>
				<?php endif; ?>

				<?php if (!$integration['available']): ?>
					<!--<span class="pp-badge"
						style="background: #9e9e9e;"><?php esc_html_e('Supported', 'revisionary'); ?></span>-->
				<?php else: ?>
					<span class="pp-badge"
						style="background: #5e92c4;"><?php esc_html_e('Active Plugin', 'revisionary'); ?></span>
				<?php endif; ?>
			</h3>

			<?php if (strlen($integration['description']) > 1):?>
			<p class="pp-integration-description"><?php echo esc_html($integration['description']); ?></p>
			<?php endif;?>

			<div class="pp-integration-features">
				<ul>
					<!--
					<?php if (!empty($integration['free'])) :?>
						<li><?php esc_html_e('Supported by PublishPress Revisions', 'revisionary');?></li>
					<?php else :?>
						<li><?php esc_html_e('Supported by Revisions Pro', 'revisionary');?></li>
					<?php endif;?>
					-->

					<?php foreach ($integration['features'] as $feature): ?>
						<li><?php echo esc_html($feature); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php if (!$integration['free'] && $integration['available']):?>
				<div class="pp-settings-toggle">
					<?php if ($is_pro && $is_enabled): ?>
						<div class="pp-integration-status active"><?php esc_html_e('Integration Active', 'revisionary'); ?></div>
					<?php else: ?>
						<div class="pp-integration-status disabled"><?php esc_html_e('Upgrade to Pro to enable this integration', 'revisionary'); ?></div>
					<?php endif; ?>
				</div>
			<?php endif;?>
		</div>

		<?php if (!$is_pro && !$integration['free']): ?>
			<div class="pp-upgrade-overlay">
				<h4><?php esc_html_e('Pro Feature', 'revisionary'); ?></h4>
				<p><?php echo esc_html(sprintf(__('Unlock %s integration to enhance your revisions solution.', 'revisionary'), $integration['title'])); ?>
				</p>
				<div class="pp-upgrade-buttons">
					<?php if (!empty($integration['learn_more_url'])): ?>
						<a href="<?php echo esc_url($integration['learn_more_url']); ?>" target="_blank" class="pp-upgrade-btn-secondary">
							<?php esc_html_e('Learn More', 'revisionary'); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url(self::UPGRADE_PRO_URL); ?>" target="_blank" class="pp-upgrade-btn-primary">
						<?php esc_html_e('Upgrade to Pro', 'revisionary'); ?>
					</a>
				</div>
			</div>

		<?php elseif ($is_pro && $integration['available'] && !empty($integration['learn_more_url'])): ?>
			<div class="pp-upgrade-overlay">
				<h4><?php $integration['free'] ? esc_html_e('Active Plugin', 'revisionary') : esc_html_e('Active Plugin Integration', 'revisionary'); ?></h4>
				<div class="pp-upgrade-buttons">
						<a href="<?php echo esc_url($integration['learn_more_url']); ?>" target="_blank" class="pp-upgrade-btn-secondary">
							<?php esc_html_e('Learn More', 'revisionary'); ?>
						</a>
				</div>
			</div>

		<?php elseif (!$integration['free'] && !empty($integration['learn_more_url'])): ?>
			<div class="pp-upgrade-overlay">
				<h4><?php esc_html_e('Supported Plugin Integration', 'revisionary'); ?></h4>
				<div class="pp-upgrade-buttons">
						<a href="<?php echo esc_url($integration['learn_more_url']); ?>" target="_blank" class="pp-upgrade-btn-secondary">
							<?php esc_html_e('Learn More', 'revisionary'); ?>
						</a>
				</div>
			</div>

		<?php elseif ($integration['free'] && !empty($integration['learn_more_url'])): ?>
			<div class="pp-upgrade-overlay">
				<h4><?php esc_html_e('Supported Plugin', 'revisionary'); ?></h4>
				<div class="pp-upgrade-buttons">
						<a href="<?php echo esc_url($integration['learn_more_url']); ?>" target="_blank" class="pp-upgrade-btn-secondary">
							<?php esc_html_e('Learn More', 'revisionary'); ?>
						</a>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

private function renderIntegrations()
{
	$int = array_merge(
		wp_filter_object_list($this->defined_integrations, ['available' => true, 'free' => false]),
		wp_filter_object_list($this->defined_integrations, ['available' => false, 'free' => false])
		//wp_filter_object_list($this->defined_integrations, ['available' => true, 'free' => true]),
		//wp_filter_object_list($this->defined_integrations, ['available' => false, 'free' => true])
	);

	// Render each fallback integration
	foreach ($int as $integration) {
		$this->renderCompatibilityPack($integration);
	}
}


} // end class RvyOptionUI


function rvy_options( $sitewide = false, $customize_defaults = false ) {
	$ui = RvyOptionUI::instance(compact('sitewide', 'customize_defaults'));
	$ui->options_ui($sitewide, $customize_defaults);
}
