<?php
/** Storage-schema requirements shared by Revisions Free and Pro. */
if (!defined('ABSPATH')) { exit; }

function rvy_builder_schema_message($with_link = true) {
    if (!$with_link) {
        return __('Page Builder integration requires Revisions Pro to be set to standard storage schema.', 'revisionary');
    }
    return sprintf(
        esc_html__('Page Builder integration requires Revisions Pro to be set to %s.', 'revisionary'),
        '<a href="' . esc_url(admin_url('admin.php?page=revisionary-settings&ppr_tab=working_copy#ppr-tab-working_copy')) . '">'
            . esc_html__('standard storage schema', 'revisionary') . '</a>'
    );
}

/** Detect actual builder documents, without loading any Pro workflow code. */
function rvy_builder_schema_blocked($post) {
    if (!rvy_get_option('permissions_compat_mode')) { return false; }
    $post = get_post($post);
    if (!$post) { return false; }
    $id = $post->ID;
    $content = $post->post_content;
    return (defined('ELEMENTOR_VERSION') && ('builder' === get_post_meta($id, '_elementor_edit_mode', true) || metadata_exists('post', $id, '_elementor_data')))
        || (defined('FL_BUILDER_VERSION') && (get_post_meta($id, '_fl_builder_enabled', true) || metadata_exists('post', $id, '_fl_builder_data')))
        || ((defined('ET_BUILDER_VERSION') || defined('ET_BUILDER_PLUGIN_VERSION') || function_exists('et_divi_replace_parent_stylesheet'))
            && ('on' === get_post_meta($id, '_et_pb_use_builder', true) || preg_match('/\[et_pb_|<!--\s+wp:divi\//', $content)))
        || (defined('WPB_VC_VERSION') && ('true' === get_post_meta($id, '_wpb_vc_js_status', true) || preg_match('/\[vc_[a-z_]+\b/', $content)))
        || ((defined('SITEORIGIN_PANELS_VERSION') || class_exists('SiteOrigin_Panels')) && metadata_exists('post', $id, 'panels_data'))
        || ((defined('SEEDPROD_VERSION') || defined('SEEDPROD_PRO_VERSION') || defined('SEEDPROD_PLUGIN_PATH'))
            && (get_post_meta($id, '_seedprod_page', true) || get_post_meta($id, '_seedprod_edited_with_seedprod', true)))
        || (defined('__BREAKDANCE_VERSION') && (metadata_exists('post', $id, '_breakdance_data') || 'breakdance' === get_post_meta($id, '_breakdance_page_settings', true)))
        || (defined('BRICKS_VERSION') && (metadata_exists('post', $id, '_bricks_page_content_2') || 'bricks' === get_post_meta($id, '_bricks_editor_mode', true)));
}

function rvy_builder_schema_notice($dismissible = true) {
    if (!rvy_get_option('permissions_compat_mode')) { return; }
    echo '<div class="notice notice-warning rvy-builder-schema-notice' . ($dismissible ? ' is-dismissible' : ' inline') . '"><p>'
        . rvy_builder_schema_message() . '</p></div>';
}
/** Settings render their own inline messages; WordPress hooks pass an empty argument. */
function rvy_builder_schema_admin_notice() {
    global $plugin_page;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = !empty($plugin_page) ? $plugin_page : (isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '');
    if (in_array($page, ['revisionary-settings', 'rvy-net_options', 'rvy-default_options'], true)) {
        return;
    }
    rvy_builder_schema_notice(true);
}
add_action('admin_notices', 'rvy_builder_schema_admin_notice');
add_action('network_admin_notices', 'rvy_builder_schema_admin_notice');
// Apply even for administrators, whose ordinary Revisions capability filters are bypassed.
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    return 'copy_post' === $cap && !empty($args[0]) && rvy_builder_schema_blocked($args[0])
        ? ['do_not_allow'] : $caps;
}, PHP_INT_MAX, 4);

/** Reconcile only to the selected schema, never normalize enhanced mode to draft/pending. */
function rvy_sync_revision_storage_schema() {
    if (rvy_get_option('permissions_compat_mode')) {
        rvy_bulk_apply_revision_statuses();
    } else {
        rvy_bulk_remove_revision_statuses();
    }
    delete_option('rvy_storage_schema_pending');
}

// WordPress silently reactivates updates without activation hooks. The pending
// marker also covers reinstalling the same version, which has no version change.
add_action('init', function () {
    if (get_option('rvy_storage_schema_pending')) {
        rvy_sync_revision_storage_schema();
    }
}, 50);
