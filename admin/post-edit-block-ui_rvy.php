<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Gutenberg Block Editor support
//
// This script executes on the 'init' action if is_admin() and $pagenow is 'post-new.php' or 'post.php' and the block editor is active.
//

if ($_post_id = rvy_detect_post_id()) {
    // PublishPress Custom Status module is not relevant to Edit Revision screen, conflicts with Revisions scripts
    if (rvy_in_revision_workflow($_post_id)) {
        add_filter(
            'pp_module_dirs', 
            function($pp_modules) {
                unset($pp_modules['custom-status']);
                return $pp_modules;
            }
        );

        if ($_post = get_post($_post_id)) {
            // For scheduled revisions, modified date was set equal to post date for queue sorting purposes. But this causes editor to display "Immediately" as the stored publish date.
            if ($_post->post_modified_gmt == $_post->post_date_gmt) {
                global $wpdb;

                $_post->post_modified_gmt = gmdate('Y/m/d H:i:s', strtotime($_post->post_modified_gmt) - 1);
                $_post->post_modified = gmdate('Y/m/d H:i:s', strtotime($_post->post_modified) - 1);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($wpdb->posts, ['post_modified_gmt' => $_post->post_modified_gmt, 'post_modified' => $_post->post_modified], ['ID' => $_post->ID]);

                if (!empty($_SERVER['REQUEST_URI'])) {
                    if (!get_transient("revisionary-post-edit-redirect-{$_post_id}")) {
                        set_transient("revisionary-post-edit-redirect-{$_post_id}", true, 30);
                        wp_redirect(esc_url_raw($_SERVER['REQUEST_URI']));
                        exit;
                    }
                }
            }
        }
    }
}

if (rvy_post_revision_supported($_post_id)) {
	add_action( 'enqueue_block_editor_assets', ['RVY_PostBlockEditUI', 'disablePublishPressStatusesScripts'], 1);
	add_action( 'enqueue_block_editor_assets', array( 'RVY_PostBlockEditUI', 'act_object_guten_scripts' ) );
}

class RVY_PostBlockEditUI {
	public static function disablePublishPressStatusesScripts() {
        global $publishpress;

        if ($post_id = rvy_detect_post_id()) {
            if (rvy_in_revision_workflow($post_id)) {
		        if (!empty($publishpress) && !empty($publishpress->custom_status->module->options)) {
		            $publishpress->custom_status->module->options->post_types = [];
		        }

                // Permalink Manager plugin
                add_filter('permalink_manager_show_uri_editor_post', 
                    function($enable, $post_obj, $post_type) {
                        return false;
                    },
                    10, 3
                );
		    }
        }
    }

	public static function act_object_guten_scripts() {
        global $current_user, $revisionary, $pagenow, $post, $wp_version;

        if ('post-new.php' == $pagenow) {
            return;
        }
        
        if (empty($post)) {
            return;
        }

		if (!$type_obj = get_post_type_object($post->post_type)) {
            return;
        }

        if (empty($revisionary->enabled_post_types[$post->post_type]) || !$revisionary->config_loaded) {
            return;
        }

        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '.dev' : '';
        
        $do_pending_revisions = rvy_get_option('pending_revisions');
        $do_scheduled_revisions = rvy_get_option('scheduled_revisions');

        if (('revision' == $post->post_type) || rvy_in_revision_workflow($post)) {
            wp_enqueue_script( 'rvy_object_edit', RVY_URLPATH . "/admin/rvy_revision-block-edit{$suffix}.js", array('jquery', 'jquery-form'), PUBLISHPRESS_REVISIONS_VERSION, true );

            $args = \PublishPress\Revisions\PostEditorWorkflowUI::revisionLinkParams(compact('post', 'do_pending_revisions', 'do_scheduled_revisions'));

            $args['deleteCaption'] = (defined('RVY_DISCARD_CAPTION')) ? esc_html__('Discard Revision', 'revisionary') : esc_html__('Delete Revision', 'revisionary');

            if (!empty($type_obj->cap->edit_others_posts) && current_user_can($type_obj->cap->edit_others_posts)) {
                add_action('admin_print_footer_scripts', ['RVY_PostBlockEditUI', 'author_ui'], 20);
            }

            $args['disableSubmitUntilSave'] = !defined('ET_BUILDER_PLUGIN_VERSION') && (false === stripos(get_template(), 'divi')) && !defined('REVISIONARY_EDITOR_NO_BUTTON_DISABLE');

        } elseif (current_user_can('edit_post', $post->ID)) {
            $status_obj = get_post_status_object($post->post_status);

		    if (empty($status_obj->public) && empty($status_obj->private) && !rvy_get_option('pending_revision_unpublished')) {
                return;
            }

            wp_enqueue_script( 'rvy_object_edit', RVY_URLPATH . "/admin/rvy_post-block-edit{$suffix}.js", array('jquery', 'jquery-form'), PUBLISHPRESS_REVISIONS_VERSION, true );

            $args = \PublishPress\Revisions\PostEditorWorkflowUI::postLinkParams(compact('post', 'do_pending_revisions', 'do_scheduled_revisions'));
        }

        $wp_timezone = wp_timezone();
        $utc_timezone = new DateTimeZone('UTC');
        $wp_time = new DateTime("now", $wp_timezone);
        $utc_time = new DateTime("now", new DateTimeZone('UTC'));

        $args['timezoneOffset'] = 0 - $wp_timezone->getOffset($utc_time);

        $args['isStatusesPro'] = rvy_status_revisions_active($post->post_type);

        $args['newRevisionDirectLink'] = (rvy_get_option('create_revision_direct_link')) 
        ? rvy_admin_url("admin.php?page=rvy-revisions&post={$post->ID}&action=revise")
        : false;

        wp_localize_script( 'rvy_object_edit', 'rvyObjEdit', $args );
    }

    public static function author_ui() {
        global $post;

        if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
            return [];
        }

        if (!$type_obj = get_post_type_object($post->post_type)) {
            return [];
        }

        $published_post_id = rvy_post_id($post->ID);
        $published_author = get_post_field('post_author', $published_post_id);

        $author_selection = get_post_meta($post->ID, '_rvy_author_selection', true);

        $select_html = wp_dropdown_users(
            array(
                'capability'       => [$type_obj->cap->edit_posts],
                'name'             => 'rvy_post_author',
                'selected'         => ($author_selection) ? $author_selection : $published_author,
                'include_selected' => true,
                'show'             => 'display_name_with_login',
                'echo'             => false,
            )
        );

        $select_html = str_replace(["\n", "\r"], '', $select_html);
        ?>
		<script type="text/javascript">
        /* <![CDATA[ */
		jQuery(document).ready( function($) {
            $(document).on('loaded-ui', 'div.rvy-submission-div', function() {
                if (!$('div.rvy-author-selection').length) {
                    $('div.rvy-submission-div').append(
                        "<br /><div class='rvy-author-selection'>"
                        + '<label>' + '<?php _e("Author", 'revisionary');?>&nbsp;</label>'
                        + '</div>'
                        + "<div class='rvy-author-selection'>"
                        + "<?php echo $select_html;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                        + '</div>'
                    );
                }
            });

            $(document).on('change', 'div.rvy-author-selection select', function(e) {
                var data = {'rvy_ajax_field': 'author_select', 'rvy_ajax_value': <?php echo esc_attr($post->ID);?>, 'rvy_selection': $('div.rvy-author-selection select').val(), 'nc': Math.floor(Math.random() * 99999999)};

                $('div.rvy-author-selection select').attr('disabled', 'disabled');

                $.ajax({
                    url: rvyObjEdit.ajaxurl,
                    data: data,
                    dataType: "html",
                    success: revisionarySelectAuthorDone,
                    error: revisionarySelectAuthorError
                });
            });

            function revisionarySelectAuthorDone() {
                $('div.rvy-author-selection select').removeAttr('disabled');
            }

            function revisionarySelectAuthorError() {
                $('div.rvy-author-selection select').removeAttr('disabled');
            }
        });
		/* ]]> */
		</script>
		<?php
    }
} // end class
