<?php
if (get_option('rvy_delete_settings_on_uninstall')) {
    global $wpdb;

    // Since stored settings are shared among all installed versions, 
    // all copies of the plugin (both Pro and Free) need to be deleted (not just deactivated) before deleting any settings.
    $revisions_plugin_count = 0;

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $_plugins = get_plugins();
    
    foreach($_plugins as $_plugin) {
        if (!empty($_plugin['Title']) && in_array($_plugin['Title'], ['PublishPress Revisions', 'PublishPress Revisions Pro'])) {
            $revisions_plugin_count++;
        }
    }
    
    if ($revisions_plugin_count === 1) {
        $orig_site_id = get_current_blog_id();
        
        $site_ids = (function_exists('get_sites')) ? get_sites(['fields' => 'ids']) : (array) $orig_site_id;
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        foreach ($site_ids as $_blog_id) {
            if (is_multisite()) {
            	switch_to_blog($_blog_id);
            }

            if (!empty($wpdb->options)) {
                @$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'rvy_%'");
                @$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_rvy_%'");
                @$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_rvy'");
                @$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%revisionary_%'");
            }

            delete_option('revisionary_last_version');
            delete_option('_pp_statuses_planner_default_revision_notifications');
            delete_option('pp_revisions_beta3_option_sync_done');

            wp_load_alloptions(true);
            
            if (!empty($wpdb->postmeta)) {
                @$wpdb->query(
                    "DELETE FROM $wpdb->postmeta WHERE meta_key IN ("
                    . "'_rvy_revision_status', '_rvy_base_post_id', '_rvy_imported_revision', '_rvy_has_revisions', '_rvy_published_gmt', '_rvy_author_selection', '_rvy_prev_revision_status', '_rvy_approved_by', '_rvy_subpost_original_source_id', '_rvy_deletion_request_date'"
                    . ")"
                );
            }

            if ($revision_ids = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_mime_type IN ('draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected')")) {
                $id_csv = implode("','", array_map('intval', $revision_ids));
                
                $wpdb->query("DELETE FROM $wpdb->posts WHERE ID IN ('$id_csv') AND post_mime_type IN ('draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected')");   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id IN ('$id_csv')");       // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                
                wp_cache_flush();
            }
        }

        if (is_multisite()) {
        	switch_to_blog($orig_site_id);
        }
    }
}
