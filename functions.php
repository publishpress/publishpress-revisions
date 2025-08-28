<?php
function revisionary() {
    return \PublishPress\Revisions::instance();
}

function revisionary_unrevisioned_postmeta() {
	$exclude = (array) apply_filters( 'revisionary_unrevisioned_postmeta', [] );

	$exclude = array_merge(
		$exclude, 
        array_fill_keys(['_rvy_base_post_id', '_rvy_has_revisions', '_rvy_published_gmt', '_rvy_approved_by', '_rvy_updated_by', '_pp_is_autodraft', '_pp_last_parent', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_attached_file', '_menu_item_classes', '_menu_item_menu_item_parent', '_menu_item_object', '_menu_item_object_id', '_menu_item_target', '_menu_item_type', '_menu_item_url', '_menu_item_xfn', '_rs_file_key', '_scoper_custom', '_scoper_last_parent', '_wp_attachment_backup_sizes', '_wp_attachment_metadata', '_wp_trash_meta_status', '_wp_trash_meta_time', '_last_attachment_ids', '_last_category_ids', '_encloseme', '_pingme', '_pp_statuses_last_main_status', 'peepso_postnotify', '_peepso_postnotify', '_rvy_subpost_original_source_id', 'jr_listing_views', '_jr_listing_views'], true)
	);
	
	return array_keys(array_filter($exclude));
}

/**
 * Sanitizes a string entry
 *
 * Keys are used as internal identifiers. Uppercase or lowercase alphanumeric characters,
 * spaces, periods, commas, plusses, asterisks, colons, pipes, parentheses, dashes and underscores are allowed.
 *
 * @param string $entry String entry
 * @return string Sanitized entry
 */
function pp_revisions_sanitize_entry( $entry ) {
    $entry = preg_replace( '/[^a-zA-Z0-9 \.\,\+\*\:\|\(\)_\-]/', '', $entry );

    return $entry;
}

/*
 * Same as sanitize_key(), but without applying filters
 */
function pp_revisions_sanitize_key( $key ) {
    $raw_key = $key;
    $key     = strtolower( $key );
    $key     = preg_replace( '/[^a-z0-9_\-]/', '', $key );
    
    return $key;
}

/**
 * Copies the taxonomies of a post to another post.
 * Based on Yoast Duplicate Post
 *
 * @param \WP_Post $from_post  The source post object.
 * @param int      $target_id  Target post ID.
 * @param array    $args The options array.
 * 
 * @return void
 */
function revisionary_copy_terms($from_post, $target_id, $args = []) {
    global $wpdb;

    $defaults = ['empty_target_only' => false];
    $args = array_merge($defaults, $args);
    foreach (array_keys($defaults) as $var) {
        $$var = $args[$var];
    }

    if ( isset( $wpdb->terms ) ) {
        if (is_scalar($from_post)) {
            $from_post = get_post($from_post);
        }

        if (empty($from_post) || empty($from_post->post_type)) {
            return;
        }

        // Clear default category (added by wp_insert_post).
        if (!$empty_target_only || !wp_get_object_terms($target_id, 'category', ['fields' => 'ids'])) {
            wp_set_object_terms( $target_id, null, 'category' );
        }

        $post_taxonomies = get_object_taxonomies( $from_post->post_type );
        
        // Several plugins just add support to post-formats but don't register post_format taxonomy.
        if (!in_array('post_format', $post_taxonomies, true) && post_type_supports($from_post->post_type, 'post-formats')) {
            $post_taxonomies[] = 'post_format';
        }

        /**
         * Filters the taxonomy excludelist when copying a post.
         *
         * @param array $taxonomies_blacklist The taxonomy excludelist from the options.
         *
         * @return array
         */
        $taxonomies_blacklist = [];
    
        $taxonomies_blacklist = apply_filters('revisionary_skip_taxonomies', $taxonomies_blacklist);
        
        if (defined('POLYLANG_VERSION')) {
            if (!empty($args['applying_revision'])) {
                $taxonomies_blacklist = array_merge($taxonomies_blacklist, ['language', 'post_translations', 'term_language', 'term_translations', '']);
            }
        }

        foreach (array_diff($post_taxonomies, $taxonomies_blacklist) as $taxonomy) {
            if ($empty_target_only) {
                $target_terms = wp_get_object_terms($target_id, $taxonomy, ['fields' => 'ids']);
                if (!empty($target_terms)) {
                    continue;
                }
            }

            $post_term_slugs = wp_get_object_terms($from_post->ID, $taxonomy, ['fields' => 'slugs', 'orderby' => 'term_order']);
            wp_set_object_terms($target_id, $post_term_slugs, $taxonomy);
        }
    }
}

/**
 * Copies the meta information of a post to another post.
 * Based on Yoast Duplicate Post
 *
 * @param \WP_Post $from_post  The source post object.
 * @param int      $target_id  Target post ID.
 * @param array    $args The options array.
 *
 * @return void
 */
function revisionary_copy_postmeta($from_post, $to_post_id, $args = []) {
    $defaults = ['empty_target_only' => false, 'apply_deletions' => false];
    $args = array_merge($defaults, $args);
    foreach (array_keys($defaults) as $var) {
        $$var = $args[$var];
    }

    if (is_scalar($from_post)) {
        $from_post = get_post($from_post);
    }

    $source_meta_keys = \get_post_custom_keys( $from_post->ID );
    if ( empty( $source_meta_keys ) ) {
        return;
    }

    $meta_excludelist = revisionary_unrevisioned_postmeta();

    $meta_excludelist_string = '(' . implode( ')|(', $meta_excludelist ) . ')';
    if ( strpos( $meta_excludelist_string, '*' ) !== false ) {
        $meta_excludelist_string = str_replace( [ '*' ], [ '[a-zA-Z0-9_]*' ], $meta_excludelist_string );

        $meta_keys = [];
        foreach ( $source_meta_keys as $meta_key ) {
            if (!in_array($meta_key, $meta_excludelist)
            && !preg_match( '#^' . $meta_excludelist_string . '$#', $meta_key ) 
            ) {
                $meta_keys[] = $meta_key;
            }
        }
    } else {
        $meta_keys = array_diff( $source_meta_keys, $meta_excludelist );
    }

    $target_meta_keys = (array) \get_post_custom_keys( $to_post_id );

    $meta_keys = apply_filters(
        'revisionary_create_revision_meta_keys',    // Bypass problematic Link Whisper plugin postmeta by default
        array_diff($meta_keys, ['wpil_links_outbound_external_count_data', 'wpil_links_outbound_internal_count_data', 'wpil_links_outbound_external_count'])
    );

    foreach ( $meta_keys as $meta_key ) {
        if ($empty_target_only && !empty($target_meta_keys) && is_array($target_meta_keys)) {
            if (in_array($meta_key, $target_meta_keys)) {
                continue;
            }
        }

        $meta_values = \get_post_custom_values( $meta_key, $from_post->ID );

        if (!empty($meta_values)) {
            if (count($meta_values) > 1) {
                delete_post_meta($to_post_id, $meta_key);

                foreach ( $meta_values as $meta_value ) {
                    $meta_value = maybe_unserialize( $meta_value );
                    add_post_meta( $to_post_id, $meta_key, \PublishPress\Revisions\Utils::recursively_slash_strings( $meta_value ) );
                }
            } else {
                foreach ( $meta_values as $meta_value ) {
                    $meta_value = maybe_unserialize( $meta_value );
                    update_post_meta( $to_post_id, $meta_key, \PublishPress\Revisions\Utils::recursively_slash_strings( $meta_value ) );
                }
            }
        }
    }

    if (!$empty_target_only && !empty($target_meta_keys) && is_array($target_meta_keys)) {
        if ($delete_meta_keys = array_diff($target_meta_keys, $meta_keys, revisionary_unrevisioned_postmeta())) {
            $deletable_keys = apply_filters('revisionary_deletable_postmeta_keys', ['_links_to', '_links_to_target']);
        }
        
        foreach($delete_meta_keys as $meta_key) {
            if (in_array($meta_key, $deletable_keys) || !empty($args['apply_deletions']) || defined('PP_REVISIONS_APPLY_POSTMETA_DELETION')) {
                delete_post_meta($to_post_id, $meta_key);
            }
        }
    }

    $args = array_merge(
        $args,
        compact('meta_keys', 'source_meta_keys')
    );

    do_action('revisionary_copy_postmeta', $from_post, $to_post_id, $args);
}

function rvy_revision_base_statuses($args = []) {
	$defaults = ['output' => 'names', 'return' => 'array'];
	$args = array_merge($defaults, $args);
	foreach (array_keys($defaults) as $var) {
		$$var = $args[$var];
	}

	$arr = array_map('sanitize_key', (array) apply_filters('rvy_revision_base_statuses', ['draft', 'pending', 'future']));

	if ('object' == $output) {
		$status_keys = array_values($arr);
		$arr = [];

		foreach($status_keys as $k) {
			$arr[$k] = get_post_status_object($k);
		}
	}

	return ('csv' == $return) ? "'" . implode("','", $arr) . "'" : $arr;
}

function rvy_revision_statuses($args = []) {
	$defaults = ['output' => 'names', 'return' => 'array'];
	$args = array_merge($defaults, $args);
	foreach (array_keys($defaults) as $var) {
		$$var = $args[$var];
	}
	
	$arr = array_map('sanitize_key', (array) apply_filters('rvy_revision_statuses', ['draft-revision', 'pending-revision', 'future-revision']));

	if ('object' == $output) {
		$status_keys = array_values($arr);
		$arr = [];

		foreach($status_keys as $k) {
			$arr[$k] = get_post_status_object($k);
		}
	}

	return ('csv' == $return) ? "'" . implode("','", $arr) . "'" : $arr;
}

function rvy_is_revision_status($post_status) {
	return in_array($post_status, rvy_revision_statuses());
}

function rvy_in_revision_workflow($post, $args = []) {
	if (!empty($post) && is_numeric($post)) {
		$post = get_post($post);
	}

	if (empty($post) || empty($post->post_mime_type)) {
		return false;
	}

    return rvy_is_revision_status($post->post_mime_type) ? $post->post_mime_type : false;
}

function rvy_from_revision_workflow($post, $args=[]) {
    if (!empty($post) && is_numeric($post)) {
		$post = get_post($post);
	}

	if (empty($post) || ('revision' != $post->post_type) || ('inherit' != $post->post_status)) {
		return false;
	}

    if ($prev_revision_status = get_post_meta($post->ID, '_rvy_prev_revision_status', true)) {
        return $prev_revision_status;
    
    } elseif (get_post_meta($post->ID, '_rvy_published_gmt', true)) {
        return true;
    } else {
        return false;
    }
}

function rvy_status_revisions_active($post_type = '') {
    if (defined('PUBLISHPRESS_STATUSES_PRO_VERSION') && class_exists('PublishPress_Statuses')) {
        if ($post_type) {
            $status_revisions_active = in_array($post_type, \PublishPress_Statuses::getEnabledPostTypes());
        } else {
            $status_revisions_active = true;
        }
    } else {
        $status_revisions_active = false;
    }

    return $status_revisions_active;
}

function rvy_post_id($revision_id) {
    if ($_post = get_post($revision_id)) {
        // if ID passed in is not a revision, return it as is
        if (('revision' != $_post->post_type) && !rvy_in_revision_workflow($_post)) {
            return $revision_id;

        } elseif ('revision' == $_post->post_type) {
            return $_post->post_parent;

        } else {
            if (!$_post->comment_count) {
                static $busy;

                if (!empty($busy)) {
                    return;
                }
                
                $busy = true;
                $published_id = rvy_get_post_meta( $revision_id, '_rvy_base_post_id', true );
                $busy = false;

                if ($published_id && ($published_id != $revision_id)) {
                    global $wpdb;

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update($wpdb->posts, ['comment_count' => $published_id], ['ID' => $revision_id]);
                }
            } else {
                $published_id = $_post->comment_count;
            }
        }
    }

	return (!empty($published_id)) ? $published_id : 0;
}

// Append a random argument for cache busting
function rvy_nc_url($url, $args = []) {
    $nc = (!empty($args['nc'])) ? $args['nc'] : substr(md5(wp_rand(1, 99999999)), 1, 8);
    return add_query_arg('nc', $nc, $url);
}

// Complete an admin URL, appending a random argument for cache busting
function rvy_admin_url($partial_admin_url) {
    return rvy_nc_url( admin_url($partial_admin_url) );
}


// deprecated
function publishpress_revisions_post_updated($post) {
    return [];
}

// deprecated
function publishpress_revisions_get_revision_author($revision_id) {
    return false;
}

/*
 * Get supplemental information about a revision.
 * 
 * @param WP_Post|int $post Revision to get information for.
 * 
 * @return array
 *      'updated_by' :          WP_User User who last updated the revision
 *      'updaters' :            array All users who updated the revision (userID => date)
 *      'is_revision' :         boolean Is the post a revision?
 *      'is_archive' :          boolean Is the post a past revision?
 *      'publish_method' :      string Publication method (Revision Publication / Scheduled Revision Publication / Direct Edit / Edit of Revision)
 *      'update_type' :         string Update type (Scheduled Rev / Submitted Rev / Direct Edit)
 *      'approved_by' :         WP_User User who approved the revision
 *      'main_post' :           WP_Post Main post the revision is based on
 */
function publishpress_get_revision_info($post) {
    global $revisionary;

    $arr = [];

    if (empty($revisionary)) {
        return false;
    }

    if (!empty($post) && is_numeric($post)) {
		$post = get_post($post);
	}

    if (empty($post)) {
        return false;
    }

    if ($revision_published_gmt = get_post_meta($post->ID, '_rvy_published_gmt', true)) {
        $from_revision_workflow = get_post_meta($post->ID, '_rvy_prev_revision_status', true);
        
        if (!$from_revision_workflow) {
            $from_revision_workflow = true;
        }

    } elseif ($revision_status = rvy_in_revision_workflow($post->post_parent)) {
        $parent_in_revision_workflow = $revision_status;
    
    } elseif ($revision_status = rvy_from_revision_workflow($post->post_parent)) {
        $parent_from_revision_workflow = $revision_status;
    } else {
        $direct_edit = true;
    }

    $prev_revision_status = get_post_meta($post->ID, '_rvy_prev_revision_status', true);

    $main_post_id = rvy_post_id($post->ID);

    if (!empty($from_revision_workflow)) {
        switch ($from_revision_workflow) {
            case 'future-revision':
                $arr['publish_method'] = esc_html__('Scheduled Revision Publication', 'revisionary');
                break;

            default:
            $arr['publish_method'] = esc_html__('Revision Publication', 'revisionary');
        }
    } elseif (!empty($parent_in_revision_workflow)) {
        if ($status_obj = get_post_status_object($parent_in_revision_workflow)) {
            $status_label = $status_obj->label;
        } else {
            $status_label = $status_name;
        }

        $arr['publish_method'] = sprintf(
            esc_html__('Edit of %s', 'revisionary'),
            "<span title='$this->active_revision_title'>" . $status_label . '</span>'
        );

    } elseif (!empty($parent_from_revision_workflow)) {
        $arr['publish_method'] = sprintf("<span title='%s'>%s</span>",
            $from_revision_title,
            esc_html__('Edit of published Revision', 'revisionary')
        );
    } elseif (!empty($direct_edit)) {
        $arr['publish_method'] = esc_html__('Direct Edit', 'revisionary');
    }

    if (!empty($direct_edit)) {
        $approver_id = $post->post_author;

    } elseif ($from_revision_workflow) {
        $approver_id = get_post_meta($post->ID, '_rvy_approved_by', true);
    }

    $approver = (!empty($approver_id)) ? new WP_User($approver_id) : false;

    $updated_by = 0;

    if (rvy_in_revision_workflow($post)) {
        $is_revision = $post->post_mime_type;

        $updaters = get_post_meta($post->ID, '_rvy_updated_by', true);

        if (!empty($updaters)) {
            $updated_by = array_key_last($updaters);
        }
    } elseif ('revision' == $post->post_type) {
        $is_revision = $post->post_status;
        
        if ('inherit' == $post->post_status) {
            $is_archive = true;
            $updated_by = $post->post_author;

            $prev_revision_status = get_post_meta($post->ID, '_rvy_prev_revision_status', true);
            $revision_publication = get_post_meta($post->ID, '_rvy_published_gmt', true);
        }

    } else {
        if ($revisions = wp_get_post_revisions($post)) {
            $last_revision = reset($revisions);

            $updated_by = $last_revision->post_author;

            $prev_revision_status = get_post_meta($last_revision->ID, '_rvy_prev_revision_status', true);
            $revision_publication = get_post_meta($last_revision->ID, '_rvy_published_gmt', true);
        }
    }

    if (!empty($prev_revision_status) || !empty($revision_publication)) {
        switch ($prev_revision_status) {
            case 'future-revision':
                $arr['update_type'] = esc_html__('Scheduled Rev.', 'revisionary');
                break;

            case 'pending-revision':
            case 'draft-revision':
                $arr['update_type'] = esc_html__('Submitted Rev.', 'revisionary');
                break;

            default:
                if (!empty($revision_publication)) {
                    $arr['update_type'] = esc_html__('Submitted Rev.', 'revisionary');
                }
        }
    }

    if (empty($update_type)) {
        $arr['update_type'] = esc_html__('Direct Edit', 'revisionary');
        $direct_update = true;
    }

    $updated_by_user = (!empty($updated_by)) ? new WP_User($updated_by) : false;

    return array_merge($arr, [
        'updated_by' => $updated_by_user,
        'updaters' => !empty($updaters) ? $updaters : [],
        'is_revision' => !empty($from_revision_workflow) || !empty($parent_from_revision_workflow),
        'is_archive' => !empty($is_archive),
        'direct_update' => !empty($direct_update),
        'approved_by' => $approver,
        'main_post' => ($main_post_id && $main_post_id != $post->ID) ? get_post($main_post_id) : $post
    ]);
}

function pp_revisions_plugin_updated($current_version, $args = []) {
    global $wpdb;
    
    $last_ver = get_option('revisionary_last_version');

    if ($current_version == $last_ver) {
        return;
    }

    do_action('revisionary_plugin_updated', $last_ver, $current_version);

    if ((defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') || !empty($args['is_pro'])) && version_compare($last_ver, '3.6.6-rc3', '<')) {
        update_option('revisionary_pro_fix_revision_scheduled_notification', true);
        update_option('revisionary_pro_fix_default_notifications_meta_key', true);
    }

    if ((defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') || !empty($args['is_pro'])) && version_compare($last_ver, '3.6.6-beta5', '<')) {
        update_option('revisionary_pro_fix_default_notification_shortcodes', true);
    }

    if ((defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') || !empty($args['is_pro'])) && version_compare($last_ver, '3.6.4-beta3', '<')) {
        update_option('revisionary_pro_restore_notifications', true);
    }

    if (version_compare($last_ver, '3.0.12-rc4', '<')) {
        global $wp_version;

        if (class_exists('WpeCommon') || version_compare($wp_version, '5.9', '>=')) {
            update_option('rvy_scheduled_publish_cron', 1);  // trigger generation of cron schedules for existing scheduled revisions
        }
    }

    if (version_compare($last_ver, '3.0.5-beta', '<')) {
        if ($role = @get_role('revisor')) {
            $role->add_cap('list_others_posts');
            $role->add_cap('list_others_pages');
            $role->add_cap('list_published_posts');
            $role->add_cap('list_published_pages');
            $role->add_cap('list_private_posts');
            $role->add_cap('list_private_pages');
        }
    }

    if (version_compare($last_ver, '3.0.1', '<')) {
        // convert pending / scheduled revisions to v3.0 format
		$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));
		$wpdb->query("UPDATE $wpdb->posts SET post_mime_type = post_status WHERE post_status IN ('$revision_status_csv')");                             // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'draft', post_mime_type = 'draft-revision' WHERE post_status IN ('draft-revision')");       // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'pending', post_mime_type = 'pending-revision' WHERE post_status IN ('pending-revision')"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("UPDATE $wpdb->posts SET post_status = 'pending', post_mime_type = 'future-revision' WHERE post_status IN ('future-revision')");   // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    } 

    if (version_compare($last_ver, '3.0.7-rc4', '<') && !defined('PRESSPERMIT_DEBUG')) {
        // delete revisions that were erroneously trashed instead of deleted
        $wpdb->query("DELETE FROM $wpdb->posts WHERE post_mime_type IN ('draft-revision', 'pending-revision', 'future-revision') AND post_status = 'trash'");   // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    if (version_compare($last_ver, '3.0-rc7', '<')) {
        if ($role = @get_role('administrator')) {
            $role->add_cap('manage_unsubmitted_revisions');
        }

        if ($role = @get_role('revisor')) {
            $role->add_cap('upload_files');
        }
    } 

    if ($current_version != $last_ver) {
        require_once( dirname(__FILE__).'/lib/agapetry_wp_core_lib.php');
        require_once(dirname(__FILE__).'/rvy_init.php');
        revisionary_refresh_revision_flags();

        // mirror to REVISIONARY_VERSION
        update_option('revisionary_last_version', $current_version);

        delete_option('revisionary_sent_mail');

        // Default-enable legacy notifications for existing installations
        if (!empty($last_ver)) {
            if (null === get_option('rvy_legacy_notifications', null)) {
                update_option('rvy_legacy_notifications', 1);
            }
        }
    }
}

function pp_revisions_plugin_activation() {
    // force this timestamp to be regenerated, in case something went wrong before
    delete_option( 'rvy_next_rev_publish_gmt' );

    if (!class_exists('RevisionaryActivation')) {
        require_once(dirname(__FILE__).'/activation_rvy.php');
    }

    require_once(dirname(__FILE__).'/functions.php');

    // import from Revisionary 1.x
    new RevisionaryActivation(['import_legacy' => true]);

    // convert pending / scheduled revisions to v3.0 format
    global $wpdb;

    $revision_statuses = rvy_revision_statuses();
    
    if (defined('PUBLISHPRESS_STATUSES_PRO_VERSION')) {
        $revision_statuses = array_merge($revision_statuses, ['revision-deferred', 'revision-needs-work', 'revision-rejected']);
        
        if (!get_option('rvy_permissions_compat_mode', false)) {
            if (!taxonomy_exists('pp_revision_status')) {
                register_taxonomy(
                    'pp_revision_status',
                    'post',
                    [
                        'hierarchical'          => false,
                        'query_var'             => false,
                        'rewrite'               => false,
                        'show_ui'               => false,
                    ]
                );
            }

            $stored_statuses = get_terms('pp_revision_status', ['hide_empty' => false, 'return' => 'name']);

            foreach ($stored_statuses as $status) {
                if (is_object($status) && property_exists($status, 'slug') && !in_array($status->slug, $revision_statuses)) {
                    $revision_statuses[] = $status->slug;
                }
            }
        }
    }

    $revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

    if (!defined('REVISIONARY_DISABLE_ACTIVATION_TRASH_QUERY')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results("SELECT ID, comment_count FROM $wpdb->posts WHERE post_mime_type IN ('$revision_status_csv') AND post_status = 'trash'");

        $trashed_ids = [];

        foreach ($results as $row) {
            $trashed_ids[$row->comment_count] = $row->ID;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $revision_post_ids = $wpdb->get_col("SELECT comment_count FROM $wpdb->posts WHERE post_mime_type IN ('$revision_status_csv')");

        $id_csv = implode("','", $revision_post_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $deleted_ids = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE post_id NOT IN ('" . $id_csv . "') AND meta_key = '_rvy_base_post_id'");

        foreach (array_merge($trashed_ids, $deleted_ids) as $revision_id) {
            delete_post_meta($revision_id, '_rvy_base_post_id', true);
        }

        if (get_option('rvy_revision_limit_per_post')) {
            foreach (array_keys($trashed_ids) as $post_id) {
                if ($post_id) {
                    revisionary_refresh_postmeta($post_id);
                }
            }
        }
    }

    if (!get_option('rvy_permissions_compat_mode', false)) {
        $wpdb->query("UPDATE $wpdb->posts SET post_mime_type = post_status WHERE post_status IN ('$revision_status_csv')");                             // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $wpdb->query("UPDATE $wpdb->posts SET post_status = 'pending' WHERE post_status IN ('$revision_status_csv') AND post_status != 'draft-revision'");  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        
        $wpdb->query("UPDATE $wpdb->posts SET post_status = 'draft' WHERE post_status IN ('draft-revision')");       // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}

function pp_revisions_plugin_deactivation() {
    global $wpdb;

    require_once(dirname(__FILE__).'/functions.php');

    if (get_option('rvy_permissions_compat_mode', false)) {
        return;
    }

    // Prevents pending / scheduled revisions from being listed as regular drafts / pending posts after plugin is deactivated
    $revision_statuses = array_merge(rvy_revision_statuses(), ['revision-deferred', 'revision-needs-work', 'revision-rejected']);
        
    if (!taxonomy_exists('pp_revision_status')) {
        register_taxonomy(
            'pp_revision_status',
            'post',
            [
                'hierarchical'          => false,
                'query_var'             => false,
                'rewrite'               => false,
                'show_ui'               => false,
            ]
        );
    }

    $stored_statuses = get_terms('pp_revision_status', ['hide_empty' => false, 'return' => 'name']);

    foreach ($stored_statuses as $status) {
        if (is_object($status) && property_exists($status, 'slug') && !in_array($status->slug, $revision_statuses)) {
            $revision_statuses[] = $status->slug;
        }
    }

    $revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

    $wpdb->query("UPDATE $wpdb->posts SET post_status = post_mime_type WHERE post_mime_type IN ('$revision_status_csv')");                          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    if ($timestamp = wp_next_scheduled('rvy_mail_buffer_hook')) {
        wp_unschedule_event($timestamp,'rvy_mail_buffer_hook');
    }
}
