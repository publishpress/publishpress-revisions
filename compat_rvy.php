<?php

class PP_Revisions_Compat {
    function __construct() {
        add_filter('default_options_rvy', [$this, 'fltDefaultOptions']);
        add_filter('options_sitewide_rvy', [$this, 'fltDefaultOptionScope']);

        add_filter('revisionary_post_revision_status', [$this, 'fltNewRevisionStatus'], 10, 3);
        add_filter('revisionary_require_base_statuses', [$this, 'fltRequireRevisionBaseStatuses']);

        add_filter(
            'user_has_cap',
            function($wp_sitecaps, $orig_reqd_caps, $args) {
                global $current_user;

                $args = (array)$args;
                $orig_cap = (isset($args[0])) ? sanitize_key($args[0]) : '';

                if ($post_id = rvy_detect_post_id()) {
                    if (rvy_in_revision_workflow($post_id)) {
                        if ($type_obj = get_post_type_object(get_post_field('post_type', $post_id))) {
                            if (!empty($type_obj->cap->publish_posts) && !empty($type_obj->cap->edit_posts)
                            && ($orig_cap == $type_obj->cap->publish_posts) && !empty($current_user->allcaps[$type_obj->cap->edit_posts])
                            ) {
                                $wp_sitecaps[$orig_cap] = true;
                            }
                        }
                    }
                }

                return $wp_sitecaps;
            },
            10, 3
        );

        add_filter( 
            'map_meta_cap',
            function($caps, $cap, $user_id, $args) {
                global $current_user;

                if (in_array($cap, ['edit_post', 'edit_page'])) {
                    $args = (array)$args;
                    $post_id = (isset($args[0]) && !is_object($args[0])) ? intval($args[0]) : 0;

                    // @todo: where is edit_published cap requirement being applied?
                    if ($post_id && rvy_in_revision_workflow($post_id)) {
                        $caps = array_diff($caps, ['edit_published_pages']);
                    }
                }

                return $caps;
            }, 
            20, 4
        );

        if (defined('JREVIEWS_ROOT') && !empty($_REQUEST['preview']) 										// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        && ((empty($_REQUEST['preview_id']) && empty($_REQUEST['thumbnail_id']))							// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        || (!empty($_REQUEST['preview_id']) && rvy_in_revision_workflow((int) $_REQUEST['preview_id']))		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        )
        ) {
            self::jreviews_preview_compat();
        }

        add_filter('presspermit_maybe_override_authors_change', [$this, 'fltPermissionsOverrideAuthorsChange'], 10, 2);

        add_action('save_post', [$this, 'actPreserveRevisionAuthor'], 1, 3);

        add_action('add_meta_boxes', [$this, 'actMaybeRemoveAuthorsMetabox'], 101);
    }

    function fltRequireRevisionBaseStatuses($require_base_statuses) {
        if (rvy_get_option('permissions_compat_mode')) {
            $require_base_statuses = false;
        }

        return $require_base_statuses;
    }

    function fltNewRevisionStatus($post_status, $revision_status, $base_post_id) {
        if (rvy_get_option('permissions_compat_mode')) {
            $post_status = $revision_status;
        }

        return $post_status;
    }

    function fltDefaultOptions($options) {
        $options['permissions_compat_mode'] = 0;
        return $options;
    }

    function fltDefaultOptionScope($options) {
        $options['permissions_conmpat_mode'] = true;
        return $options;
    }

    function fltPermissionsOverrideAuthorsChange($maybe_override, $post) {
        if (did_action('revisionary_pre_insert_revision')) {
            $maybe_override_authors = false;
        }

        return $maybe_override_authors;
    }

    function actMaybeRemoveAuthorsMetabox() {
        global $post;

        if (!empty($post) && rvy_in_revision_workflow($post) 
        && (!current_user_can('edit_post', rvy_post_id($post->ID)) || (rvy_get_option('revisor_lock_others_revisions') && !current_user_can('edit_others_revisions')))
        ) {
            remove_meta_box(
                'ppma_authorsdiv',
                $post->post_type,
                'side'
            );
        }
    }

    function actPreserveRevisionAuthor($post_id, $post, $update) {
        global $current_user;

        static $busy;

        if (!empty($busy)) {
            return;
        }

        if (rvy_in_revision_workflow($post) && ($current_user->ID == $post->post_author)
        && (!current_user_can('edit_post', rvy_post_id($post->ID)) || (rvy_get_option('revisor_lock_others_revisions') && !current_user_can('edit_others_revisions')))
        ) {
            unset($_POST['authors']);
            $_POST['fallback_author_user'] = $current_user->ID;
        }

        $busy = false;
    }

    // JReviews plugin breaks Pending Revision / Scheduled Revision preview
    private static function jreviews_preview_compat() {
        global $JReviews;

        if (empty($JReviews)) {
            return;
        }

        remove_action('admin_footer',                  [$JReviews, 'assets']);

        remove_action('admin_init',                    [$JReviews, 'admin_init']);

        remove_action('admin_menu',                    [$JReviews, 'admin_menu']);

        remove_action('save_post',                     [$JReviews, 'admin_save_menu']);

        remove_filter('admin_head',                    [$JReviews, 'admin_head']);

        // Using priority 100 to force select2 to load after nello content ncselect2 script which causes a conflict
        remove_action('admin_enqueue_scripts',         [$JReviews, 'admin_enqueue'], 100);

        // Display the User ID column in the User Manager in WP

        remove_filter('manage_users_columns',          [$JReviews, 'add_user_id_column']);

        remove_action('manage_users_custom_column',    [$JReviews, 'show_user_id_column_content'], 10, 3);

        /**
        * Site functions
        */
        remove_action('wp_enqueue_scripts',            [$JReviews,'assets']);

        remove_action('wp_footer',                     [$JReviews,'assets']);

        // Ajax

        remove_action('wp_ajax_jreviews_ajax',         [$JReviews, 'ajax']);

        remove_action('wp_ajax_nopriv_jreviews_ajax',  [$JReviews, 'ajax']);

        // WP System functions

        remove_action('init',                          [$JReviews, 'init']);

        remove_action('wp_login',                      [$JReviews, 'endSession']);

        remove_action('wp_logout',                     [$JReviews, 'endSession']);

        remove_action('wp_loaded',                     [$JReviews, 'wp_loaded']);

        remove_action('get_header',                    [$JReviews, 'get_header']);

        remove_action('wp_head',                       [$JReviews, 'wp_head']);

        // Routing functions

        remove_filter('rewrite_rules_array',           [$JReviews, 'rewrite_rules_array']);

        remove_filter('query_vars',                    [$JReviews, 'query_vars']);

        // Template functions

        remove_filter('template_include',              [$JReviews, 'template_include']);

        // SEO functions
        remove_filter('document_title_parts',          [$JReviews, 'page_title_parts'], 20);

        remove_filter('pre_get_document_title',        [$JReviews, 'page_title_override'], 20);

        remove_filter('wp_title',                      [$JReviews, 'page_title'], 20, 3);

        // Widgets

        remove_action('widgets_init',                  [$JReviews, 'widgets_init']);

        // Load scripts with defer attribute

        remove_filter('script_loader_tag',            [$JReviews, 'defer_js_async'], 10, 2 );

        // Stop WP core category queries from running on JReviews pages

        remove_filter('posts_pre_query',              [$JReviews, 'posts_pre_query_filter'], 10, 2);
    }
}
