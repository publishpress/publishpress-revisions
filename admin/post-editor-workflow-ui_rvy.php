<?php
namespace PublishPress\Revisions;

class PostEditorWorkflowUI {
    public static function revisionLinkParams($args = []) {
        $defaults = ['post' => false, 'do_pending_revisions' => true, 'do_scheduled_revisions' => true];
        $args = array_merge( $defaults, $args );
        foreach( array_keys($defaults) as $var ) { $$var = $args[$var]; }

        global $wp_version, $revisionary;

        if (empty($post)) {
            return [];
        }

        if (!$type_obj = get_post_type_object($post->post_type)) {
            return [];
        }

        $published_post_id = rvy_post_id($post->ID);

        $block_editor = \PublishPress\Revisions\Utils::isBlockEditorActive($post->post_type);

        $can_publish = current_user_can('edit_post', $published_post_id);

        $vars = [
            'postID' => $post->ID,
            'saveRevision' => pp_revisions_label('update_revision'),
            'scheduledRevisionsEnabled' => $do_scheduled_revisions,
            'multiPreviewActive' => version_compare($wp_version, '5.5-beta', '>='),
            'statusLabel' => esc_html__('Status', 'revisionary'),
            'ajaxurl' => rvy_admin_url(''),
            'currentPostAuthor' => get_post_field('post_author', $published_post_id),
            'onApprovalCaption' => esc_html__('(on approval)', 'revisionary'),
            'saveRevisionTooltip' =>  htmlEntities(
                wp_get_admin_notice(
                    $revisionary->admin->tooltipText(
                        __('Save changes to continue.', 'revisionary'),
                        __('Please save changes to the revision before submitting it.', 'revisionary'),
                        false
                    ),
                    ['type' => 'info', 'additional_classes' => ['rvy-save-revision-tip']]
                )
            ),
            'canPublish' => $can_publish
        ];

        // @todo: adapt Submit button to custom statuses?
        switch ($post->post_mime_type) {
            case 'future-revision' :
                $vars['currentStatus'] = 'future';
                break;

            case 'draft-revision' :
                $vars['currentStatus'] = 'draft';
                break;

            default :
                $vars['currentStatus'] = 'pending';
        }

        $vars['pendingStatus'] = 'pending';

        $vars['disableRecaption'] = version_compare($wp_version, '5.9-beta', '>=') || is_plugin_active('gutenberg/gutenberg.php');
        $vars['viewTitle'] = '';

        if (rvy_get_option('revision_preview_links') || current_user_can('administrator') || is_super_admin()) {
            $vars['viewURL'] = rvy_preview_url($post);

            if ($type_obj && empty($type_obj->public)) {
                $vars['viewURL']  = '';
                $vars['viewCaption'] = '';
                $vars['viewTitle'] = '';

            } elseif ($can_publish) {
                if (version_compare($wp_version, '5.5-beta', '>=')) {
                    $vars['viewCaption'] = ($block_editor) ? esc_html__('Preview this Revision', 'revisionary') : esc_html__('Preview', 'revisionary');
                } else {
                    $vars['viewCaption'] = ('future-revision' == $post->post_mime_type) ? esc_html__('View / Publish', 'revisionary') : esc_html__('View / Approve', 'revisionary');
                }

                $vars['viewTitle'] =  esc_html__('View / Moderate saved revision', 'revisionary');
            } else {
                $vars['viewCaption'] = version_compare($wp_version, '5.5-beta', '>=') ? esc_html__('Preview / Submit') :  esc_html__('View / Submit');
                $vars['viewTitle'] =  esc_html__('View / Submit saved revision', 'revisionary');
            }

        } else {
            $vars['viewURL']  = '';
            $vars['viewCaption'] = '';
            $vars['viewTitle'] =  '';
        }

        $vars['previewTitle'] = esc_html__('View unsaved changes', 'revisionary');

        $_revisions = wp_get_post_revisions($post->ID);
        if ($_revisions && count($_revisions) > 1) {
            $vars['revisionEdits'] = sprintf(esc_html(_n('%s%s Revision Edit', '%s%s Revision Edits', count($_revisions), 'revisionary')), '<span class="dashicons dashicons-backup"></span>&nbsp;', count($_revisions));
        } else {
            $vars['revisionEdits'] = '';
        }
                                                                                                        //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';

        $draft_obj = get_post_status_object('draft-revision');
        $vars['draftStatusCaption'] = $draft_obj->label;

        $vars['draftAjaxField'] = (current_user_can('administrator') || current_user_can('set_revision_pending-revision', $post->ID)) ? 'submit_revision' : '';
        $vars['draftErrorCaption'] = esc_html__('Revision Submission Error', 'revisionary');
        $vars['draftDeletionURL'] = get_delete_post_link($post->ID, '', false);

        if ($vars['draftAjaxField']) {
            $vars['draftActionCaption'] = ($can_publish) ? pp_revisions_status_label('pending-revision', 'submit_short') : pp_revisions_status_label('pending-revision', 'submit');
            $vars['draftActionURL'] = '';
            $vars['draftInProcessCaption'] = pp_revisions_status_label('pending-revision', 'submitting');
            $vars['draftCompletedCaption'] = pp_revisions_status_label('pending-revision', 'submitted');

            $preview_caption = ($block_editor) ? esc_html__('Preview this Revision', 'revisionary') : esc_html__('Preview', 'revisionary');

            $vars['draftCompletedLinkCaption'] = (!empty($type_obj->public)) ? $preview_caption : '';
            $vars['draftCompletedURL'] = (!empty($type_obj->public)) ? rvy_preview_url($post) : '';

            $vars['draftCompletedEditCaption'] = esc_html__('Edit', 'revisionary');
            $vars['draftCompletedEditURL'] = admin_url("post.php?post={$post->ID}&action=edit");
        } else {
            $vars['draftActionCaption'] = '';
        }

        if ($can_publish) {
            $vars['approveCaption'] = rvy_get_option('approve_button_verbose') ? __('Approve and Publish', 'revisionary') : pp_revisions_status_label('pending-revision', 'approve');
        } else {
            $vars['approveCaption'] = '';
        }

        $vars['approvingCaption'] = __('Approving the Revision...', 'revisionary');

        if ($block_editor) {
            if ($can_publish) {
                $vars['scheduleCaption'] = rvy_get_option('approve_button_verbose') ? __('Approve and Schedule', 'revisionary') : pp_revisions_status_label('future-revision', 'submit_short');
            } else {
                $vars['scheduleCaption'] = '';
            }
        } else {
            $vars['scheduleCaption'] = ($can_publish) ? pp_revisions_status_label('future-revision', 'submit') : '';
        }

        $pending_obj = get_post_status_object('pending-revision');
        $vars['pendingStatusCaption'] = $pending_obj->label;

        $future_obj = get_post_status_object('future-revision');
        $vars['futureStatusCaption'] = $future_obj->label;

        if ($can_publish) {
            $vars['pendingActionCaption'] = pp_revisions_status_label('pending-revision', 'approve');
            $vars['pendingActionURL'] = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&amp;revision={$post->ID}&amp;action=approve$redirect_arg&amp;editor=1"), "approve-post_$published_post_id|{$post->ID}" );

            $vars['pendingInProcessCaption'] = pp_revisions_status_label('pending-revision', 'approving');

            $vars['futureActionCaption'] = pp_revisions_status_label('future-revision', 'publish');
            $vars['futureActionURL'] = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&amp;revision={$post->ID}&amp;action=publish$redirect_arg&amp;editor=1"), "publish-post_$published_post_id|{$post->ID}" );

            $vars['pendingDeletionURL'] = get_delete_post_link($post->ID, '', false);
            $vars['futureDeletionURL'] = $vars['pendingDeletionURL'];
        } else {
            $vars['pendingActionURL'] = '';
            $vars['futureActionURL'] = '';
            $vars['pendingDeletionURL'] = '';
            $vars['futureDeletionURL'] = '';
        }

        if ($block_editor) {
            $vars['updateCaption'] =  esc_html__('Update Revision', 'revisionary');
        } else {
            if (!rvy_status_revisions_active($post->post_type)) {
            	if (!$vars['updateCaption'] = pp_revisions_status_label($post->post_mime_type, 'update')) {
                	$vars['updateCaption'] = pp_revisions_label('update_revision');
                }
            }
        }

        $vars['approvalLocked'] = false;

        return $vars;
    }

    public static function postLinkParams($args = []) {
        $defaults = ['post' => false, 'do_pending_revisions' => true, 'do_scheduled_revisions' => true];
        $args = array_merge( $defaults, $args );
        foreach( array_keys($defaults) as $var ) { $$var = $args[$var]; }

        global $wp_version;

        if (empty($post)) {
            return [];
        }

        if (!$type_obj = get_post_type_object($post->post_type)) {
            return [];
        }

        $type_obj = get_post_type_object($post->post_type);

        $vars = ['postID' => $post->ID, 'currentStatus' => $post->post_status];

        if ($do_pending_revisions && $_revisions = rvy_get_post_revisions($post->ID, 'pending-revision', ['orderby' => 'ID', 'order' => 'ASC'])) {
            $status_obj = get_post_status_object('pending-revision');

            $status_label = (count($_revisions) <= 1) ? pp_revisions_status_label('pending-revision', 'name') : pp_revisions_status_label('pending-revision', 'plural');
            $vars['pendingRevisionsCaption'] = sprintf('<span class="dashicons dashicons-edit"></span>&nbsp;%s %s', count($_revisions), $status_label);

            $vars['pendingRevisionsURL'] = rvy_admin_url("revision.php?post_id=$post->ID&revision=pending-revision");   // @todo: fix i8n
        } else {
            $vars['pendingRevisionsURL'] = '';
        }

        if ($do_scheduled_revisions && $_revisions = rvy_get_post_revisions($post->ID, 'future-revision', ['orderby' => 'ID', 'order' => 'ASC'])) {
            $status_obj = get_post_status_object('future-revision');

            $status_label = (count($_revisions) <= 1) ? pp_revisions_status_label('future-revision', 'name') : pp_revisions_status_label('future-revision', 'plural');
            $vars['scheduledRevisionsCaption'] = sprintf('<span class="dashicons dashicons-clock"></span>&nbsp;%s %s', count($_revisions), $status_label);

            $vars['scheduledRevisionsURL'] = rvy_admin_url("revision.php?post_id=$post->ID&revision=future-revision");
        } else {
            $vars['scheduledRevisionsURL'] = '';
        }
                                                                                                //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';
        $published_post_id = rvy_post_id($post->ID);

        $is_block_editor = \PublishPress\Revisions\Utils::isBlockEditorActive($post->post_type);
        $preview_caption = $is_block_editor ? esc_html__('Preview Revision', 'revisionary') : esc_html__('Preview', 'revisionary');
        $edit_caption = $is_block_editor ? esc_html__('Edit Revision', 'revisionary') : esc_html__('Edit', 'revisionary');

        if (rvy_get_option('pending_revisions') && current_user_can('copy_post', $post->ID)) {
            $vars = array_merge($vars, array(
                'actionCaption' => pp_revisions_status_label('draft-revision', 'submit'),
                'actionTitle' => '',
                'actionDisabledTitle' => esc_attr(sprintf(esc_html__('Update post before creating %s.', 'revisionary'), strtolower(pp_revisions_status_label('draft-revision', 'basic')))),
                'creatingCaption' => pp_revisions_status_label('draft-revision', 'submitting'),
                'completedCaption' => pp_revisions_status_label('draft-revision', 'submitted'),
                'completedLinkCaption' => (!empty($type_obj->public)) ? $preview_caption : '',
                'completedURL' => (!empty($type_obj->public)) ? rvy_nc_url( wp_nonce_url(add_query_arg('get_new_revision', $post->ID, admin_url('')), 'new-revision') ) : '',
                'completedEditLinkCaption' => $edit_caption,
                'completedEditURL' => rvy_nc_url( wp_nonce_url(add_query_arg(['edit_new_revision' => $post->ID, 'published_post' => $post->ID], admin_url('admin.php?page=revisionary-q')), 'edit-new-revision') ),
                'errorCaption' => esc_html__('Error Creating Revision', 'revisionary'),
                'ajaxurl' => rvy_admin_url(''),
                'update' => esc_html__('Update', 'revisionary'),
                'postID' => $post->ID
            ));
        } else {
            $vars['actionCaption'] = '';
        }

        if (rvy_get_option('scheduled_revisions') && current_user_can($type_obj->cap->publish_posts)) {
            $published_statuses = array_merge(get_post_stati(['public' => true]), get_post_stati(['private' => true]));

            $vars = array_merge($vars, array(
                'publishedStatuses' => $published_statuses,
                'scheduleCaption' => pp_revisions_status_label('future-revision', 'submit'),
                'scheduleTitle' => '',
                'scheduleDisabledTitle' => esc_attr(sprintf(esc_html__('For custom field changes, edit a scheduled %s.', 'revisionary'), strtolower(pp_revisions_status_label('draft-revision', 'basic')))),
                'scheduledCaption' => pp_revisions_status_label('future-revision', 'submitted'),
                'scheduledLinkCaption' => (!empty($type_obj->public)) ? $preview_caption : '',
                'scheduledURL' => (!empty($type_obj->public)) ? rvy_nc_url( wp_nonce_url(add_query_arg('get_new_revision', $post->ID, admin_url('')), 'new-revision') ) : '',
                'scheduledEditLinkCaption' => $edit_caption,
                'scheduledEditURL' => rvy_nc_url( wp_nonce_url(add_query_arg(['edit_new_revision' => $post->ID, 'published_post' => $post->ID], admin_url('admin.php?page=revisionary-q')), 'edit-new-revision') ),
                'update' => esc_html__('Update', 'revisionary'),
            ));

            if (empty($vars['actionCaption'])) {
                $vars = array_merge($vars, array(
                    'actionCaption' => '',
                    'ajaxurl' => rvy_admin_url(''),
                ));
            }
        }

        return $vars;
    }
}
