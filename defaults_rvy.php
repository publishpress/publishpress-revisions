<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();

/**
 * @package     PublishPress\Revisions\RevisionaryOptions
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2025 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

// Setting scope: For Network installations, which Revisionary options should default to site-wide control?
function rvy_default_options_sitewide() {
	$def = array(
		'revision_queue_capability' => true,
		'manage_unsubmitted_capability' => true,
		'copy_posts_capability' => true,
		'revision_statuses_noun_labels' => true,
		'caption_copy_as_edit' => true,
		'pending_revisions' => true,
		'auto_submit_revisions' => true,
		'revise_posts_capability' => true,
		'scheduled_revisions' => true,
		'scheduled_publish_cron' => true,
		'async_scheduled_publish' => true,
		'wp_cron_usage_detected' => false,
		'pending_rev_notify_admin' => true,
		'pending_rev_notify_author' => true,
		'rev_approval_notify_admin' => true,
		'rev_approval_notify_author' => true,
		'rev_approval_notify_revisor' => true,
		'publish_scheduled_notify_admin' => true,
		'publish_scheduled_notify_author' => true,
		'publish_scheduled_notify_revisor' => true,
		'use_publishpress_notifications' => true,
		'use_notification_buffer' => true,
		'display_hints' => true,
		'revisor_role_add_custom_rolecaps' => true,
		'revisor_lock_others_revisions' => true,
		'revisor_hide_others_revisions' => true,
		'admin_revisions_to_own_posts' => true,
		'require_edit_others_drafts' => true,
		'diff_display_strip_tags' => false,
		'scheduled_revision_update_post_date' => true,
		'scheduled_revision_update_modified_date' => true,
		'pending_revision_update_post_date' => true,
		'pending_revision_update_modified_date' => true,
		'edd_key' => true,
		'revision_preview_links' => true,
		'preview_link_type' => true,
		'preview_link_alternate_preview_arg' => true,
		'home_preview_set_home_flag' => true,
		'compare_revisions_direct_approval' => true,
		'revision_update_notifications' => true,
		'trigger_post_update_actions' => true,
		'copy_revision_comments_to_post' => true,
		'past_revisions_order_by' => true,
		'list_unsubmitted_revisions' => true,
		'rev_publication_delete_ed_comments' => true,
		'deletion_queue' => true,
		'revision_archive_deletion' => true,
		'revision_restore_require_cap' => true,
		'revision_limit_per_post' => true,
		'revision_unfiltered_html_check' => true,
		'revision_limit_compat_mode' => true,
		'permissions_compat_mode' => true,
		'planner_notifications_access_limited' => false,
		'archive_postmeta' => true,
		'extended_archive' => true,
		'delete_settings_on_uninstall' => true,
		'apply_post_exceptions' => true,
		'legacy_notifications' => true,
		'approve_button_verbose' => true,
		'create_revision_direct_link' => true,
		'revision_edit_disable_rank_math' => true,
	);

	if ( $other_options = array_diff_key( rvy_default_options(), $def ) ) {
		$def = array_merge( $def, array_fill_keys( array_keys($other_options), true ) );
	}

	return $def;	
}
 
// Default values for Revisionary settings
function rvy_default_options() {
	$def = array(
		'revision_queue_capability' => 0,
		'manage_unsubmitted_capability' => 0,
		'copy_posts_capability' => 0,
		'revision_statuses_noun_labels' => 0,
		'caption_copy_as_edit' => 0,
		'pending_revisions' => 1,
		'auto_submit_revisions' => 0,
		'revise_posts_capability' => 0,
		'scheduled_revisions' => 1,
		'scheduled_publish_cron' => 1,
		'async_scheduled_publish' => 1,
		'wp_cron_usage_detected' => 0,
		'pending_rev_notify_admin' => 1,
		'pending_rev_notify_author' => 1,
		'rev_approval_notify_admin' => 0,
		'rev_approval_notify_author' => 1,
		'rev_approval_notify_revisor' => 1,
		'publish_scheduled_notify_admin' => 1,
		'publish_scheduled_notify_author' => 1,
		'publish_scheduled_notify_revisor' => 1,
		'use_publishpress_notifications' => 0,
		'use_notification_buffer' => 1,
		'display_hints' => 1,
		'revisor_role_add_custom_rolecaps' => 1,
		'revisor_lock_others_revisions' => 1,
		'revisor_hide_others_revisions' => 1,
		'admin_revisions_to_own_posts' => 1,
		'require_edit_others_drafts' => 1,
		'diff_display_strip_tags' => 0,
		'scheduled_revision_update_post_date' => 1,
		'pending_revision_update_post_date' => 0,
		'scheduled_revision_update_modified_date' => 1,
		'pending_revision_update_modified_date' => 0,
		'edd_key' => '',
		'revision_preview_links' => 1,
		'preview_link_type' => 'published_slug',
		'preview_link_alternate_preview_arg' => 1,
		'home_preview_set_home_flag' => 0,
		'compare_revisions_direct_approval' => 0,
		'revision_update_notifications' => 0,
		'trigger_post_update_actions' => 0,
		'copy_revision_comments_to_post' => 0,
		'past_revisions_order_by' => '',
		'list_unsubmitted_revisions' => 0,
		'rev_publication_delete_ed_comments' => 0,
		'deletion_queue' => 0,
		'revision_archive_deletion' => 0,
		'revision_restore_require_cap' => 0,
		'revision_limit_per_post' => 0,
		'revision_unfiltered_html_check' => 0,
		'revision_limit_compat_mode' => 0,
		'permissions_compat_mode' => 0,
		'planner_notifications_access_limited' => 0,
		'archive_postmeta' => 0,
		'extended_archive' => 0,
		'delete_settings_on_uninstall' => 0,
		'apply_post_exceptions' => 0,
		'legacy_notifications' => 0,
		'approve_button_verbose' => 0,
		'create_revision_direct_link' => 0,
		'revision_edit_disable_rank_math' => 1,
	);

	return $def;
}

function rvy_po_trigger( $string ) {
	return $string;	
}
