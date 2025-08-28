<?php

class Rvy_Revision_Workflow_UI {
    public static function default_notification_recipients($object_id, $args = []) {
        global $revisionary;
        
        $notify_editors = (string) rvy_get_option('pending_rev_notify_admin');
        $notify_author = (string) rvy_get_option('pending_rev_notify_author');
    
        // @todo: always pull post type from post based on $object_id? (confirm no execution timing issues with Post retrieval)
        $object_type = (!empty($args['object_type'])) ? $args['object_type'] : awp_post_type_from_uri();
    
        $post_publishers = array();
        $publisher_ids = array();
        $default_ids = array();
        
        if (!rvy_get_option('legacy_notifications')) {
            return compact('default_ids', 'post_publishers', 'publisher_ids');
        }

        $type_obj = get_post_type_object( $object_type );
        
        if ( '1' === $notify_editors ) {
            if ( defined('RVY_CONTENT_ROLES') && ! defined('SCOPER_DEFAULT_MONITOR_GROUPS') && ! defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS') ) {
                $monitor_groups_enabled = true;
                $revisionary->content_roles->ensure_init();
    
                if ( $publisher_ids = $revisionary->content_roles->get_metagroup_members( 'Pending Revision Monitors' ) ) {
                    if ( $type_obj ) {
                        $cols = ( defined('COLS_ALL_RS') ) ? COLS_ALL_RS : 'all';
                        $post_publishers = $revisionary->content_roles->users_who_can( 'edit_post', $object_id, array( 'cols' => $cols, 'force_refresh' => true, 'user_ids' => $publisher_ids ) );

                        $can_publish_post = array();
                        foreach ( $post_publishers as $key => $user ) {
                            $can_publish_post []= $user->ID;
                            
                            if ( ! in_array( $user->ID, $publisher_ids ) )
                                unset(  $post_publishers[$key] );
                        }
                        
                        $publisher_ids = array_intersect( $publisher_ids, $can_publish_post );
                        $publisher_ids = array_fill_keys( $publisher_ids, true );
                    }
                }
            }
            
            if ( ! $publisher_ids && ( empty($monitor_groups_enabled) || ! defined('RVY_FORCE_MONITOR_GROUPS') ) ) {
                // If RS is not active, default to sending to all Administrators and Editors who can publish the post
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                
                if ( defined( 'SCOPER_MONITOR_ROLES' ) )
                    $use_wp_roles = SCOPER_MONITOR_ROLES;
                else
                    $use_wp_roles = ( defined( 'RVY_MONITOR_ROLES' ) ) ? RVY_MONITOR_ROLES : 'administrator,editor';
                
                $use_wp_roles = str_replace( ' ', '', $use_wp_roles );
                $use_wp_roles = explode( ',', $use_wp_roles );
                
                $recipients = array();
                
                foreach ( $use_wp_roles as $role_name ) {
                    $search = new WP_User_Query( "search=&role=$role_name" );
                    $recipients = array_merge( $recipients, $search->results );
                }
                
                foreach ( $recipients as $_user ) {	
                    $reqd_caps = map_meta_cap( 'edit_post', $_user->ID, $object_id );

                    if ( ! array_diff( $reqd_caps, array_keys( array_intersect( $_user->allcaps, array( true, 1, '1' ) ) ) ) ) {
                        $post_publishers []= $_user;
                        $publisher_ids [$_user->ID] = true;
                    }
                }
            }
    
            // boolean array with user IDs as array keys
            $default_ids = apply_filters('revisionary_notify_publisher_default_ids', $publisher_ids, $object_id);
        }
        
        if ( '1' === $notify_author ) {
            global $post;
    
            if (function_exists('get_multiple_authors')) {
                $author_ids = [];
                foreach(get_multiple_authors($post) as $_author) {
                    $author_ids []= $_author->ID;
                }	
            } else {
                $author_ids = [$post->post_author];
            }
    
            foreach($author_ids as $author_id) {
                if ( empty( $default_ids[$author_id] ) ) {
                    if ( defined('RVY_CONTENT_ROLES') ) {
                        $cols = ( defined('COLS_ALL_RS') ) ? COLS_ALL_RS : 'all';
                        $author_notify = (bool) $revisionary->content_roles->users_who_can( 'edit_post', $object_id, array( 'cols' => $cols, 'force_refresh' => true, 'user_ids' => (array) $author_id ) );
                    } else {
                        $_user = new WP_User($author_id);
                        $reqd_caps = map_meta_cap( 'edit_post', $_user->ID, $object_id );
                        $author_notify = ! array_diff( $reqd_caps, array_keys( array_intersect( $_user->allcaps, array( true, 1, '1' ) ) ) );
                    }
    
                    if ( $author_notify ) {
                        $default_ids[$author_id] = true;
    
                        $user = new WP_User( $author_id );
                        $post_publishers[] = $user;
                    }
                }
            }
        }
    
        if ($default_ids) {
            // array of WP_User objects
            $post_publishers = apply_filters('revisionary_notify_publishers_eligible', $post_publishers, $object_id);
        }
    
        return compact('default_ids', 'post_publishers', 'publisher_ids');
    }

    function do_notifications( $notification_type, $status, $post_arr, $args ) {
        global $revisionary, $current_user;

        if (defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=') && rvy_get_option('use_publishpress_notifications')
        ) {
            return;
        }

        if ( 'pending-revision' != $notification_type ) {
            return;
        }

        if (!rvy_get_option('legacy_notifications')) {
            return;
        }

        $defaults = array( 'revision_id' => 0, 'published_post' => false, 'object_type' => '', 'selected_recipients' => array() );
        $args = array_merge( $defaults, $args );
        foreach( array_keys($defaults) as $var ) { $$var = $args[$var]; }

        $revision_id = (int) $revision_id;
        $object_type = sanitize_key($object_type);

        if ( ! $published_post ) {
            return;
        }

        // Support workaround to prevent notification when an Administrator or Editor voluntarily creates a pending revision
        if (defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS') && current_user_can('edit_post', $published_post->ID)) {
            return;
        }

        if ( $revisionary->doing_rest && $revisionary->rest->is_posts_request && ! empty( $revisionary->rest->request ) ) {
            $post_arr = array_merge( $revisionary->rest->request->get_params(), $post_arr );
        }

        $recipient_ids = [];

        $admin_notify = rvy_get_option( 'pending_rev_notify_admin' );
        $author_notify = rvy_get_option( 'pending_rev_notify_author' );

        if ( ( $admin_notify || $author_notify ) && $revision_id ) {
            $type_obj = get_post_type_object( $object_type );
            $type_caption = strtolower($type_obj->labels->singular_name);
            $post_arr['post_type'] = $published_post->post_type;
            
            $blogname = wp_specialchars_decode( get_option('blogname'), ENT_QUOTES );
            
            if (!empty($args['update'])) {
                $title = sprintf( esc_html__('[%s] %s Updated', 'revisionary'), $blogname, pp_revisions_status_label('pending-revision', 'name') );
                
                $message = sprintf( esc_html__('%1$s updated a %2$s of the %3$s "%4$s".', 'revisionary'), $current_user->display_name, strtolower(pp_revisions_status_label('pending-revision', 'name')), $type_caption, $post_arr['post_title'] ) . "\r\n\r\n";
            } else {
                $title = sprintf( esc_html__('[%s] %s', 'revisionary'), $blogname, pp_revisions_status_label('pending-revision', 'name') );
                
                $message = sprintf( esc_html__('%1$s submitted changes to the %2$s "%3$s". You can review the changes for possible publication:', 'revisionary'), $current_user->display_name, $type_caption, $post_arr['post_title'] ) . "\r\n\r\n";
            }

            if ( $revision_id ) {
                $revision = get_post($revision_id);

                if (rvy_get_option('revision_preview_links') || current_user_can('administrator') || is_super_admin()) {
                    $preview_link = rvy_preview_url($revision);
                    $message .= esc_html__( 'Preview and Approval: ', 'revisionary' ) . $preview_link . "\r\n\r\n";
                }

                $message .= esc_html__( 'Revision Queue: ', 'revisionary' ) . rvy_admin_url("admin.php?page=revisionary-q&published_post={$published_post->ID}&all=1") . "\r\n\r\n";
                
                $message .= sprintf(esc_html__( 'Edit %s: ', 'revisionary' ), pp_revisions_status_label('pending-revision', 'name')) . rvy_admin_url("post.php?action=edit&post={$revision_id}") . "\r\n";
            }

            if ( $admin_notify ) {
                // establish the publisher recipients
                $recipient_ids = apply_filters('revisionary_submission_notify_admin', self::getRecipients('rev_submission_notify_admin', compact('type_obj', 'published_post')), ['post_type' => $object_type, 'post_id' => $published_post->ID, 'revision_id' => $revision_id]);
                
                if ( ( 'always' != $admin_notify ) && $selected_recipients ) {
                    // intersect default recipients with selected recipients
                    $recipient_ids = array_intersect( $selected_recipients, $recipient_ids );
                }
                
                if ( defined( 'RVY_NOTIFY_SUPER_ADMIN' ) && is_multisite() ) {
                    $super_admin_logins = get_super_admins();
                    foreach( $super_admin_logins as $user_login ) {
                        if ( $super = new WP_User($user_login) ) {
                            $recipient_ids []= $super->ID;
                        }
                    }
                }
            }

            if ( $author_notify ) {
                if (function_exists('get_multiple_authors')) {
                    $author_ids = [];
                    foreach(get_multiple_authors($published_post) as $_author) {
                        $author_ids []= $_author->ID;
                    }	
                } else {
                    $author_ids = [$published_post->post_author];
                }

                if ('always' != $author_notify) {
                    $author_ids = $selected_recipients ? array_intersect($author_ids, $selected_recipients) : [];
                }

                $recipient_ids = array_merge($recipient_ids, $author_ids);
            }

            if ( $recipient_ids ) {
                $to_addresses = [];

                foreach($recipient_ids as $user_id) {
                    $user = new WP_User($user_id);                

                    if ($user->exists() && !empty($user->user_email)) {
                        $to_addresses[$user_id] = $user->user_email;
                    }
                }

                $to_addresses = array_unique($to_addresses);
            } else {
                $to_addresses = array();
            }

            $message = str_replace('&quot;', '"', $message);

            foreach ( $to_addresses as $user_id => $address ) {
                if (!empty($author_ids) && in_array($user_id, $author_ids)) {
                    $notification_class = 'rev_submission_notify_author';
                } elseif (!empty($monitor_ids) && in_array($user_id, $monitor_ids)) {
                    $notification_class = 'rev_submission_notify_monitor';
                } else {
                    $notification_class = 'rev_submission_notify_admin';
                }

                rvy_mail(
                    $address, 
                    $title, 
                    $message, 
                    [
                        'revision_id' => $revision_id, 
                        'post_id' => $published_post->ID, 
                        'notification_type' => $notification_type,
                        'notification_class' => $notification_class,
                    ]
                );
            }
        }
    }
    
    static function getRecipients($notification_class, $args) {
        $defaults = ['type_obj' => false, 'published_post' => false];
        foreach(array_keys($defaults) as $key) {
            if (!empty($args[$key])) {
                $$key = $args[$key];
            } else {
                return [];
            }
        }

        $recipient_ids = [];

        switch ($notification_class) {
            case 'rev_submission_notify_admin' :
            case 'rev_approval_notify_admin' :

                do_action('presspermit_init_rvy_interface');

                if ( defined('RVY_CONTENT_ROLES') && ! defined('SCOPER_DEFAULT_MONITOR_GROUPS') && ! defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS') ) {
                    global $revisionary;
                    
                    $monitor_groups_enabled = true;
                    $revisionary->content_roles->ensure_init();
                    
                    $recipient_ids = $revisionary->content_roles->get_metagroup_members( 'Pending Revision Monitors' );
                    
                    if ( $type_obj ) {
                        $post_publisher_ids = $revisionary->content_roles->users_who_can( 'edit_post', $published_post->ID, array( 'cols' => 'id', 'user_ids' => $recipient_ids ) );
                        $recipient_ids = array_intersect( $recipient_ids, $post_publisher_ids );
                    }

                    $monitor_ids = $recipient_ids;
                }

                if (!$recipient_ids && (empty($monitor_groups_enabled) || ! defined('RVY_FORCE_MONITOR_GROUPS'))) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                    
                    if ( defined( 'SCOPER_MONITOR_ROLES' ) ) {
                        $use_wp_roles = SCOPER_MONITOR_ROLES;
                    } else {
                        $use_wp_roles = ( defined( 'RVY_MONITOR_ROLES' ) ) ? RVY_MONITOR_ROLES : 'administrator,editor';
                    }

                    $use_wp_roles = str_replace( ' ', '', $use_wp_roles );
                    $use_wp_roles = explode( ',', $use_wp_roles );
                    
                    foreach ( $use_wp_roles as $role_name ) {
                        $search = new WP_User_Query( "search=&fields=id&role=$role_name" );
                        $recipient_ids = array_merge( $recipient_ids, $search->results );
                        $recipient_ids = array_unique($recipient_ids);
                    }

                    if ( $recipient_ids && $type_obj ) {
                        foreach( $recipient_ids as $key => $user_id ) {
                            $_user = new WP_User($user_id);
                            $reqd_caps = map_meta_cap( 'edit_post', $user_id, $published_post->ID );

                            if (
                                array_diff( $reqd_caps, array_keys( array_intersect( $_user->allcaps, array( true, 1, '1' ) ) ) ) 
                                && !in_array('administrator', $_user->allcaps) 
                            ) {
                                unset( $recipient_ids[$key] );
                            }
                        }
                    }
                }

                break;
            default:
        } // end switch notification_class

        return $recipient_ids;
    }
}
