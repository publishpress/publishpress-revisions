<?php
/** Storage-schema requirements shared by Revisions Free and Pro. */
defined('ABSPATH') || exit;

function rvy_print_builder_schema_message($with_link = true) {
    if (!$with_link) {
        _e('Page Builder integration requires Revisions Pro to be set to standard storage schema.', 'revisionary');
    } else {
        printf(
            esc_html__('Page Builder integration requires Revisions Pro to be set to %s.', 'revisionary'),
            '<a href="' . esc_url(admin_url('admin.php?page=revisionary-settings&ppr_tab=working_copy#ppr-tab-working_copy')) . '">'
                . esc_html__('standard storage schema', 'revisionary') . '</a>'
        );
    }
}

/** Return whether a supported page builder is active on this site. */
function rvy_builder_schema_has_supported_builder() {
    return (bool) rvy_builder_schema_active_builder_titles();
}

/** Check whether the current user has dismissed the schema warning. */
function rvy_builder_schema_notice_is_dismissed() {
    $user_id = get_current_user_id();
    return $user_id && '1' === get_user_option(rvy_builder_schema_notice_dismissal_key(), $user_id);
}

/** Persist dismissal for the current user on this site. */
function rvy_builder_schema_notice_dismiss() {
    check_ajax_referer('rvy_builder_schema_notice');
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(null, 403);
    }
    update_user_option($user_id, rvy_builder_schema_notice_dismissal_key(), '1');
    wp_send_json_success();
}
add_action('wp_ajax_rvy_builder_schema_notice_dismiss', 'rvy_builder_schema_notice_dismiss');

/** Store the user's dismissal when WordPress removes the admin notice. */
function rvy_builder_schema_notice_dismiss_script() {
    wp_enqueue_script('common');
    $config = wp_json_encode([
        'action' => 'rvy_builder_schema_notice_dismiss',
        '_ajax_nonce' => wp_create_nonce('rvy_builder_schema_notice'),
    ]);
    if (!is_string($config)) {
        return;
    }
    wp_add_inline_script('common', 'window.rvyBuilderSchemaNoticeDismiss = ' . $config . ';', 'before');
    wp_add_inline_script(
        'common',
        'document.addEventListener("click", function (event) {'
            . 'var dismiss = event.target.closest ? event.target.closest(".rvy-builder-schema-notice .notice-dismiss") : null;'
            . 'if (!dismiss) { return; }'
            . 'var data = new URLSearchParams(window.rvyBuilderSchemaNoticeDismiss);'
            . 'window.fetch(window.ajaxurl, {method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"}, body: data.toString()});'
        . '}, true);'
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
    if (!rvy_builder_schema_has_supported_builder() || rvy_builder_schema_notice_is_dismissed()) { return; }
    $pro_active = defined('PUBLISHPRESS_REVISIONS_PRO_VERSION');
    if ($pro_active ? !current_user_can('pp_manage_settings') : !rvy_builder_notice_can_list_plugins()) { return; }
    if (!$pro_active) { $dismissible = true; }
    if ($pro_active && !rvy_get_option('permissions_compat_mode')) { return; }
    echo '<div class="notice ' . ($pro_active ? 'notice-warning' : 'notice-info') . ' rvy-builder-schema-notice' . ($dismissible ? ' is-dismissible' : ' inline') . '"><p>';
    if ($pro_active) { rvy_print_builder_schema_message(); } else { rvy_print_builder_upgrade_message(); }
    echo '</p></div>';
    if ($dismissible) {
        rvy_builder_schema_notice_dismiss_script();
    }
}
/** Settings render their own inline messages; WordPress hooks pass an empty argument. */
function rvy_builder_schema_admin_notice() {
    global $plugin_page;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = !empty($plugin_page) ? $plugin_page : (isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '');
    if (rvy_get_option('permissions_compat_mode') && in_array($page, ['revisionary-settings', 'rvy-net_options', 'rvy-default_options'], true)) {
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

/** List active supported builders without treating Gutenberg as a page builder. */
function rvy_builder_schema_active_builder_titles() {
    $titles = [];
    if (defined('__BREAKDANCE_VERSION')) { $titles[] = 'Breakdance'; }
    if (defined('BRICKS_VERSION')) { $titles[] = 'Bricks Builder'; }
    if (defined('FL_BUILDER_VERSION')) { $titles[] = 'Beaver Builder'; }
    if (defined('ELEMENTOR_VERSION')) { $titles[] = 'Elementor'; }
    if (defined('ET_BUILDER_VERSION') || defined('ET_BUILDER_PLUGIN_VERSION') || function_exists('et_divi_replace_parent_stylesheet')) {
        $version = defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : (defined('ET_BUILDER_PLUGIN_VERSION') ? ET_BUILDER_PLUGIN_VERSION : '4');
        $titles[] = (int) $version >= 5 ? 'Divi 5' : 'Divi 4';
    }
    if (defined('AVADA_VERSION')) {
        $titles[] = version_compare(AVADA_VERSION, '2.0', '<') ? 'Avada One Page Builder' : 'Avada Page Builder';
    }
    if (defined('SITEORIGIN_PANELS_VERSION') || class_exists('SiteOrigin_Panels')) { $titles[] = 'SiteOrigin Page Builder'; }
    if (defined('SEEDPROD_VERSION') || defined('SEEDPROD_PRO_VERSION') || defined('SEEDPROD_PLUGIN_PATH')) { $titles[] = 'SeedProd'; }
    if (defined('WPB_VC_VERSION')) { $titles[] = 'WPBakery Page Builder'; }
    return $titles;
}

/** Keep upgrade and storage-schema dismissals independent for each user/site. */
function rvy_builder_schema_notice_dismissal_key() {
    return defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') ? 'rvy_builder_schema_notice_dismissed' : 'rvy_builder_upgrade_notice_dismissed';
}

function rvy_print_builder_upgrade_message() {
    $titles = rvy_builder_schema_active_builder_titles();
    $last = array_pop($titles);
    // Translators: %1$s is a builder title or comma-separated list; %2$s is the last builder title.
    $builders = $titles ? sprintf(__('%1$s and %2$s', 'revisionary'), implode(', ', $titles), $last) : $last;
    $installed = rvy_builder_notice_pro_installed();
    $label = $installed ? esc_html__('activate Revisions Pro', 'revisionary') : esc_html__('upgrade to Revisions Pro', 'revisionary');
    $url = $installed ? self_admin_url('plugins.php') : 'https://publishpress.com/revisions/';
    printf(
        // Translators: %1$s is the active page builder title(s); %2$s is the upgrade or activation link.
        esc_html__('For %1$s revision editing and visual compare, %2$s.', 'revisionary'),
        esc_html($builders),
        '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>'
    );
}
/** Match access to the Plugins screen, including multisite menu restrictions. */
function rvy_builder_notice_can_list_plugins() {
    if (is_network_admin()) { return current_user_can('manage_network_plugins'); }
    if (!current_user_can('activate_plugins')) { return false; }
    if (is_multisite() && !is_super_admin()) {
        $menu_items = get_site_option('menu_items', []);
        return !empty($menu_items['plugins']);
    }
    return true;
}

/** Recognize installed Pro copies even when their plugin directory was renamed. */
function rvy_builder_notice_pro_installed() {
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    foreach (get_plugins() as $plugin_file => $plugin_data) {
        if ('revisionary-pro.php' === basename($plugin_file)
            || 'PublishPress Revisions Pro' === ($plugin_data['Name'] ?? '')) {
            return true;
        }
    }
    return false;
}
