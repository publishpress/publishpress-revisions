<?php
class RvyRevisionEditSubmitMetabox
{
    /**
     *  Classic Editor Post Submit Metabox: HTML
     */
    public static function post_submit_meta_box($post, $args = [])
    {
        do_action('revisionary_post_submit_meta_box');

        $type_obj = get_post_type_object($post->post_type);
        $post_status = $post->post_mime_type;

        $post_status_obj = get_post_status_object($post_status);

        $can_publish = current_user_can($type_obj->cap->publish_posts);

        $_args = compact('type_obj', 'post_status_obj', 'can_publish');
        $_args = array_merge($args, $_args);  // in case args passed into metabox are needed within static calls in the future
        ?>
        <div class="submitbox" id="submitpost">
            <?php ob_start();

            ?>
            <div id="minor-publishing">
                <div id="minor-publishing-actions">
                    <div id="save-action">
                        <?php self::post_save_button($post, $_args); ?>
                    </div>
                    <div id="preview-action">
                        <?php self::post_preview_button($post, $_args); ?>
                    </div>
                    <div class="clear"></div>
                </div><?php // minor-publishing-actions ?>

                <div id="misc-publishing-actions">
                    <div class="misc-pub-section">
                        <?php self::post_status_display($post, $_args); ?>
                    </div>

                    <?php
                    if ($can_publish) : // Contributors don't get to choose the date of publish
                        ?>
                        <div class="misc-pub-section curtime misc-pub-section-last">
                            <?php self::post_time_display($post, $_args); ?>
                        </div>
                    <?php endif; ?>

                    <?php do_action('post_submitbox_misc_actions', $post); ?>
                </div> <?php // misc-publishing-actions ?>

                <div class="clear"></div>
            </div> <?php // minor-publishing ?>

            <div id="major-publishing-actions">
                <?php do_action('post_submitbox_start', $post); ?>

                <div id="delete-action">
                    <?php // PP: no change from WP core
                    if (current_user_can("delete_post", $post->ID)) {
                        if (!EMPTY_TRASH_DAYS)
                            $delete_text = (defined('RVY_DISCARD_CAPTION')) ? esc_html__('Discard Revision', 'revisionary') : esc_html__('Delete Revision', 'revisionary');
                        else
                            $delete_text = esc_html__('Move to Trash');
                        ?>
                        <a class="submitdelete deletion"
                           href="<?php echo esc_url(get_delete_post_link($post->ID)); ?>"><?php echo esc_html($delete_text); ?></a><?php
                    } ?>
                </div>

                <div class="clear"></div>
            </div> <?php // major-publishing-actions ?>

            <?php
            $html = apply_filters('revisionary_submit_revision_metabox_classic', ob_get_clean(), $post);
            echo $html;                 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div> <?php // submitpost ?>

        <?php
    }

    /*
     *  Classic Editor Post Submit Metabox: Post Save Button HTML
     */
    public static function post_save_button($post, $args)
    {
        if (!$draft_label = pp_revisions_status_label($post->post_mime_type, 'update')) {
        	$draft_label = pp_revisions_label('update_revision');
        }
        ?>
        <input type="submit" name="save" id="save-post" value="<?php echo esc_attr($draft_label) ?>"
                tabindex="4" class="button button-highlighted"/>

        <span class="spinner" style="margin:2px 2px 0"></span>
        <?php
    }

    /**
     *  Classic Editor Post Submit Metabox: Post Preview Button HTML
     */
    public static function post_preview_button($post, $args)
    {
        if (empty($args['post_status_obj'])) return;

        $post_status_obj = $args['post_status_obj'];
        ?>
        <?php
        if (rvy_get_option('revision_preview_links') || current_user_can('administrator') || is_super_admin()) {
            $preview_link = esc_url(get_permalink($post->ID));

            $type_obj = get_post_type_object($post->post_type);

            if (empty($type_obj->public)) {
                return;
            }

            $can_publish = current_user_can('approve_revision', $post->ID);

            if ($type_obj && empty($type_obj->public)) {
                return;
            } else {
                $preview_button = esc_html__('Preview', 'revisionary');
                $preview_title = esc_html__('Preview revision in progress', 'revisionary');
            }
            ?>
            <a class="preview button" href="<?php echo esc_url($preview_link); ?>" target="_blank" id="post-preview"
            tabindex="4" title="<?php echo esc_attr($preview_title);?>"><?php echo esc_html($preview_button); ?></a>

            <input type="hidden" name="wp-preview" id="wp-preview" value="">
            <?php
        }
    }

    /**
     *  Classic Editor Post Submit Metabox: Post Status Dropdown HTML
     */
    public static function post_status_display($post, $args)
    {
        $defaults = ['post_status_obj' => false];
        $args = array_merge($defaults, $args);
        foreach (array_keys($defaults) as $var) {
            $$var = $args[$var];
        }

        ?>
        <label for="post_status"><?php echo esc_html__('Status:'); ?></label>
        <?php
        $status_label = (!empty($post_status_obj->labels->caption)) ? $post_status_obj->labels->caption : $post_status_obj->label;
        ?>
        <span id="post-status-display">
        <?php
        echo esc_html($status_label);
        ?>
        </span>&nbsp;

        <?php /* Output status select for js consistency with date select OK / Cancel */?>
        <div id="post-status-select" class="hide-if-js" style="display:none">
            <select name='post_status' id='post_status' tabindex='4'>
                    <option selected value='<?php echo esc_attr($post_status_obj->name) ?>'><?php echo esc_html($status_label) ?></option>
            </select>
        </div>
        <?php
    }

    /**
     *  Classic Editor Post Submit Metabox: Post Time Display HTML
     */
    public static function post_time_display($post, $args)
    {
        global $action;

        if (empty($args['post_status_obj'])) return;

        $post_status_obj = $args['post_status_obj'];
        ?>
        <span id="timestamp">
        <?php
        // translators: Publish box date formt, see http://php.net/date
        $datef = esc_html__('M j, Y @ G:i', 'revisionary');

        $published_stati = get_post_stati(['public' => true, 'private' => true], 'names', 'or');

        if ('future-revision' == $post_status_obj->name) { // scheduled for publishing at a future date
            printf(esc_html__('Scheduled for: %s'), '<b>' . esc_html(date_i18n($datef, strtotime($post->post_date))) . '</b>'); ?></span>
            <?php
        } elseif (strtotime($post->post_date_gmt) > agp_time_gmt()) {
            printf(esc_html__('Publish on: %s'), '<b>' . esc_html(date_i18n($datef, strtotime($post->post_date))) . '</b>'); ?></span>
            <?php
        } else {
            printf(esc_html__('Publish %son approval%s', 'revisionary'), '<b>', '</b>'); ?></span>
            <?php
        }
        ?>

        <a href="#edit_timestamp" class="edit-timestamp hide-if-no-js" tabindex='4'><?php echo esc_html__('Edit') ?></a>
        <div id="timestampdiv" class="hide-if-js"><?php touch_time(($action == 'edit'), 1, 4); ?></div>
        <?php
    }
}
